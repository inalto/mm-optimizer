<?php
/**
 * Competing-plugin detection and the stand-down rules.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Conflicts {

	/**
	 * Scan result, cached per request.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Known competing plugins: basename => label.
	 *
	 * @return array
	 */
	public static function known() {
		return array(
			'ewww-image-optimizer/ewww-image-optimizer.php'             => 'EWWW Image Optimizer',
			'ewww-image-optimizer-cloud/ewww-image-optimizer-cloud.php' => 'EWWW Image Optimizer Cloud',
			'webp-converter-for-media/webp-converter-for-media.php'     => 'Converter for Media',
			'converter-for-media/converter-for-media.php'               => 'Converter for Media',
			'imagify/imagify.php'                                       => 'Imagify',
			'shortpixel-image-optimiser/wp-shortpixel.php'              => 'ShortPixel Image Optimizer',
			'shortpixel-adaptive-images/short-pixel-ai.php'             => 'ShortPixel Adaptive Images',
			'wp-smushit/wp-smush.php'                                   => 'Smush',
			'wp-smush-pro/wp-smush.php'                                 => 'Smush Pro',
			'optimole-wp/optimole-wp.php'                               => 'Optimole',
		);
	}

	/**
	 * Symbols that give away a renamed competitor.
	 *
	 * @return array
	 */
	protected static function symbols() {
		return array(
			'EWWW Image Optimizer' => array( 'constant' => 'EWWW_IMAGE_OPTIMIZER_VERSION' ),
			'Converter for Media'  => array( 'class' => 'WebpConverter\\Plugin_Data' ),
			'Imagify'              => array( 'constant' => 'IMAGIFY_VERSION' ),
			'ShortPixel'           => array( 'class' => 'ShortPixel\\Controller\\OptimizeController' ),
			'Smush'                => array( 'class' => 'WP_Smush' ),
			'Optimole'             => array( 'constant' => 'OPTML_VERSION' ),
		);
	}

	/**
	 * Markers left behind in .htaccess files.
	 *
	 * @return array
	 */
	protected static function markers() {
		return array(
			'BEGIN Converter for Media' => 'Converter for Media',
			'BEGIN EWWWIO'              => 'EWWW Image Optimizer',
			'EWWWIO_WEBP'               => 'EWWW Image Optimizer',
			'BEGIN ShortPixel'          => 'ShortPixel',
		);
	}

	/**
	 * Runs the full scan.
	 *
	 * @return array {
	 *     @type array $plugins  Labels of active plugins.
	 *     @type array $symbols  Labels detected at runtime.
	 *     @type array $htaccess Path => label.
	 * }
	 */
	public static function scan() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$result = array(
			'plugins'  => array(),
			'symbols'  => array(),
			'htaccess' => array(),
		);

		// Signal 1: active plugins. Authoritative.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( self::known() as $basename => $label ) {
			if ( is_plugin_active( $basename ) || is_plugin_active_for_network( $basename ) ) {
				$result['plugins'][ $basename ] = $label;
			}
		}

		// Signal 2: runtime symbols, for renamed folders or mu-plugin copies.
		foreach ( self::symbols() as $label => $probe ) {
			$found = false;
			if ( isset( $probe['constant'] ) && defined( $probe['constant'] ) ) {
				$found = true;
			}
			if ( isset( $probe['class'] ) && class_exists( $probe['class'], false ) ) {
				$found = true;
			}
			if ( $found ) {
				$result['symbols'][ $label ] = $label;
			}
		}

		// Signal 3: rules left in .htaccess, including by deactivated plugins.
		$result['htaccess'] = self::scan_htaccess();

		self::$cache = $result;

		return $result;
	}

	/**
	 * Looks for third-party markers in the relevant .htaccess files.
	 *
	 * @return array Path => label.
	 */
	protected static function scan_htaccess() {
		$cached = get_transient( 'mm_opt_htaccess_scan' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$found = array();
		$files = array(
			trailingslashit( ABSPATH ) . '.htaccess',
			trailingslashit( WP_CONTENT_DIR ) . '.htaccess',
			trailingslashit( Base::uploads_basedir() ) . '.htaccess',
		);

		foreach ( array_unique( $files ) as $file ) {
			if ( ! @is_readable( $file ) ) {
				continue;
			}

			$contents = (string) @file_get_contents( $file );
			if ( '' === $contents ) {
				continue;
			}

			foreach ( self::markers() as $marker => $label ) {
				if ( false !== strpos( $contents, $marker ) ) {
					$found[ $file ] = $label;
					break;
				}
			}
		}

		set_transient( 'mm_opt_htaccess_scan', $found, 15 * MINUTE_IN_SECONDS );

		return $found;
	}

	/**
	 * Labels of every competitor that is genuinely active.
	 *
	 * @return array
	 */
	public static function active() {
		$scan = self::scan();

		return array_values( array_unique( array_merge( array_values( $scan['plugins'] ), array_values( $scan['symbols'] ) ) ) );
	}

	/**
	 * True when no competitor is active.
	 *
	 * @return bool
	 */
	public static function clear_field() {
		return array() === self::active();
	}

	/**
	 * True when nobody has left rewrite rules in an .htaccess file.
	 *
	 * @return bool
	 */
	public static function htaccess_free() {
		$scan = self::scan();

		return array() === $scan['htaccess'] && self::clear_field();
	}

	/**
	 * Decides whether a subsystem must stand down.
	 *
	 * Conversion always stays on: standing down concerns delivery and, in the
	 * specific case of EWWW, in-place thumbnail optimization.
	 *
	 * @param string $subsystem 'picture', 'htaccess' or 'thumbnails'.
	 * @return bool
	 */
	public static function blocks( $subsystem ) {
		if ( defined( 'MM_OPTIMIZER_FORCE_DELIVERY' ) && MM_OPTIMIZER_FORCE_DELIVERY ) {
			$blocked = false;
		} else {
			switch ( $subsystem ) {
				case 'picture':
					// Rules left in an .htaccess do not harm <picture> output:
					// only a genuinely active plugin blocks it.
					$blocked = ! self::clear_field();
					break;

				case 'htaccess':
					$blocked = ! self::htaccess_free();
					break;

				case 'thumbnails':
					// Two optimizers rewriting the same file is a real
					// corruption risk.
					$blocked = in_array( 'EWWW Image Optimizer', self::active(), true );
					break;

				default:
					$blocked = false;
			}
		}

		/**
		 * Filters the stand-down decision for a subsystem.
		 *
		 * @param bool   $blocked   True when the subsystem must stay off.
		 * @param string $subsystem Subsystem identifier.
		 */
		return (bool) apply_filters( 'mm_optimizer_blocks', $blocked, $subsystem );
	}

	/**
	 * Clears the detection caches.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
		delete_transient( 'mm_opt_htaccess_scan' );
	}
}
