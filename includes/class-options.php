<?php
/**
 * Plugin settings.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Options {

	const KEY = 'mm_optimizer_settings';

	/**
	 * Per-request cache.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Complete default values.
	 *
	 * Strings here are deliberately untranslated: defaults are read before the
	 * text domain is loaded.
	 */
	public static function defaults() {
		return array(
			'level'            => 'balanced',
			'custom'           => array(
				'webp_jpeg'   => 82,
				'webp_png'    => 85,
				'avif_jpeg'   => 58,
				'avif_png'    => 65,
				'avif_speed'  => 6,
				'cwebp_m'     => 6,
				'min_saving'  => 8,
				'skip_bytes'  => 10240,
			),
			'webp'             => array(
				'enabled' => 1,
				'engine'  => 'auto',
				'deliver' => 1,
			),
			'avif'             => array(
				'enabled' => 1,
				'engine'  => 'auto',
				'deliver' => 1,
			),
			'sizes'            => array(
				'mode'    => 'srcset',
				'include' => array(),
				'exclude' => array(),
			),
			'optimize'         => array(
				'jpeg' => 0,
				'png'  => 0,
				'gif'  => 0,
			),
			'delivery'         => array(
				'mode'            => 'picture',
				'buffer'          => 0,
				'verify_disk'     => 0,
				'partial_srcset'  => 0,
				'lazy_mode'       => 'skip',
				'exclude_classes' => array(),
				'addtype'         => 1,
			),
			'bulk'             => array(
				'driver'     => 'auto',
				'batch'      => 5,
				'time_share' => 60,
			),
			'storage'          => array(
				'mode' => 'sibling',
			),
			'log_enabled'      => 1,
			'log_max'          => 200,
			'uninstall_delete' => 0,
			'db_version'       => 0,
			'version'          => MM_OPTIMIZER_VERSION,
		);
	}

	/**
	 * All settings, merged with the defaults.
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		self::$cache = self::merge( self::defaults(), $stored );

		return self::$cache;
	}

	/**
	 * Recursive merge, one level deep.
	 */
	protected static function merge( $defaults, $stored ) {
		$out = $defaults;

		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				continue;
			}
			if ( is_array( $default ) && is_array( $stored[ $key ] ) ) {
				$out[ $key ] = array_merge( $default, $stored[ $key ] );
			} else {
				$out[ $key ] = $stored[ $key ];
			}
		}

		return $out;
	}

	/**
	 * Reads a setting using dot notation: "avif.quality".
	 *
	 * @param string $path    Path.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get( $path, $default = null ) {
		$options = self::all();
		$parts   = explode( '.', $path );
		$value   = $options;

		foreach ( $parts as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return $default;
			}
			$value = $value[ $part ];
		}

		return $value;
	}

	/**
	 * Saves the settings (already sanitized).
	 */
	public static function update( array $options ) {
		self::$cache = null;
		return update_option( self::KEY, $options );
	}

	/**
	 * Applies a partial set of values, going through sanitization.
	 *
	 * Used by Recommender: a proposal cannot bypass validation.
	 *
	 * @param array $partial Values to override.
	 * @return array Resulting settings.
	 */
	public static function apply_partial( array $partial ) {
		$merged = self::merge( self::all(), $partial );
		$clean  = self::sanitize( $merged );

		self::update( $clean );

		return $clean;
	}

	/**
	 * Clears the per-request cache.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Setting registration.
	 */
	public static function register() {
		register_setting(
			self::KEY,
			self::KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Full sanitization: strict whitelist, unknown keys discarded.
	 *
	 * @param mixed $input Incoming data.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = self::all();
		$out     = $current;

		if ( ! is_array( $input ) ) {
			return $out;
		}

		$out['level'] = self::whitelist( $input, 'level', array_keys( Presets::all() ), 'balanced' );

		if ( isset( $input['custom'] ) && is_array( $input['custom'] ) ) {
			$c                           = $input['custom'];
			$out['custom']['webp_jpeg']  = self::clamp( $c, 'webp_jpeg', 1, 100, 82 );
			$out['custom']['webp_png']   = self::clamp( $c, 'webp_png', 1, 100, 85 );
			$out['custom']['avif_jpeg']  = self::clamp( $c, 'avif_jpeg', 1, 100, 58 );
			$out['custom']['avif_png']   = self::clamp( $c, 'avif_png', 1, 100, 65 );
			$out['custom']['avif_speed'] = self::clamp( $c, 'avif_speed', 0, 10, 6 );
			$out['custom']['cwebp_m']    = self::clamp( $c, 'cwebp_m', 0, 6, 6 );
			$out['custom']['min_saving'] = self::clamp( $c, 'min_saving', 0, 50, 8 );
			$out['custom']['skip_bytes'] = self::clamp( $c, 'skip_bytes', 0, 10485760, 10240 );
		}

		foreach ( array( 'webp', 'avif' ) as $format ) {
			if ( isset( $input[ $format ] ) && is_array( $input[ $format ] ) ) {
				$f                            = $input[ $format ];
				$out[ $format ]['enabled']    = self::boolean( $f, 'enabled' );
				$out[ $format ]['deliver']    = self::boolean( $f, 'deliver' );
				$out[ $format ]['engine']     = self::whitelist(
					$f,
					'engine',
					array( 'auto', 'gd', 'imagick', 'gmagick', 'cwebp', 'avifenc' ),
					'auto'
				);
			}
		}

		if ( isset( $input['sizes'] ) && is_array( $input['sizes'] ) ) {
			$s                      = $input['sizes'];
			$out['sizes']['mode']    = self::whitelist( $s, 'mode', array( 'srcset', 'all', 'list' ), 'srcset' );
			$out['sizes']['include'] = self::size_list( $s, 'include' );
			$out['sizes']['exclude'] = self::size_list( $s, 'exclude' );
		}

		if ( isset( $input['optimize'] ) && is_array( $input['optimize'] ) ) {
			foreach ( array( 'jpeg', 'png', 'gif' ) as $mime ) {
				$out['optimize'][ $mime ] = self::boolean( $input['optimize'], $mime );
			}
		}

		if ( isset( $input['delivery'] ) && is_array( $input['delivery'] ) ) {
			$d                                   = $input['delivery'];
			$out['delivery']['mode']             = self::whitelist( $d, 'mode', array( 'picture', 'htaccess', 'off' ), 'picture' );
			$out['delivery']['buffer']           = self::boolean( $d, 'buffer' );
			$out['delivery']['verify_disk']      = self::boolean( $d, 'verify_disk' );
			$out['delivery']['partial_srcset']   = self::boolean( $d, 'partial_srcset' );
			$out['delivery']['addtype']          = self::boolean( $d, 'addtype' );
			$out['delivery']['lazy_mode']        = self::whitelist( $d, 'lazy_mode', array( 'skip', 'mirror' ), 'skip' );
			$out['delivery']['exclude_classes']  = self::class_list( $d, 'exclude_classes' );

			if ( 'htaccess' === $out['delivery']['mode'] && ! Conflicts::htaccess_free() ) {
				$out['delivery']['mode'] = 'picture';
				add_settings_error(
					self::KEY,
					'mm_opt_htaccess',
					__( '.htaccess mode is not available: another plugin has already written image rewrite rules. Switched to &lt;picture&gt; mode.', 'mm-optimizer' )
				);
			}
		}

		if ( isset( $input['bulk'] ) && is_array( $input['bulk'] ) ) {
			$b                          = $input['bulk'];
			$out['bulk']['driver']      = self::whitelist( $b, 'driver', array( 'auto', 'loopback', 'as', 'cron', 'manual' ), 'auto' );
			$out['bulk']['batch']       = self::clamp( $b, 'batch', 1, 100, 5 );
			$out['bulk']['time_share']  = self::clamp( $b, 'time_share', 10, 90, 60 );
		}

		if ( isset( $input['storage'] ) && is_array( $input['storage'] ) ) {
			$out['storage']['mode'] = self::whitelist( $input['storage'], 'mode', array( 'sibling', 'parallel' ), 'sibling' );
		}

		$out['log_enabled']      = self::boolean( $input, 'log_enabled' );
		$out['log_max']          = self::clamp( $input, 'log_max', 20, 2000, 200 );
		$out['uninstall_delete'] = self::boolean( $input, 'uninstall_delete' );
		$out['version']          = MM_OPTIMIZER_VERSION;

		return $out;
	}

	protected static function whitelist( $arr, $key, $allowed, $fallback ) {
		if ( isset( $arr[ $key ] ) && in_array( $arr[ $key ], $allowed, true ) ) {
			return $arr[ $key ];
		}
		return $fallback;
	}

	protected static function clamp( $arr, $key, $min, $max, $fallback ) {
		if ( ! isset( $arr[ $key ] ) || '' === $arr[ $key ] ) {
			return $fallback;
		}
		return max( $min, min( $max, (int) $arr[ $key ] ) );
	}

	protected static function boolean( $arr, $key ) {
		return ( isset( $arr[ $key ] ) && $arr[ $key ] ) ? 1 : 0;
	}

	protected static function size_list( $arr, $key ) {
		if ( ! isset( $arr[ $key ] ) ) {
			return array();
		}

		$raw = is_array( $arr[ $key ] ) ? $arr[ $key ] : preg_split( '/[\s,]+/', (string) $arr[ $key ] );

		return array_values( array_filter( array_map( 'sanitize_key', (array) $raw ) ) );
	}

	protected static function class_list( $arr, $key ) {
		if ( ! isset( $arr[ $key ] ) ) {
			return array();
		}

		$raw = is_array( $arr[ $key ] ) ? $arr[ $key ] : preg_split( '/[\s,]+/', (string) $arr[ $key ] );

		$out = array();
		foreach ( (array) $raw as $item ) {
			$item = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}

		return array_values( array_unique( $out ) );
	}
}
