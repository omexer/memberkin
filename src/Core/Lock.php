<?php
/**
 * MySQL named locks.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Cross-request mutual exclusion using GET_LOCK / RELEASE_LOCK (connection scoped, auto-released on disconnect).
 */
final class Lock {

	/**
	 * Locks held by this request (re-entrant counting).
	 *
	 * @var array<string, int>
	 */
	private static array $held = array();

	/**
	 * Full lock name, scoped to the site's table prefix (max 64 chars for MySQL).
	 *
	 * @param string $name Short name.
	 */
	private static function full_name( string $name ): string {
		global $wpdb;
		$full = $wpdb->prefix . 'memberkin_' . $name;
		return strlen( $full ) > 64 ? 'memberkin_' . md5( $full ) : $full;
	}

	/**
	 * Tries to acquire a lock.
	 *
	 * @param string $name    Lock name.
	 * @param int    $timeout Seconds to wait.
	 */
	public static function acquire( string $name, int $timeout = 0 ): bool {
		if ( isset( self::$held[ $name ] ) ) {
			++self::$held[ $name ];
			return true;
		}
		global $wpdb;
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::full_name( $name ), $timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( '1' === (string) $result ) {
			self::$held[ $name ] = 1;
			return true;
		}
		return false;
	}

	/**
	 * Releases a lock.
	 *
	 * @param string $name Lock name.
	 */
	public static function release( string $name ): void {
		if ( ! isset( self::$held[ $name ] ) ) {
			return;
		}
		if ( --self::$held[ $name ] > 0 ) {
			return;
		}
		unset( self::$held[ $name ] );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::full_name( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Runs a callback while holding a lock.
	 *
	 * @param string   $name     Lock name.
	 * @param callable $callback Callback.
	 * @param int      $timeout  Seconds to wait.
	 * @return mixed Callback result.
	 * @throws LockException When the lock cannot be acquired.
	 */
	public static function with( string $name, callable $callback, int $timeout = 10 ): mixed {
		if ( ! self::acquire( $name, $timeout ) ) {
			throw new LockException( sprintf( 'Could not acquire lock "%s".', esc_html( $name ) ) );
		}
		try {
			return $callback();
		} finally {
			self::release( $name );
		}
	}
}
