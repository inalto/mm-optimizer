<?php
/**
 * Costruzione di un lavoro in blocco.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Scanner {

	const OFFSET = 'mm_optimizer_scan_offset';

	/**
	 * Numero di allegati esaminati per passata.
	 *
	 * @var int
	 */
	const PAGE = 200;

	/**
	 * Aggiunge alla coda una passata di allegati.
	 *
	 * Ritorna dopo una pagina, cosi' che la scansione di una libreria da
	 * migliaia di immagini possa avanzare senza superare il tempo massimo.
	 *
	 * @param bool $restart Ricomincia da capo.
	 * @return array {
	 *     @type int  $scanned  Allegati esaminati.
	 *     @type int  $queued   Righe accodate.
	 *     @type bool $finished Vero quando non resta altro.
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
	 * Scarta i file le cui derivate sono gia' complete e aggiornate.
	 *
	 * Una sola interrogazione per allegato invece di una per file.
	 *
	 * @param array $paths Percorsi.
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
	 * Quante immagini contiene la libreria.
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
