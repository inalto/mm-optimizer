<?php
/**
 * Builds a bulk job.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Scanner {

	const OFFSET = 'mm_optimizer_scan_offset';

	/**
	 * Attachments examined per pass.
	 *
	 * @var int
	 */
	const PAGE = 200;

	/**
	 * Queues one pass of attachments.
	 *
	 * Returns after a single page, so that scanning a library of thousands of
	 * images can make progress without blowing the execution time limit.
	 *
	 * @param bool $restart Start again from the beginning.
	 * @return array {
	 *     @type int  $scanned  Attachments examined.
	 *     @type int  $queued   Rows queued.
	 *     @type bool $finished True when nothing is left.
	 * }
	 */
	public static function step( $restart = false ) {
		if ( $restart ) {
			delete_option( self::OFFSET );
			Queue::clear();
		}

		$offset = (int) get_option( self::OFFSET, 0 );

		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => array( 'image/jpeg', 'image/png', 'image/gif' ),
				'posts_per_page'         => self::PAGE,
				'offset'                 => $offset,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'cache_results'          => false,
			)
		);

		$ids = (array) $query->posts;

		if ( empty( $ids ) ) {
			delete_option( self::OFFSET );

			return array( 'scanned' => 0, 'queued' => 0, 'finished' => true );
		}

		$queued = 0;

		foreach ( $ids as $id ) {
			$metadata = wp_get_attachment_metadata( $id );
			$paths    = wp_list_pluck( Converter::plan( $id, $metadata ), 'path' );

			$pending = self::pending_only( $paths );

			if ( ! empty( $pending ) ) {
				$queued += Queue::push( $pending, (int) $id, 'bulk', 10 );
			}
		}

		update_option( self::OFFSET, $offset + count( $ids ), false );

		return array(
			'scanned'  => count( $ids ),
			'queued'   => $queued,
			'finished' => count( $ids ) < self::PAGE,
		);
	}

	/**
	 * Drops files whose derivatives are already complete and up to date.
	 *
	 * One query per attachment rather than one per file.
	 *
	 * @param array $paths Paths.
	 * @return array
	 */
	protected static function pending_only( array $paths ) {
		if ( empty( $paths ) ) {
			return array();
		}

		$formats   = Converter::enabled_formats();
		$available = Records::available_for( $paths );

		$out = array();

		foreach ( $paths as $path ) {
			if ( ! @is_file( $path ) ) {
				continue;
			}

			$relative = Base::relativize( $path );
			$have     = isset( $available[ $relative ] ) ? $available[ $relative ] : array();

			foreach ( $formats as $format ) {
				if ( ! in_array( $format, $have, true ) ) {
					$out[] = $path;
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * How many images the library holds.
	 *
	 * @return int
	 */
	public static function total_attachments() {
		$counts = (array) wp_count_attachments();
		$total  = 0;

		foreach ( array( 'image/jpeg', 'image/png', 'image/gif' ) as $mime ) {
			if ( isset( $counts[ $mime ] ) ) {
				$total += (int) $counts[ $mime ];
			}
		}

		return $total;
	}
}
