<?php
/**
 * Access to the tracking table.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Records {

	const STATUS_PENDING = 0;
	const STATUS_DONE    = 1;
	const STATUS_SKIPPED = 2;
	const STATUS_ERROR   = 3;

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'mm_optimizer_files';
	}

	/**
	 * Freshness fingerprint of a source.
	 *
	 * Size and modification time, not content: hashing 1.3 GB of images is not
	 * acceptable, one stat() call is.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function hash( $path ) {
		$size  = @filesize( $path );
		$mtime = @filemtime( $path );

		return md5( (string) $size . '|' . (string) $mtime );
	}

	/**
	 * Inserts or updates a row.
	 *
	 * @param array $row Row data.
	 * @return bool
	 */
	public static function save( array $row ) {
		global $wpdb;

		$data = array(
			'attachment_id' => isset( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0,
			'size_name'     => isset( $row['size_name'] ) ? substr( (string) $row['size_name'], 0, 64 ) : '',
			'format'        => isset( $row['format'] ) ? substr( (string) $row['format'], 0, 12 ) : '',
			'source_path'   => Base::relativize( $row['source_path'] ),
			'source_bytes'  => isset( $row['source_bytes'] ) ? (int) $row['source_bytes'] : 0,
			'source_hash'   => isset( $row['source_hash'] ) ? (string) $row['source_hash'] : '',
			'out_bytes'     => isset( $row['out_bytes'] ) ? (int) $row['out_bytes'] : 0,
			'status'        => isset( $row['status'] ) ? (int) $row['status'] : self::STATUS_PENDING,
			'engine'        => isset( $row['engine'] ) ? substr( (string) $row['engine'], 0, 16 ) : '',
			'quality'       => isset( $row['quality'] ) ? (int) $row['quality'] : 0,
			'error_code'    => isset( $row['error_code'] ) ? substr( (string) $row['error_code'], 0, 32 ) : '',
			'updated'       => time(),
		);

		$formats = array( '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%d' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- own table, no WordPress API available.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE source_path = %s AND format = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
				$data['source_path'],
				$data['format']
			)
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return false !== $wpdb->update( self::table(), $data, array( 'id' => (int) $existing ), $formats, array( '%d' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->insert( self::table(), $data, $formats );
	}

	/**
	 * Reads one row.
	 *
	 * @param string $source_path Source path.
	 * @param string $format      Format.
	 * @return array|null
	 */
	public static function get( $source_path, $format ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE source_path = %s AND format = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Base::relativize( $source_path ),
				$format
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Bulk-reads the successful derivatives for a set of sources.
	 *
	 * One query: in the delivery path a page can ask for hundreds of these.
	 *
	 * @param array $paths Absolute paths.
	 * @return array Relativized path => array of available formats.
	 */
	public static function available_for( array $paths ) {
		global $wpdb;

		if ( empty( $paths ) ) {
			return array();
		}

		$relative = array_map( array( 'MM_Optimizer\\Base', 'relativize' ), $paths );
		$relative = array_values( array_unique( $relative ) );

		$placeholders = implode( ',', array_fill( 0, count( $relative ), '%s' ) );

		$sql = $wpdb->prepare(
			'SELECT source_path, format FROM ' . self::table() . " WHERE status = %d AND source_path IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( array( self::STATUS_DONE ), $relative )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ $row['source_path']][] = $row['format'];
		}

		return $out;
	}

	/**
	 * Rows belonging to an attachment.
	 *
	 * @param int $attachment_id Identifier.
	 * @return array
	 */
	public static function for_attachment( $attachment_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE attachment_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $attachment_id
			),
			ARRAY_A
		);
	}

	/**
	 * Deletes the rows for a source.
	 *
	 * @param string $source_path Path.
	 * @return int Rows removed.
	 */
	public static function delete_for_path( $source_path ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->delete( self::table(), array( 'source_path' => Base::relativize( $source_path ) ), array( '%s' ) );
	}

	/**
	 * Deletes the rows for an attachment.
	 *
	 * @param int $attachment_id Identifier.
	 * @return int Rows removed.
	 */
	public static function delete_for_attachment( $attachment_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->delete( self::table(), array( 'attachment_id' => (int) $attachment_id ), array( '%d' ) );
	}

	/**
	 * Summary for the dashboard.
	 *
	 * @return array
	 */
	public static function totals() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			'SELECT status, COUNT(*) AS n, SUM(source_bytes) AS src, SUM(out_bytes) AS out FROM ' . self::table() . ' GROUP BY status', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$out = array(
			'done'    => 0,
			'skipped' => 0,
			'error'   => 0,
			'pending' => 0,
			'saved'   => 0,
		);

		$map = array(
			self::STATUS_DONE    => 'done',
			self::STATUS_SKIPPED => 'skipped',
			self::STATUS_ERROR   => 'error',
			self::STATUS_PENDING => 'pending',
		);

		foreach ( $rows as $row ) {
			$key = isset( $map[ (int) $row['status'] ] ) ? $map[ (int) $row['status'] ] : 'pending';

			$out[ $key ] = (int) $row['n'];

			if ( self::STATUS_DONE === (int) $row['status'] ) {
				$out['saved'] = max( 0, (int) $row['src'] - (int) $row['out'] );
			}
		}

		return $out;
	}
}
