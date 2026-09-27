<?php
/**
 * Derivative production.
 *
 * Core guarantee: the source file is never opened for writing. This class
 * reads the source and writes only the derivative.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Converter {

	/**
	 * Converts a source into one format.
	 *
	 * @param string $source        Absolute source path.
	 * @param string $format        'webp' or 'avif'.
	 * @param int    $attachment_id Owning attachment.
	 * @param string $size_name     Size name.
	 * @param bool   $force         Regenerate even when present and current.
	 * @return array {
	 *     @type int    $status Recorded status.
	 *     @type string $error  Error code.
	 *     @type int    $bytes  Derivative bytes.
	 *     @type int    $saved  Bytes saved.
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

		// Animations would lose their frames: skip them entirely.
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

		// WebP cannot exceed 16,383 pixels per side: that is a format limit.
		if ( 'webp' === $format && ( $width > 16383 || $height > 16383 ) ) {
			return self::record( $source, $format, $attachment_id, $size_name, Records::STATUS_SKIPPED, 'too_wide', 0, $source_bytes );
		}

		// Estimate the memory before opening: better to skip than to kill the
		// request with a fatal error.
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

		// A derivative larger than its source has no reason to exist. This
		// happens often with icons and already-optimized PNGs: it is a normal
		// outcome, not an error, and must not be retried on every pass.
		$threshold = (int) ( $source_bytes * ( 100 - Presets::min_saving() ) / 100 );

		if ( $out_bytes >= $threshold ) {
			wp_delete_file( $dest );

			return self::record( $source, $format, $attachment_id, $size_name, Records::STATUS_SKIPPED, 'not_smaller', $out_bytes, $source_bytes, $encoded['engine'], $quality, $hash );
		}

		return self::record( $source, $format, $attachment_id, $size_name, Records::STATUS_DONE, '', $out_bytes, $source_bytes, $encoded['engine'], $quality, $hash );
	}

	/**
	 * True when the derivative exists and matches the source.
	 *
	 * @param string $source Source.
	 * @param string $dest   Derivative.
	 * @param string $format Format.
	 * @param string $hash   Current fingerprint of the source.
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

		// Changing the level has to be able to regenerate what was produced
		// under the previous setting.
		$expected = Presets::quality( Base::sniff_mime( $source ), Paths::formats()[ $format ] );

		return (int) $row['quality'] === (int) $expected;
	}

	/**
	 * Converts every size of an attachment.
	 *
	 * @param int   $attachment_id Identifier.
	 * @param array $metadata      Metadata; read when absent.
	 * @param bool  $force         Regenerate regardless.
	 * @return array Summary.
	 */
	public static function convert_attachment( $attachment_id, $metadata = null, $force = false ) {
		$attachment_id = (int) $attachment_id;
		$metadata      = is_array( $metadata ) ? $metadata : wp_get_attachment_metadata( $attachment_id );

		$summary = array( 'done' => 0, 'skipped' => 0, 'error' => 0, 'saved' => 0 );

		foreach ( self::plan( $attachment_id, $metadata ) as $item ) {
			// Lossless thumbnail reduction first: the derivatives should be
			// produced from the already-cleaned version.
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
	 * Lists the files to convert for an attachment.
	 *
	 * @param int   $attachment_id Identifier.
	 * @param array $metadata      Metadata.
	 * @return array List of arrays with 'path' and 'size'.
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
	 * Which sizes to convert, according to the setting.
	 *
	 * @param array $metadata Attachment metadata.
	 * @return array|null Null means "all of them".
	 */
	protected static function wanted_sizes( $metadata ) {
		$mode = Options::get( 'sizes.mode', 'srcset' );

		if ( 'all' === $mode ) {
			return null;
		}

		if ( 'list' === $mode ) {
			return (array) Options::get( 'sizes.include', array() );
		}

		// srcset mode: only the sizes that preserve the original's aspect
		// ratio, since those are the only ones WordPress admits into a srcset.
		// Cropped sizes still get in when the theme uses them directly as a
		// src, so the large ones are kept.
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
	 * Formats that are enabled and genuinely producible.
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
	 * Removes an attachment's derivatives and rows.
	 *
	 * @param int   $attachment_id Identifier.
	 * @param array $metadata      Metadata.
	 * @return int Files removed.
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
	 * Records the outcome and returns it.
	 *
	 * @param string $source        Source.
	 * @param string $format        Format.
	 * @param int    $attachment_id Attachment.
	 * @param string $size_name     Size.
	 * @param int    $status        Status.
	 * @param string $error         Error code.
	 * @param int    $out_bytes     Bytes produced.
	 * @param int    $source_bytes  Source bytes.
	 * @param string $engine        Engine.
	 * @param int    $quality       Quality.
	 * @param string $hash          Fingerprint.
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
	 * Builds a result.
	 *
	 * @param int    $status Status.
	 * @param string $error  Code.
	 * @param int    $bytes  Bytes produced.
	 * @param int    $saved  Bytes saved.
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
