<?php
/**
 * Chooses and arms the delivery mode.
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
	 * Registers the hooks for the active mode.
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

		// <picture> mode.
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
	 * Whether visitors are actually served the derivatives.
	 *
	 * @return array {
	 *     @type bool   $active True when a mode is armed.
	 *     @type string $reason 'off' when switched off in the settings,
	 *                          'conflict' when another plugin blocks it.
	 * }
	 */
	public static function status() {
		$mode = Options::get( 'delivery.mode', 'picture' );

		if ( 'off' === $mode ) {
			return array( 'active' => false, 'reason' => 'off' );
		}

		if ( Conflicts::blocks( $mode ) ) {
			return array( 'active' => false, 'reason' => 'conflict' );
		}

		return array( 'active' => true, 'reason' => '' );
	}

	/**
	 * True only on a public page request.
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

		// AMP does not allow <picture>.
		if ( function_exists( 'amp_is_request' ) && amp_is_request() ) {
			return false;
		}

		return true;
	}

	/**
	 * The one stylesheet rule that matters.
	 *
	 * display: contents removes the <picture> from the box tree, so every flex
	 * rule, grid placement and object-fit written for the <img> keeps working.
	 * Without it, wrapping an image in a <picture> breaks any layout that
	 * assumed the <img> was a direct child.
	 *
	 * @return void
	 */
	public static function inline_style() {
		echo "<style id=\"mm-opt-picture\">picture.mm-opt-picture{display:contents}@supports not (display:contents){picture.mm-opt-picture{display:block;width:100%}}</style>\n";
	}
}
