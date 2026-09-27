<?php
/**
 * Rilevamento dei plugin concorrenti e logica di ritiro.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Conflicts {

	/**
	 * Risultato della scansione, cache di richiesta.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Plugin concorrenti noti: basename => etichetta.
	 *
	 * @return array
	 */
	public static function known() {
		return array(
			'ewww-image-optimizer/ewww-image-optimizer.php'             => 'EWWW Image Optimizer',
			'ewww-image-optimizer-cloud/ewww-image-optimizer-cloud.php' => 'EWWW Image Optimizer Cloud',
			'webp-converter-for-media/webp-converter-for-media.php'     => 'Converter for Media',
			'converter-for-media/converter-for-media.php'               => 'Converter for Media',
			'imagify/imagify.php'                                       => 'Imagify',
			'shortpixel-image-optimiser/wp-shortpixel.php'              => 'ShortPixel Image Optimizer',
			'shortpixel-adaptive-images/short-pixel-ai.php'             => 'ShortPixel Adaptive Images',
			'wp-smushit/wp-smush.php'                                   => 'Smush',
			'wp-smush-pro/wp-smush.php'                                 => 'Smush Pro',
			'optimole-wp/optimole-wp.php'                               => 'Optimole',
		);
	}

	/**
	 * Simboli che tradiscono un plugin concorrente rinominato.
	 *
	 * @return array
	 */
	protected static function symbols() {
		return array(
			'EWWW Image Optimizer' => array( 'constant' => 'EWWW_IMAGE_OPTIMIZER_VERSION' ),
			'Converter for Media'  => array( 'class' => 'WebpConverter\\Plugin_Data' ),
			'Imagify'              => array( 'constant' => 'IMAGIFY_VERSION' ),
			'ShortPixel'           => array( 'class' => 'ShortPixel\\Controller\\OptimizeController' ),
			'Smush'                => array( 'class' => 'WP_Smush' ),
			'Optimole'             => array( 'constant' => 'OPTML_VERSION' ),
		);
	}

	/**
	 * Marcatori lasciati nei file .htaccess.
	 *
	 * @return array
	 */
	protected static function markers() {
		return array(
			'BEGIN Converter for Media' => 'Converter for Media',
			'BEGIN EWWWIO'              => 'EWWW Image Optimizer',
			'EWWWIO_WEBP'               => 'EWWW Image Optimizer',
			'BEGIN ShortPixel'          => 'ShortPixel',
		);
	}

	/**
	 * Esegue la scansione completa.
	 *
	 * @return array {
	 *     @type array $plugins  Etichette dei plugin attivi.
	 *     @type array $symbols  Etichette rilevate a runtime.
	 *     @type array $htaccess Percorso => etichetta.
	 * }
	 */
	public static function scan() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$result = array(
			'plugins'  => array(),
			'symbols'  => array(),
			'htaccess' => array(),
		);

		// Segnale 1: plugin attivi. Autorevole.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( self::known() as $basename => $label ) {
			if ( is_plugin_active( $basename ) || is_plugin_active_for_network( $basename ) ) {
				$result['plugins'][ $basename ] = $label;
			}
		}

		// Segnale 2: simboli a runtime, per cartelle rinominate o copie mu-plugin.
		foreach ( self::symbols() as $label => $probe ) {
			$found = false;
			if ( isset( $probe['constant'] ) && defined( $probe['constant'] ) ) {
				$found = true;
			}
			if ( isset( $probe['class'] ) && class_exists( $probe['class'], false ) ) {
				$found = true;
			}
			if ( $found ) {
				$result['symbols'][ $label ] = $label;
			}
		}

		// Segnale 3: regole lasciate nei .htaccess, anche da plugin disattivati.
		$result['htaccess'] = self::scan_htaccess();

		self::$cache = $result;

		return $result;
	}

	/**
	 * Cerca marcatori di terze parti nei file .htaccess rilevanti.
	 *
	 * @return array Percorso => etichetta.
	 */
	protected static function scan_htaccess() {
		$cached = get_transient( 'mm_opt_htaccess_scan' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$found = array();
		$files = array(
			trailingslashit( ABSPATH ) . '.htaccess',
			trailingslashit( WP_CONTENT_DIR ) . '.htaccess',
			trailingslashit( Base::uploads_basedir() ) . '.htaccess',
		);

		foreach ( array_unique( $files ) as $file ) {
			if ( ! @is_readable( $file ) ) {
				continue;
			}

			$contents = (string) @file_get_contents( $file );
			if ( '' === $contents ) {
				continue;
			}

			foreach ( self::markers() as $marker => $label ) {
				if ( false !== strpos( $contents, $marker ) ) {
					$found[ $file ] = $label;
					break;
				}
			}
		}

		set_transient( 'mm_opt_htaccess_scan', $found, 15 * MINUTE_IN_SECONDS );

		return $found;
	}

	/**
	 * Etichette di tutti i concorrenti realmente attivi.
	 *
	 * @return array
	 */
	public static function active() {
		$scan = self::scan();

		return array_values( array_unique( array_merge( array_values( $scan['plugins'] ), array_values( $scan['symbols'] ) ) ) );
	}

	/**
	 * Vero se nessun concorrente e' attivo.
	 *
	 * @return bool
	 */
	public static function clear_field() {
		return array() === self::active();
	}

	/**
	 * Vero se nessuno ha lasciato regole di riscrittura nei .htaccess.
	 *
	 * @return bool
	 */
	public static function htaccess_free() {
		$scan = self::scan();

		return array() === $scan['htaccess'] && self::clear_field();
	}

	/**
	 * Decide se un sottosistema deve ritirarsi.
	 *
	 * La conversione resta sempre attiva: il ritiro riguarda solo la consegna
	 * e, nel caso specifico di EWWW, l'ottimizzazione in place delle miniature.
	 *
	 * @param string $subsystem 'picture', 'htaccess' oppure 'thumbnails'.
	 * @return bool
	 */
	public static function blocks( $subsystem ) {
		if ( defined( 'MM_OPTIMIZER_FORCE_DELIVERY' ) && MM_OPTIMIZER_FORCE_DELIVERY ) {
			$blocked = false;
		} else {
			switch ( $subsystem ) {
				case 'picture':
					// Le regole rimaste in un .htaccess non danneggiano l'output
					// <picture>: blocca solo un plugin realmente attivo.
					$blocked = ! self::clear_field();
					break;

				case 'htaccess':
					$blocked = ! self::htaccess_free();
					break;

				case 'thumbnails':
					// Due ottimizzatori che riscrivono lo stesso file sono un
					// rischio concreto di corruzione.
					$blocked = in_array( 'EWWW Image Optimizer', self::active(), true );
					break;

				default:
					$blocked = false;
			}
		}

		/**
		 * Filtra la decisione di ritiro per un sottosistema.
		 *
		 * @param bool   $blocked   Vero se il sottosistema deve restare spento.
		 * @param string $subsystem Identificativo del sottosistema.
		 */
		return (bool) apply_filters( 'mm_optimizer_blocks', $blocked, $subsystem );
	}

	/**
	 * Svuota le cache di rilevamento.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
		delete_transient( 'mm_opt_htaccess_scan' );
	}
}
