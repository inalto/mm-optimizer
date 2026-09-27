<?php
/**
 * Admin menu, assets and notices.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Admin {

	/**
	 * Registers the hooks.
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
	 * Menu entry.
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
	 * Shortcut from the plugin list.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=mm-optimizer' ) ),
			esc_html__( 'Settings', 'mm-optimizer' )
		);

		array_unshift( $links, $settings );

		return $links;
	}

	/**
	 * Page stylesheet and script.
	 *
	 * @param string $hook Screen identifier.
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
					'detecting' => __( 'Detecting…', 'mm-optimizer' ),
					'applying'  => __( 'Applying…', 'mm-optimizer' ),
					'failed'    => __( 'The operation failed.', 'mm-optimizer' ),
					'confirm'   => __( 'Replace the current settings with the recommended ones?', 'mm-optimizer' ),
					'scanning'  => __( 'Scanning the library…', 'mm-optimizer' ),
					/* translators: 1: derivatives created, 2: files skipped, 3: errors, 4: space saved, 5: files remaining. */
					'progress'  => __( 'Created %1$s · skipped %2$s · errors %3$s · saved %4$s · %5$s remaining', 'mm-optimizer' ),
					'finished'  => __( 'Conversion complete.', 'mm-optimizer' ),
					'stopped'   => __( 'Conversion stopped.', 'mm-optimizer' ),
				),
			)
		);
	}

	/**
	 * Notices.
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
	 * Warns when another optimizer is active.
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
			/* translators: %s: list of conflicting plugins. */
			esc_html(
				_n(
					'%s already handles this site\'s images, so MM Optimizer keeps its delivery switched off to avoid overlapping. Conversion carries on: the derivatives will be ready when you decide to switch over.',
					'%s already handle this site\'s images, so MM Optimizer keeps its delivery switched off to avoid overlapping. Conversion carries on: the derivatives will be ready when you decide to switch over.',
					count( $active ),
					'mm-optimizer'
				)
			),
			'<strong>' . esc_html( implode( ', ', $active ) ) . '</strong>'
		);

		printf(
			' <a href="%s">%s</a>',
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Go to plugins', 'mm-optimizer' )
		);

		echo '</p></div>';
	}

	/**
	 * Warns when the host cannot produce modern formats.
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
		echo esc_html__( 'No graphics library on this server can produce WebP or AVIF. Open the Diagnostics tab: it lists exactly what to ask your host for.', 'mm-optimizer' );
		printf(
			' <a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=mm-optimizer' ) ),
			esc_html__( 'Open diagnostics', 'mm-optimizer' )
		);
		echo '</p></div>';
	}
}
