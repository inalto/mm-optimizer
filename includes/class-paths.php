<?php
/**
 * Corrispondenza fra file sorgente e derivate.
 *
 * Unico punto del plugin che conosce lo schema di denominazione. Il percorso
 * della derivata non viene mai memorizzato nel database: e' una funzione pura
 * della sorgente e del formato, e memorizzarlo lascerebbe solo che i due
 * valori divergano.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Paths {

	/**
	 * Formati producibili.
	 *
	 * @return array Formato => tipo MIME.
	 */
	public static function formats() {
		return array(
			'avif' => 'image/avif',
			'webp' => 'image/webp',
		);
	}

	/**
	 * Tipi MIME che possono fare da sorgente.
	 *
	 * @return array
	 */
	public static function source_mimes() {
		return array( 'image/jpeg', 'image/png', 'image/gif' );
	}

	/**
	 * Percorso della derivata.
	 *
	 * Lo schema aggiunge l'estensione invece di sostituirla: "foto.jpg.webp"
	 * e non "foto.webp". Sostituirla farebbe collidere foto.jpg e foto.png
	 * nella stessa cartella, e collidere con un .webp caricato davvero.
	 *
	 * @param string $source Percorso della sorgente.
	 * @param string $format 'webp' oppure 'avif'.
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
	 * URL della derivata.
	 *
	 * Operazione di sola stringa: nel percorso critico della consegna una
	 * pagina puo' richiedere centinaia di questi calcoli.
	 *
	 * @param string $url    URL della sorgente.
	 * @param string $format 'webp' oppure 'avif'.
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
	 * Cartella dell'albero parallelo.
	 *
	 * @return string
	 */
	public static function parallel_dir() {
		return trailingslashit( WP_CONTENT_DIR ) . 'mm-optimizer';
	}

	/**
	 * Tutte le derivate possibili di una sorgente, esistenti o no.
	 *
	 * @param string $source Percorso della sorgente.
	 * @return array Formato => percorso.
	 */
	public static function all_derivatives( $source ) {
		$out = array();

		foreach ( array_keys( self::formats() ) as $format ) {
			$out[ $format ] = self::derivative( $source, $format );
		}

		return $out;
	}

	/**
	 * Converte un URL degli upload nel percorso su disco.
	 *
	 * @param string $url URL.
	 * @return string Percorso assoluto, oppure stringa vuota se esterno.
	 */
	public static function url_to_path( $url ) {
		$baseurl = Base::uploads_baseurl();
		$basedir = Base::uploads_basedir();

		if ( '' === $baseurl || '' === $basedir ) {
			return '';
		}

		// Normalizza lo schema: l'URL in pagina puo' essere https mentre
		// l'URL degli upload e' http, o viceversa.
		$url     = preg_replace( '#^https?://#', '//', (string) $url );
		$baseurl = preg_replace( '#^https?://#', '//', $baseurl );

		// Scarta la stringa di interrogazione.
		$url = strtok( $url, '?' );

		if ( 0 !== strpos( $url, $baseurl ) ) {
			return '';
		}

		$relative = ltrim( substr( $url, strlen( $baseurl ) ), '/' );

		// Nessuna risalita nell'albero.
		if ( false !== strpos( $relative, '..' ) ) {
			return '';
		}

		return trailingslashit( wp_normalize_path( $basedir ) ) . $relative;
	}

	/**
	 * Rimuove tutte le derivate di una sorgente.
	 *
	 * @param string $source Percorso della sorgente.
	 * @return int Numero di file rimossi.
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
