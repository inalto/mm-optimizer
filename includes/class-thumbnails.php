<?php
/**
 * Ottimizzazione senza perdita delle miniature generate da WordPress.
 *
 * Interviene soltanto sulle miniature. Il file originale e la versione
 * ridimensionata da WordPress non vengono mai toccati: sono l'unica copia
 * ancora integra, e riscriverli renderebbe irreversibile qualunque errore.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Thumbnails {

	/**
	 * Ottimizza un file, se le condizioni lo consentono.
	 *
	 * @param string $path          Percorso assoluto.
	 * @param string $size_name     Nome della dimensione.
	 * @param int    $attachment_id Allegato.
	 * @return array {
	 *     @type bool   $ok     Vero se il file e' stato ridotto.
	 *     @type int    $before Byte iniziali.
	 *     @type int    $after  Byte finali.
	 *     @type string $reason Codice se non si e' intervenuti.
	 * }
	 */
	public static function optimize( $path, $size_name = '', $attachment_id = 0 ) {
		$path = wp_normalize_path( (string) $path );

		if ( Conflicts::blocks( 'thumbnails' ) ) {
			return self::result( false, 0, 0, 'conflict' );
		}

		if ( ! self::is_thumbnail( $path, $size_name, $attachment_id ) ) {
			return self::result( false, 0, 0, 'not_a_thumbnail' );
		}

		if ( ! @is_file( $path ) || ! wp_is_writable( $path ) ) {
			return self::result( false, 0, 0, 'unwritable' );
		}

		$mime = Base::sniff_mime( $path );
		$key  = str_replace( 'image/', '', $mime );

		if ( ! Options::get( 'optimize.' . $key ) ) {
			return self::result( false, 0, 0, 'disabled' );
		}

		$caps   = Capabilities::get();
		$engine = isset( $caps['lossless'][ $mime ]['engine'] ) ? $caps['lossless'][ $mime ]['engine'] : '';

		if ( '' === $engine || empty( $caps['lossless'][ $mime ]['available'] ) ) {
			return self::result( false, 0, 0, 'no_tool' );
		}

		$before = (int) filesize( $path );

		// Si lavora su una copia: il file in uso viene sostituito solo se il
		// risultato e' valido e piu' piccolo.
		$temp = trailingslashit( Base::tmp_dir() ) . 'opt-' . wp_generate_password( 10, false ) . '.' . pathinfo( $path, PATHINFO_EXTENSION );

		if ( ! @copy( $path, $temp ) ) {
			return self::result( false, $before, $before, 'copy_failed' );
		}

		$ok = self::run_engine( $engine, $temp, $mime );

		if ( ! $ok || ! @is_file( $temp ) ) {
			@unlink( $temp );
			return self::result( false, $before, $before, 'engine_failed' );
		}

		$after = (int) filesize( $temp );

		// Il formato deve essere rimasto lo stesso, e il file deve essere
		// rileggibile: un ottimizzatore che sbaglia non deve poter sostituire
		// una miniatura valida con un file rotto.
		if ( Base::sniff_mime( $temp ) !== $mime || ! @getimagesize( $temp ) ) {
			@unlink( $temp );
			return self::result( false, $before, $before, 'invalid_output' );
		}

		if ( $after >= $before || $after < 1 ) {
			@unlink( $temp );
			return self::result( false, $before, $before, 'not_smaller' );
		}

		if ( ! @rename( $temp, $path ) ) {
			@unlink( $temp );
			return self::result( false, $before, $before, 'replace_failed' );
		}

		Base::inherit_permissions( $path );

		return self::result( true, $before, $after, '' );
	}

	/**
	 * Vero solo per un file generato da WordPress.
	 *
	 * Il confronto e' con i metadati dell'allegato, non con il nome del file:
	 * un originale chiamato "foto-800x600.jpg" non deve essere scambiato per
	 * una miniatura.
	 *
	 * @param string $path          Percorso.
	 * @param string $size_name     Nome della dimensione.
	 * @param int    $attachment_id Allegato.
	 * @return bool
	 */
	public static function is_thumbnail( $path, $size_name = '', $attachment_id = 0 ) {
		if ( 'full' === $size_name || 'original' === $size_name ) {
			return false;
		}

		if ( $attachment_id > 0 ) {
			$original = get_attached_file( $attachment_id );

			if ( $original && wp_normalize_path( $original ) === $path ) {
				return false;
			}

			$metadata = wp_get_attachment_metadata( $attachment_id );

			if ( ! empty( $metadata['original_image'] ) ) {
				$dir = trailingslashit( dirname( $path ) );

				if ( $dir . $metadata['original_image'] === $path ) {
					return false;
				}
			}

			if ( empty( $metadata['sizes'] ) ) {
				return false;
			}

			$dir   = trailingslashit( dirname( $path ) );
			$known = false;

			foreach ( (array) $metadata['sizes'] as $data ) {
				if ( ! empty( $data['file'] ) && $dir . $data['file'] === $path ) {
					$known = true;
					break;
				}
			}

			return $known;
		}

		// Senza allegato non si puo' stabilire con certezza che sia una
		// miniatura, quindi non si tocca nulla.
		return false;
	}

	/**
	 * Esegue l'ottimizzatore scelto.
	 *
	 * @param string $engine Motore.
	 * @param string $path   Percorso della copia di lavoro.
	 * @param string $mime   Tipo MIME.
	 * @return bool
	 */
	protected static function run_engine( $engine, $path, $mime ) {
		$caps = Capabilities::get();

		switch ( $engine ) {
			case 'gd':
				// Solo PNG: GD ricomprime i pixel senza alterarli e scarta i
				// metadati. Per il JPEG una ricodifica con GD sarebbe con
				// perdita, quindi non e' prevista.
				if ( 'image/png' !== $mime ) {
					return false;
				}

				$image = @imagecreatefrompng( $path );

				if ( ! $image ) {
					return false;
				}

				imagealphablending( $image, false );
				imagesavealpha( $image, true );

				$ok = @imagepng( $image, $path, 9, PNG_ALL_FILTERS );

				imagedestroy( $image );

				return (bool) $ok;

			case 'jpegoptim':
				Capabilities::run_command( array( $caps['binaries']['jpegoptim']['path'], '--strip-all', '--quiet', $path ), 60 );
				return true;

			case 'jpegtran':
				$out = $path . '.out';
				Capabilities::run_command( array( $caps['binaries']['jpegtran']['path'], '-copy', 'none', '-optimize', '-progressive', '-outfile', $out, $path ), 60 );
				if ( @is_file( $out ) ) {
					return (bool) @rename( $out, $path );
				}
				return false;

			case 'optipng':
				Capabilities::run_command( array( $caps['binaries']['optipng']['path'], '-quiet', '-o2', '-strip', 'all', $path ), 90 );
				return true;

			case 'gifsicle':
				Capabilities::run_command( array( $caps['binaries']['gifsicle']['path'], '-O3', '-b', $path ), 60 );
				return true;
		}

		return false;
	}

	/**
	 * Costruisce un esito.
	 *
	 * @param bool   $ok     Esito.
	 * @param int    $before Byte iniziali.
	 * @param int    $after  Byte finali.
	 * @param string $reason Codice.
	 * @return array
	 */
	protected static function result( $ok, $before, $after, $reason ) {
		return array(
			'ok'     => (bool) $ok,
			'before' => (int) $before,
			'after'  => (int) $after,
			'saved'  => max( 0, (int) $before - (int) $after ),
			'reason' => (string) $reason,
		);
	}
}
