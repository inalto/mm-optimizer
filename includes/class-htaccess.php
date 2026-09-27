<?php
/**
 * Rule block in the .htaccess file.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Htaccess {

	const MARKER = 'MM Optimizer';

	/**
	 * File we operate on.
	 *
	 * @return string
	 */
	public static function file() {
		return trailingslashit( Base::uploads_basedir() ) . '.htaccess';
	}

	/**
	 * Brings the block in line with the current settings.
	 *
	 * @return bool
	 */
	public static function sync() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$file = self::file();

		// Never write where another plugin has already placed its rules.
		if ( Conflicts::blocks( 'htaccess' ) ) {
			return self::remove();
		}

		$rules = self::rules();

		if ( empty( $rules ) ) {
			return self::remove();
		}

		if ( ! self::writable( $file ) ) {
			return false;
		}

		return (bool) insert_with_markers( $file, self::MARKER, $rules );
	}

	/**
	 * Removes the block.
	 *
	 * @return bool
	 */
	public static function remove() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$file = self::file();

		if ( ! @is_file( $file ) || ! self::writable( $file ) ) {
			return false;
		}

		return (bool) insert_with_markers( $file, self::MARKER, array() );
	}

	/**
	 * Builds the rules.
	 *
	 * @return array
	 */
	public static function rules() {
		$rules = array();
		$mode  = Options::get( 'delivery.mode', 'picture' );

		// The MIME types are needed in <picture> mode too: many servers hand
		// back .avif as application/octet-stream, and when that happens Firefox
		// and Safari pick the AVIF source and then fail to decode it, with no
		// fallback to the original image.
		if ( Options::get( 'delivery.addtype' ) ) {
			$rules[] = '<IfModule mod_mime.c>';
			$rules[] = '  AddType image/avif .avif';
			$rules[] = '  AddType image/webp .webp';
			$rules[] = '</IfModule>';
		}

		if ( 'htaccess' !== $mode ) {
			return $rules;
		}

		$rules[] = '<IfModule mod_rewrite.c>';
		$rules[] = '  RewriteEngine On';

		foreach ( array( 'avif', 'webp' ) as $format ) {
			if ( ! Options::get( $format . '.enabled' ) || ! Options::get( $format . '.deliver' ) ) {
				continue;
			}

			$rules[] = '';
			$rules[] = '  # ' . strtoupper( $format );
			$rules[] = '  RewriteCond %{HTTP_ACCEPT} image/' . $format;
			$rules[] = '  RewriteCond %{QUERY_STRING} !mm-original';
			$rules[] = '  RewriteCond %{REQUEST_FILENAME}.' . $format . ' -f';
			$rules[] = '  RewriteRule ^(.+)\.(jpe?g|png|gif)$ %{REQUEST_URI}.' . $format . ' [T=image/' . $format . ',L]';
		}

		$rules[] = '</IfModule>';
		$rules[] = '';
		$rules[] = '<IfModule mod_headers.c>';
		$rules[] = '  <FilesMatch "\.(jpe?g|png|gif)$">';
		$rules[] = '    Header append Vary Accept';
		$rules[] = '  </FilesMatch>';
		$rules[] = '</IfModule>';

		return $rules;
	}

	/**
	 * Whether the file is writable, or would be.
	 *
	 * @param string $file Path.
	 * @return bool
	 */
	public static function writable( $file ) {
		if ( @is_file( $file ) ) {
			return wp_is_writable( $file );
		}

		return wp_is_writable( dirname( $file ) );
	}
}
