<?php
/**
 * Adds subscriptions.ended_at and the date indexes used by reports.
 *
 * @package Memberkin
 */

namespace Memberkin\Core\Migrations;

use Memberkin\Core\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent: each column/index is only added when missing (fresh installs already have them from CoreTables).
 * Existing ended subscriptions are backfilled: a cancellation scheduled for the period end ended at
 * current_period_end, anything else at cancelled_at (or its last update).
 */
final class ReportingColumns implements Migration {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'core_20261124_002_reporting_columns';
	}

	/**
	 * {@inheritDoc}
	 */
	public function up(): void {
		global $wpdb;
		$subs = Db::table_name( 'subscriptions' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- schema migration on our own tables.
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$subs} LIKE %s", 'ended_at' ) ) ) {
			$wpdb->query( "ALTER TABLE {$subs} ADD COLUMN ended_at datetime NULL DEFAULT NULL AFTER cancel_reason" );
		}
		$wpdb->query(
			"UPDATE {$subs} SET ended_at = CASE WHEN cancel_at_period_end = 1 AND current_period_end IS NOT NULL THEN current_period_end ELSE COALESCE(cancelled_at, updated_at) END
			WHERE ended_at IS NULL AND status IN ('cancelled','expired','completed')"
		);
		$indexes = array(
			'subscriptions' => array(
				'created_at' => 'created_at',
				'ended_at'   => 'ended_at',
			),
			'memberships'   => array(
				'created_at' => 'created_at',
				'ended_at'   => 'ended_at',
			),
			'refunds'       => array( 'created_at' => 'created_at' ),
		);
		foreach ( $indexes as $short => $keys ) {
			$table = Db::table_name( $short );
			foreach ( $keys as $name => $column ) {
				if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $name ) ) ) {
					$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD KEY %i (%i)', $table, $name, $column ) );
				}
			}
		}
		// phpcs:enable
	}
}
