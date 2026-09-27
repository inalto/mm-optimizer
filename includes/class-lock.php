<?php
/**
 * Lucchetto di esecuzione, doppio.
 *
 * Un transient soltanto non basta: con un object cache esterno uno svuotamento
 * lo fa sparire e due worker partono insieme. Un file soltanto non basta:
 * puo' restare orfano. Si usano entrambi, con la stessa chiave casuale.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Lock {

	const KEY = 'mm_opt_queue_lock';
	const TTL = 300;

	/**
	 * Chiave posseduta da questo processo.
	 *
	 * @var string
	 */
	protected static $token = '';

	/**
	 * Percorso del file di lucchetto.
	 *
	 * @return string
	 */
	protected static function file() {
		return trailingslashit( Base::tmp_dir() ) . 'queue.lock';
	}

	/**
	 * Tenta di acquisire il lucchetto.
	 *
	 * @return bool
	 */
	public static function acquire() {
		if ( self::is_held() ) {
			return false;
		}

		$token = wp_generate_password( 16, false );

		set_transient( self::KEY, $token, self::TTL );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- lucchetto: serve LOCK_EX, non la Filesystem API.
		@file_put_contents( self::file(), $token, LOCK_EX );

		// Rilettura: se due processi hanno scritto insieme, uno solo vince.
		if ( self::read_file() !== $token || get_transient( self::KEY ) !== $token ) {
			return false;
		}

		self::$token = $token;

		return true;
	}

	/**
	 * Vero se un altro processo tiene il lucchetto.
	 *
	 * @return bool
	 */
	public static function is_held() {
		if ( get_transient( self::KEY ) ) {
			return true;
		}

		$file = self::file();

		if ( ! @is_file( $file ) ) {
			return false;
		}

		// Un file piu' vecchio del TTL e' un residuo di un processo morto.
		if ( ( time() - (int) @filemtime( $file ) ) > self::TTL ) {
			@unlink( $file );
			return false;
		}

		return true;
	}

	/**
	 * Prolunga il lucchetto durante un lotto lungo.
	 *
	 * @return void
	 */
	public static function refresh() {
		if ( '' === self::$token ) {
			return;
		}

		set_transient( self::KEY, self::$token, self::TTL );
		@touch( self::file() );
	}

	/**
	 * Rilascia il lucchetto, solo se e' ancora il nostro.
	 *
	 * @return void
	 */
	public static function release() {
		if ( '' === self::$token ) {
			return;
		}

		if ( self::read_file() === self::$token ) {
			@unlink( self::file() );
		}

		if ( get_transient( self::KEY ) === self::$token ) {
			delete_transient( self::KEY );
		}

		self::$token = '';
	}

	/**
	 * Contenuto del file di lucchetto.
	 *
	 * @return string
	 */
	protected static function read_file() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- lucchetto locale.
		return @is_file( self::file() ) ? trim( (string) @file_get_contents( self::file() ) ) : '';
	}
}
