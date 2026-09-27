<?php
/**
 * Scelta e attivazione della modalita' di consegna.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer\Delivery;

use MM_Optimizer\Conflicts;
use MM_Optimizer\Htaccess;
use MM_Optimizer\Options;

defined( 'ABSPATH' ) || exit;

class Delivery {

	/**
	 * Registra gli agganci della modalita' attiva.
	 *
	 * @return void
	 */
	public static function init() {
		$mode = Options::get( 'delivery.mode', 'picture' );

		if ( 'off' === $mode ) {
			return;
		}

		if ( 'htaccess' === $mode ) {
			if ( ! Conflicts::blocks( 'htaccess' ) ) {
				add_action( 'admin_init', array( 'MM_Optimizer\\Htaccess', 'sync' ) );
			}
			return;
		}

		// Modalita' <picture>.
		if ( Conflicts::blocks( 'picture' ) ) {
			return;
		}

		if ( ! self::front_end_request() ) {
			return;
		}

		Srcset::init();
		Picture::init();

		add_action( 'wp_head', array( __CLASS__, 'inline_style' ), 1 );
	}

	/**
	 * Vero solo su una richiesta di pagina pubblica.
	 *
	 * @return bool
	 */
	protected static function front_end_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		// AMP non ammette <picture>.
		if ( function_exists( 'amp_is_request' ) && amp_is_request() ) {
			return false;
		}

		return true;
	}

	/**
	 * Regola di stile indispensabile.
	 *
	 * display: contents toglie il <picture> dall'albero di formattazione, cosi'
	 * ogni regola flex, ogni posizionamento su griglia e ogni object-fit
	 * scritti per l'<img> continuano a valere. Senza, racchiudere un'immagine
	 * in un <picture> rompe i layout che davano l'<img> per figlio diretto.
	 *
	 * @return void
	 */
	public static function inline_style() {
		echo "<style id=\"mm-opt-picture\">picture.mm-opt-picture{display:contents}@supports not (display:contents){picture.mm-opt-picture{display:block;width:100%}}</style>\n";
	}
}
