<?php
/**
 * Shared helpers: capability probing, paths, magic bytes.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Base {

	/**
	 * Per-request cache for function_exists().
	 *
	 * @var array
	 */
	protected static $fn_cache = array();

	/**
	 * Suhosin blacklist, computed once.
	 *
	 * @var array|null
	 */
	protected static $suhosin = null;

	/**
	 * Whether a PHP function is genuinely callable.
	 *
	 * function_exists() alone is not enough: disable_functions and the suhosin
	 * blacklist can neutralise a function while leaving the symbol defined.
	 *
	 * @param string $name Function name.
	 * @return bool
	 */
	public static function function_exists( $name ) {
		$name = ltrim( (string) $name, '\\' );

		if ( isset( self::$fn_cache[ $name ] ) ) {
			return self::$fn_cache[ $name ];
		}

		$available = \function_exists( '\\' . $name );

		if ( $available ) {
			$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
			if ( in_array( $name, $disabled, true ) ) {
				$available = false;
			}
		}

		if ( $available && in_array( $name, self::suhosin_blacklist(), true ) ) {
			$available = false;
		}

		self::$fn_cache[ $name ] = $available;

		return $available;
	}

	/**
	 * Suhosin blacklist, lowercased.
	 *
	 * @return array
	 */
	protected static function suhosin_blacklist() {
		if ( null !== self::$suhosin ) {
			return self::$suhosin;
		}

		self::$suhosin = array();

		if ( extension_loaded( 'suhosin' ) ) {
			$list = (string) ini_get( 'suhosin.executor.func.blacklist' );
			if ( '' !== $list ) {
				self::$suhosin = array_map(
					static function ( $item ) {
						return strtolower( trim( $item ) );
					},
					explode( ',', $list )
				);
			}
		}

		return self::$suhosin;
	}

	/**
	 * open_basedir-aware is_file().
	 *
	 * Probes /usr/bin and friends without emitting warnings on hosts where
	 * open_basedir excludes those paths.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function system_file_exists( $path ) {
		$path = (string) $path;

		if ( '' === $path ) {
			return false;
		}

		$open_basedir = trim( (string) ini_get( 'open_basedir' ) );

		if ( '' !== $open_basedir ) {
			$allowed = false;
			foreach ( explode( PATH_SEPARATOR, $open_basedir ) as $prefix ) {
				$prefix = trim( $prefix );
				if ( '' !== $prefix && 0 === strpos( $path, $prefix ) ) {
					$allowed = true;
					break;
				}
			}
			if ( ! $allowed ) {
				return false;
			}
		}

		return @is_file( $path ) && @is_executable( $path );
	}

	/**
	 * MIME type read from the magic bytes, never from the extension.
	 *
	 * @param string $path File path.
	 * @return string MIME type, or an empty string.
	 */
	public static function sniff_mime( $path ) {
		if ( ! @is_file( $path ) ) {
			return '';
		}

		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			return '';
		}

		$head = (string) fread( $handle, 32 );
		fclose( $handle );

		if ( strlen( $head ) < 12 ) {
			return '';
		}

		if ( "\xFF\xD8\xFF" === substr( $head, 0, 3 ) ) {
			return 'image/jpeg';
		}
		if ( "\x89PNG\x0D\x0A\x1A\x0A" === substr( $head, 0, 8 ) ) {
			return 'image/png';
		}
		if ( 'GIF87a' === substr( $head, 0, 6 ) || 'GIF89a' === substr( $head, 0, 6 ) ) {
			return 'image/gif';
		}
		if ( 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			return 'image/webp';
		}
		if ( 'ftyp' === substr( $head, 4, 4 ) ) {
			$brand = substr( $head, 8, 4 );
			if ( in_array( $brand, array( 'avif', 'avis' ), true ) ) {
				return 'image/avif';
			}
			if ( in_array( $brand, array( 'heic', 'heix', 'heim', 'heis' ), true ) ) {
				return 'image/heic';
			}
			if ( 'mif1' === $brand || 'msf1' === $brand ) {
				// Generic container: only the compatible-brands list tells them apart.
				return false !== strpos( substr( $head, 16, 16 ), 'avif' ) ? 'image/avif' : 'image/heif';
			}
		}

		return '';
	}

	/**
	 * Detects an animated GIF (more than one image block).
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public static function is_animated_gif( $path ) {
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			return false;
		}

		$count  = 0;
		$buffer = '';

		while ( ! feof( $handle ) && $count < 2 ) {
			$buffer = substr( $buffer, -1 ) . (string) fread( $handle, 8192 );
			$count += preg_match_all( '/\x00\x21\xF9\x04.{4}\x00[\x2C\x21]/s', $buffer );
		}

		fclose( $handle );

		return $count > 1;
	}

	/**
	 * Detects an animated PNG (acTL chunk before the first IDAT).
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public static function is_animated_png( $path ) {
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			return false;
		}

		$head = (string) fread( $handle, 4096 );
		fclose( $handle );

		$actl = strpos( $head, 'acTL' );
		$idat = strpos( $head, 'IDAT' );

		return false !== $actl && ( false === $idat || $actl < $idat );
	}

	/**
	 * Collapses an absolute path into a portable form.
	 *
	 * Moving the site or changing UPLOADS must not orphan every row in the
	 * tracking table.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	public static function relativize( $path ) {
		$path = wp_normalize_path( (string) $path );
		$base = wp_normalize_path( self::uploads_basedir() );

		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			return 'UPLOADS' . substr( $path, strlen( $base ) );
		}

		$content = wp_normalize_path( WP_CONTENT_DIR );
		if ( 0 === strpos( $path, $content ) ) {
			return 'CONTENT' . substr( $path, strlen( $content ) );
		}

		$abspath = wp_normalize_path( ABSPATH );
		if ( 0 === strpos( $path, $abspath ) ) {
			return 'ABSPATH/' . substr( $path, strlen( $abspath ) );
		}

		return $path;
	}

	/**
	 * Inverse of relativize().
	 *
	 * @param string $path Stored path.
	 * @return string
	 */
	public static function expand( $path ) {
		$path = (string) $path;

		if ( 0 === strpos( $path, 'UPLOADS' ) ) {
			return wp_normalize_path( self::uploads_basedir() ) . substr( $path, 7 );
		}
		if ( 0 === strpos( $path, 'CONTENT' ) ) {
			return wp_normalize_path( WP_CONTENT_DIR ) . substr( $path, 7 );
		}
		if ( 0 === strpos( $path, 'ABSPATH/' ) ) {
			return wp_normalize_path( ABSPATH ) . substr( $path, 8 );
		}

		return $path;
	}

	/**
	 * Uploads base directory, without the year/month subfolder.
	 *
	 * @return string
	 */
	public static function uploads_basedir() {
		$dir = wp_get_upload_dir();
		return isset( $dir['basedir'] ) ? (string) $dir['basedir'] : '';
	}

	/**
	 * Uploads base URL.
	 *
	 * @return string
	 */
	public static function uploads_baseurl() {
		$dir = wp_get_upload_dir();
		return isset( $dir['baseurl'] ) ? (string) $dir['baseurl'] : '';
	}

	/**
	 * Plugin working directory, inside uploads.
	 *
	 * Deliberately inside uploads rather than /tmp: on hosts with open_basedir
	 * set, /tmp is frequently unreachable.
	 *
	 * @return string
	 */
	public static function tmp_dir() {
		$dir = trailingslashit( self::uploads_basedir() ) . 'mm-optimizer-tmp';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return $dir;
	}

	/**
	 * Effective memory limit in bytes.
	 *
	 * @return int Zero when unlimited.
	 */
	public static function memory_limit_bytes() {
		$raw = trim( (string) ini_get( 'memory_limit' ) );

		if ( '' === $raw || '-1' === $raw ) {
			return 0;
		}

		return (int) wp_convert_hr_to_bytes( $raw );
	}

	/**
	 * Copies the parent directory's permissions onto a newly created file.
	 *
	 * @param string $file File path.
	 * @return void
	 */
	public static function inherit_permissions( $file ) {
		$stat = @stat( dirname( $file ) );

		if ( ! $stat ) {
			return;
		}

		// Same permissions as the directory, minus the executable bits.
		@chmod( $file, $stat['mode'] & 0000666 );
	}
}
