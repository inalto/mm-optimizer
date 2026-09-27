<?php
/**
 * Blocco di regole nel file .htaccess.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Htaccess {

	const MARKER = 'MM Optimizer';

	/**
	 * File su cui si interviene.
	 *
	 * @return string
	 */
	public static function file() {
		return trailingslashit( Base::uploads_basedir() ) . '.htaccess';
	}

	/**
	 * Allinea il blocco alle impostazioni correnti.
	 *
	 * @return bool
	 */
	public static function sync() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$file = self::file();

		// Mai scrivere dove un altro plugin ha gia' messo le sue regole.
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
	 * Rimuove il blocco.
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
	 * Costruisce le regole.
	 *
	 * @return array
	 */
	public static function rules() {
		$rules = array();
		$mode  = Options::get( 'delivery.mode', 'picture' );

		// I tipi MIME servono anche in modalita' <picture>: molti server
		// consegnano .avif come application/octet-stream, e in quel caso
		// Firefox e Safari scelgono la sorgente AVIF e poi non la decodificano,
		// senza ripiegare sull'immagine originale.
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
	 * Vero se il file e' scrivibile, o lo sarebbe.
	 *
	 * @param string $file Percorso.
	 * @return bool
	 */
	public static function writable( $file ) {
		if ( @is_file( $file ) ) {
			return wp_is_writable( $file );
		}

		return wp_is_writable( dirname( $file ) );
	}
}
