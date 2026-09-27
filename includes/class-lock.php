<?php
/**
 * Dual execution lock.
 *
 * A transient alone is not enough: with an external object cache, a flush
 * makes it vanish and two workers start together. A lock file alone is not
 * enough either: it can be left orphaned. Both are used, with the same
 * random key.
 *
 * @package MM_Optimizer
 */

namespace MM_Optimizer;

defined( 'ABSPATH' ) || exit;

class Lock {

	const KEY = 'mm_opt_queue_lock';
	const TTL = 300;

	/**
	 * Seconds without a heartbeat after which the holder is taken for dead.
	 *
	 * The worker touches the lock file before every encode, and no single
	 * encode gets anywhere near this long.
	 */
	const STALE = 120;

	/**
	 * Key held by this process.
	 *
	 * @var string
	 */
	protected static $token = '';

	/**
	 * Path to the lock file.
	 *
	 * @return string
	 */
	protected static function file() {
		return trailingslashit( Base::tmp_dir() ) . 'queue.lock';
	}

	/**
	 * Attempts to acquire the lock.
	 *
	 * @return bool
	 */
	public static function acquire() {
		if ( self::is_held() ) {
			return false;
		}

		$token = wp_generate_password( 16, false );

		set_transient( self::KEY, $token, self::TTL );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- lock file: LOCK_EX is required, the Filesystem API cannot provide it.
		@file_put_contents( self::file(), $token, LOCK_EX );

		// Read back: if two processes wrote at once, only one wins.
		if ( self::read_file() !== $token || get_transient( self::KEY ) !== $token ) {
			return false;
		}

		self::$token = $token;

		return true;
	}

	/**
	 * Whether another process holds the lock.
	 *
	 * @return bool
	 */
	public static function is_held() {
		$file = self::file();

		clearstatcache( true, $file );

		// The file is the heartbeat: when it has gone quiet the holder died
		// without releasing (a fatal error, a killed process), and waiting
		// for the transient to expire would stall the queue for minutes.
		if ( @is_file( $file ) ) {
			if ( ( time() - (int) @filemtime( $file ) ) > self::STALE ) {
				@unlink( $file );
				delete_transient( self::KEY );
				return false;
			}

			return true;
		}

		return (bool) get_transient( self::KEY );
	}

	/**
	 * Extends the lock during a long batch.
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
	 * Releases the lock, but only if it is still ours.
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
	 * Contents of the lock file.
	 *
	 * @return string
	 */
	protected static function read_file() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- local lock file.
		return @is_file( self::file() ) ? trim( (string) @file_get_contents( self::file() ) ) : '';
	}
}
