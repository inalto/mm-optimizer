<?php
/**
 * Records the srcset computed by core.
 *
 * Changes nothing: it only takes note. Rewriting this filter to point at the
 * derivatives would destroy the <img> fallback, which is the only safety net
 * when a derivative is missing.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer\Delivery;

use MM_Optimizer\Options;

defined( 'ABSPATH' ) || exit;

class Srcset {

	/**
	 * Recorded sources, per request.
	 *
	 * @var array
	 */
	protected static $recorded = array();

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'record' ), 20, 5 );
	}

	/**
	 * Records the sources core resolved.
	 *
	 * @param array  $sources       Sources.
	 * @param array  $size_array    Dimensions.
	 * @param string $image_src     Main URL.
	 * @param array  $image_meta    Metadata.
	 * @param int    $attachment_id Attachment.
	 * @return array Sources, unchanged.
	 */
	public static function record( $sources, $size_array, $image_src, $image_meta, $attachment_id = 0 ) {
		if ( is_array( $sources ) && ! empty( $sources ) ) {
			self::$recorded[ self::key( $image_src ) ] = $sources;
		}

		return $sources;
	}

	/**
	 * Retrieves the recorded sources for a URL.
	 *
	 * @param string $image_src Main URL.
	 * @return array
	 */
	public static function get( $image_src ) {
		$key = self::key( $image_src );

		return isset( self::$recorded[ $key ] ) ? self::$recorded[ $key ] : array();
	}

	/**
	 * Record key.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected static function key( $url ) {
		return md5( (string) strtok( (string) $url, '?' ) );
	}
}
