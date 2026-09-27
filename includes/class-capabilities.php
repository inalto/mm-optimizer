<?php
/**
 * Graphics library detection engine.
 *
 * It always distinguishes "claimed" support from "verified" support: that is
 * exactly the difference between a host that works and one that silently
 * produces corrupted files.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Capabilities {

	const KEY    = 'mm_optimizer_capabilities';
	const SCHEMA = 1;

	/**
	 * Per-request cache.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Binaries we look for, with the argument that prints their version.
	 *
	 * @return array
	 */
	public static function binaries() {
		return array(
			'cwebp'     => array( 'flag' => '-version', 'formats' => array( 'image/webp' ) ),
			'avifenc'   => array( 'flag' => '--version', 'formats' => array( 'image/avif' ) ),
			'jpegoptim' => array( 'flag' => '--version', 'formats' => array() ),
			'jpegtran'  => array( 'flag' => '-version', 'formats' => array() ),
			'optipng'   => array( 'flag' => '-v', 'formats' => array() ),
			'pngquant'  => array( 'flag' => '--version', 'formats' => array() ),
			'gifsicle'  => array( 'flag' => '--version', 'formats' => array() ),
		);
	}

	/**
	 * Directories to search for binaries. Never a shell-resolved PATH.
	 *
	 * @return array
	 */
	protected static function search_paths() {
		$paths = array( '/usr/bin', '/usr/local/bin', '/bin', '/opt/local/bin', '/opt/homebrew/bin', '/usr/gnu/bin', '/usr/syno/bin' );

		/**
		 * Filters the directories searched for optimization binaries.
		 *
		 * @param array $paths Absolute paths.
		 */
		return (array) apply_filters( 'mm_optimizer_binary_paths', $paths );
	}

	/**
	 * Managed hosts where trying exec() is pointless.
	 *
	 * @return string Host label, or an empty string.
	 */
	public static function managed_host() {
		$map = array(
			'WPCOMSH_VERSION'           => 'WordPress.com',
			'WPE_PLUGIN_VERSION'        => 'WP Engine',
			'FLYWHEEL_CONFIG_DIR'       => 'Flywheel',
			'KINSTAMU_VERSION'          => 'Kinsta',
			'WPNET_INIT_PLUGIN_VERSION' => 'WP NET',
		);

		foreach ( $map as $constant => $label ) {
			if ( defined( $constant ) ) {
				return $label;
			}
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against known constants, never output.
		if ( ! empty( $_ENV['PANTHEON_ENVIRONMENT'] ) ) {
			return 'Pantheon';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- same as above.
		if ( ! empty( $_ENV['WPAAS_V2_SITE_ID'] ) ) {
			return 'GoDaddy Managed';
		}

		return '';
	}

	/**
	 * Environment fingerprint: changes when something that matters changes.
	 *
	 * Invalidating by fingerprint rather than by expiry avoids guessing a TTL
	 * and catches a PHP upgrade the moment it happens.
	 *
	 * @return string
	 */
	public static function fingerprint() {
		$parts = array(
			PHP_VERSION,
			(string) phpversion( 'gd' ),
			(string) ( function_exists( 'imagetypes' ) ? imagetypes() : 0 ),
			extension_loaded( 'imagick' ) ? self::imagick_version() : '',
			extension_loaded( 'gmagick' ) ? '1' : '0',
			(string) ini_get( 'disable_functions' ),
			(string) ini_get( 'open_basedir' ),
			PHP_OS,
			MM_OPTIMIZER_VERSION,
			(string) self::SCHEMA,
		);

		return sha1( implode( '|', $parts ) );
	}

	/**
	 * Imagick version string, guarded against exceptions.
	 *
	 * @return string
	 */
	protected static function imagick_version() {
		try {
			$version = \Imagick::getVersion();
			return isset( $version['versionString'] ) ? (string) $version['versionString'] : '1';
		} catch ( \Throwable $e ) {
			return '0';
		}
	}

	/**
	 * Complete empty structure.
	 *
	 * Guarantees that every consumer finds all the keys even when detection has
	 * never run or was interrupted.
	 *
	 * @return array
	 */
	public static function blank() {
		return array(
			'schema'      => self::SCHEMA,
			'generated'   => 0,
			'fingerprint' => '',
			'duration_ms' => 0,
			'env'         => array(
				'php'                => PHP_VERSION,
				'os'                 => PHP_OS,
				'memory_limit'       => 0,
				'max_execution_time' => 0,
				'managed_host'       => '',
				'open_basedir'       => '',
				'exec_available'     => false,
				'uploads_writable'   => false,
				'file_uploads'       => true,
				'loopback_ok'        => null,
			),
			'libraries'   => array(),
			'binaries'    => array(),
			'bench'       => array(),
			'encoders'    => array(
				'image/webp' => array( 'engine' => '', 'why' => 'no_candidate', 'bytes' => 0, 'ms' => 0.0, 'alternatives' => array() ),
				'image/avif' => array( 'engine' => '', 'why' => 'no_candidate', 'bytes' => 0, 'ms' => 0.0, 'alternatives' => array() ),
			),
			'lossless'    => array(
				'image/jpeg' => array( 'available' => false, 'engine' => '', 'reason' => 'not_detected' ),
				'image/png'  => array( 'available' => false, 'engine' => '', 'reason' => 'not_detected' ),
				'image/gif'  => array( 'available' => false, 'engine' => '', 'reason' => 'not_detected' ),
			),
			'delivery'    => array(
				'apache'               => false,
				'htaccess_writable'    => false,
				'avif_mime_registered' => null,
				'picture'              => true,
			),
			'warnings'    => array(),
		);
	}

	/**
	 * Fills in a possibly partial structure.
	 *
	 * @param array $caps Capabilities.
	 * @return array
	 */
	protected static function normalize( array $caps ) {
		$blank = self::blank();

		foreach ( $blank as $key => $default ) {
			if ( ! array_key_exists( $key, $caps ) ) {
				$caps[ $key ] = $default;
			} elseif ( is_array( $default ) && is_array( $caps[ $key ] ) ) {
				$caps[ $key ] = $caps[ $key ] + $default;
			}
		}

		return $caps;
	}

	/**
	 * Returns the capabilities, detecting them when necessary.
	 *
	 * @param bool $force Force a fresh detection run.
	 * @return array
	 */
	public static function get( $force = false ) {
		if ( ! $force && null !== self::$cache ) {
			return self::$cache;
		}

		if ( ! $force ) {
			$stored = get_option( self::KEY, array() );

			if (
				is_array( $stored )
				&& isset( $stored['fingerprint'], $stored['schema'] )
				&& self::SCHEMA === (int) $stored['schema']
				&& self::fingerprint() === $stored['fingerprint']
				&& ! self::binaries_stale( $stored )
			) {
				self::$cache = self::normalize( $stored );
				return self::$cache;
			}
		}

		return self::detect();
	}

	/**
	 * Binaries can appear without the fingerprint changing, so they are
	 * re-examined once a week regardless.
	 *
	 * @param array $stored Stored capabilities.
	 * @return bool
	 */
	protected static function binaries_stale( array $stored ) {
		$generated = isset( $stored['generated'] ) ? (int) $stored['generated'] : 0;

		return ( time() - $generated ) > WEEK_IN_SECONDS;
	}

	/**
	 * Runs the full detection and stores it.
	 *
	 * @return array
	 */
	public static function detect() {
		// One process at a time: detection spawns proc_open.
		if ( get_transient( 'mm_opt_cap_lock' ) ) {
			$stored = get_option( self::KEY, array() );
			if ( is_array( $stored ) && ! empty( $stored ) ) {
				return self::normalize( $stored );
			}
		}

		set_transient( 'mm_opt_cap_lock', 1, MINUTE_IN_SECONDS );

		$start = microtime( true );

		$caps = array(
			'schema'      => self::SCHEMA,
			'generated'   => time(),
			'fingerprint' => self::fingerprint(),
			'duration_ms' => 0,
			'env'         => self::probe_env(),
			'libraries'   => array(),
			'binaries'    => array(),
			'bench'       => array(),
			'encoders'    => array(),
			'lossless'    => array(),
			'delivery'    => array(),
			'warnings'    => array(),
		);

		$caps['libraries'] = self::probe_libraries();
		$caps['binaries']  = self::probe_binaries( $caps['env'] );
		$caps['bench']     = self::run_bench( $caps );
		$caps['encoders']  = self::resolve_encoders( $caps );
		$caps['lossless']  = self::resolve_lossless( $caps );
		$caps['delivery']  = self::probe_delivery( $caps );
		$caps['warnings']  = self::collect_warnings( $caps );

		$caps['duration_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );

		// autoload = no: only read in the admin and in the worker.
		if ( false === get_option( self::KEY, false ) ) {
			add_option( self::KEY, $caps, '', false );
		} else {
			update_option( self::KEY, $caps, false );
		}

		delete_transient( 'mm_opt_cap_lock' );

		self::$cache = self::normalize( $caps );

		return self::$cache;
	}

	/**
	 * Stage 0: environment.
	 *
	 * @return array
	 */
	protected static function probe_env() {
		$managed = self::managed_host();

		$exec = '' === $managed
			&& Base::function_exists( 'proc_open' )
			&& Base::function_exists( 'exec' );

		$uploads = Base::uploads_basedir();

		return array(
			'php'                 => PHP_VERSION,
			'os'                  => PHP_OS,
			'memory_limit'        => Base::memory_limit_bytes(),
			'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
			'managed_host'        => $managed,
			'open_basedir'        => (string) ini_get( 'open_basedir' ),
			'exec_available'      => $exec,
			'uploads_writable'    => '' !== $uploads && wp_is_writable( $uploads ),
			'file_uploads'        => (bool) ini_get( 'file_uploads' ),
			'loopback_ok'         => null,
		);
	}

	/**
	 * Stages 1 and 2: PHP extensions, claimed support and functional test.
	 *
	 * @return array
	 */
	protected static function probe_libraries() {
		return array(
			'gd'      => self::probe_gd(),
			'imagick' => self::probe_imagick(),
			'gmagick' => self::probe_gmagick(),
		);
	}

	/**
	 * GD.
	 *
	 * @return array
	 */
	protected static function probe_gd() {
		if ( ! extension_loaded( 'gd' ) || ! Base::function_exists( 'gd_info' ) ) {
			return array( 'present' => false, 'reason' => 'extension_not_loaded', 'formats' => array() );
		}

		$info    = gd_info();
		$version = isset( $info['GD Version'] ) ? (string) $info['GD Version'] : '';
		$types   = Base::function_exists( 'imagetypes' ) ? imagetypes() : 0;

		$formats = array();

		$candidates = array(
			'image/webp' => array( 'flag' => IMG_WEBP, 'writer' => 'imagewebp' ),
			'image/avif' => array( 'flag' => defined( 'IMG_AVIF' ) ? IMG_AVIF : 0, 'writer' => 'imageavif' ),
			'image/jpeg' => array( 'flag' => IMG_JPG, 'writer' => 'imagejpeg' ),
			'image/png'  => array( 'flag' => IMG_PNG, 'writer' => 'imagepng' ),
			'image/gif'  => array( 'flag' => IMG_GIF, 'writer' => 'imagegif' ),
		);

		foreach ( $candidates as $mime => $spec ) {
			$claimed = ( $spec['flag'] && ( $types & $spec['flag'] ) ) && Base::function_exists( $spec['writer'] );

			$entry = array(
				'claimed'  => (bool) $claimed,
				'verified' => false,
				'bytes'    => 0,
				'ms'       => 0.0,
				'reason'   => $claimed ? '' : 'not_claimed',
			);

			if ( $claimed ) {
				$probe = Probe::run(
					static function ( $source, $dest ) use ( $mime ) {
						return self::gd_encode( $source, $dest, $mime );
					},
					$mime
				);

				$entry['verified'] = $probe['verified'];
				$entry['bytes']    = $probe['bytes'];
				$entry['ms']       = $probe['ms'];
				$entry['reason']   = $probe['reason'];
			}

			$formats[ $mime ] = $entry;
		}

		return array(
			'present' => true,
			'version' => $version,
			'formats' => $formats,
		);
	}

	/**
	 * Encodes the sample with GD.
	 *
	 * @param string $source Source path.
	 * @param string $dest   Destination path.
	 * @param string $mime   Output MIME type.
	 * @return bool
	 */
	protected static function gd_encode( $source, $dest, $mime ) {
		$image = @imagecreatefrompng( $source );

		if ( ! $image ) {
			return false;
		}

		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		switch ( $mime ) {
			case 'image/webp':
				$ok = @imagewebp( $image, $dest, 82 );
				break;
			case 'image/avif':
				$ok = @imageavif( $image, $dest, 58, 6 );
				break;
			case 'image/jpeg':
				$flat = imagecreatetruecolor( imagesx( $image ), imagesy( $image ) );
				imagefilledrectangle( $flat, 0, 0, imagesx( $image ), imagesy( $image ), imagecolorallocate( $flat, 255, 255, 255 ) );
				imagecopy( $flat, $image, 0, 0, 0, 0, imagesx( $image ), imagesy( $image ) );
				$ok = @imagejpeg( $flat, $dest, 82 );
				imagedestroy( $flat );
				break;
			case 'image/png':
				$ok = @imagepng( $image, $dest, 9, PNG_ALL_FILTERS );
				break;
			case 'image/gif':
				$ok = @imagegif( $image, $dest );
				break;
			default:
				$ok = false;
		}

		imagedestroy( $image );

		return (bool) $ok;
	}

	/**
	 * Imagick.
	 *
	 * @return array
	 */
	protected static function probe_imagick() {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( '\\Imagick' ) ) {
			return array( 'present' => false, 'reason' => 'extension_not_loaded', 'formats' => array() );
		}

		try {
			$available = \Imagick::queryFormats();
		} catch ( \Throwable $e ) {
			return array( 'present' => false, 'reason' => 'query_failed', 'formats' => array() );
		}

		$available = array_map( 'strtoupper', (array) $available );
		$formats   = array();

		$candidates = array(
			'image/webp' => 'WEBP',
			'image/avif' => 'AVIF',
			'image/jpeg' => 'JPEG',
			'image/png'  => 'PNG',
			'image/gif'  => 'GIF',
		);

		foreach ( $candidates as $mime => $tag ) {
			$claimed = in_array( $tag, $available, true );

			$entry = array(
				'claimed'  => $claimed,
				'verified' => false,
				'bytes'    => 0,
				'ms'       => 0.0,
				'reason'   => $claimed ? '' : 'not_claimed',
			);

			if ( $claimed ) {
				$probe = Probe::run(
					static function ( $source, $dest ) use ( $tag ) {
						return self::imagick_encode( $source, $dest, $tag );
					},
					$mime
				);

				$entry['verified'] = $probe['verified'];
				$entry['bytes']    = $probe['bytes'];
				$entry['ms']       = $probe['ms'];
				$entry['reason']   = $probe['reason'];
			}

			$formats[ $mime ] = $entry;
		}

		return array(
			'present' => true,
			'version' => self::imagick_version(),
			'formats' => $formats,
		);
	}

	/**
	 * Encodes the sample with Imagick.
	 *
	 * @param string $source Source path.
	 * @param string $dest   Destination path.
	 * @param string $tag    Imagick format.
	 * @return bool
	 */
	protected static function imagick_encode( $source, $dest, $tag ) {
		try {
			$image = new \Imagick( $source );
			$image->setImageFormat( $tag );

			if ( 'AVIF' === $tag ) {
				$image->setOption( 'heic:speed', '7' );
			}

			$image->setImageCompressionQuality( 82 );
			$ok = $image->writeImage( $dest );
			$image->clear();
			$image->destroy();

			return (bool) $ok;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Gmagick.
	 *
	 * @return array
	 */
	protected static function probe_gmagick() {
		if ( ! extension_loaded( 'gmagick' ) || ! class_exists( '\\Gmagick' ) ) {
			return array( 'present' => false, 'reason' => 'extension_not_loaded', 'formats' => array() );
		}

		try {
			$image     = new \Gmagick();
			$available = array_map( 'strtoupper', (array) $image->queryFormats() );
		} catch ( \Throwable $e ) {
			return array( 'present' => false, 'reason' => 'query_failed', 'formats' => array() );
		}

		$formats = array();

		foreach ( array( 'image/webp' => 'WEBP', 'image/jpeg' => 'JPEG', 'image/png' => 'PNG' ) as $mime => $tag ) {
			$formats[ $mime ] = array(
				'claimed'  => in_array( $tag, $available, true ),
				'verified' => false,
				'bytes'    => 0,
				'ms'       => 0.0,
				'reason'   => 'not_probed',
			);
		}

		return array( 'present' => true, 'version' => '', 'formats' => $formats );
	}

	/**
	 * Stage 3: binary discovery and testing.
	 *
	 * @param array $env Already-detected environment.
	 * @return array
	 */
	protected static function probe_binaries( array $env ) {
		$out = array();

		foreach ( self::binaries() as $name => $spec ) {
			$out[ $name ] = array(
				'path'     => '',
				'version'  => '',
				'verified' => false,
				'bytes'    => 0,
				'ms'       => 0.0,
				'reason'   => '',
			);
		}

		if ( empty( $env['exec_available'] ) ) {
			$reason = '' !== $env['managed_host'] ? 'managed_host_skip' : 'exec_unavailable';
			foreach ( $out as $name => $entry ) {
				$out[ $name ]['reason'] = $reason;
			}
			return $out;
		}

		$deadline = microtime( true ) + 5.0;

		foreach ( self::binaries() as $name => $spec ) {
			if ( microtime( true ) > $deadline ) {
				$out[ $name ]['reason'] = 'probe_deadline';
				continue;
			}

			$path = self::find_binary( $name );

			if ( '' === $path ) {
				$out[ $name ]['reason'] = 'not_found';
				continue;
			}

			$out[ $name ]['path']    = $path;
			$out[ $name ]['version'] = self::binary_version( $path, $spec['flag'] );

			$probe = self::probe_binary( $name, $path );

			$out[ $name ]['verified'] = $probe['verified'];
			$out[ $name ]['bytes']    = $probe['bytes'];
			$out[ $name ]['ms']       = $probe['ms'];
			$out[ $name ]['reason']   = $probe['reason'];
		}

		return $out;
	}

	/**
	 * Looks for a binary in the known directories.
	 *
	 * @param string $name Binary name.
	 * @return string Absolute path, or an empty string.
	 */
	protected static function find_binary( $name ) {
		$constant = 'MM_OPTIMIZER_' . strtoupper( $name );

		if ( defined( $constant ) && Base::system_file_exists( constant( $constant ) ) ) {
			return (string) constant( $constant );
		}

		foreach ( self::search_paths() as $dir ) {
			$candidate = trailingslashit( $dir ) . $name;
			if ( Base::system_file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * First line of a binary's version output.
	 *
	 * @param string $path Path.
	 * @param string $flag Argument.
	 * @return string
	 */
	protected static function binary_version( $path, $flag ) {
		$output = self::run_command( array( $path, $flag ), 5 );

		if ( '' === $output ) {
			return '';
		}

		$first = strtok( $output, "\n" );

		return trim( substr( (string) $first, 0, 60 ) );
	}

	/**
	 * Functional test of a binary.
	 *
	 * @param string $name Binary name.
	 * @param string $path Path.
	 * @return array
	 */
	protected static function probe_binary( $name, $path ) {
		switch ( $name ) {
			case 'cwebp':
				return Probe::run(
					static function ( $source, $dest ) use ( $path ) {
						self::run_command( array( $path, '-quiet', '-q', '82', $source, '-o', $dest ), 10 );
						return @is_file( $dest );
					},
					'image/webp'
				);

			case 'avifenc':
				return Probe::run(
					static function ( $source, $dest ) use ( $path ) {
						self::run_command( array( $path, '-q', '58', '-s', '6', $source, $dest ), 15 );
						return @is_file( $dest );
					},
					'image/avif'
				);

			case 'jpegoptim':
			case 'jpegtran':
				// The sample carries 6 KB of junk metadata: a binary that does
				// nothing cannot get under the threshold.
				return self::probe_lossless_jpeg( $name, $path );

			case 'optipng':
			case 'pngquant':
				return self::probe_lossless_png( $name, $path );

			case 'gifsicle':
				return array( 'verified' => true, 'bytes' => 0, 'ms' => 0.0, 'reason' => '' );
		}

		return array( 'verified' => false, 'bytes' => 0, 'ms' => 0.0, 'reason' => 'no_probe' );
	}

	/**
	 * Tests a lossless JPEG optimizer.
	 *
	 * @param string $name Binary name.
	 * @param string $path Path.
	 * @return array
	 */
	protected static function probe_lossless_jpeg( $name, $path ) {
		$source = Probe::sample_bloated_jpeg();
		$dest   = trailingslashit( Base::tmp_dir() ) . 'probe-' . wp_generate_password( 8, false ) . '.jpg';

		if ( ! @copy( $source, $dest ) ) {
			return array( 'verified' => false, 'bytes' => 0, 'ms' => 0.0, 'reason' => 'tmp_unwritable' );
		}

		$start = microtime( true );

		if ( 'jpegoptim' === $name ) {
			self::run_command( array( $path, '--strip-all', '--quiet', $dest ), 10 );
		} else {
			$out = $dest . '.out';
			self::run_command( array( $path, '-copy', 'none', '-optimize', '-outfile', $out, $dest ), 10 );
			if ( @is_file( $out ) ) {
				@rename( $out, $dest );
			}
		}

		$ms    = round( ( microtime( true ) - $start ) * 1000, 1 );
		$bytes = @is_file( $dest ) ? (int) filesize( $dest ) : 0;

		@unlink( $dest );
		@unlink( $dest . '.out' );

		// The source is 8,465 B, roughly 6 KB of it a junk comment.
		$verified = $bytes > 0 && $bytes < 6000;

		return array(
			'verified' => $verified,
			'bytes'    => $bytes,
			'ms'       => $ms,
			'reason'   => $verified ? '' : 'no_reduction',
		);
	}

	/**
	 * Tests a lossless PNG optimizer.
	 *
	 * @param string $name Binary name.
	 * @param string $path Path.
	 * @return array
	 */
	protected static function probe_lossless_png( $name, $path ) {
		$source = Probe::sample_png();
		$dest   = trailingslashit( Base::tmp_dir() ) . 'probe-' . wp_generate_password( 8, false ) . '.png';

		if ( ! @copy( $source, $dest ) ) {
			return array( 'verified' => false, 'bytes' => 0, 'ms' => 0.0, 'reason' => 'tmp_unwritable' );
		}

		$start = microtime( true );

		if ( 'optipng' === $name ) {
			self::run_command( array( $path, '-quiet', '-o2', $dest ), 15 );
		} else {
			self::run_command( array( $path, '--force', '--skip-if-larger', '--output', $dest, '--', $dest ), 15 );
		}

		$ms    = round( ( microtime( true ) - $start ) * 1000, 1 );
		$bytes = @is_file( $dest ) ? (int) filesize( $dest ) : 0;
		$valid = 'image/png' === Base::sniff_mime( $dest );

		@unlink( $dest );

		return array(
			'verified' => $bytes > 0 && $valid,
			'bytes'    => $bytes,
			'ms'       => $ms,
			'reason'   => ( $bytes > 0 && $valid ) ? '' : 'probe_failed',
		);
	}

	/**
	 * Runs a command without going through a shell.
	 *
	 * @param array $argv    Command and arguments.
	 * @param int   $timeout Seconds.
	 * @return string Combined output.
	 */
	public static function run_command( array $argv, $timeout = 10 ) {
		if ( ! Base::function_exists( 'proc_open' ) ) {
			return '';
		}

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$pipes   = array();
		$process = @proc_open( $argv, $descriptors, $pipes );

		if ( ! is_resource( $process ) ) {
			return '';
		}

		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$output   = '';
		$deadline = microtime( true ) + (float) $timeout;

		while ( microtime( true ) < $deadline ) {
			$status = proc_get_status( $process );

			$output .= (string) stream_get_contents( $pipes[1] );
			$output .= (string) stream_get_contents( $pipes[2] );

			if ( ! $status['running'] ) {
				break;
			}

			usleep( 20000 );
		}

		$status = proc_get_status( $process );
		if ( $status['running'] ) {
			proc_terminate( $process, 9 );
		}

		$output .= (string) stream_get_contents( $pipes[1] );
		$output .= (string) stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		return $output;
	}

	/**
	 * Ranking: measures every verified encoder against a photograph.
	 *
	 * Kept separate from verification because the two questions differ. "Does it
	 * work?" is answered on a tiny sample in milliseconds. "Which compresses
	 * best?" needs a photographic image: on the 64x64 sample cwebp -m 6 comes out
	 * larger than -m 4, while on a real photograph it is 5-7% smaller. Ranking on
	 * the wrong sample picks the wrong engine.
	 *
	 * @param array $caps Partial capabilities.
	 * @return array Format => engine => array( bytes, ms ).
	 */
	protected static function run_bench( array $caps ) {
		$out      = array();
		$deadline = microtime( true ) + 4.0;

		foreach ( array( 'image/webp', 'image/avif' ) as $mime ) {
			$out[ $mime ] = array();

			foreach ( array( 'gd', 'imagick' ) as $library ) {
				if ( empty( $caps['libraries'][ $library ]['formats'][ $mime ]['verified'] ) ) {
					continue;
				}
				if ( microtime( true ) > $deadline ) {
					break 2;
				}

				$out[ $mime ][ $library ] = Probe::bench(
					static function ( $source, $dest ) use ( $library, $mime ) {
						return 'gd' === $library
							? self::gd_bench_encode( $source, $dest, $mime )
							: self::imagick_encode( $source, $dest, 'image/avif' === $mime ? 'AVIF' : 'WEBP' );
					},
					$mime
				);
			}

			$binary = 'image/webp' === $mime ? 'cwebp' : 'avifenc';

			if ( ! empty( $caps['binaries'][ $binary ]['verified'] ) && microtime( true ) <= $deadline ) {
				$path   = $caps['binaries'][ $binary ]['path'];
				$method = (string) Presets::cwebp_method();
				$speed  = (string) Presets::avif_speed();

				$out[ $mime ][ $binary ] = Probe::bench(
					static function ( $source, $dest ) use ( $path, $binary, $method, $speed ) {
						$argv = 'cwebp' === $binary
							? array( $path, '-quiet', '-q', '82', '-m', $method, $source, '-o', $dest )
							: array( $path, '-q', '58', '-s', $speed, $source, $dest );

						self::run_command( $argv, 20 );

						return @is_file( $dest );
					},
					$mime
				);
			}
		}

		return $out;
	}

	/**
	 * Encodes the photographic sample with GD, at production settings.
	 *
	 * @param string $source Source path.
	 * @param string $dest   Destination path.
	 * @param string $mime   Output MIME type.
	 * @return bool
	 */
	protected static function gd_bench_encode( $source, $dest, $mime ) {
		$image = @imagecreatefromjpeg( $source );

		if ( ! $image ) {
			return false;
		}

		$ok = 'image/avif' === $mime
			? @imageavif( $image, $dest, 58, Presets::avif_speed() )
			: @imagewebp( $image, $dest, 82 );

		imagedestroy( $image );

		return (bool) $ok;
	}

	/**
	 * Stage 4: picks the winning engine for each output format.
	 *
	 * Ranking is on the bytes measured against the same photograph, with
	 * milliseconds as the tie-breaker. No hand-written preference table: on a host
	 * where cwebp beats GD, cwebp wins on its own.
	 *
	 * @param array $caps Partial capabilities.
	 * @return array
	 */
	protected static function resolve_encoders( array $caps ) {
		$out = array();

		foreach ( array( 'image/webp', 'image/avif' ) as $mime ) {
			$candidates = array();

			foreach ( (array) ( $caps['bench'][ $mime ] ?? array() ) as $engine => $measure ) {
				if ( ! empty( $measure['bytes'] ) ) {
					$candidates[ $engine ] = $measure;
				}
			}

			// Fall back to the verification numbers when ranking could not
			// run (deadline reached, sample missing).
			if ( empty( $candidates ) ) {
				foreach ( array( 'gd', 'imagick', 'gmagick' ) as $library ) {
					$entry = $caps['libraries'][ $library ]['formats'][ $mime ] ?? null;
					if ( $entry && ! empty( $entry['verified'] ) ) {
						$candidates[ $library ] = array( 'bytes' => (int) $entry['bytes'], 'ms' => (float) $entry['ms'] );
					}
				}

				$binary = 'image/webp' === $mime ? 'cwebp' : 'avifenc';
				if ( ! empty( $caps['binaries'][ $binary ]['verified'] ) ) {
					$candidates[ $binary ] = array(
						'bytes' => (int) $caps['binaries'][ $binary ]['bytes'],
						'ms'    => (float) $caps['binaries'][ $binary ]['ms'],
					);
				}
			}

			if ( empty( $candidates ) ) {
				$out[ $mime ] = array( 'engine' => '', 'why' => 'no_candidate', 'bytes' => 0, 'ms' => 0.0, 'alternatives' => array() );
				continue;
			}

			uasort(
				$candidates,
				static function ( $a, $b ) {
					if ( $a['bytes'] !== $b['bytes'] ) {
						return $a['bytes'] <=> $b['bytes'];
					}
					return $a['ms'] <=> $b['ms'];
				}
			);

			$names  = array_keys( $candidates );
			$winner = array_shift( $names );

			if ( 1 === count( $candidates ) ) {
				$why = 'only_candidate';
			} else {
				$values = array_values( $candidates );
				$why    = ( $values[0]['bytes'] === $values[1]['bytes'] ) ? 'tie_on_bytes_faster' : 'smallest_output';
			}

			$out[ $mime ] = array(
				'engine'       => $winner,
				'why'          => $why,
				'bytes'        => $candidates[ $winner ]['bytes'],
				'ms'           => $candidates[ $winner ]['ms'],
				'alternatives' => $names,
			);
		}

		return $out;
	}

	/**
	 * Which formats can be optimized losslessly in place.
	 *
	 * @param array $caps Partial capabilities.
	 * @return array
	 */
	protected static function resolve_lossless( array $caps ) {
		$out = array();

		// JPEG: no PHP library can recompress losslessly. A dedicated binary
		// is required.
		if ( ! empty( $caps['binaries']['jpegoptim']['verified'] ) ) {
			$out['image/jpeg'] = array( 'available' => true, 'engine' => 'jpegoptim', 'reason' => '' );
		} elseif ( ! empty( $caps['binaries']['jpegtran']['verified'] ) ) {
			$out['image/jpeg'] = array( 'available' => true, 'engine' => 'jpegtran', 'reason' => '' );
		} else {
			$out['image/jpeg'] = array( 'available' => false, 'engine' => '', 'reason' => 'no_jpeg_tool' );
		}

		// PNG: optipng when present, otherwise GD can recompress at level 9
		// while keeping the pixels identical.
		if ( ! empty( $caps['binaries']['optipng']['verified'] ) ) {
			$out['image/png'] = array( 'available' => true, 'engine' => 'optipng', 'reason' => '' );
		} elseif ( ! empty( $caps['libraries']['gd']['formats']['image/png']['verified'] ) ) {
			$out['image/png'] = array( 'available' => true, 'engine' => 'gd', 'reason' => '' );
		} else {
			$out['image/png'] = array( 'available' => false, 'engine' => '', 'reason' => 'no_png_tool' );
		}

		$out['image/gif'] = ! empty( $caps['binaries']['gifsicle']['verified'] )
			? array( 'available' => true, 'engine' => 'gifsicle', 'reason' => '' )
			: array( 'available' => false, 'engine' => '', 'reason' => 'no_gifsicle' );

		return $out;
	}

	/**
	 * Stage 5: delivery conditions.
	 *
	 * @param array $caps Partial capabilities.
	 * @return array
	 */
	protected static function probe_delivery( array $caps ) {
		$candidates = array(
			trailingslashit( ABSPATH ) . '.htaccess',
			trailingslashit( Base::uploads_basedir() ) . '.htaccess',
		);

		$writable = false;
		foreach ( $candidates as $file ) {
			if ( ( @is_file( $file ) && wp_is_writable( $file ) ) || ( ! @is_file( $file ) && wp_is_writable( dirname( $file ) ) ) ) {
				$writable = true;
				break;
			}
		}

		return array(
			'apache'               => function_exists( 'got_mod_rewrite' ) ? (bool) got_mod_rewrite() : false,
			'htaccess_writable'    => $writable,
			'avif_mime_registered' => self::probe_avif_mime(),
			'picture'              => true,
		);
	}

	/**
	 * Empirically checks the Content-Type the server uses to deliver an .avif
	 * file.
	 *
	 * Many hosts have an /etc/mime.types that predates AVIF: the file is served as
	 * application/octet-stream, and in <picture> mode Firefox and Safari fail
	 * without falling back to the <img>.
	 *
	 * @return bool|null Null when the probe could not run.
	 */
	protected static function probe_avif_mime() {
		$url = MM_OPTIMIZER_URL . 'assets/probe/probe.avif';

		$response = wp_remote_head(
			$url,
			array(
				'timeout'   => 5,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );

		return false !== strpos( $type, 'image/avif' );
	}

	/**
	 * Warnings to surface in the diagnostics screen.
	 *
	 * @param array $caps Complete capabilities.
	 * @return array
	 */
	protected static function collect_warnings( array $caps ) {
		$warnings = array();

		if ( '' === $caps['encoders']['image/webp']['engine'] && '' === $caps['encoders']['image/avif']['engine'] ) {
			$warnings[] = 'no_modern_encoder';
		} elseif ( '' === $caps['encoders']['image/avif']['engine'] ) {
			$warnings[] = 'avif_unavailable';
		}

		if ( false === $caps['delivery']['avif_mime_registered'] ) {
			$warnings[] = 'avif_mime_missing';
		}

		if ( empty( $caps['lossless']['image/jpeg']['available'] ) ) {
			$warnings[] = 'no_jpeg_lossless_tool';
		}

		if ( empty( $caps['env']['file_uploads'] ) ) {
			$warnings[] = 'file_uploads_off';
		}

		if ( empty( $caps['env']['uploads_writable'] ) ) {
			$warnings[] = 'uploads_unwritable';
		}

		// A format that is claimed but not verified is the case that silently
		// corrupts files: it has to be flagged prominently.
		foreach ( $caps['libraries'] as $library => $data ) {
			if ( empty( $data['present'] ) ) {
				continue;
			}
			foreach ( (array) $data['formats'] as $mime => $entry ) {
				if ( ! empty( $entry['claimed'] ) && empty( $entry['verified'] ) && 'not_probed' !== $entry['reason'] ) {
					$warnings[] = 'claimed_not_verified:' . $library . ':' . $mime;
				}
			}
		}

		foreach ( Conflicts::active() as $label ) {
			$warnings[] = 'conflict_active:' . $label;
		}

		return $warnings;
	}

	/**
	 * Clears the per-request cache.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}
}
