<?php
/**
 * Content access assignments.
 *
 * @package Memberkin
 */

namespace Memberkin\Repository;

use Memberkin\Core\Db;

defined( 'ABSPATH' ) || exit;

/**
 * SQL for memberkin_content_access. Assignments are cached per object.
 */
class AccessRepository {

	public const GROUP = 'memberkin_access';

	/**
	 * Levels assigned to a post or term: level_id => drip delay in days (null = immediate).
	 *
	 * @param string $object_type post|term.
	 * @param int    $object_id   Id.
	 * @return array<int, int|null>
	 */
	public function levels_for( string $object_type, int $object_id ): array {
		$key    = $object_type . '_' . $object_id;
		$cached = wp_cache_get( $key, self::GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$table = Db::table_name( 'content_access' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT level_id, drip_delay_days FROM {$table} WHERE object_type = %s AND object_id = %d", $object_type, $object_id ) );
		$levels = array();
		foreach ( $rows as $row ) {
			$levels[ (int) $row->level_id ] = null !== $row->drip_delay_days ? (int) $row->drip_delay_days : null;
		}
		wp_cache_set( $key, $levels, self::GROUP, DAY_IN_SECONDS );
		return $levels;
	}

	/**
	 * Replaces the levels assigned to a post or term.
	 *
	 * @param string               $object_type post|term.
	 * @param int                  $object_id   Id.
	 * @param array<int, int|null> $levels      level_id => drip delay days (null = immediate).
	 */
	public function set_levels( string $object_type, int $object_id, array $levels ): void {
		global $wpdb;
		$table = Db::table_name( 'content_access' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$table,
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
			),
			array( '%s', '%d' )
		);
		// phpcs:enable
		foreach ( $levels as $level_id => $delay ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'object_type'     => $object_type,
					'object_id'       => $object_id,
					'level_id'        => (int) $level_id,
					'drip_delay_days' => null === $delay ? null : max( 0, (int) $delay ),
					'created_at'      => Db::now(),
				)
			);
		}
		wp_cache_delete( $object_type . '_' . $object_id, self::GROUP );
		$this->bump();
	}

	/**
	 * Whether any assignment exists, or an add-on reports restrictions through "memberkin_access_has_restrictions" (fast path for sites without restrictions).
	 */
	public function has_any(): bool {
		$cached = wp_cache_get( 'has_any', self::GROUP );
		if ( false !== $cached ) {
			return (bool) $cached;
		}
		global $wpdb;
		$table = Db::table_name( 'content_access' );
		$any   = (bool) $wpdb->get_var( "SELECT 1 FROM {$table} LIMIT 1" ) || (bool) apply_filters( 'memberkin_access_has_restrictions', false ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		wp_cache_set( 'has_any', $any ? 1 : 0, self::GROUP, DAY_IN_SECONDS );
		return $any;
	}







	/**
	 * Invalidates compiled caches.
	 */
	public function bump(): void {
		update_option( 'memberkin_access_version', (string) microtime( true ), true );
		wp_cache_delete( 'has_any', self::GROUP );
		/**
		 * Fires when access assignments (or an add-on's restrictions) changed.
		 */
		do_action( 'memberkin_access_changed' );
	}
}
