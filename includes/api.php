<?php
/**
 * API pubblica del plugin.
 *
 * Funzioni deliberatamente nello spazio dei nomi globale, cosi' che temi e
 * altri plugin possano usarle senza conoscere la struttura interna.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer {

	defined( 'ABSPATH' ) || exit;
}

namespace {

	defined( 'ABSPATH' ) || exit;

	/**
	 * Legge un'impostazione con notazione puntata.
	 *
	 * @param string $path    Percorso, ad esempio "avif.enabled".
	 * @param mixed  $default Valore di ripiego.
	 * @return mixed
	 */
	function mm_optimizer_get_option( $path, $default = null ) {
		return MM_Optimizer\Options::get( $path, $default );
	}

	/**
	 * Capacita' rilevate dell'host.
	 *
	 * @param bool $force Forza un nuovo rilevamento.
	 * @return array
	 */
	function mm_optimizer_capabilities( $force = false ) {
		return MM_Optimizer\Capabilities::get( $force );
	}

	/**
	 * Configurazione consigliata per questo host.
	 *
	 * @return array
	 */
	function mm_optimizer_recommendation() {
		return MM_Optimizer\Recommender::analyze();
	}

	/**
	 * Vero se un formato moderno e' realmente producibile qui.
	 *
	 * @param string $format 'webp' oppure 'avif'.
	 * @return bool
	 */
	function mm_optimizer_supports( $format ) {
		$caps = MM_Optimizer\Capabilities::get();
		$mime = 'avif' === $format ? 'image/avif' : 'image/webp';

		return ! empty( $caps['encoders'][ $mime ]['engine'] );
	}
}
