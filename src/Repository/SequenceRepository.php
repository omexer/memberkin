<?php
/**
 * Gapless number sequences (invoices, credit notes).
 *
 * @package Memberkin
 */

namespace Memberkin\Repository;

use Memberkin\Core\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic counters stored in memberkin_sequences.
 */
class SequenceRepository {

	/**
	 * Returns the next value of a sequence and advances it atomically.
	 * Uses MySQL's LAST_INSERT_ID(expr) trick so the read and the increment are one statement.
	 *
	 * @param string $name  Sequence name.
	 * @param int    $start First value for a new sequence.
	 */
	public function next( string $name, int $start = 1 ): int {
		global $wpdb;
		$table = Db::table_name( 'sequences' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (name, prefix, next_value) VALUES (%s, '', %d)", $name, max( 1, $start ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET next_value = LAST_INSERT_ID(next_value) + 1 WHERE name = %s", $name ) );
		$value = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		// phpcs:enable
		return $value;
	}

	/**
	 * Current next value without advancing (admin display).
	 *
	 * @param string $name Sequence.
	 */
	public function peek( string $name ): int {
		global $wpdb;
		$table = Db::table_name( 'sequences' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT next_value FROM {$table} WHERE name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Sets the next value (only upwards, so numbers are never reused).
	 *
	 * @param string $name  Sequence.
	 * @param int    $value Next value.
	 */
	public function bump_to( string $name, int $value ): void {
		global $wpdb;
		$table = Db::table_name( 'sequences' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (name, prefix, next_value) VALUES (%s, '', %d)", $name, max( 1, $value ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET next_value = GREATEST(next_value, %d) WHERE name = %s", $value, $name ) );
		// phpcs:enable
	}
}
