<?php
/**
 * Plugin bootstrap.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Returns the single instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor: go through instance().
	 */
	protected function __construct() {}

	/**
	 * Registers every hook.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 20 );

		// Conversion stays on even when a competitor is active: only delivery
		// stands down.
		Attachment_Hooks::init();
		Queue::init();

		// Delivery decides for itself whether to arm, once every plugin has
		// loaded and conflict detection is trustworthy.
		add_action( 'plugins_loaded', array( 'MM_Optimizer\\Delivery\\Delivery', 'init' ), 30 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( 'MM_Optimizer\\Options', 'register' ) );
			add_action( 'update_option_' . Options::KEY, array( __CLASS__, 'on_settings_saved' ), 10, 0 );
			Admin::init();
			Admin_Ajax::init();
		}
	}

	/**
	 * When the settings are saved.
	 *
	 * @return void
	 */
	public static function on_settings_saved() {
		Options::flush();
		Conflicts::flush();
		Htaccess::sync();
	}

	/**
	 * Loads the translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'mm-optimizer', false, dirname( MM_OPTIMIZER_BASENAME ) . '/languages' );
	}

	/**
	 * Brings the schema up to date when the version changes.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		$stored = (string) get_option( 'mm_optimizer_version', '' );

		if ( MM_OPTIMIZER_VERSION === $stored ) {
			return;
		}

		self::install_tables();

		update_option( 'mm_optimizer_version', MM_OPTIMIZER_VERSION, true );

		// Capabilities recompute themselves thanks to the fingerprint, which
		// includes the plugin version.
		Capabilities::flush();
		Conflicts::flush();
	}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( Options::KEY, false ) ) {
			add_option( Options::KEY, Options::defaults(), '', true );
		}

		self::install_tables();

		update_option( 'mm_optimizer_version', MM_OPTIMIZER_VERSION, true );

		// Detection is expensive: run it just afterwards, outside the
		// activation request, so the first screen already has data.
		if ( ! wp_next_scheduled( 'mm_optimizer_detect' ) ) {
			wp_schedule_single_event( time() + 30, 'mm_optimizer_detect' );
		}

		if ( ! wp_next_scheduled( 'mm_optimizer_run_queue' ) ) {
			wp_schedule_event( time() + 300, 'mm_opt_5min', 'mm_optimizer_run_queue' );
		}
	}

	/**
	 * Deactivation: removes nothing.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'mm_optimizer_detect' );
		wp_clear_scheduled_hook( 'mm_optimizer_run_queue' );

		delete_transient( 'mm_opt_cap_lock' );
		delete_transient( 'mm_opt_htaccess_scan' );

		// Rewrite rules must not outlive deactivation.
		Htaccess::remove();
	}

	/**
	 * Creates or updates the tables.
	 *
	 * @return void
	 */
	public static function install_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$files   = $wpdb->prefix . 'mm_optimizer_files';
		$queue   = $wpdb->prefix . 'mm_optimizer_queue';

		dbDelta(
			"CREATE TABLE {$files} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				size_name varchar(64) NOT NULL DEFAULT '',
				format varchar(12) NOT NULL DEFAULT '',
				source_path varchar(255) NOT NULL DEFAULT '',
				source_bytes int(10) unsigned NOT NULL DEFAULT 0,
				source_hash char(32) NOT NULL DEFAULT '',
				out_bytes int(10) unsigned NOT NULL DEFAULT 0,
				status tinyint(4) NOT NULL DEFAULT 0,
				engine varchar(16) NOT NULL DEFAULT '',
				quality smallint(6) NOT NULL DEFAULT 0,
				error_code varchar(32) NOT NULL DEFAULT '',
				updated int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY source_format (source_path(191),format),
				KEY attachment (attachment_id,format),
				KEY status_updated (status,updated)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$queue} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_group varchar(32) NOT NULL DEFAULT '',
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				source_path varchar(255) NOT NULL DEFAULT '',
				formats varchar(32) NOT NULL DEFAULT '',
				priority tinyint(4) NOT NULL DEFAULT 10,
				attempts tinyint(4) NOT NULL DEFAULT 0,
				claimed_at int(10) unsigned NOT NULL DEFAULT 0,
				claim_token char(16) NOT NULL DEFAULT '',
				created int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY claim (claimed_at,priority,id),
				KEY grp (job_group)
			) {$charset};"
		);
	}
}
