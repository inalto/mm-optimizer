<?php
/**
 * Utilita' condivise: rilevamento funzioni, percorsi, magic bytes.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Base {

	/**
	 * Cache di richiesta per function_exists().
	 *
	 * @var array
	 */
	protected static $fn_cache = array();

	/**
	 * Lista suhosin, calcolata una sola volta.
	 *
	 * @var array|null
	 */
	protected static $suhosin = null;

	/**
	 * Verifica se una funzione PHP e' realmente invocabile.
	 *
	 * Non basta function_exists(): disable_functions e la blacklist di suhosin
	 * possono neutralizzarla lasciando il simbolo definito.
	 *
	 * @param string $name Nome della funzione.
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
	 * Blacklist di suhosin, normalizzata in minuscolo.
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
	 * is_file() consapevole di open_basedir.
	 *
	 * Sonda /usr/bin e simili senza generare warning quando open_basedir
	 * esclude quei percorsi.
	 *
	 * @param string $path Percorso assoluto.
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
	 * Tipo MIME dedotto dai magic bytes, mai dall'estensione.
	 *
	 * @param string $path Percorso del file.
	 * @return string Tipo MIME oppure stringa vuota.
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
				// Contenitore generico: distinguibile solo leggendo i brand compatibili.
				return false !== strpos( substr( $head, 16, 16 ), 'avif' ) ? 'image/avif' : 'image/heif';
			}
		}

		return '';
	}

	/**
	 * Rileva una GIF animata (piu' di un blocco immagine).
	 *
	 * @param string $path Percorso del file.
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
	 * Rileva un PNG animato (chunk acTL prima del primo IDAT).
	 *
	 * @param string $path Percorso del file.
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
	 * Comprime un percorso assoluto in forma portabile.
	 *
	 * Un trasloco del sito o un cambio di UPLOADS non deve orfanare le righe
	 * della tabella di tracciamento.
	 *
	 * @param string $path Percorso assoluto.
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
	 * Inverso di relativize().
	 *
	 * @param string $path Percorso memorizzato.
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
	 * Cartella base degli upload, senza sottocartella anno/mese.
	 *
	 * @return string
	 */
	public static function uploads_basedir() {
		$dir = wp_get_upload_dir();
		return isset( $dir['basedir'] ) ? (string) $dir['basedir'] : '';
	}

	/**
	 * URL base degli upload.
	 *
	 * @return string
	 */
	public static function uploads_baseurl() {
		$dir = wp_get_upload_dir();
		return isset( $dir['baseurl'] ) ? (string) $dir['baseurl'] : '';
	}

	/**
	 * Cartella di lavoro del plugin dentro gli upload.
	 *
	 * Volutamente dentro gli upload e non in /tmp: su host con open_basedir
	 * /tmp e' spesso irraggiungibile.
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
	 * Limite di memoria effettivo in byte.
	 *
	 * @return int Zero se illimitato.
	 */
	public static function memory_limit_bytes() {
		$raw = trim( (string) ini_get( 'memory_limit' ) );

		if ( '' === $raw || '-1' === $raw ) {
			return 0;
		}

		return (int) wp_convert_hr_to_bytes( $raw );
	}

	/**
	 * Copia i permessi della cartella contenitore sul file appena creato.
	 *
	 * @param string $file Percorso del file.
	 * @return void
	 */
	public static function inherit_permissions( $file ) {
		$stat = @stat( dirname( $file ) );

		if ( ! $stat ) {
			return;
		}

		// Stessi permessi della cartella, senza i bit di esecuzione.
		@chmod( $file, $stat['mode'] & 0000666 );
	}
}
