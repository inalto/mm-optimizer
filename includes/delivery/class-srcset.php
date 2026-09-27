<?php
/**
 * Registrazione del srcset calcolato dal nucleo.
 *
 * Non modifica nulla: annota soltanto. Riscrivere questo filtro per puntare
 * alle derivate distruggerebbe il ripiego dell'<img>, che e' l'unica rete di
 * sicurezza quando una derivata manca.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer\Delivery;

use MM_Optimizer\Options;

defined( 'ABSPATH' ) || exit;

class Srcset {

	/**
	 * Sorgenti annotate, per richiesta.
	 *
	 * @var array
	 */
	protected static $recorded = array();

	/**
	 * Registra gli agganci.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'record' ), 20, 5 );
	}

	/**
	 * Annota le sorgenti risolte dal nucleo.
	 *
	 * @param array  $sources       Sorgenti.
	 * @param array  $size_array    Dimensioni.
	 * @param string $image_src     URL principale.
	 * @param array  $image_meta    Metadati.
	 * @param int    $attachment_id Allegato.
	 * @return array Sorgenti, non modificate.
	 */
	public static function record( $sources, $size_array, $image_src, $image_meta, $attachment_id = 0 ) {
		if ( is_array( $sources ) && ! empty( $sources ) ) {
			self::$recorded[ self::key( $image_src ) ] = $sources;
		}

		return $sources;
	}

	/**
	 * Recupera le sorgenti annotate per un URL.
	 *
	 * @param string $image_src URL principale.
	 * @return array
	 */
	public static function get( $image_src ) {
		$key = self::key( $image_src );

		return isset( self::$recorded[ $key ] ) ? self::$recorded[ $key ] : array();
	}

	/**
	 * Chiave di annotazione.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected static function key( $url ) {
		return md5( (string) strtok( (string) $url, '?' ) );
	}
}
