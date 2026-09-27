<?php
/**
 * Import from a competing plugin.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Migrator {

	/**
	 * Output directory used by Converter for Media.
	 *
	 * @return string
	 */
	public static function webpc_dir() {
		/**
		 * Filters the Converter for Media output directory.
		 *
		 * @param string $dir Absolute path.
		 */
		return apply_filters( 'mm_optimizer_webpc_dir', trailingslashit( WP_CONTENT_DIR ) . 'uploads-webpc' );
	}

	/**
	 * Whether there is anything to import.
	 *
	 * @return bool
	 */
	public static function has_source() {
		return false !== get_option( 'webpc_settings', false ) || is_dir( self::webpc_dir() );
	}

	/**
	 * Takes stock of what the previous plugin produced.
	 *
	 * @return array {
	 *     @type int    $webp     WebP files found.
	 *     @type int    $avif     AVIF files found.
	 *     @type int    $bytes    Space occupied.
	 *     @type array  $settings Settings we could read.
	 * }
	 */
	public static function inventory() {
		$dir = self::webpc_dir();

		$out = array(
			'webp'     => 0,
			'avif'     => 0,
			'bytes'    => 0,
			'settings' => array(),
		);

		$settings = get_option( 'webpc_settings', array() );

		if ( is_array( $settings ) ) {
			$out['settings'] = array(
				'quality' => isset( $settings['quality'] ) ? (int) $settings['quality'] : 0,
				'formats' => isset( $settings['output_formats'] ) ? (array) $settings['output_formats'] : array(),
				'method'  => isset( $settings['method'] ) ? (string) $settings['method'] : '',
			);
		}

		if ( ! is_dir( $dir ) ) {
			return $out;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$extension = strtolower( $file->getExtension() );

			if ( 'webp' === $extension ) {
				$out['webp']++;
			} elseif ( 'avif' === $extension ) {
				$out['avif']++;
			} else {
				continue;
			}

			$out['bytes'] += $file->getSize();
		}

		return $out;
	}

	/**
	 * Translates the previous plugin's settings into ours.
	 *
	 * @return array Resulting options.
	 */
	public static function import_settings() {
		$settings = get_option( 'webpc_settings', array() );

		if ( ! is_array( $settings ) ) {
			return Options::all();
		}

		$partial = array();

		$formats = isset( $settings['output_formats'] ) ? (array) $settings['output_formats'] : array();

		if ( ! empty( $formats ) ) {
			$partial['webp'] = array( 'enabled' => in_array( 'webp', $formats, true ) ? 1 : 0 );
			$partial['avif'] = array( 'enabled' => in_array( 'avif', $formats, true ) ? 1 : 0 );
		}

		// The previous plugin's quality is mapped to the nearest level rather
		// than copied into "custom": the levels are calibrated against real
		// measurements, a single inherited number is not.
		if ( ! empty( $settings['quality'] ) ) {
			$quality = (int) $settings['quality'];

			if ( $quality >= 86 ) {
				$partial['level'] = 'conservative';
			} elseif ( $quality >= 78 ) {
				$partial['level'] = 'balanced';
			} else {
				$partial['level'] = 'aggressive';
			}
		}

		Log::add( __( 'Settings imported from Converter for Media.', 'mm-optimizer' ), 'info', $partial );

		return Options::apply_partial( $partial );
	}

	/**
	 * Adopts the files already produced, renaming them to our scheme.
	 *
	 * Converter for Media writes into a parallel tree that mirrors the path:
	 * uploads-webpc/uploads/2024/01/photo.jpg.webp. The naming scheme matches
	 * ours, so the files only need moving.
	 *
	 * @param int  $limit Maximum number of files per pass.
	 * @param bool $copy  Copy instead of moving.
	 * @return array {
	 *     @type int  $adopted  Files adopted.
	 *     @type int  $skipped  Files ignored.
	 *     @type bool $finished True when nothing is left.
	 * }
	 */
	public static function adopt( $limit = 200, $copy = true ) {
		$root = trailingslashit( self::webpc_dir() ) . 'uploads';

		$out = array( 'adopted' => 0, 'skipped' => 0, 'finished' => true );

		if ( ! is_dir( $root ) ) {
			return $out;
		}

		$uploads = trailingslashit( Base::uploads_basedir() );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		$seen = 0;

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$extension = strtolower( $file->getExtension() );

			if ( ! in_array( $extension, array( 'webp', 'avif' ), true ) ) {
				continue;
			}

			if ( $seen >= $limit ) {
				$out['finished'] = false;
				break;
			}

			$seen++;

			$relative = ltrim( str_replace( wp_normalize_path( $root ), '', wp_normalize_path( $file->getPathname() ) ), '/' );
			$target   = $uploads . $relative;

			// The source name is the target minus our extension.
			$source = preg_replace( '/\.' . preg_quote( $extension, '/' ) . '$/i', '', $target );

			if ( ! @is_file( $source ) ) {
				$out['skipped']++;
				continue;
			}

			if ( @is_file( $target ) ) {
				$out['skipped']++;
				continue;
			}

			$done = $copy
				? @copy( $file->getPathname(), $target )
				: @rename( $file->getPathname(), $target );

			if ( ! $done ) {
				$out['skipped']++;
				continue;
			}

			Base::inherit_permissions( $target );

			Records::save(
				array(
					'attachment_id' => 0,
					'size_name'     => '',
					'format'        => $extension,
					'source_path'   => $source,
					'source_bytes'  => (int) filesize( $source ),
					'source_hash'   => Records::hash( $source ),
					'out_bytes'     => (int) filesize( $target ),
					'status'        => Records::STATUS_DONE,
					'engine'        => 'webpc',
					'quality'       => 0,
					'error_code'    => '',
				)
			);

			$out['adopted']++;
		}

		return $out;
	}
}
