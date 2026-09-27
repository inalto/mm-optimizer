<?php
/**
 * Livelli di ottimizzazione.
 *
 * I numeri derivano da misurazioni reali su un JPEG 2048x1367 da 721.366 byte:
 * WebP q82 = 400.174 B (-44,5%), WebP q75 = 293.764 B (-59,3%),
 * AVIF q60 = 261.654 B (-63,7%), AVIF q50 = 161.662 B (-77,6%).
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Presets {

	/**
	 * Definizione dei livelli.
	 *
	 * Nota su cwebp_m: GD codifica sempre con il metodo libwebp 4 e non e'
	 * configurabile. cwebp con -m 6 produce circa il 5-7% in meno sulle
	 * fotografie al costo del doppio del tempo, per cui i livelli che puntano
	 * alla qualita' lo usano e solo Aggressivo scende a 4 per smaltire in
	 * fretta un arretrato.
	 *
	 * Le etichette non sono tradotte qui: vengono risolte da label() a
	 * runtime, quando il textdomain e' caricato.
	 *
	 * @return array
	 */
	public static function all() {
		return array(
			'conservative' => array(
				'webp_jpeg'  => 90,
				'webp_png'   => 100,
				'avif_jpeg'  => 68,
				'avif_png'   => 75,
				'avif_speed' => 4,
				'cwebp_m'    => 6,
				'min_saving' => 5,
				'skip_bytes' => 4096,
			),
			'balanced'     => array(
				'webp_jpeg'  => 82,
				'webp_png'   => 85,
				'avif_jpeg'  => 58,
				'avif_png'   => 65,
				'avif_speed' => 6,
				'cwebp_m'    => 6,
				'min_saving' => 8,
				'skip_bytes' => 10240,
			),
			'aggressive'   => array(
				'webp_jpeg'  => 75,
				'webp_png'   => 80,
				'avif_jpeg'  => 50,
				'avif_png'   => 55,
				'avif_speed' => 8,
				'cwebp_m'    => 4,
				'min_saving' => 10,
				'skip_bytes' => 10240,
			),
			'custom'       => array(),
		);
	}

	/**
	 * Etichetta tradotta di un livello.
	 *
	 * @param string $level Identificativo del livello.
	 * @return string
	 */
	public static function label( $level ) {
		switch ( $level ) {
			case 'conservative':
				return __( 'Conservativo', 'mm-optimizer' );
			case 'balanced':
				return __( 'Bilanciato', 'mm-optimizer' );
			case 'aggressive':
				return __( 'Aggressivo', 'mm-optimizer' );
			case 'custom':
				return __( 'Personalizzato', 'mm-optimizer' );
		}

		return $level;
	}

	/**
	 * Descrizione tradotta di un livello.
	 *
	 * @param string $level Identificativo del livello.
	 * @return string
	 */
	public static function description( $level ) {
		switch ( $level ) {
			case 'conservative':
				return __( 'Qualita\' massima, risparmio piu\' contenuto. Adatto a fotografia e portfolio dove la resa viene prima del peso.', 'mm-optimizer' );
			case 'balanced':
				return __( 'Il compromesso consigliato: circa il 45% in meno con WebP e il 67% in meno con AVIF, senza differenze visibili sulle fotografie.', 'mm-optimizer' );
			case 'aggressive':
				return __( 'Risparmio massimo: fino al 78% in meno con AVIF. Su texture fini e gradienti possono comparire lievi artefatti.', 'mm-optimizer' );
			case 'custom':
				return __( 'Valori impostati a mano. Usa il banco di prova per verificarli sulle immagini di questo sito.', 'mm-optimizer' );
		}

		return '';
	}

	/**
	 * Valori effettivi del livello attivo, con i personalizzati applicati.
	 *
	 * @return array
	 */
	public static function active() {
		$level = Options::get( 'level', 'balanced' );

		if ( 'custom' === $level ) {
			return Options::get( 'custom', self::all()['balanced'] );
		}

		$all = self::all();

		return isset( $all[ $level ] ) ? $all[ $level ] : $all['balanced'];
	}

	/**
	 * Qualita' da usare per una data coppia sorgente/destinazione.
	 *
	 * @param string $source_mime Tipo MIME della sorgente.
	 * @param string $target_mime Tipo MIME della destinazione.
	 * @return int
	 */
	public static function quality( $source_mime, $target_mime ) {
		$values = self::active();
		$is_png = ( 'image/png' === $source_mime );

		if ( 'image/avif' === $target_mime ) {
			$quality = $is_png ? $values['avif_png'] : $values['avif_jpeg'];
		} else {
			$quality = $is_png ? $values['webp_png'] : $values['webp_jpeg'];
		}

		/**
		 * Filtra la qualita' di codifica scelta per una conversione.
		 *
		 * @param int    $quality     Qualita' 1-100.
		 * @param string $source_mime Tipo MIME della sorgente.
		 * @param string $target_mime Tipo MIME della destinazione.
		 */
		return (int) apply_filters( 'mm_optimizer_quality', $quality, $source_mime, $target_mime );
	}

	/**
	 * Risparmio minimo percentuale per conservare una derivata.
	 *
	 * @return int
	 */
	public static function min_saving() {
		$values = self::active();
		return isset( $values['min_saving'] ) ? (int) $values['min_saving'] : 8;
	}

	/**
	 * Dimensione sotto la quale una sorgente non viene nemmeno tentata.
	 *
	 * @return int
	 */
	public static function skip_bytes() {
		$values = self::active();
		return isset( $values['skip_bytes'] ) ? (int) $values['skip_bytes'] : 10240;
	}

	/**
	 * Parametro di velocita' per l'encoder AVIF.
	 *
	 * @return int
	 */
	public static function avif_speed() {
		$values = self::active();
		return isset( $values['avif_speed'] ) ? (int) $values['avif_speed'] : 6;
	}

	/**
	 * Parametro -m per cwebp.
	 *
	 * @return int
	 */
	public static function cwebp_method() {
		$values = self::active();
		return isset( $values['cwebp_m'] ) ? (int) $values['cwebp_m'] : 4;
	}

	/**
	 * Risparmio stimato per la UI, in percentuale, sulla base delle misure.
	 *
	 * Interpolazione lineare fra i punti misurati; oltre l'intervallo misurato
	 * il valore e' dichiarato come stima nella UI.
	 *
	 * @param string $level  Livello.
	 * @param string $format 'webp' oppure 'avif'.
	 * @return int
	 */
	public static function estimated_saving( $level, $format ) {
		$all = self::all();

		if ( ! isset( $all[ $level ] ) || empty( $all[ $level ] ) ) {
			return 0;
		}

		$quality = 'avif' === $format ? $all[ $level ]['avif_jpeg'] : $all[ $level ]['webp_jpeg'];

		// Punti misurati: qualita' => risparmio percentuale.
		$measured = 'avif' === $format
			? array( 50 => 77.6, 60 => 63.7 )
			: array( 75 => 59.3, 82 => 44.5 );

		$points = array_keys( $measured );
		$low    = min( $points );
		$high   = max( $points );

		if ( $quality <= $low ) {
			$slope = ( $measured[ $low ] - $measured[ $high ] ) / ( $high - $low );
			return (int) round( $measured[ $low ] + ( $low - $quality ) * $slope );
		}

		if ( $quality >= $high ) {
			$slope = ( $measured[ $low ] - $measured[ $high ] ) / ( $high - $low );
			return (int) round( max( 0, $measured[ $high ] - ( $quality - $high ) * $slope ) );
		}

		$ratio = ( $quality - $low ) / ( $high - $low );

		return (int) round( $measured[ $low ] + ( $measured[ $high ] - $measured[ $low ] ) * $ratio );
	}
}
