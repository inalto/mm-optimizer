<?php
/**
 * Codifica di un file sorgente in un formato moderno.
 *
 * Il motore non e' scelto qui: arriva dal rilevamento, che lo ha stabilito
 * misurando i byte prodotti sullo stesso campione.
 *
 * @package MM_Optimizer;
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Encoder {

	/**
	 * Codifica una sorgente in una derivata.
	 *
	 * @param string $source      Percorso della sorgente.
	 * @param string $dest        Percorso della destinazione.
	 * @param string $target_mime Tipo MIME di uscita.
	 * @param int    $quality     Qualita' 1-100.
	 * @return array {
	 *     @type bool   $ok      Esito.
	 *     @type string $engine  Motore usato.
	 *     @type string $error   Codice di errore.
	 * }
	 */
	public static function encode( $source, $dest, $target_mime, $quality ) {
		$engine = self::engine_for( $target_mime );

		if ( '' === $engine ) {
			return self::fail( '', 'no_engine' );
		}

		$dir = dirname( $dest );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return self::fail( $engine, 'mkdir_failed' );
		}

		if ( ! wp_is_writable( $dir ) ) {
			return self::fail( $engine, 'dir_unwritable' );
		}

		switch ( $engine ) {
			case 'gd':
				$ok = self::encode_gd( $source, $dest, $target_mime, $quality );
				break;
			case 'imagick':
				$ok = self::encode_imagick( $source, $dest, $target_mime, $quality );
				break;
			case 'cwebp':
			case 'avifenc':
				$ok = self::encode_binary( $engine, $source, $dest, $target_mime, $quality );
				break;
			default:
				return self::fail( $engine, 'unknown_engine' );
		}

		if ( ! $ok || ! @is_file( $dest ) ) {
			self::cleanup( $dest );
			return self::fail( $engine, 'encode_failed' );
		}

		// Un motore puo' scrivere un file del formato sbagliato senza segnalare
		// errore: si controllano i magic bytes prima di fidarsi.
		if ( Base::sniff_mime( $dest ) !== $target_mime ) {
			self::cleanup( $dest );
			return self::fail( $engine, 'wrong_format' );
		}

		Base::inherit_permissions( $dest );

		return array( 'ok' => true, 'engine' => $engine, 'error' => '' );
	}

	/**
	 * Motore risolto per un formato di uscita.
	 *
	 * @param string $target_mime Tipo MIME.
	 * @return string
	 */
	public static function engine_for( $target_mime ) {
		$format = 'image/avif' === $target_mime ? 'avif' : 'webp';
		$forced = Options::get( $format . '.engine', 'auto' );

		if ( 'auto' !== $forced ) {
			return $forced;
		}

		$caps = Capabilities::get();

		$engine = isset( $caps['encoders'][ $target_mime ]['engine'] ) ? $caps['encoders'][ $target_mime ]['engine'] : '';

		/**
		 * Filtra il motore usato per un formato di uscita.
		 *
		 * @param string $engine      Identificativo del motore.
		 * @param string $target_mime Tipo MIME di uscita.
		 */
		return (string) apply_filters( 'mm_optimizer_encoder_for', $engine, $target_mime );
	}

	/**
	 * Codifica con GD.
	 *
	 * @param string $source      Sorgente.
	 * @param string $dest        Destinazione.
	 * @param string $target_mime Tipo MIME.
	 * @param int    $quality     Qualita'.
	 * @return bool
	 */
	protected static function encode_gd( $source, $dest, $target_mime, $quality ) {
		$image = self::load_gd( $source );

		if ( ! $image ) {
			return false;
		}

		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		if ( 'image/avif' === $target_mime ) {
			$ok = @imageavif( $image, $dest, $quality, Presets::avif_speed() );
		} else {
			$ok = @imagewebp( $image, $dest, $quality );
		}

		imagedestroy( $image );

		return (bool) $ok;
	}

	/**
	 * Carica una sorgente in GD rispettando il tipo reale.
	 *
	 * @param string $source Percorso.
	 * @return resource|\GdImage|false
	 */
	protected static function load_gd( $source ) {
		switch ( Base::sniff_mime( $source ) ) {
			case 'image/jpeg':
				return @imagecreatefromjpeg( $source );

			case 'image/png':
				$image = @imagecreatefrompng( $source );
				// Un PNG a tavolozza va portato a colore pieno, altrimenti
				// WebP e AVIF perdono la trasparenza.
				if ( $image && ! imageistruecolor( $image ) ) {
					imagepalettetotruecolor( $image );
				}
				return $image;

			case 'image/gif':
				return @imagecreatefromgif( $source );

			case 'image/webp':
				return @imagecreatefromwebp( $source );
		}

		return false;
	}

	/**
	 * Codifica con Imagick.
	 *
	 * @param string $source      Sorgente.
	 * @param string $dest        Destinazione.
	 * @param string $target_mime Tipo MIME.
	 * @param int    $quality     Qualita'.
	 * @return bool
	 */
	protected static function encode_imagick( $source, $dest, $target_mime, $quality ) {
		try {
			$image = new \Imagick( $source );

			$image->setImageFormat( 'image/avif' === $target_mime ? 'AVIF' : 'WEBP' );

			if ( 'image/avif' === $target_mime ) {
				$image->setOption( 'heic:speed', (string) Presets::avif_speed() );
			} else {
				$image->setOption( 'webp:method', (string) min( 6, Presets::cwebp_method() ) );
			}

			$image->setImageCompressionQuality( $quality );
			$image->stripImage();

			$ok = $image->writeImage( $dest );

			$image->clear();
			$image->destroy();

			return (bool) $ok;
		} catch ( \Throwable $e ) {
			Log::add(
				sprintf(
					/* translators: %s: messaggio di errore della libreria. */
					__( 'Imagick ha sollevato un\'eccezione durante la codifica: %s', 'mm-optimizer' ),
					$e->getMessage()
				),
				'error'
			);

			return false;
		}
	}

	/**
	 * Codifica con un binario di sistema.
	 *
	 * @param string $engine      'cwebp' oppure 'avifenc'.
	 * @param string $source      Sorgente.
	 * @param string $dest        Destinazione.
	 * @param string $target_mime Tipo MIME.
	 * @param int    $quality     Qualita'.
	 * @return bool
	 */
	protected static function encode_binary( $engine, $source, $dest, $target_mime, $quality ) {
		$caps = Capabilities::get();
		$path = isset( $caps['binaries'][ $engine ]['path'] ) ? $caps['binaries'][ $engine ]['path'] : '';

		if ( '' === $path ) {
			return false;
		}

		if ( 'cwebp' === $engine ) {
			$argv = array(
				$path,
				'-quiet',
				'-q',
				(string) $quality,
				'-m',
				(string) Presets::cwebp_method(),
				'-metadata',
				'none',
				$source,
				'-o',
				$dest,
			);
		} else {
			$argv = array(
				$path,
				'-q',
				(string) $quality,
				'-s',
				(string) Presets::avif_speed(),
				$source,
				$dest,
			);
		}

		Capabilities::run_command( $argv, 120 );

		return @is_file( $dest );
	}

	/**
	 * Stima i byte necessari a GD per aprire un'immagine.
	 *
	 * GD tiene in memoria pixel non compressi: quattro byte per pixel piu'
	 * un margine per la copia di lavoro.
	 *
	 * @param int $width  Larghezza.
	 * @param int $height Altezza.
	 * @return int
	 */
	public static function estimate_memory( $width, $height ) {
		return (int) ( $width * $height * 4 * 1.6 );
	}

	/**
	 * Verifica che ci sia memoria a sufficienza.
	 *
	 * @param int $width  Larghezza.
	 * @param int $height Altezza.
	 * @return bool
	 */
	public static function memory_available( $width, $height ) {
		$limit = Base::memory_limit_bytes();

		if ( 0 === $limit ) {
			return true;
		}

		$needed = self::estimate_memory( $width, $height );
		$free   = $limit - memory_get_usage( true );

		return $needed < ( $free * 0.8 );
	}

	/**
	 * Rimuove una destinazione parziale.
	 *
	 * @param string $dest Percorso.
	 * @return void
	 */
	protected static function cleanup( $dest ) {
		if ( @is_file( $dest ) ) {
			wp_delete_file( $dest );
		}
	}

	/**
	 * Esito negativo.
	 *
	 * @param string $engine Motore.
	 * @param string $error  Codice.
	 * @return array
	 */
	protected static function fail( $engine, $error ) {
		return array( 'ok' => false, 'engine' => $engine, 'error' => $error );
	}
}
