<?php
/**
 * Menu, risorse e avvisi di amministrazione.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Admin {

	/**
	 * Registra gli agganci.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'mm_optimizer_detect', array( 'MM_Optimizer\\Capabilities', 'detect' ) );
		add_filter( 'plugin_action_links_' . MM_OPTIMIZER_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Voce di menu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page(
			__( 'MM Optimizer', 'mm-optimizer' ),
			__( 'MM Optimizer', 'mm-optimizer' ),
			'manage_options',
			'mm-optimizer',
			array( 'MM_Optimizer\\Settings', 'render_page' ),
			'dashicons-images-alt2',
			81
		);
	}

	/**
	 * Collegamento rapido dalla lista dei plugin.
	 *
	 * @param array $links Collegamenti esistenti.
	 * @return array
	 */
	public static function action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=mm-optimizer' ) ),
			esc_html__( 'Impostazioni', 'mm-optimizer' )
		);

		array_unshift( $links, $settings );

		return $links;
	}

	/**
	 * Fogli di stile e script della pagina.
	 *
	 * @param string $hook Identificativo della schermata.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( 'toplevel_page_mm-optimizer' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'mm-optimizer-admin', MM_OPTIMIZER_URL . 'admin/css/admin.css', array(), MM_OPTIMIZER_VERSION );
		wp_enqueue_script( 'mm-optimizer-admin', MM_OPTIMIZER_URL . 'admin/js/admin.js', array(), MM_OPTIMIZER_VERSION, true );

		wp_localize_script(
			'mm-optimizer-admin',
			'mmOptimizer',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'mm_optimizer_admin' ),
				'i18n'     => array(
					'detecting' => __( 'Rilevamento in corso...', 'mm-optimizer' ),
					'applying'  => __( 'Applicazione in corso...', 'mm-optimizer' ),
					'failed'    => __( 'Operazione non riuscita.', 'mm-optimizer' ),
					'confirm'   => __( 'Sostituire le impostazioni attuali con quelle consigliate?', 'mm-optimizer' ),
					'scanning'  => __( 'Analisi della libreria in corso...', 'mm-optimizer' ),
					/* translators: 1: derivate create, 2: file saltati, 3: errori, 4: spazio risparmiato, 5: file rimanenti. */
					'progress'  => __( 'Create %1$s · saltate %2$s · errori %3$s · risparmiati %4$s · rimangono %5$s', 'mm-optimizer' ),
					'finished'  => __( 'Conversione completata.', 'mm-optimizer' ),
					'stopped'   => __( 'Conversione interrotta.', 'mm-optimizer' ),
				),
			)
		);
	}

	/**
	 * Avvisi.
	 *
	 * @return void
	 */
	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::conflict_notice();
		self::capability_notice();
	}

	/**
	 * Avverte quando un altro ottimizzatore e' attivo.
	 *
	 * @return void
	 */
	public static function conflict_notice() {
		$active = Conflicts::active();

		if ( empty( $active ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'MM Optimizer', 'mm-optimizer' ) . '</strong> — ';

		printf(
			/* translators: %s: elenco dei plugin in conflitto. */
			esc_html(
				_n(
					'%s gestisce gia\' le immagini di questo sito, quindi la consegna di MM Optimizer resta spenta per non sovrapporsi. La conversione continua a funzionare: le derivate saranno pronte quando deciderai di passare.',
					'%s gestiscono gia\' le immagini di questo sito, quindi la consegna di MM Optimizer resta spenta per non sovrapporsi. La conversione continua a funzionare: le derivate saranno pronte quando deciderai di passare.',
					count( $active ),
					'mm-optimizer'
				)
			),
			'<strong>' . esc_html( implode( ', ', $active ) ) . '</strong>'
		);

		printf(
			' <a href="%s">%s</a>',
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Vai ai plugin', 'mm-optimizer' )
		);

		echo '</p></div>';
	}

	/**
	 * Avverte quando l'host non e' in grado di produrre formati moderni.
	 *
	 * @return void
	 */
	public static function capability_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && 'toplevel_page_mm-optimizer' === $screen->id ) {
			return;
		}

		$caps = Capabilities::get();

		if ( ! in_array( 'no_modern_encoder', (array) $caps['warnings'], true ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'MM Optimizer', 'mm-optimizer' ) . '</strong> — ';
		echo esc_html__( 'Nessuna libreria grafica di questo server sa produrre WebP o AVIF. Apri la scheda Diagnostica: elenca esattamente cosa chiedere all\'hosting.', 'mm-optimizer' );
		printf(
			' <a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=mm-optimizer' ) ),
			esc_html__( 'Apri la diagnostica', 'mm-optimizer' )
		);
		echo '</p></div>';
	}
}
