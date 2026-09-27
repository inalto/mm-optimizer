<?php
/**
 * Importazione da un plugin concorrente.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Migrator {

	/**
	 * Cartella di uscita di Converter for Media.
	 *
	 * @return string
	 */
	public static function webpc_dir() {
		/**
		 * Filtra la cartella di uscita di Converter for Media.
		 *
		 * @param string $dir Percorso assoluto.
		 */
		return apply_filters( 'mm_optimizer_webpc_dir', trailingslashit( WP_CONTENT_DIR ) . 'uploads-webpc' );
	}

	/**
	 * Vero se c'e' qualcosa da importare.
	 *
	 * @return bool
	 */
	public static function has_source() {
		return false !== get_option( 'webpc_settings', false ) || is_dir( self::webpc_dir() );
	}

	/**
	 * Fotografa cosa ha prodotto il plugin precedente.
	 *
	 * @return array {
	 *     @type int    $webp     File WebP trovati.
	 *     @type int    $avif     File AVIF trovati.
	 *     @type int    $bytes    Spazio occupato.
	 *     @type array  $settings Impostazioni leggibili.
	 * }
	 */
	public static function inventory() {
		$dir = self::webpc_dir();

		$out = array(
			'webp'     => 0,
			'avif'     => 0,
			'bytes'    => 0,
			'settings' => array(),
		);

		$settings = get_option( 'webpc_settings', array() );

		if ( is_array( $settings ) ) {
			$out['settings'] = array(
				'quality' => isset( $settings['quality'] ) ? (int) $settings['quality'] : 0,
				'formats' => isset( $settings['output_formats'] ) ? (array) $settings['output_formats'] : array(),
				'method'  => isset( $settings['method'] ) ? (string) $settings['method'] : '',
			);
		}

		if ( ! is_dir( $dir ) ) {
			return $out;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$extension = strtolower( $file->getExtension() );

			if ( 'webp' === $extension ) {
				$out['webp']++;
			} elseif ( 'avif' === $extension ) {
				$out['avif']++;
			} else {
				continue;
			}

			$out['bytes'] += $file->getSize();
		}

		return $out;
	}

	/**
	 * Traduce le impostazioni del plugin precedente nelle nostre.
	 *
	 * @return array Opzioni risultanti.
	 */
	public static function import_settings() {
		$settings = get_option( 'webpc_settings', array() );

		if ( ! is_array( $settings ) ) {
			return Options::all();
		}

		$partial = array();

		$formats = isset( $settings['output_formats'] ) ? (array) $settings['output_formats'] : array();

		if ( ! empty( $formats ) ) {
			$partial['webp'] = array( 'enabled' => in_array( 'webp', $formats, true ) ? 1 : 0 );
			$partial['avif'] = array( 'enabled' => in_array( 'avif', $formats, true ) ? 1 : 0 );
		}

		// La qualita' del plugin precedente viene ricondotta al livello piu'
		// vicino, invece di essere copiata in "personalizzato": i livelli sono
		// tarati su misure reali, un singolo numero ereditato no.
		if ( ! empty( $settings['quality'] ) ) {
			$quality = (int) $settings['quality'];

			if ( $quality >= 86 ) {
				$partial['level'] = 'conservative';
			} elseif ( $quality >= 78 ) {
				$partial['level'] = 'balanced';
			} else {
				$partial['level'] = 'aggressive';
			}
		}

		Log::add( __( 'Impostazioni importate da Converter for Media.', 'mm-optimizer' ), 'info', $partial );

		return Options::apply_partial( $partial );
	}

	/**
	 * Adotta i file gia' prodotti, rinominandoli secondo il nostro schema.
	 *
	 * Converter for Media salva in un albero parallelo con il percorso
	 * ricalcato: uploads-webpc/uploads/2024/01/foto.jpg.webp. Lo schema del
	 * nome coincide con il nostro, quindi basta spostarli.
	 *
	 * @param int  $limit Numero massimo di file per passata.
	 * @param bool $copy  Copia invece di spostare.
	 * @return array {
	 *     @type int  $adopted  File adottati.
	 *     @type int  $skipped  File ignorati.
	 *     @type bool $finished Vero quando non resta altro.
	 * }
	 */
	public static function adopt( $limit = 200, $copy = true ) {
		$root = trailingslashit( self::webpc_dir() ) . 'uploads';

		$out = array( 'adopted' => 0, 'skipped' => 0, 'finished' => true );

		if ( ! is_dir( $root ) ) {
			return $out;
		}

		$uploads = trailingslashit( Base::uploads_basedir() );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		$seen = 0;

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$extension = strtolower( $file->getExtension() );

			if ( ! in_array( $extension, array( 'webp', 'avif' ), true ) ) {
				continue;
			}

			if ( $seen >= $limit ) {
				$out['finished'] = false;
				break;
			}

			$seen++;

			$relative = ltrim( str_replace( wp_normalize_path( $root ), '', wp_normalize_path( $file->getPathname() ) ), '/' );
			$target   = $uploads . $relative;

			// Il nome della sorgente si ottiene togliendo la nostra estensione.
			$source = preg_replace( '/\.' . preg_quote( $extension, '/' ) . '$/i', '', $target );

			if ( ! @is_file( $source ) ) {
				$out['skipped']++;
				continue;
			}

			if ( @is_file( $target ) ) {
				$out['skipped']++;
				continue;
			}

			$done = $copy
				? @copy( $file->getPathname(), $target )
				: @rename( $file->getPathname(), $target );

			if ( ! $done ) {
				$out['skipped']++;
				continue;
			}

			Base::inherit_permissions( $target );

			Records::save(
				array(
					'attachment_id' => 0,
					'size_name'     => '',
					'format'        => $extension,
					'source_path'   => $source,
					'source_bytes'  => (int) filesize( $source ),
					'source_hash'   => Records::hash( $source ),
					'out_bytes'     => (int) filesize( $target ),
					'status'        => Records::STATUS_DONE,
					'engine'        => 'webpc',
					'quality'       => 0,
					'error_code'    => '',
				)
			);

			$out['adopted']++;
		}

		return $out;
	}
}
