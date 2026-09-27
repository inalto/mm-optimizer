<?php
/**
 * Admin AJAX handlers.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Admin_Ajax {

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_mm_optimizer_detect', array( __CLASS__, 'detect' ) );
		add_action( 'wp_ajax_mm_optimizer_apply_recommendation', array( __CLASS__, 'apply_recommendation' ) );
		add_action( 'wp_ajax_mm_optimizer_bulk_scan', array( __CLASS__, 'bulk_scan' ) );
		add_action( 'wp_ajax_mm_optimizer_bulk_tick', array( __CLASS__, 'bulk_tick' ) );
		add_action( 'wp_ajax_mm_optimizer_bulk_stop', array( __CLASS__, 'bulk_stop' ) );
		add_action( 'wp_ajax_mm_optimizer_import_settings', array( __CLASS__, 'import_settings' ) );
		add_action( 'wp_ajax_mm_optimizer_adopt', array( __CLASS__, 'adopt' ) );
	}

	/**
	 * Checks permissions and the nonce.
	 *
	 * @return void
	 */
	protected static function guard() {
		check_ajax_referer( 'mm_optimizer_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mm-optimizer' ) ), 403 );
		}
	}

	/**
	 * Re-runs detection.
	 *
	 * @return void
	 */
	public static function detect() {
		self::guard();

		Conflicts::flush();
		$caps = Capabilities::detect();

		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: %d: detection duration in milliseconds. */
					__( 'Detection completed in %d ms.', 'mm-optimizer' ),
					(int) $caps['duration_ms']
				),
				'duration' => (int) $caps['duration_ms'],
			)
		);
	}

	/**
	 * Applies the recommended configuration.
	 *
	 * @return void
	 */
	public static function apply_recommendation() {
		self::guard();

		$analysis = Recommender::analyze();

		Options::apply_partial( $analysis['settings'] );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: name of the optimization level applied. */
					__( 'Configuration applied: %s level.', 'mm-optimizer' ),
					Presets::label( $analysis['level'] )
				),
			)
		);
	}

	/**
	 * One scanning pass over the library.
	 *
	 * @return void
	 */
	public static function bulk_scan() {
		self::guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- already verified by guard().
		$restart = ! empty( $_POST['restart'] );

		if ( $restart ) {
			Queue::reset_state( 0 );
		}

		$step = Scanner::step( $restart );

		wp_send_json_success(
			array(
				'scanned'   => (int) $step['scanned'],
				'queued'    => (int) $step['queued'],
				'finished'  => (bool) $step['finished'],
				'remaining' => Queue::remaining(),
				'total'     => Scanner::total_attachments(),
			)
		);
	}

	/**
	 * Works a batch and reports progress.
	 *
	 * @return void
	 */
	public static function bulk_tick() {
		self::guard();

		// A browser-driven batch runs tighter: the request has to answer
		// before the browser gives up waiting.
		$summary = Queue::run( microtime( true ) + 20 );
		$state   = Queue::state();

		wp_send_json_success(
			array(
				'locked'    => ! empty( $summary['locked'] ),
				'processed' => isset( $summary['processed'] ) ? (int) $summary['processed'] : 0,
				'remaining' => (int) $state['remaining'],
				'done'      => (int) $state['done'],
				'skipped'   => (int) $state['skipped'],
				'errors'    => (int) $state['errors'],
				'saved'     => size_format( (int) $state['saved'], 1 ),
			)
		);
	}

	/**
	 * Empties the queue.
	 *
	 * @return void
	 */
	public static function bulk_stop() {
		self::guard();

		Queue::clear();
		Queue::reset_state( 0 );

		wp_send_json_success( array( 'message' => __( 'Queue cleared.', 'mm-optimizer' ) ) );
	}

	/**
	 * Imports the settings from the previous plugin.
	 *
	 * @return void
	 */
	public static function import_settings() {
		self::guard();

		Migrator::import_settings();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: name of the resulting optimization level. */
					__( 'Settings imported. Resulting level: %s.', 'mm-optimizer' ),
					Presets::label( Options::get( 'level' ) )
				),
			)
		);
	}

	/**
	 * Adopts one pass of already-converted files.
	 *
	 * @return void
	 */
	public static function adopt() {
		self::guard();

		$step = Migrator::adopt( 200, true );

		wp_send_json_success(
			array(
				'adopted'  => (int) $step['adopted'],
				'skipped'  => (int) $step['skipped'],
				'finished' => (bool) $step['finished'],
				'message'  => sprintf(
					/* translators: 1: files adopted in this pass, 2: files ignored. */
					__( 'Adopted %1$s files, ignored %2$s.', 'mm-optimizer' ),
					number_format_i18n( (int) $step['adopted'] ),
					number_format_i18n( (int) $step['skipped'] )
				),
			)
		);
	}
}
