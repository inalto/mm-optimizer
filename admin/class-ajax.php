<?php
/**
 * Gestori AJAX di amministrazione.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Admin_Ajax {

	/**
	 * Registra gli agganci.
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
	 * Verifica permessi e nonce.
	 *
	 * @return void
	 */
	protected static function guard() {
		check_ajax_referer( 'mm_optimizer_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'mm-optimizer' ) ), 403 );
		}
	}

	/**
	 * Riesegue il rilevamento.
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
					/* translators: %d: durata del rilevamento in millisecondi. */
					__( 'Rilevamento completato in %d ms.', 'mm-optimizer' ),
					(int) $caps['duration_ms']
				),
				'duration' => (int) $caps['duration_ms'],
			)
		);
	}

	/**
	 * Applica la configurazione consigliata.
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
					/* translators: %s: nome del livello di ottimizzazione applicato. */
					__( 'Configurazione applicata: livello %s.', 'mm-optimizer' ),
					Presets::label( $analysis['level'] )
				),
			)
		);
	}

	/**
	 * Una passata di scansione della libreria.
	 *
	 * @return void
	 */
	public static function bulk_scan() {
		self::guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- gia' verificato da guard().
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
	 * Lavora un lotto e restituisce l'avanzamento.
	 *
	 * @return void
	 */
	public static function bulk_tick() {
		self::guard();

		// Un lotto guidato dal browser sta piu' stretto: la richiesta deve
		// rispondere prima che il browser si stanchi di aspettare.
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
	 * Svuota la coda.
	 *
	 * @return void
	 */
	public static function bulk_stop() {
		self::guard();

		Queue::clear();
		Queue::reset_state( 0 );

		wp_send_json_success( array( 'message' => __( 'Coda svuotata.', 'mm-optimizer' ) ) );
	}

	/**
	 * Importa le impostazioni dal plugin precedente.
	 *
	 * @return void
	 */
	public static function import_settings() {
		self::guard();

		Migrator::import_settings();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: nome del livello di ottimizzazione risultante. */
					__( 'Impostazioni importate. Livello risultante: %s.', 'mm-optimizer' ),
					Presets::label( Options::get( 'level' ) )
				),
			)
		);
	}

	/**
	 * Adotta una passata di file gia' convertiti.
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
					/* translators: 1: file adottati in questa passata, 2: file ignorati. */
					__( 'Adottati %1$s file, ignorati %2$s.', 'mm-optimizer' ),
					number_format_i18n( (int) $step['adopted'] ),
					number_format_i18n( (int) $step['skipped'] )
				),
			)
		);
	}
}
