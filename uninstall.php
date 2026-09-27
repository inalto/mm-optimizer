<?php
/**
 * Disinstallazione.
 *
 * Non rimuove nulla se non esplicitamente richiesto: disattivare o cancellare
 * il plugin non deve mai distruggere il lavoro gia' fatto.
 *
 * @package MM_Optimizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$mm_optimizer_settings = get_option( 'mm_optimizer_settings', array() );

if ( empty( $mm_optimizer_settings['uninstall_delete'] ) ) {
	return;
}

global $wpdb;

// Derivate generate: si cancellano prima delle righe che le indicizzano.
$mm_optimizer_table = $wpdb->prefix . 'mm_optimizer_files';
$mm_optimizer_dir   = wp_get_upload_dir();
$mm_optimizer_base  = isset( $mm_optimizer_dir['basedir'] ) ? $mm_optimizer_dir['basedir'] : '';

if ( '' !== $mm_optimizer_base ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- disinstallazione, nessuna cache disponibile.
	$mm_optimizer_rows = $wpdb->get_col( "SELECT source_path FROM {$mm_optimizer_table}" );

	foreach ( (array) $mm_optimizer_rows as $mm_optimizer_row ) {
		$mm_optimizer_path = 0 === strpos( $mm_optimizer_row, 'UPLOADS' )
			? $mm_optimizer_base . substr( $mm_optimizer_row, 7 )
			: $mm_optimizer_row;

		foreach ( array( '.webp', '.avif' ) as $mm_optimizer_ext ) {
			$mm_optimizer_file = $mm_optimizer_path . $mm_optimizer_ext;
			if ( is_file( $mm_optimizer_file ) ) {
				wp_delete_file( $mm_optimizer_file );
			}
		}
	}

	$mm_optimizer_tmp = trailingslashit( $mm_optimizer_base ) . 'mm-optimizer-tmp';
	if ( is_dir( $mm_optimizer_tmp ) ) {
		foreach ( (array) glob( $mm_optimizer_tmp . '/*' ) as $mm_optimizer_leftover ) {
			if ( is_file( $mm_optimizer_leftover ) ) {
				wp_delete_file( $mm_optimizer_leftover );
			}
		}
		@rmdir( $mm_optimizer_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- puo' non essere vuota, non e' un errore.
	}
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- disinstallazione.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mm_optimizer_files" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mm_optimizer_queue" );
// phpcs:enable

delete_option( 'mm_optimizer_settings' );
delete_option( 'mm_optimizer_capabilities' );
delete_option( 'mm_optimizer_log' );
delete_option( 'mm_optimizer_version' );
delete_option( 'mm_optimizer_bulk_state' );

delete_transient( 'mm_opt_cap_lock' );
delete_transient( 'mm_opt_htaccess_scan' );

wp_clear_scheduled_hook( 'mm_optimizer_detect' );
wp_clear_scheduled_hook( 'mm_optimizer_run_queue' );
