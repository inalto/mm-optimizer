<?php
/**
 * Encodes a source file into a modern format.
 *
 * The engine is not chosen here: it comes from detection, which settled it by
 * measuring the bytes each one produced on the same sample.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Encoder {

	/**
	 * Encodes a source into a derivative.
	 *
	 * @param string $source      Source path.
	 * @param string $dest        Destination path.
	 * @param string $target_mime Output MIME type.
	 * @param int    $quality     Quality, 1-100.
	 * @return array {
	 *     @type bool   $ok     Result.
	 *     @type string $engine Engine used.
	 *     @type string $error  Error code.
	 * }
	 */
	public static function encode( $source, $dest, $target_mime, $quality ) {
		$engine = self::engine_for( $target_mime );

		if ( '' === $engine ) {
			return self::fail( '', 'no_engine' );
		}

		$dir = dirname( $dest );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return self::fail( $engine, 'mkdir_failed' );
		}

		if ( ! wp_is_writable( $dir ) ) {
			return self::fail( $engine, 'dir_unwritable' );
		}

		switch ( $engine ) {
			case 'gd':
				$ok = self::encode_gd( $source, $dest, $target_mime, $quality );
				break;
			case 'imagick':
				$ok = self::encode_imagick( $source, $dest, $target_mime, $quality );
				break;
			case 'cwebp':
			case 'avifenc':
				$ok = self::encode_binary( $engine, $source, $dest, $target_mime, $quality );
				break;
			default:
				return self::fail( $engine, 'unknown_engine' );
		}

		if ( ! $ok || ! @is_file( $dest ) ) {
			self::cleanup( $dest );
			return self::fail( $engine, 'encode_failed' );
		}

		// An engine can write a file in the wrong format without reporting an
		// error: check the magic bytes before trusting it.
		if ( Base::sniff_mime( $dest ) !== $target_mime ) {
			self::cleanup( $dest );
			return self::fail( $engine, 'wrong_format' );
		}

		Base::inherit_permissions( $dest );

		return array( 'ok' => true, 'engine' => $engine, 'error' => '' );
	}

	/**
	 * Engine resolved for an output format.
	 *
	 * @param string $target_mime MIME type.
	 * @return string
	 */
	public static function engine_for( $target_mime ) {
		$format = 'image/avif' === $target_mime ? 'avif' : 'webp';
		$forced = Options::get( $format . '.engine', 'auto' );

		if ( 'auto' !== $forced ) {
			return $forced;
		}

		$caps = Capabilities::get();

		$engine = isset( $caps['encoders'][ $target_mime ]['engine'] ) ? $caps['encoders'][ $target_mime ]['engine'] : '';

		/**
		 * Filters the engine used for an output format.
		 *
		 * @param string $engine      Engine identifier.
		 * @param string $target_mime Output MIME type.
		 */
		return (string) apply_filters( 'mm_optimizer_encoder_for', $engine, $target_mime );
	}

	/**
	 * Encodes with GD.
	 *
	 * @param string $source      Source.
	 * @param string $dest        Destination.
	 * @param string $target_mime MIME type.
	 * @param int    $quality     Quality.
	 * @return bool
	 */
	protected static function encode_gd( $source, $dest, $target_mime, $quality ) {
		$image = self::load_gd( $source );

		if ( ! $image ) {
			return false;
		}

		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		if ( 'image/avif' === $target_mime ) {
			$ok = @imageavif( $image, $dest, $quality, Presets::avif_speed() );
		} else {
			$ok = @imagewebp( $image, $dest, $quality );
		}

		imagedestroy( $image );

		return (bool) $ok;
	}

	/**
	 * Loads a source into GD according to its real type.
	 *
	 * @param string $source Path.
	 * @return resource|\GdImage|false
	 */
	protected static function load_gd( $source ) {
		switch ( Base::sniff_mime( $source ) ) {
			case 'image/jpeg':
				return @imagecreatefromjpeg( $source );

			case 'image/png':
				$image = @imagecreatefrompng( $source );
				// A palette PNG has to be promoted to true colour, otherwise
				// WebP and AVIF lose the transparency.
				if ( $image && ! imageistruecolor( $image ) ) {
					imagepalettetotruecolor( $image );
				}
				return $image;

			case 'image/gif':
				return @imagecreatefromgif( $source );

			case 'image/webp':
				return @imagecreatefromwebp( $source );
		}

		return false;
	}

	/**
	 * Encodes with Imagick.
	 *
	 * @param string $source      Source.
	 * @param string $dest        Destination.
	 * @param string $target_mime MIME type.
	 * @param int    $quality     Quality.
	 * @return bool
	 */
	protected static function encode_imagick( $source, $dest, $target_mime, $quality ) {
		try {
			$image = new \Imagick( $source );

			$image->setImageFormat( 'image/avif' === $target_mime ? 'AVIF' : 'WEBP' );

			if ( 'image/avif' === $target_mime ) {
				$image->setOption( 'heic:speed', (string) Presets::avif_speed() );
			} else {
				$image->setOption( 'webp:method', (string) min( 6, Presets::cwebp_method() ) );
			}

			$image->setImageCompressionQuality( $quality );
			$image->stripImage();

			$ok = $image->writeImage( $dest );

			$image->clear();
			$image->destroy();

			return (bool) $ok;
		} catch ( \Throwable $e ) {
			Log::add(
				sprintf(
					/* translators: %s: error message from the library. */
					__( 'Imagick threw an exception while encoding: %s', 'mm-optimizer' ),
					$e->getMessage()
				),
				'error'
			);

			return false;
		}
	}

	/**
	 * Encodes with a system binary.
	 *
	 * @param string $engine      'cwebp' or 'avifenc'.
	 * @param string $source      Source.
	 * @param string $dest        Destination.
	 * @param string $target_mime MIME type.
	 * @param int    $quality     Quality.
	 * @return bool
	 */
	protected static function encode_binary( $engine, $source, $dest, $target_mime, $quality ) {
		$caps = Capabilities::get();
		$path = isset( $caps['binaries'][ $engine ]['path'] ) ? $caps['binaries'][ $engine ]['path'] : '';

		if ( '' === $path ) {
			return false;
		}

		if ( 'cwebp' === $engine ) {
			$argv = array(
				$path,
				'-quiet',
				'-q',
				(string) $quality,
				'-m',
				(string) Presets::cwebp_method(),
				'-metadata',
				'none',
				$source,
				'-o',
				$dest,
			);
		} else {
			$argv = array(
				$path,
				'-q',
				(string) $quality,
				'-s',
				(string) Presets::avif_speed(),
				$source,
				$dest,
			);
		}

		Capabilities::run_command( $argv, 120 );

		return @is_file( $dest );
	}

	/**
	 * Estimates the bytes GD needs to open an image.
	 *
	 * GD holds uncompressed pixels in memory: four bytes per pixel plus room
	 * for a working copy.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return int
	 */
	public static function estimate_memory( $width, $height ) {
		return (int) ( $width * $height * 4 * 1.6 );
	}

	/**
	 * Checks that there is enough memory.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return bool
	 */
	public static function memory_available( $width, $height ) {
		$limit = Base::memory_limit_bytes();

		if ( 0 === $limit ) {
			return true;
		}

		$needed = self::estimate_memory( $width, $height );
		$free   = $limit - memory_get_usage( true );

		return $needed < ( $free * 0.8 );
	}

	/**
	 * Removes a partial destination file.
	 *
	 * @param string $dest Path.
	 * @return void
	 */
	protected static function cleanup( $dest ) {
		if ( @is_file( $dest ) ) {
			wp_delete_file( $dest );
		}
	}

	/**
	 * Failure result.
	 *
	 * @param string $engine Engine.
	 * @param string $error  Code.
	 * @return array
	 */
	protected static function fail( $engine, $error ) {
		return array( 'ok' => false, 'engine' => $engine, 'error' => $error );
	}
}
