<?php
/**
 * Riscrittura di <img> in <picture> con un srcset per formato.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer\Delivery;

use MM_Optimizer\Base;
use MM_Optimizer\Conflicts;
use MM_Optimizer\Converter;
use MM_Optimizer\Options;
use MM_Optimizer\Paths;
use MM_Optimizer\Records;

defined( 'ABSPATH' ) || exit;

class Picture {

	/**
	 * Esistenza delle derivate, memorizzata per richiesta.
	 *
	 * @var array
	 */
	protected static $known = array();

	/**
	 * Registra gli agganci.
	 *
	 * @return void
	 */
	public static function init() {
		// Il nucleo passa l'identificativo dell'allegato: nessuna congettura
		// sull'URL.
		add_filter( 'wp_content_img_tag', array( __CLASS__, 'filter_content_img' ), 20, 3 );
		add_filter( 'wp_get_attachment_image', array( __CLASS__, 'filter_attachment_image' ), 20, 5 );
	}

	/**
	 * Immagini dentro il contenuto.
	 *
	 * @param string $html    Markup dell'immagine.
	 * @param string $context Contesto.
	 * @param int    $id      Allegato.
	 * @return string
	 */
	public static function filter_content_img( $html, $context = '', $id = 0 ) {
		return self::wrap( $html );
	}

	/**
	 * Immagini generate da wp_get_attachment_image().
	 *
	 * @param string       $html       Markup.
	 * @param int          $id         Allegato.
	 * @param string|array $size       Dimensione.
	 * @param bool         $icon       Icona.
	 * @param array        $attributes Attributi.
	 * @return string
	 */
	public static function filter_attachment_image( $html, $id = 0, $size = '', $icon = false, $attributes = array() ) {
		return self::wrap( $html );
	}

	/**
	 * Racchiude un <img> in un <picture>, se ha senso farlo.
	 *
	 * @param string $html Markup dell'immagine.
	 * @return string
	 */
	public static function wrap( $html ) {
		if ( ! is_string( $html ) || '' === $html || false === strpos( $html, '<img' ) ) {
			return $html;
		}

		// Mai raddoppiare l'involucro.
		if ( false !== stripos( $html, '<picture' ) ) {
			return $html;
		}

		$formats = self::deliverable_formats();

		if ( empty( $formats ) ) {
			return $html;
		}

		if ( ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$tags = new \WP_HTML_Tag_Processor( $html );

		if ( ! $tags->next_tag( 'img' ) ) {
			return $html;
		}

		$src = (string) $tags->get_attribute( 'src' );

		if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
			return $html;
		}

		// Un caricatore differito tiene l'URL vero in un attributo dati: il
		// src e' solo un segnaposto, e costruirci sopra le sorgenti darebbe
		// un <picture> che punta al segnaposto.
		if ( self::is_lazy_placeholder( $tags ) ) {
			return $html;
		}

		if ( self::is_excluded( (string) $tags->get_attribute( 'class' ) ) ) {
			return $html;
		}

		$srcset = (string) $tags->get_attribute( 'srcset' );
		$sizes  = (string) $tags->get_attribute( 'sizes' );

		$candidates = self::candidates( $src, $srcset );

		if ( empty( $candidates ) ) {
			return $html;
		}

		$sources = array();

		foreach ( $formats as $format ) {
			$built = self::build_source( $candidates, $format, $sizes );

			if ( '' !== $built ) {
				$sources[] = $built;
			}
		}

		if ( empty( $sources ) ) {
			return $html;
		}

		return '<picture class="mm-opt-picture">' . implode( '', $sources ) . $html . '</picture>';
	}

	/**
	 * Formati abilitati, producibili e da consegnare.
	 *
	 * L'ordine conta: il browser prende la prima sorgente che sa leggere,
	 * quindi AVIF va prima di WebP.
	 *
	 * @return array
	 */
	protected static function deliverable_formats() {
		$out = array();

		foreach ( Converter::enabled_formats() as $format ) {
			if ( Options::get( $format . '.deliver' ) ) {
				$out[] = $format;
			}
		}

		// Paths::formats() e' gia' in ordine di preferenza.
		return array_values( array_intersect( array_keys( Paths::formats() ), $out ) );
	}

	/**
	 * Estrae le coppie URL/descrittore dell'immagine.
	 *
	 * @param string $src    URL principale.
	 * @param string $srcset Attributo srcset.
	 * @return array URL => descrittore.
	 */
	protected static function candidates( $src, $srcset ) {
		$out = array();

		if ( '' !== $srcset ) {
			foreach ( explode( ',', $srcset ) as $part ) {
				$part = trim( $part );

				if ( '' === $part ) {
					continue;
				}

				$bits = preg_split( '/\s+/', $part, 2 );
				$url  = $bits[0];

				if ( '' !== $url ) {
					$out[ $url ] = isset( $bits[1] ) ? trim( $bits[1] ) : '';
				}
			}
		}

		if ( empty( $out ) ) {
			$out[ $src ] = '';
		}

		return $out;
	}

	/**
	 * Costruisce una sorgente per un formato.
	 *
	 * Regola del tutto o niente: se manca anche una sola derivata, la sorgente
	 * viene omessa. Un srcset parziale fa scegliere al browser una risoluzione
	 * piu' bassa di quella che avrebbe preso dall'<img>, con un peggioramento
	 * visibile e quasi impossibile da diagnosticare.
	 *
	 * @param array  $candidates URL => descrittore.
	 * @param string $format     Formato.
	 * @param string $sizes      Attributo sizes.
	 * @return string Markup della sorgente, oppure stringa vuota.
	 */
	protected static function build_source( array $candidates, $format, $sizes ) {
		$available = self::availability( array_keys( $candidates ) );
		$entries   = array();

		foreach ( $candidates as $url => $descriptor ) {
			if ( empty( $available[ $url ][ $format ] ) ) {
				if ( ! Options::get( 'delivery.partial_srcset' ) ) {
					return '';
				}
				continue;
			}

			$target = Paths::derivative_url( $url, $format );

			$entries[] = '' !== $descriptor ? $target . ' ' . $descriptor : $target;
		}

		if ( empty( $entries ) ) {
			return '';
		}

		$source = '<source type="' . esc_attr( Paths::formats()[ $format ] ) . '" srcset="' . esc_attr( implode( ', ', $entries ) ) . '"';

		if ( '' !== $sizes ) {
			$source .= ' sizes="' . esc_attr( $sizes ) . '"';
		}

		return $source . ' />';
	}

	/**
	 * Quali derivate esistono, per un insieme di URL.
	 *
	 * Una sola interrogazione per gruppo, con memoria di richiesta e cache
	 * oggetti: una pagina puo' richiedere centinaia di queste verifiche.
	 *
	 * @param array $urls URL.
	 * @return array URL => array( formato => bool ).
	 */
	protected static function availability( array $urls ) {
		$out     = array();
		$lookup  = array();
		$missing = array();

		foreach ( $urls as $url ) {
			if ( isset( self::$known[ $url ] ) ) {
				$out[ $url ] = self::$known[ $url ];
				continue;
			}

			$path = Paths::url_to_path( $url );

			if ( '' === $path ) {
				$out[ $url ]        = array();
				self::$known[ $url ] = array();
				continue;
			}

			$lookup[ $url ] = $path;
			$missing[]      = $path;
		}

		if ( empty( $missing ) ) {
			return $out;
		}

		$rows    = Records::available_for( $missing );
		$verify  = (bool) Options::get( 'delivery.verify_disk' );

		foreach ( $lookup as $url => $path ) {
			$relative = Base::relativize( $path );
			$formats  = isset( $rows[ $relative ] ) ? $rows[ $relative ] : array();

			$entry = array();

			foreach ( $formats as $format ) {
				$entry[ $format ] = $verify ? @is_file( Paths::derivative( $path, $format ) ) : true;
			}

			$out[ $url ]         = $entry;
			self::$known[ $url ] = $entry;
		}

		return $out;
	}

	/**
	 * Vero se l'immagine e' gestita da un caricatore differito.
	 *
	 * @param \WP_HTML_Tag_Processor $tags Processore posizionato sull'img.
	 * @return bool
	 */
	protected static function is_lazy_placeholder( $tags ) {
		if ( 'skip' !== Options::get( 'delivery.lazy_mode', 'skip' ) ) {
			return false;
		}

		foreach ( array( 'data-src', 'data-lazy-src', 'data-srcset', 'data-lazy-srcset' ) as $attribute ) {
			if ( null !== $tags->get_attribute( $attribute ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Vero se una classe e' nell'elenco di esclusione.
	 *
	 * @param string $class_attr Attributo class.
	 * @return bool
	 */
	protected static function is_excluded( $class_attr ) {
		$excluded = (array) Options::get( 'delivery.exclude_classes', array() );

		if ( empty( $excluded ) || '' === $class_attr ) {
			return false;
		}

		$classes = preg_split( '/\s+/', $class_attr );

		return (bool) array_intersect( $classes, $excluded );
	}
}
