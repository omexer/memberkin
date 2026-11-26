<?php
/**
 * Clock abstraction (frozen in tests).
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * All "now" calls go through here so tests can travel in time.
 */
final class Clock {

	/**
	 * Frozen time, if any.
	 *
	 * @var \DateTimeImmutable|null
	 */
	private static ?\DateTimeImmutable $frozen = null;

	/**
	 * Current time in UTC.
	 */
	public static function now(): \DateTimeImmutable {
		if ( null !== self::$frozen ) {
			return self::$frozen;
		}
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Current Unix timestamp.
	 */
	public static function timestamp(): int {
		return self::now()->getTimestamp();
	}

	/**
	 * Freezes time (tests only).
	 *
	 * @param \DateTimeImmutable|string $at Moment.
	 */
	public static function freeze( \DateTimeImmutable|string $at ): void {
		self::$frozen = is_string( $at ) ? new \DateTimeImmutable( $at, new \DateTimeZone( 'UTC' ) ) : $at->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Releases frozen time.
	 */
	public static function unfreeze(): void {
		self::$frozen = null;
	}
}
