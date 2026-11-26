<?php
/**
 * Database helpers.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Table names and small SQL helpers. All queries with variables must use $wpdb->prepare().
 */
final class Db {

	/**
	 * Full table name for an Memberkin table on the current site.
	 *
	 * @param string $name Short name, e.g. "levels".
	 */
	public static function table_name( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'memberkin_' . preg_replace( '/[^a-z0-9_]/', '', strtolower( $name ) );
	}

	/**
	 * Current UTC time in MySQL DATETIME format.
	 */
	public static function now(): string {
		return Clock::now()->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Converts a DateTimeInterface (or null) to a UTC MySQL DATETIME string (or null).
	 *
	 * @param \DateTimeInterface|null $date Date.
	 */
	public static function datetime( ?\DateTimeInterface $date ): ?string {
		if ( null === $date ) {
			return null;
		}
		return \DateTimeImmutable::createFromInterface( $date )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Parses a UTC MySQL DATETIME string (or null / zero date) into an immutable date.
	 *
	 * @param string|null $value DB value.
	 */
	public static function to_date( ?string $value ): ?\DateTimeImmutable {
		if ( null === $value || '' === $value || str_starts_with( $value, '0000-00-00' ) ) {
			return null;
		}
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Builds a "%d,%d,%d" placeholder list for an IN() clause.
	 *
	 * @param array  $values      Values.
	 * @param string $placeholder Placeholder per value.
	 */
	public static function placeholders( array $values, string $placeholder = '%d' ): string {
		return implode( ',', array_fill( 0, max( 1, count( $values ) ), $placeholder ) );
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $name Short table name.
	 */
	public static function table_exists( string $name ): bool {
		global $wpdb;
		$table = self::table_name( $name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
