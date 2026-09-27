<?php
/**
 * Agganci del ciclo di vita degli allegati.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Attachment_Hooks {

	/**
	 * Allegati gia' presi in carico in questa richiesta.
	 *
	 * wp_generate_attachment_metadata e wp_update_attachment_metadata possono
	 * scattare entrambi per lo stesso caricamento.
	 *
	 * @var array
	 */
	protected static $seen = array();

	/**
	 * Registra gli agganci.
	 *
	 * @return void
	 */
	public static function init() {
		// Priorita' 20: dopo il nucleo e dopo i plugin che aggiungono
		// dimensioni, cosi' il piano le comprende tutte.
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_metadata' ), 20, 3 );
		add_filter( 'wp_update_attachment_metadata', array( __CLASS__, 'on_meta_update' ), 20, 2 );

		// Prima che il nucleo rimuova i file: i metadati servono ancora.
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete' ), 10, 2 );

		// Rete di sicurezza: qualunque file cancellato dal nucleo porta con se'
		// le proprie derivate. Copre la rigenerazione delle miniature e il
		// ripristino dell'originale dall'editor immagini.
		add_filter( 'wp_delete_file', array( __CLASS__, 'on_delete_file' ), 10, 1 );

		// Qualita' con cui WordPress genera le miniature: e' qui che si
		// ottimizzano, al momento in cui nascono, invece di ricomprimerle
		// dopo con una seconda perdita.
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'filter_quality' ), 10, 2 );
		add_filter( 'jpeg_quality', array( __CLASS__, 'filter_jpeg_quality' ), 10, 2 );
	}

	/**
	 * Alla creazione dei metadati.
	 *
	 * @param array  $metadata      Metadati.
	 * @param int    $attachment_id Identificativo.
	 * @param string $context       'create' oppure 'update'.
	 * @return array Metadati, non modificati.
	 */
	public static function on_metadata( $metadata, $attachment_id = 0, $context = '' ) {
		self::enqueue( (int) $attachment_id, $metadata );

		// I metadati del nucleo non vengono mai alterati.
		return $metadata;
	}

	/**
	 * All'aggiornamento dei metadati.
	 *
	 * @param array $metadata      Metadati.
	 * @param int   $attachment_id Identificativo.
	 * @return array
	 */
	public static function on_meta_update( $metadata, $attachment_id = 0 ) {
		self::enqueue( (int) $attachment_id, $metadata );

		return $metadata;
	}

	/**
	 * Mette in lavorazione un allegato.
	 *
	 * @param int   $attachment_id Identificativo.
	 * @param array $metadata      Metadati.
	 * @return void
	 */
	protected static function enqueue( $attachment_id, $metadata ) {
		if ( $attachment_id < 1 || isset( self::$seen[ $attachment_id ] ) ) {
			return;
		}

		if ( empty( $metadata['file'] ) && empty( $metadata['sizes'] ) ) {
			return;
		}

		if ( ! Converter::enabled_formats() ) {
			return;
		}

		self::$seen[ $attachment_id ] = true;

		$paths = wp_list_pluck( Converter::plan( $attachment_id, $metadata ), 'path' );

		if ( empty( $paths ) ) {
			return;
		}

		// Priorita' 5: un caricamento nuovo passa davanti a un arretrato in
		// corso di smaltimento.
		Queue::push( $paths, $attachment_id, 'upload', 5 );

		Queue::spawn();
	}

	/**
	 * Alla cancellazione di un allegato.
	 *
	 * @param int      $attachment_id Identificativo.
	 * @param \WP_Post $post          Oggetto allegato.
	 * @return void
	 */
	public static function on_delete( $attachment_id, $post = null ) {
		Converter::cleanup_attachment( (int) $attachment_id );
		Queue::remove_attachment( (int) $attachment_id );
	}

	/**
	 * A ogni file cancellato dal nucleo.
	 *
	 * @param string $file Percorso.
	 * @return string Percorso, non modificato.
	 */
	public static function on_delete_file( $file ) {
		// Mai inseguire la cancellazione di una derivata con se stessa.
		if ( preg_match( '/\.(webp|avif)$/i', (string) $file ) ) {
			return $file;
		}

		Paths::delete_derivatives( $file );
		Records::delete_for_path( $file );

		return $file;
	}

	/**
	 * Qualita' delle miniature generate da WordPress.
	 *
	 * @param int    $quality Qualita' proposta dal nucleo.
	 * @param string $mime    Tipo MIME.
	 * @return int
	 */
	public static function filter_quality( $quality, $mime = '' ) {
		$values = Presets::active();

		switch ( $mime ) {
			case 'image/webp':
				return (int) $values['webp_jpeg'];
			case 'image/avif':
				return (int) $values['avif_jpeg'];
			case 'image/jpeg':
				return (int) $values['webp_jpeg'];
		}

		return $quality;
	}

	/**
	 * Qualita' JPEG.
	 *
	 * @param int    $quality Qualita'.
	 * @param string $context Contesto.
	 * @return int
	 */
	public static function filter_jpeg_quality( $quality, $context = '' ) {
		// L'editor immagini di WordPress salva l'originale modificato: non e'
		// una miniatura e non va toccato.
		if ( 'edit_image' === $context ) {
			return $quality;
		}

		$values = Presets::active();

		return (int) $values['webp_jpeg'];
	}
}
