<?php
/**
 * Optimization levels.
 *
 * The numbers come from real measurements on a 2048x1367 JPEG of 721,366 bytes:
 * WebP q82 = 400,174 B (-44.5%), WebP q75 = 293,764 B (-59.3%),
 * AVIF q60 = 261,654 B (-63.7%), AVIF q50 = 161,662 B (-77.6%).
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Presets {

	/**
	 * Level definitions.
	 *
	 * A note on cwebp_m: GD always encodes with libwebp method 4 and offers no
	 * way to change it. cwebp at -m 6 produces roughly 5-7% smaller files on
	 * photographs at twice the time, so the quality-oriented levels use it and
	 * only Aggressive drops to 4 to clear a backlog quickly.
	 *
	 * Labels are not translated here: label() resolves them at runtime, once
	 * the text domain is loaded.
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
	 * Translated label for a level.
	 *
	 * @param string $level Level identifier.
	 * @return string
	 */
	public static function label( $level ) {
		switch ( $level ) {
			case 'conservative':
				return __( 'Conservative', 'mm-optimizer' );
			case 'balanced':
				return __( 'Balanced', 'mm-optimizer' );
			case 'aggressive':
				return __( 'Aggressive', 'mm-optimizer' );
			case 'custom':
				return __( 'Custom', 'mm-optimizer' );
		}

		return $level;
	}

	/**
	 * Translated description for a level.
	 *
	 * @param string $level Level identifier.
	 * @return string
	 */
	public static function description( $level ) {
		switch ( $level ) {
			case 'conservative':
				return __( 'Highest quality, smaller savings. Suited to photography and portfolios where how the image looks matters more than what it weighs.', 'mm-optimizer' );
			case 'balanced':
				return __( 'The recommended compromise: around 45% smaller with WebP and 66% smaller with AVIF, with no visible difference on photographs.', 'mm-optimizer' );
			case 'aggressive':
				return __( 'Maximum savings: up to 78% smaller with AVIF. Fine texture and gradients may show slight artefacts.', 'mm-optimizer' );
			case 'custom':
				return __( 'Hand-set values. Use the test bench to check them against this site\'s own images.', 'mm-optimizer' );
		}

		return '';
	}

	/**
	 * Effective values for the active level, with custom values applied.
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
	 * Quality to use for a given source/target pair.
	 *
	 * @param string $source_mime Source MIME type.
	 * @param string $target_mime Target MIME type.
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
		 * Filters the encoding quality chosen for a conversion.
		 *
		 * @param int    $quality     Quality, 1-100.
		 * @param string $source_mime Source MIME type.
		 * @param string $target_mime Target MIME type.
		 */
		return (int) apply_filters( 'mm_optimizer_quality', $quality, $source_mime, $target_mime );
	}

	/**
	 * Minimum percentage saving required to keep a derivative.
	 *
	 * @return int
	 */
	public static function min_saving() {
		$values = self::active();
		return isset( $values['min_saving'] ) ? (int) $values['min_saving'] : 8;
	}

	/**
	 * Size below which a source is not even attempted.
	 *
	 * @return int
	 */
	public static function skip_bytes() {
		$values = self::active();
		return isset( $values['skip_bytes'] ) ? (int) $values['skip_bytes'] : 10240;
	}

	/**
	 * Speed parameter for the AVIF encoder.
	 *
	 * @return int
	 */
	public static function avif_speed() {
		$values = self::active();
		return isset( $values['avif_speed'] ) ? (int) $values['avif_speed'] : 6;
	}

	/**
	 * The -m parameter for cwebp.
	 *
	 * @return int
	 */
	public static function cwebp_method() {
		$values = self::active();
		return isset( $values['cwebp_m'] ) ? (int) $values['cwebp_m'] : 4;
	}

	/**
	 * Estimated saving for the UI, as a percentage, based on the measurements.
	 *
	 * Linear interpolation between the measured points; beyond the measured
	 * range the UI labels the figure as an estimate.
	 *
	 * @param string $level  Level.
	 * @param string $format 'webp' or 'avif'.
	 * @return int
	 */
	public static function estimated_saving( $level, $format ) {
		$all = self::all();

		if ( ! isset( $all[ $level ] ) || empty( $all[ $level ] ) ) {
			return 0;
		}

		$quality = 'avif' === $format ? $all[ $level ]['avif_jpeg'] : $all[ $level ]['webp_jpeg'];

		// Measured points: quality => percentage saved.
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
