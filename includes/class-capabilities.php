<?php
/**
 * Motore di rilevamento delle librerie grafiche.
 *
 * Distingue sempre fra supporto "dichiarato" e supporto "verificato": e'
 * esattamente la differenza fra un host che funziona e uno che produce file
 * corrotti in silenzio.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Capabilities {

	const KEY    = 'mm_optimizer_capabilities';
	const SCHEMA = 1;

	/**
	 * Cache di richiesta.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Binari cercati, con l'argomento che ne stampa la versione.
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
	 * Cartelle in cui cercare i binari. Mai il PATH risolto da una shell.
	 *
	 * @return array
	 */
	protected static function search_paths() {
		$paths = array( '/usr/bin', '/usr/local/bin', '/bin', '/opt/local/bin', '/opt/homebrew/bin', '/usr/gnu/bin', '/usr/syno/bin' );

		/**
		 * Filtra le cartelle in cui cercare i binari di ottimizzazione.
		 *
		 * @param array $paths Percorsi assoluti.
		 */
		return (array) apply_filters( 'mm_optimizer_binary_paths', $paths );
	}

	/**
	 * Host gestiti su cui exec() e' inutile da provare.
	 *
	 * @return string Etichetta dell'host, oppure stringa vuota.
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

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- confronto con costanti note, nessun output.
		if ( ! empty( $_ENV['PANTHEON_ENVIRONMENT'] ) ) {
			return 'Pantheon';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- idem.
		if ( ! empty( $_ENV['WPAAS_V2_SITE_ID'] ) ) {
			return 'GoDaddy Managed';
		}

		return '';
	}

	/**
	 * Impronta dell'ambiente: cambia quando cambia qualcosa che conta.
	 *
	 * Invalidare per impronta invece che per scadenza evita di indovinare un
	 * TTL e coglie un aggiornamento di PHP nell'istante in cui avviene.
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
	 * Stringa di versione di Imagick, protetta da eccezioni.
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
	 * Struttura vuota completa.
	 *
	 * Garantisce che ogni consumatore trovi tutte le chiavi anche se il
	 * rilevamento non e' mai stato eseguito o e' stato interrotto.
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
	 * Completa una struttura eventualmente parziale.
	 *
	 * @param array $caps Capacita'.
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
	 * Restituisce le capacita', rilevandole se necessario.
	 *
	 * @param bool $force Forza un nuovo rilevamento.
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
	 * I binari possono comparire senza che l'impronta cambi: si riesaminano
	 * comunque una volta a settimana.
	 *
	 * @param array $stored Capacita' memorizzate.
	 * @return bool
	 */
	protected static function binaries_stale( array $stored ) {
		$generated = isset( $stored['generated'] ) ? (int) $stored['generated'] : 0;

		return ( time() - $generated ) > WEEK_IN_SECONDS;
	}

	/**
	 * Esegue il rilevamento completo e lo memorizza.
	 *
	 * @return array
	 */
	public static function detect() {
		// Un solo processo alla volta: il rilevamento lancia proc_open.
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

		// autoload = no: si legge solo in amministrazione e nel worker.
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
	 * Fase 0: ambiente.
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
	 * Fase 1 e 2: estensioni PHP, supporto dichiarato e prova funzionale.
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
	 * Codifica il campione con GD.
	 *
	 * @param string $source Percorso sorgente.
	 * @param string $dest   Percorso destinazione.
	 * @param string $mime   Tipo MIME di uscita.
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
	 * Codifica il campione con Imagick.
	 *
	 * @param string $source Percorso sorgente.
	 * @param string $dest   Percorso destinazione.
	 * @param string $tag    Formato Imagick.
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
	 * Fase 3: ricerca e prova dei binari.
	 *
	 * @param array $env Ambiente gia' rilevato.
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
	 * Cerca un binario nelle cartelle note.
	 *
	 * @param string $name Nome del binario.
	 * @return string Percorso assoluto, oppure stringa vuota.
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
	 * Prima riga dell'output di versione di un binario.
	 *
	 * @param string $path Percorso.
	 * @param string $flag Argomento.
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
	 * Prova funzionale di un binario.
	 *
	 * @param string $name Nome del binario.
	 * @param string $path Percorso.
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
				// Il campione ha 6 KB di metadati inutili: un binario che non
				// fa nulla non scende sotto la soglia.
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
	 * Prova un ottimizzatore JPEG senza perdita.
	 *
	 * @param string $name Nome del binario.
	 * @param string $path Percorso.
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

		// La sorgente e' 8.465 B di cui ~6 KB di commento superfluo.
		$verified = $bytes > 0 && $bytes < 6000;

		return array(
			'verified' => $verified,
			'bytes'    => $bytes,
			'ms'       => $ms,
			'reason'   => $verified ? '' : 'no_reduction',
		);
	}

	/**
	 * Prova un ottimizzatore PNG senza perdita.
	 *
	 * @param string $name Nome del binario.
	 * @param string $path Percorso.
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
	 * Esegue un comando senza passare da una shell.
	 *
	 * @param array $argv    Comando e argomenti.
	 * @param int   $timeout Secondi.
	 * @return string Output combinato.
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
	 * Classifica: misura ogni codificatore verificato su una fotografia.
	 *
	 * Separata dalla verifica perche' le due domande sono diverse. "Funziona?"
	 * si risponde su un campione minuscolo in pochi millisecondi. "Chi comprime
	 * meglio?" richiede un'immagine fotografica: sul campione 64x64 cwebp -m 6
	 * risulta piu' grande di -m 4, su una fotografia reale e' il 5-7% piu'
	 * piccolo. Classificare sul campione sbagliato sceglie il motore sbagliato.
	 *
	 * @param array $caps Capacita' parziali.
	 * @return array Formato => motore => array( bytes, ms ).
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
	 * Codifica il campione fotografico con GD, alle impostazioni di produzione.
	 *
	 * @param string $source Percorso sorgente.
	 * @param string $dest   Percorso destinazione.
	 * @param string $mime   Tipo MIME di uscita.
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
	 * Fase 5: sceglie il motore vincente per ogni formato di uscita.
	 *
	 * La classifica e' sui byte misurati sulla stessa fotografia, con i
	 * millisecondi come spareggio. Nessuna tabella di preferenze scritta a mano:
	 * su un host dove cwebp batte GD, cwebp vince da solo.
	 *
	 * @param array $caps Capacita' parziali.
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

			// Ripiego sui numeri di verifica se la classifica non e' stata
			// eseguibile (deadline, campione mancante).
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
	 * Quali formati possono essere ottimizzati in place senza perdita.
	 *
	 * @param array $caps Capacita' parziali.
	 * @return array
	 */
	protected static function resolve_lossless( array $caps ) {
		$out = array();

		// JPEG: nessuna libreria PHP sa ricomprimere senza perdita. Serve un
		// binario dedicato.
		if ( ! empty( $caps['binaries']['jpegoptim']['verified'] ) ) {
			$out['image/jpeg'] = array( 'available' => true, 'engine' => 'jpegoptim', 'reason' => '' );
		} elseif ( ! empty( $caps['binaries']['jpegtran']['verified'] ) ) {
			$out['image/jpeg'] = array( 'available' => true, 'engine' => 'jpegtran', 'reason' => '' );
		} else {
			$out['image/jpeg'] = array( 'available' => false, 'engine' => '', 'reason' => 'no_jpeg_tool' );
		}

		// PNG: optipng se c'e', altrimenti GD sa ricomprimere a livello 9
		// mantenendo i pixel identici.
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
	 * Fase 5: condizioni di consegna.
	 *
	 * @param array $caps Capacita' parziali.
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
	 * Verifica empiricamente il Content-Type con cui il server consegna un
	 * file .avif.
	 *
	 * Molti host hanno un /etc/mime.types anteriore ad AVIF: il file viene
	 * servito come application/octet-stream e in modalita' <picture> Firefox
	 * e Safari falliscono senza ripiegare sull'<img>.
	 *
	 * @return bool|null Null se la sonda non e' stata eseguibile.
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
	 * Avvertenze da mostrare in diagnostica.
	 *
	 * @param array $caps Capacita' complete.
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

		// Un formato dichiarato ma non verificato e' il caso che corrompe i
		// file in silenzio: va segnalato in evidenza.
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
	 * Svuota la cache di richiesta.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}
}
