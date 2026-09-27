<?php
/**
 * Mapping between source files and derivatives.
 *
 * The only place in the plugin that knows the naming scheme. A derivative's
 * path is never stored in the database: it is a pure function of the source
 * and the format, and storing it would only let the two drift apart.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Paths {

	/**
	 * Producible formats, in order of preference.
	 *
	 * @return array Format => MIME type.
	 */
	public static function formats() {
		return array(
			'avif' => 'image/avif',
			'webp' => 'image/webp',
		);
	}

	/**
	 * MIME types accepted as a source.
	 *
	 * @return array
	 */
	public static function source_mimes() {
		return array( 'image/jpeg', 'image/png', 'image/gif' );
	}

	/**
	 * Path of a derivative.
	 *
	 * The scheme appends the extension rather than replacing it:
	 * "photo.jpg.webp", not "photo.webp". Replacing it would make photo.jpg
	 * and photo.png collide in the same directory, and collide with a genuinely
	 * uploaded .webp.
	 *
	 * @param string $source Source path.
	 * @param string $format 'webp' or 'avif'.
	 * @return string
	 */
	public static function derivative( $source, $format ) {
		$source = wp_normalize_path( (string) $source );

		if ( 'parallel' === Options::get( 'storage.mode', 'sibling' ) ) {
			$base = wp_normalize_path( Base::uploads_basedir() );

			if ( 0 === strpos( $source, $base ) ) {
				return trailingslashit( self::parallel_dir() ) . ltrim( substr( $source, strlen( $base ) ), '/' ) . '.' . $format;
			}
		}

		return $source . '.' . $format;
	}

	/**
	 * URL of a derivative.
	 *
	 * Pure string work: in the delivery hot path a single page can ask for
	 * hundreds of these.
	 *
	 * @param string $url    Source URL.
	 * @param string $format 'webp' or 'avif'.
	 * @return string
	 */
	public static function derivative_url( $url, $format ) {
		if ( 'parallel' === Options::get( 'storage.mode', 'sibling' ) ) {
			$baseurl = Base::uploads_baseurl();

			if ( 0 === strpos( $url, $baseurl ) ) {
				return trailingslashit( content_url( 'mm-optimizer' ) ) . ltrim( substr( $url, strlen( $baseurl ) ), '/' ) . '.' . $format;
			}
		}

		return $url . '.' . $format;
	}

	/**
	 * Directory of the parallel tree.
	 *
	 * @return string
	 */
	public static function parallel_dir() {
		return trailingslashit( WP_CONTENT_DIR ) . 'mm-optimizer';
	}

	/**
	 * Every possible derivative of a source, whether it exists or not.
	 *
	 * @param string $source Source path.
	 * @return array Format => path.
	 */
	public static function all_derivatives( $source ) {
		$out = array();

		foreach ( array_keys( self::formats() ) as $format ) {
			$out[ $format ] = self::derivative( $source, $format );
		}

		return $out;
	}

	/**
	 * Converts an uploads URL into a path on disk.
	 *
	 * @param string $url URL.
	 * @return string Absolute path, or an empty string when external.
	 */
	public static function url_to_path( $url ) {
		$baseurl = Base::uploads_baseurl();
		$basedir = Base::uploads_basedir();

		if ( '' === $baseurl || '' === $basedir ) {
			return '';
		}

		// Normalise the scheme: the URL on the page may be https while the
		// uploads URL is http, or the other way round.
		$url     = preg_replace( '#^https?://#', '//', (string) $url );
		$baseurl = preg_replace( '#^https?://#', '//', $baseurl );

		// Drop the query string.
		$url = strtok( $url, '?' );

		if ( 0 !== strpos( $url, $baseurl ) ) {
			return '';
		}

		$relative = ltrim( substr( $url, strlen( $baseurl ) ), '/' );

		// No walking back up the tree.
		if ( false !== strpos( $relative, '..' ) ) {
			return '';
		}

		return trailingslashit( wp_normalize_path( $basedir ) ) . $relative;
	}

	/**
	 * Removes every derivative of a source.
	 *
	 * @param string $source Source path.
	 * @return int Number of files removed.
	 */
	public static function delete_derivatives( $source ) {
		$removed = 0;

		foreach ( self::all_derivatives( $source ) as $path ) {
			if ( @is_file( $path ) ) {
				wp_delete_file( $path );
				$removed++;
			}
		}

		return $removed;
	}
}
