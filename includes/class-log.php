<?php
/**
 * Registro diagnostico a dimensione limitata.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Log {

	const KEY = 'mm_optimizer_log';

	/**
	 * Aggiunge una voce al registro.
	 *
	 * @param string $message Messaggio, gia' in chiaro.
	 * @param string $level   'info', 'warning' oppure 'error'.
	 * @param array  $context Dati aggiuntivi.
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
	 * Voci del registro, dalla piu' recente.
	 *
	 * @param int $limit Numero massimo di voci.
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
	 * Svuota il registro.
	 *
	 * @return void
	 */
	public static function clear() {
		update_option( self::KEY, array(), false );
	}
}
