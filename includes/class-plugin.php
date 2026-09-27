<?php
/**
 * Avvio del plugin.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * Istanza unica.
	 *
	 * @var Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Restituisce l'istanza unica.
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
	 * Costruttore privato: si passa da instance().
	 */
	protected function __construct() {}

	/**
	 * Registra tutti gli agganci.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 20 );

		// La conversione resta attiva anche in presenza di un concorrente:
		// si ritira soltanto la consegna.
		Attachment_Hooks::init();
		Queue::init();

		// La consegna decide da sola se accendersi, dopo che i plugin sono
		// tutti caricati e il rilevamento dei conflitti e' attendibile.
		add_action( 'plugins_loaded', array( 'MM_Optimizer\\Delivery\\Delivery', 'init' ), 30 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( 'MM_Optimizer\\Options', 'register' ) );
			add_action( 'update_option_' . Options::KEY, array( __CLASS__, 'on_settings_saved' ), 10, 0 );
			Admin::init();
			Admin_Ajax::init();
		}
	}

	/**
	 * Al salvataggio delle impostazioni.
	 *
	 * @return void
	 */
	public static function on_settings_saved() {
		Options::flush();
		Conflicts::flush();
		Htaccess::sync();
	}

	/**
	 * Carica le traduzioni.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'mm-optimizer', false, dirname( MM_OPTIMIZER_BASENAME ) . '/languages' );
	}

	/**
	 * Allinea lo schema quando la versione cambia.
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

		// Le capacita' si ricalcolano da sole grazie all'impronta, che
		// include la versione del plugin.
		Capabilities::flush();
		Conflicts::flush();
	}

	/**
	 * Attivazione.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( Options::KEY, false ) ) {
			add_option( Options::KEY, Options::defaults(), '', true );
		}

		self::install_tables();

		update_option( 'mm_optimizer_version', MM_OPTIMIZER_VERSION, true );

		// Il rilevamento e' costoso: si fa subito dopo, fuori dalla richiesta
		// di attivazione, cosi' la prima schermata trova gia' i dati pronti.
		if ( ! wp_next_scheduled( 'mm_optimizer_detect' ) ) {
			wp_schedule_single_event( time() + 30, 'mm_optimizer_detect' );
		}

		if ( ! wp_next_scheduled( 'mm_optimizer_run_queue' ) ) {
			wp_schedule_event( time() + 300, 'mm_opt_5min', 'mm_optimizer_run_queue' );
		}
	}

	/**
	 * Disattivazione: non rimuove nulla.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'mm_optimizer_detect' );
		wp_clear_scheduled_hook( 'mm_optimizer_run_queue' );

		delete_transient( 'mm_opt_cap_lock' );
		delete_transient( 'mm_opt_htaccess_scan' );

		// Le regole nel .htaccess non devono sopravvivere alla disattivazione.
		Htaccess::remove();
	}

	/**
	 * Crea o aggiorna le tabelle.
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
