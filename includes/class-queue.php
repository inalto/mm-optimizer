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
	 * Rows claimed more than ten minutes ago become available again: a worker
	 * killed mid-batch does not block the queue, and no watchdog process is
	 * needed.
	 *
	 * @param int $limit Number of rows.
	 * @return array
	 */
	public static function claim( $limit = 5 ) {
		global $wpdb;

		$token = wp_generate_password( 16, false );
		$now   = time();
		$stale = $now - 600;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET claimed_at = %d, claim_token = %s, attempts = attempts + 1
				 WHERE claimed_at = 0 OR claimed_at < %d
				 ORDER BY priority ASC, id ASC LIMIT %d',
				$now,
				$token,
				$stale,
				(int) $limit
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE claim_token = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token
			),
			ARRAY_A
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

		$summary = array( 'locked' => false, 'processed' => 0, 'done' => 0, 'skipped' => 0, 'error' => 0, 'saved' => 0 );

		try {
			$deadline = null !== $deadline ? (float) $deadline : self::deadline();
			$batch    = max( 1, (int) Options::get( 'bulk.batch', 5 ) );

			while ( microtime( true ) < $deadline ) {
				$rows = self::claim( $batch );

				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $row ) {
					if ( microtime( true ) >= $deadline ) {
						break 2;
					}

					self::process( $row, $summary );
					Lock::refresh();
				}
			}
		} finally {
			Lock::release();
		}

		self::update_state( $summary );

		// If work remains, another worker picks up straight away.
		if ( self::remaining() > 0 ) {
			self::spawn();
		}

		return $summary;
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
