<?php
/**
 * Level group persistence.
 *
 * @package Memberkin
 */

namespace Memberkin\Repository;

use Memberkin\Core\Db;
use Memberkin\Domain\LevelGroup;

defined( 'ABSPATH' ) || exit;

/**
 * Level groups (cached as a whole).
 */
class LevelGroupRepository {

	private const CACHE_KEY = 'level_groups_all';

	/**
	 * Request cache.
	 *
	 * @var array<int, LevelGroup>|null
	 */
	private ?array $all = null;

	/**
	 * All groups keyed by id.
	 *
	 * @return array<int, LevelGroup>
	 */
	public function all(): array {
		if ( null !== $this->all ) {
			return $this->all;
		}
		$rows = wp_cache_get( self::CACHE_KEY, 'memberkin' );
		if ( ! is_array( $rows ) ) {
			global $wpdb;
			$table = Db::table_name( 'level_groups' );
			$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY display_order ASC, name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			wp_cache_set( self::CACHE_KEY, $rows, 'memberkin' );
		}
		$this->all = array();
		foreach ( $rows as $row ) {
			$this->all[ (int) $row['id'] ] = new LevelGroup(
				(int) $row['id'],
				(string) $row['name'],
				(bool) $row['allow_multiple'],
				(bool) $row['is_upgrade_path'],
				(int) $row['fallback_level_id'],
				(string) $row['proration_mode'],
				(string) $row['downgrade_timing'],
				(int) $row['display_order']
			);
		}
		return $this->all;
	}

	/**
	 * Group by id.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?LevelGroup {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Inserts or updates a group. Returns its id.
	 *
	 * @param LevelGroup $group Group.
	 */
	public function save( LevelGroup $group ): int {
		global $wpdb;
		$data  = array(
			'name'              => $group->name,
			'allow_multiple'    => $group->allow_multiple ? 1 : 0,
			'is_upgrade_path'   => $group->is_upgrade_path ? 1 : 0,
			'fallback_level_id' => $group->fallback_level_id,
			'proration_mode'    => $group->proration_mode,
			'downgrade_timing'  => $group->downgrade_timing,
			'display_order'     => $group->display_order,
			'updated_at'        => Db::now(),
		);
		$table = Db::table_name( 'level_groups' );
		if ( $group->id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $group->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$data['created_at'] = Db::now();
			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$group->id = (int) $wpdb->insert_id;
		}
		$this->flush();
		return $group->id;
	}

	/**
	 * Deletes a group.
	 *
	 * @param int $id Id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Db::table_name( 'level_groups' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->flush();
	}

	/**
	 * Clears caches.
	 */
	public function flush(): void {
		$this->all = null;
		wp_cache_delete( self::CACHE_KEY, 'memberkin' );
	}
}
