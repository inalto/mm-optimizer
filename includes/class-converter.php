<?php
/**
 * Produzione delle derivate.
 *
 * Garanzia fondamentale: il file sorgente non viene mai aperto in scrittura.
 * Questa classe legge la sorgente e scrive soltanto il file della derivata.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Converter {

	/**
	 * Converte una sorgente in un formato.
	 *
	 * @param string $source        Percorso assoluto della sorgente.
	 * @param string $format        'webp' oppure 'avif'.
	 * @param int    $attachment_id Allegato di appartenenza.
	 * @param string $size_name     Nome della dimensione.
	 * @param bool   $force         Rigenera anche se gia' presente e aggiornata.
	 * @return array {
	 *     @type int    $status Stato registrato.
	 *     @type string $error  Codice di errore.
	 *     @type int    $bytes  Byte della derivata.
	 *     @type int    $saved  Byte risparmiati.
	 * }
	 */
	public static function convert( $source, $format, $attachment_id = 0, $size_name = '', $force = false ) {
		$source  = wp_normalize_path( (string) $source );
		$formats = Paths::formats();

		if ( ! isset( $formats[ $format ] ) ) {
			return self::result( Records::STATUS_ERROR, 'unknown_format' );
		}

		$target_mime = $formats[ $format ];

		if ( ! @is_file( $source ) || ! @is_readable( $source ) ) {
			return self::result( Records::STATUS_ERROR, 'source_missing' );
		}

		$source_mime = Base::sniff_mime( $source );

		if ( ! in_array( $source_mime, Paths::source_mimes(), true ) ) {
			return self::record( $source, $format, $attachment_id, $size_name, Records::STATUS_SKIPPED, 'unsupported_source' );
		}

		// Le animazioni perderebbero i fotogrammi: si saltano interamente.
		if ( 'image/gif' === $source_mime && Base::is_animated_gif( $source ) ) {
			return self::record( $source, $format, $attachment_id, $size_name, Records::STATUS_SKIPPED, 'animated' );
		}

		if ( 'image/png' === $source_mime && Base::is_animated_png( $source ) ) {
			return self::record( $source, $format, $attachment_id, $size_name, Records::STATUS_SKIPPED, 'animated' );
		}

		$source_bytes = (int) filesize( $source );

		if ( $source_bytes < Presets::skip_bytes() ) {
			return self::record( $source, $format, $attachment_id, $size_name, Records::STATUS_SKIPPED, 'too_small', 0, $source_bytes );
		}

		$size = @getimagesize( $source );

		if ( ! $size ) {
			return self::record( $source, $format, $attachment_id, $size_name, Records::STATUS_ERROR, 'unreadable', 0, $source_bytes );
		}

		list( $width, $height ) = $size;

		// WebP non supera i 16.383 pixel di lato: e' un limite del formato.
		if ( 'webp' === $format && ( $width > 16383 || $height > 16383 ) ) {
			return self::record( $source, $format, $attachment_id, $size_name, Records::STATUS_SKIPPED, 'too_wide', 0, $source_bytes );
		}

		// Stima della memoria prima di aprire: meglio saltare che far morire
		// la richiesta con un errore fatale.
		if ( ! Encoder::memory_available( $width, $height ) ) {
			return self::record( $source, $format, $attachment_id, $size_name, Records::STATUS_ERROR, 'too_large', 0, $source_bytes );
		}

		$dest = Paths::derivative( $source, $format );
		$hash = Records::hash( $source );

		if ( ! $force && self::is_fresh( $source, $dest, $format, $hash ) ) {
			return self::result( Records::STATUS_DONE, '', (int) filesize( $dest ), max( 0, $source_bytes - (int) filesize( $dest ) ) );
		}

		$quality = Presets::quality( $source_mime, $target_mime );

		wp_raise_memory_limit( 'image' );

		$encoded = Encoder::encode( $source, $dest, $target_mime, $quality );

		if ( empty( $encoded['ok'] ) ) {
			return self::record( $source, $format, $attachment_id, $size_name, Records::STATUS_ERROR, $encoded['error'], 0, $source_bytes, $encoded['engine'], $quality, $hash );
		}

		$out_bytes = (int) filesize( $dest );

		// Una derivata piu' grande della sorgente non ha ragione di esistere.
		// Capita spesso sulle icone e sui PNG gia' ottimizzati: e' un esito
		// normale, non un errore, e non va ritentato a ogni passaggio.
		$threshold = (int) ( $source_bytes * ( 100 - Presets::min_saving() ) / 100 );

		if ( $out_bytes >= $threshold ) {
			wp_delete_file( $dest );

			return self::record( $source, $format, $attachment_id, $size_name, Records::STATUS_SKIPPED, 'not_smaller', $out_bytes, $source_bytes, $encoded['engine'], $quality, $hash );
		}

		return self::record( $source, $format, $attachment_id, $size_name, Records::STATUS_DONE, '', $out_bytes, $source_bytes, $encoded['engine'], $quality, $hash );
	}

	/**
	 * Vero se la derivata esiste ed e' allineata alla sorgente.
	 *
	 * @param string $source Sorgente.
	 * @param string $dest   Derivata.
	 * @param string $format Formato.
	 * @param string $hash   Impronta corrente della sorgente.
	 * @return bool
	 */
	protected static function is_fresh( $source, $dest, $format, $hash ) {
		if ( ! @is_file( $dest ) ) {
			return false;
		}

		$row = Records::get( $source, $format );

		if ( ! $row || Records::STATUS_DONE !== (int) $row['status'] ) {
			return false;
		}

		if ( $row['source_hash'] !== $hash ) {
			return false;
		}

		// Un cambio di livello deve poter rigenerare quello che era stato
		// prodotto con l'impostazione precedente.
		$expected = Presets::quality( Base::sniff_mime( $source ), Paths::formats()[ $format ] );

		return (int) $row['quality'] === (int) $expected;
	}

	/**
	 * Converte tutte le dimensioni di un allegato.
	 *
	 * @param int   $attachment_id Identificativo.
	 * @param array $metadata      Metadati; se assenti vengono letti.
	 * @param bool  $force         Rigenera comunque.
	 * @return array Riepilogo.
	 */
	public static function convert_attachment( $attachment_id, $metadata = null, $force = false ) {
		$attachment_id = (int) $attachment_id;
		$metadata      = is_array( $metadata ) ? $metadata : wp_get_attachment_metadata( $attachment_id );

		$summary = array( 'done' => 0, 'skipped' => 0, 'error' => 0, 'saved' => 0 );

		foreach ( self::plan( $attachment_id, $metadata ) as $item ) {
			// Prima la riduzione senza perdita della miniatura: le derivate
			// vanno prodotte dalla versione gia' ripulita.
			$lossless = Thumbnails::optimize( $item['path'], $item['size'], $attachment_id );

			if ( $lossless['ok'] ) {
				$summary['saved'] += $lossless['saved'];
			}

			foreach ( self::enabled_formats() as $format ) {
				$result = self::convert( $item['path'], $format, $attachment_id, $item['size'], $force );

				switch ( $result['status'] ) {
					case Records::STATUS_DONE:
						$summary['done']++;
						$summary['saved'] += $result['saved'];
						break;
					case Records::STATUS_SKIPPED:
						$summary['skipped']++;
						break;
					default:
						$summary['error']++;
				}
			}
		}

		return $summary;
	}

	/**
	 * Elenca i file da convertire per un allegato.
	 *
	 * @param int   $attachment_id Identificativo.
	 * @param array $metadata      Metadati.
	 * @return array Elenco di array con 'path' e 'size'.
	 */
	public static function plan( $attachment_id, $metadata ) {
		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! @is_file( $file ) ) {
			return array();
		}

		$plan = array(
			array(
				'path' => wp_normalize_path( $file ),
				'size' => 'full',
			),
		);

		if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return $plan;
		}

		$dir     = trailingslashit( dirname( wp_normalize_path( $file ) ) );
		$wanted  = self::wanted_sizes( $metadata );

		foreach ( $metadata['sizes'] as $name => $data ) {
			if ( empty( $data['file'] ) ) {
				continue;
			}

			if ( null !== $wanted && ! in_array( $name, $wanted, true ) ) {
				continue;
			}

			$plan[] = array(
				'path' => $dir . $data['file'],
				'size' => (string) $name,
			);
		}

		return $plan;
	}

	/**
	 * Quali dimensioni convertire, secondo l'impostazione.
	 *
	 * @param array $metadata Metadati dell'allegato.
	 * @return array|null Null significa "tutte".
	 */
	protected static function wanted_sizes( $metadata ) {
		$mode = Options::get( 'sizes.mode', 'srcset' );

		if ( 'all' === $mode ) {
			return null;
		}

		if ( 'list' === $mode ) {
			return (array) Options::get( 'sizes.include', array() );
		}

		// Modalita' srcset: solo le dimensioni che conservano le proporzioni
		// dell'originale, perche' sono le uniche che WordPress ammette nel
		// srcset. Le dimensioni ritagliate finiscono comunque dentro se il
		// tema le usa direttamente come src, quindi si tengono quelle grandi.
		if ( empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
			return null;
		}

		$wanted = array();

		foreach ( (array) $metadata['sizes'] as $name => $data ) {
			if ( empty( $data['width'] ) || empty( $data['height'] ) ) {
				continue;
			}

			$matches = function_exists( 'wp_image_matches_ratio' )
				&& wp_image_matches_ratio( (int) $metadata['width'], (int) $metadata['height'], (int) $data['width'], (int) $data['height'] );

			if ( $matches || (int) $data['width'] >= 600 ) {
				$wanted[] = $name;
			}
		}

		return $wanted;
	}

	/**
	 * Formati abilitati e realmente producibili.
	 *
	 * @return array
	 */
	public static function enabled_formats() {
		$out = array();

		foreach ( array_keys( Paths::formats() ) as $format ) {
			if ( Options::get( $format . '.enabled' ) && '' !== Encoder::engine_for( Paths::formats()[ $format ] ) ) {
				$out[] = $format;
			}
		}

		return $out;
	}

	/**
	 * Rimuove derivate e righe di un allegato.
	 *
	 * @param int   $attachment_id Identificativo.
	 * @param array $metadata      Metadati.
	 * @return int File rimossi.
	 */
	public static function cleanup_attachment( $attachment_id, $metadata = null ) {
		$metadata = is_array( $metadata ) ? $metadata : wp_get_attachment_metadata( $attachment_id );
		$removed  = 0;

		foreach ( self::plan( $attachment_id, $metadata ) as $item ) {
			$removed += Paths::delete_derivatives( $item['path'] );
		}

		Records::delete_for_attachment( $attachment_id );

		return $removed;
	}

	/**
	 * Registra l'esito e lo restituisce.
	 *
	 * @param string $source        Sorgente.
	 * @param string $format        Formato.
	 * @param int    $attachment_id Allegato.
	 * @param string $size_name     Dimensione.
	 * @param int    $status        Stato.
	 * @param string $error         Codice di errore.
	 * @param int    $out_bytes     Byte prodotti.
	 * @param int    $source_bytes  Byte della sorgente.
	 * @param string $engine        Motore.
	 * @param int    $quality       Qualita'.
	 * @param string $hash          Impronta.
	 * @return array
	 */
	protected static function record( $source, $format, $attachment_id, $size_name, $status, $error, $out_bytes = 0, $source_bytes = 0, $engine = '', $quality = 0, $hash = '' ) {
		Records::save(
			array(
				'attachment_id' => $attachment_id,
				'size_name'     => $size_name,
				'format'        => $format,
				'source_path'   => $source,
				'source_bytes'  => $source_bytes,
				'source_hash'   => '' !== $hash ? $hash : Records::hash( $source ),
				'out_bytes'     => $out_bytes,
				'status'        => $status,
				'engine'        => $engine,
				'quality'       => $quality,
				'error_code'    => $error,
			)
		);

		$saved = ( Records::STATUS_DONE === $status ) ? max( 0, $source_bytes - $out_bytes ) : 0;

		return self::result( $status, $error, $out_bytes, $saved );
	}

	/**
	 * Costruisce un esito.
	 *
	 * @param int    $status Stato.
	 * @param string $error  Codice.
	 * @param int    $bytes  Byte prodotti.
	 * @param int    $saved  Byte risparmiati.
	 * @return array
	 */
	protected static function result( $status, $error = '', $bytes = 0, $saved = 0 ) {
		return array(
			'status' => $status,
			'error'  => $error,
			'bytes'  => $bytes,
			'saved'  => $saved,
		);
	}
}
