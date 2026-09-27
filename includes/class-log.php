<?php
/**
 * Bounded diagnostic log.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Log {

	const KEY = 'mm_optimizer_log';

	/**
	 * Appends an entry to the log.
	 *
	 * @param string $message Message, already in plain text.
	 * @param string $level   'info', 'warning' or 'error'.
	 * @param array  $context Additional data.
	 * @return void
	 */
	public static function add( $message, $level = 'info', array $context = array() ) {
		if ( ! Options::get( 'log_enabled', 1 ) ) {
			return;
		}

		$entries = get_option( self::KEY, array() );
		if ( ! is_array( $entries ) ) {
			$entries = array();
		}

		$entries[] = array(
			'time'    => time(),
			'level'   => in_array( $level, array( 'info', 'warning', 'error' ), true ) ? $level : 'info',
			'message' => (string) $message,
			'context' => $context,
		);

		$max = (int) Options::get( 'log_max', 200 );
		if ( count( $entries ) > $max ) {
			$entries = array_slice( $entries, -$max );
		}

		update_option( self::KEY, $entries, false );
	}

	/**
	 * Log entries, newest first.
	 *
	 * @param int $limit Maximum number of entries.
	 * @return array
	 */
	public static function entries( $limit = 100 ) {
		$entries = get_option( self::KEY, array() );

		if ( ! is_array( $entries ) ) {
			return array();
		}

		return array_slice( array_reverse( $entries ), 0, (int) $limit );
	}

	/**
	 * Empties the log.
	 *
	 * @return void
	 */
	public static function clear() {
		update_option( self::KEY, array(), false );
	}
}
