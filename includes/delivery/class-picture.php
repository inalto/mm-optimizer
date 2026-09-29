<?php
/**
 * Rewrites <img> into <picture> with one srcset per format.
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
	 * Attribute set on every <img> already wrapped, so a later filter pass
	 * over the same markup leaves it alone.
	 */
	const MARKER = 'data-mm-opt';

	/**
	 * Derivative existence, memoized per request.
	 *
	 * @var array
	 */
	protected static $known = array();

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Core hands us the attachment ID: no guessing from the URL.
		add_filter( 'wp_content_img_tag', array( __CLASS__, 'filter_content_img' ), 20, 3 );
		add_filter( 'wp_get_attachment_image', array( __CLASS__, 'filter_attachment_image' ), 20, 5 );
	}

	/**
	 * Images inside post content.
	 *
	 * @param string $html    Image markup.
	 * @param string $context Context.
	 * @param int    $id      Attachment.
	 * @return string
	 */
	public static function filter_content_img( $html, $context = '', $id = 0 ) {
		return self::wrap( $html );
	}

	/**
	 * Images produced by wp_get_attachment_image().
	 *
	 * @param string       $html       Markup.
	 * @param int          $id         Attachment.
	 * @param string|array $size       Size.
	 * @param bool         $icon       Icon.
	 * @param array        $attributes Attributes.
	 * @return string
	 */
	public static function filter_attachment_image( $html, $id = 0, $size = '', $icon = false, $attributes = array() ) {
		return self::wrap( $html );
	}

	/**
	 * Wraps an <img> in a <picture>, when that makes sense.
	 *
	 * @param string $html Image markup.
	 * @return string
	 */
	public static function wrap( $html ) {
		if ( ! is_string( $html ) || '' === $html || false === strpos( $html, '<img' ) ) {
			return $html;
		}

		// Never wrap twice.
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

		// Already wrapped upstream. Builders (Divi, for one) pass the output of
		// wp_get_attachment_image() through wp_filter_content_tags(), which hands
		// the content filter the bare <img>: the <picture> around it is invisible
		// from here, so the tag itself has to carry the mark.
		if ( null !== $tags->get_attribute( self::MARKER ) ) {
			return $html;
		}

		$src = (string) $tags->get_attribute( 'src' );

		if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
			return $html;
		}

		// A lazy loader keeps the real URL in a data attribute: the src is only
		// a placeholder, and building the sources on top of it would produce a
		// <picture> pointing at the placeholder.
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

		$tags->set_attribute( self::MARKER, true );

		return '<picture class="mm-opt-picture">' . implode( '', $sources ) . $tags->get_updated_html() . '</picture>';
	}

	/**
	 * Formats that are enabled, producible and meant for delivery.
	 *
	 * Order matters: the browser takes the first source it can read, so AVIF
	 * comes before WebP.
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

		// Paths::formats() is already in preference order.
		return array_values( array_intersect( array_keys( Paths::formats() ), $out ) );
	}

	/**
	 * Extracts the URL/descriptor pairs from the image.
	 *
	 * @param string $src    Main URL.
	 * @param string $srcset The srcset attribute.
	 * @return array URL => descriptor.
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
	 * Builds a <source> for one format.
	 *
	 * All-or-nothing rule: if even one derivative is missing, the source is left
	 * out. A partial srcset makes the browser pick a lower resolution than it
	 * would have taken from the <img>, a visible regression that is nearly
	 * impossible to diagnose.
	 *
	 * @param array  $candidates URL => descriptor.
	 * @param string $format     Format.
	 * @param string $sizes      The sizes attribute.
	 * @return string Source markup, or an empty string.
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
	 * Which derivatives exist, for a set of URLs.
	 *
	 * One query per group, with a per-request memo and the object cache: a single
	 * page can ask for hundreds of these checks.
	 *
	 * @param array $urls URLs.
	 * @return array URL => array( format => bool ).
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
	 * Whether the image is handled by a lazy loader.
	 *
	 * @param \WP_HTML_Tag_Processor $tags Processor positioned on the img.
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
	 * Whether a class is in the exclusion list.
	 *
	 * @param string $class_attr The class attribute.
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
