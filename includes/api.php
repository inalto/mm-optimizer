<?php
/**
 * Public API.
 *
 * These functions live in the global namespace on purpose, so themes and
 * other plugins can use them without knowing anything about the internals.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer {

	defined( 'ABSPATH' ) || exit;
}

namespace {

	defined( 'ABSPATH' ) || exit;

	/**
	 * Reads a setting using dot notation.
	 *
	 * @param string $path    Path, for example "avif.enabled".
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	function mm_optimizer_get_option( $path, $default = null ) {
		return MM_Optimizer\Options::get( $path, $default );
	}

	/**
	 * Detected host capabilities.
	 *
	 * @param bool $force Force a fresh detection run.
	 * @return array
	 */
	function mm_optimizer_capabilities( $force = false ) {
		return MM_Optimizer\Capabilities::get( $force );
	}

	/**
	 * Recommended configuration for this host.
	 *
	 * @return array
	 */
	function mm_optimizer_recommendation() {
		return MM_Optimizer\Recommender::analyze();
	}

	/**
	 * Whether a modern format can actually be produced here.
	 *
	 * @param string $format 'webp' or 'avif'.
	 * @return bool
	 */
	function mm_optimizer_supports( $format ) {
		$caps = MM_Optimizer\Capabilities::get();
		$mime = 'avif' === $format ? 'image/avif' : 'image/webp';

		return ! empty( $caps['encoders'][ $mime ]['engine'] );
	}
}
