<?php
/**
 * Work queue.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Queue {

	const STATE  = 'mm_optimizer_bulk_state';
	const SECRET = 'mm_optimizer_worker_secret';

	/**
	 * Claim token of the batch this process is working; empty when idle.
	 *
	 * @var string
	 */
	protected static $token = '';

	/**
	 * Row being worked right now.
	 *
	 * @var int
	 */
	protected static $current = 0;

	/**
	 * Running totals of the current batch, kept where the shutdown handler
	 * can still reach them after a fatal error.
	 *
	 * @var array
	 */
	protected static $summary = array();

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'mm_optimizer_queue';
	}

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- own interval, used only by the worker.
		add_action( 'mm_optimizer_run_queue', array( __CLASS__, 'run' ) );
		add_action( 'wp_ajax_mm_optimizer_worker', array( __CLASS__, 'handle_worker' ) );
		add_action( 'wp_ajax_nopriv_mm_optimizer_worker', array( __CLASS__, 'handle_worker' ) );
	}

	/**
	 * Adds a five-minute interval.
	 *
	 * @param array $schedules Intervals.
	 * @return array
	 */
	public static function cron_schedule( $schedules ) {
		$schedules['mm_opt_5min'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 minutes (MM Optimizer)', 'mm-optimizer' ),
		);

		return $schedules;
	}

	/**
	 * Queues a set of files.
	 *
	 * @param array  $paths         Absolute paths.
	 * @param int    $attachment_id Attachment.
	 * @param string $group         Job group.
	 * @param int    $priority      Priority (lower runs first).
	 * @return int Rows inserted.
	 */
	public static function push( array $paths, $attachment_id = 0, $group = 'bulk', $priority = 10 ) {
		global $wpdb;

		$formats = implode( ',', Converter::enabled_formats() );

		if ( '' === $formats ) {
			return 0;
		}

		$inserted = 0;
		$now      = time();

		foreach ( $paths as $path ) {
			$relative = Base::relativize( $path );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . self::table() . ' WHERE source_path = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$relative
				)
			);

			if ( $exists ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				self::table(),
				array(
					'job_group'     => substr( (string) $group, 0, 32 ),
					'attachment_id' => (int) $attachment_id,
					'source_path'   => $relative,
					'formats'       => $formats,
					'priority'      => (int) $priority,
					'created'       => $now,
				),
				array( '%s', '%d', '%s', '%s', '%d', '%d' )
			);

			$inserted++;
		}

		return $inserted;
	}

	/**
	 * Claims a batch.
	 *
	 * Only the lock holder claims, so rows are simply taken with the batch
	 * token: whatever a dead worker left claimed is put back by
	 * release_claims() before the next batch starts.
	 *
	 * @param int    $limit Number of rows.
	 * @param string $token Claim token of the batch.
	 * @return array
	 */
	public static function claim( $limit, $token ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET claimed_at = %d, claim_token = %s, attempts = attempts + 1
				 WHERE claimed_at = 0
				 ORDER BY priority ASC, id ASC LIMIT %d',
				time(),
				$token,
				(int) $limit
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE claim_token = %s ORDER BY priority ASC, id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token
			),
			ARRAY_A
		);
	}

	/**
	 * Puts claimed rows back in line.
	 *
	 * Rows that were claimed but never reached get their attempt back; only
	 * the row being worked when a worker died keeps it, so a file that keeps
	 * killing the worker leaves the queue after three tries.
	 *
	 * @param string $token  Claim token; empty releases every claimed row.
	 * @param int    $except Row that keeps its attempt.
	 * @return void
	 */
	protected static function release_claims( $token = '', $except = 0 ) {
		global $wpdb;

		if ( '' === $token ) {
			// Nobody else holds the lock, so every claimed row is an orphan.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( 'UPDATE ' . self::table() . " SET claimed_at = 0, claim_token = '' WHERE claimed_at > 0" );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET claimed_at = 0, claim_token = '', attempts = IF( id = %d, attempts, GREATEST( attempts, 1 ) - 1 )
				 WHERE claim_token = %s",
				(int) $except,
				$token
			)
		);
	}

	/**
	 * Removes a completed row.
	 *
	 * @param int $id Identifier.
	 * @return void
	 */
	public static function complete( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Removes an attachment's rows.
	 *
	 * @param int $attachment_id Identifier.
	 * @return void
	 */
	public static function remove_attachment( $attachment_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'attachment_id' => (int) $attachment_id ), array( '%d' ) );
	}

	/**
	 * How many rows are left.
	 *
	 * @return int
	 */
	public static function remaining() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}

	/**
	 * Empties the queue.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DELETE FROM ' . self::table() );
	}

	/**
	 * Runs a batch, within a time budget.
	 *
	 * @param float|null $deadline Cut-off instant; computed when absent.
	 * @return array Batch summary.
	 */
	public static function run( $deadline = null ) {
		if ( ! Lock::acquire() ) {
			return array( 'locked' => true, 'processed' => 0 );
		}

		self::$token   = wp_generate_password( 16, false );
		self::$current = 0;
		self::$summary = array( 'locked' => false, 'processed' => 0, 'done' => 0, 'skipped' => 0, 'error' => 0, 'saved' => 0 );

		// A fatal error (usually the execution time limit, inside an encoder)
		// skips the finally block below: the shutdown handler does its job.
		// WordPress's own fatal error handler is a shutdown function
		// registered earlier, and it ends in wp_die(), which exits before
		// ours is reached: its error message filter is the hook that runs.
		register_shutdown_function( array( __CLASS__, 'shutdown' ) );
		add_filter( 'wp_php_error_message', array( __CLASS__, 'on_fatal' ) );

		self::release_claims();

		try {
			$deadline = null !== $deadline ? (float) $deadline : self::deadline();
			$batch    = max( 1, (int) Options::get( 'bulk.batch', 5 ) );

			while ( microtime( true ) < $deadline ) {
				$rows = self::claim( $batch, self::$token );

				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $row ) {
					if ( microtime( true ) >= $deadline ) {
						break 2;
					}

					self::extend_time();

					self::$current = (int) $row['id'];
					self::process( $row, self::$summary );
					self::$current = 0;

					Lock::refresh();
				}
			}
		} finally {
			// Rows claimed but not reached before the deadline.
			self::release_claims( self::$token );
			self::$token = '';
			Lock::release();
			remove_filter( 'wp_php_error_message', array( __CLASS__, 'on_fatal' ) );
		}

		self::update_state( self::$summary );

		// If work remains, another worker picks up straight away.
		if ( self::remaining() > 0 ) {
			self::spawn();
		}

		return self::$summary;
	}

	/**
	 * Runs the cleanup from inside WordPress's fatal error handler.
	 *
	 * @param string $message Error message shown to the visitor.
	 * @return string Unchanged.
	 */
	public static function on_fatal( $message ) {
		self::shutdown();

		return $message;
	}

	/**
	 * Cleans up after a worker that died mid-batch.
	 *
	 * Registered by run(); does nothing when the batch ended normally.
	 *
	 * @return void
	 */
	public static function shutdown() {
		if ( '' === self::$token ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 20 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$error = error_get_last();

		if ( self::$current && $error && in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			Log::add(
				sprintf(
					/* translators: 1: queue row, 2: error message. */
					__( 'The worker died on queue row %1$d: %2$s', 'mm-optimizer' ),
					self::$current,
					$error['message']
				),
				'error'
			);
		}

		self::release_claims( self::$token, self::$current );
		self::$token = '';
		Lock::release();

		self::update_state( self::$summary );

		if ( self::remaining() > 0 ) {
			self::spawn();
		}
	}

	/**
	 * Restarts the execution time count before each row.
	 *
	 * On Linux the limit counts CPU time, and multithreaded AVIF encoding
	 * burns it several times faster than the wall clock the deadline uses.
	 *
	 * @return void
	 */
	protected static function extend_time() {
		$max = (int) ini_get( 'max_execution_time' );

		if ( $max > 0 && function_exists( 'set_time_limit' ) ) {
			@set_time_limit( max( 60, $max ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Works one row.
	 *
	 * @param array $row     Queue row.
	 * @param array $summary Summary, by reference.
	 * @return void
	 */
	protected static function process( array $row, array &$summary ) {
		$path = Base::expand( $row['source_path'] );

		// An attempt that fails three times leaves the queue: the tracking row
		// still holds the reason.
		if ( (int) $row['attempts'] > 3 ) {
			self::complete( $row['id'] );
			return;
		}

		$lossless = Thumbnails::optimize( $path, '', (int) $row['attachment_id'] );

		if ( $lossless['ok'] ) {
			$summary['saved'] += $lossless['saved'];
		}

		foreach ( array_filter( explode( ',', (string) $row['formats'] ) ) as $format ) {
			Lock::refresh();

			try {
				$result = Converter::convert( $path, $format, (int) $row['attachment_id'], '' );
			} catch ( \Throwable $e ) {
				Log::add(
					sprintf(
						/* translators: 1: file path, 2: error message. */
						__( 'Conversion failed for %1$s: %2$s', 'mm-optimizer' ),
						$path,
						$e->getMessage()
					),
					'error'
				);

				$summary['error']++;
				continue;
			}

			switch ( $result['status'] ) {
				case Records::STATUS_DONE:
					$summary['done']++;
					$summary['saved'] += $result['saved'];
					break;
				case Records::STATUS_SKIPPED:
					$summary['skipped']++;
					break;
				default:
					$summary['error']++;
			}
		}

		$summary['processed']++;

		self::complete( $row['id'] );
	}

	/**
	 * Cut-off instant for a batch.
	 *
	 * @return float
	 */
	public static function deadline() {
		$max   = (int) ini_get( 'max_execution_time' );
		$share = (int) Options::get( 'bulk.time_share', 60 );

		// With no declared limit, stay conservative anyway.
		$budget = $max > 0 ? ( $max * $share / 100 ) : 60;

		return microtime( true ) + min( 150, max( 10, $budget ) );
	}

	/**
	 * Starts a background worker.
	 *
	 * @return bool
	 */
	public static function spawn() {
		if ( Lock::is_held() ) {
			return false;
		}

		$driver = Options::get( 'bulk.driver', 'auto' );

		if ( 'manual' === $driver ) {
			return false;
		}

		if ( 'as' === $driver || ( 'auto' === $driver && function_exists( 'as_enqueue_async_action' ) && '' !== Capabilities::managed_host() ) ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'mm_optimizer_run_queue', array(), 'mm-optimizer' );
				return true;
			}
		}

		if ( 'cron' === $driver ) {
			if ( ! wp_next_scheduled( 'mm_optimizer_run_queue' ) ) {
				wp_schedule_single_event( time() + 10, 'mm_optimizer_run_queue' );
			}
			return true;
		}

		// Non-blocking loopback request.
		$url = add_query_arg(
			array(
				'action' => 'mm_optimizer_worker',
				'secret' => self::secret(),
			),
			admin_url( 'admin-ajax.php' )
		);

		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
				'cookies'   => array(),
			)
		);

		return true;
	}

	/**
	 * Entry point for the loopback worker.
	 *
	 * The endpoint is reachable without authentication, so it cannot rely on a
	 * nonce: access is guarded by a rotating secret compared in constant time.
	 *
	 * @return void
	 */
	public static function handle_worker() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nopriv endpoint, guarded by the secret below.
		$given = isset( $_GET['secret'] ) ? sanitize_text_field( wp_unslash( $_GET['secret'] ) ) : '';

		if ( ! hash_equals( self::secret(), $given ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		if ( function_exists( 'session_write_close' ) ) {
			session_write_close();
		}

		ignore_user_abort( true );

		self::run();

		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Worker secret, created on first use.
	 *
	 * @return string
	 */
	public static function secret() {
		$secret = get_option( self::SECRET, '' );

		if ( ! is_string( $secret ) || 32 !== strlen( $secret ) ) {
			$secret = wp_generate_password( 32, false );
			update_option( self::SECRET, $secret, false );
		}

		return $secret;
	}

	/**
	 * Updates the state shown on the progress screen.
	 *
	 * @param array $summary Batch summary.
	 * @return void
	 */
	protected static function update_state( array $summary ) {
		$state = get_option( self::STATE, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$state['last_tick'] = time();
		$state['remaining'] = self::remaining();
		$state['done']      = ( isset( $state['done'] ) ? (int) $state['done'] : 0 ) + $summary['done'];
		$state['skipped']   = ( isset( $state['skipped'] ) ? (int) $state['skipped'] : 0 ) + $summary['skipped'];
		$state['errors']    = ( isset( $state['errors'] ) ? (int) $state['errors'] : 0 ) + $summary['error'];
		$state['saved']     = ( isset( $state['saved'] ) ? (int) $state['saved'] : 0 ) + $summary['saved'];

		update_option( self::STATE, $state, false );
	}

	/**
	 * Current state.
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::STATE, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return wp_parse_args(
			$state,
			array(
				'total'     => 0,
				'remaining' => self::remaining(),
				'done'      => 0,
				'skipped'   => 0,
				'errors'    => 0,
				'saved'     => 0,
				'last_tick' => 0,
			)
		);
	}

	/**
	 * Resets the state and sets a new total.
	 *
	 * @param int $total Rows queued.
	 * @return void
	 */
	public static function reset_state( $total ) {
		update_option(
			self::STATE,
			array(
				'total'     => (int) $total,
				'remaining' => (int) $total,
				'done'      => 0,
				'skipped'   => 0,
				'errors'    => 0,
				'saved'     => 0,
				'last_tick' => time(),
			),
			false
		);
	}
}
