<?php
/**
 * Sliding-window attempt counters.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Stores recent attempt timestamps per bucket and key in a transient (object cache when available). Keys are hashed,
 * so no IP addresses or usernames are stored in clear.
 */
final class Throttle {

	/**
	 * Transient name.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Key (IP, email…).
	 */
	private static function name( string $bucket, string $key ): string {
		return 'memberkin_thr_' . $bucket . '_' . substr( hash( 'sha256', strtolower( trim( $key ) ) . wp_salt( 'nonce' ) ), 0, 24 );
	}

	/**
	 * Recent attempts within the window.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Key.
	 * @param int    $window Seconds.
	 * @return int[] Timestamps.
	 */
	private static function recent( string $bucket, string $key, int $window ): array {
		$cutoff = Clock::timestamp() - $window;
		return array_values( array_filter( (array) get_transient( self::name( $bucket, $key ) ), static fn( $t ) => (int) $t > $cutoff ) );
	}

	/**
	 * Records an attempt; returns the number of attempts in the window (including this one).
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Key.
	 * @param int    $window Seconds.
	 */
	public static function hit( string $bucket, string $key, int $window ): int {
		if ( '' === $key ) {
			return 0;
		}
		$times   = self::recent( $bucket, $key, $window );
		$times[] = Clock::timestamp();
		$times   = array_slice( $times, -200 );
		set_transient( self::name( $bucket, $key ), $times, $window );
		return count( $times );
	}

	/**
	 * Attempts in the window.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Key.
	 * @param int    $window Seconds.
	 */
	public static function count( string $bucket, string $key, int $window ): int {
		return '' === $key ? 0 : count( self::recent( $bucket, $key, $window ) );
	}

	/**
	 * Seconds until the oldest attempt in the window expires (when the limit will relax).
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Key.
	 * @param int    $window Seconds.
	 */
	public static function retry_after( string $bucket, string $key, int $window ): int {
		$times = self::recent( $bucket, $key, $window );
		return $times ? max( 1, min( $times ) + $window - Clock::timestamp() ) : 0;
	}

	/**
	 * Forgets attempts.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Key.
	 */
	public static function clear( string $bucket, string $key ): void {
		delete_transient( self::name( $bucket, $key ) );
	}
}
