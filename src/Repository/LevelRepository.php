<?php
/**
 * Level persistence.
 *
 * @package Memberkin
 */

namespace Memberkin\Repository;

use Memberkin\Core\Db;
use Memberkin\Domain\Level;
use Memberkin\Domain\PriceSchedule;

defined( 'ABSPATH' ) || exit;

/**
 * Levels are few and read on almost every request, so all rows are cached together.
 */
class LevelRepository {

	private const CACHE_KEY = 'levels_all';

	/**
	 * Request cache.
	 *
	 * @var array<int, Level>|null
	 */
	private ?array $all = null;

	/**
	 * All levels keyed by id, ordered by display order then name.
	 *
	 * @return array<int, Level>
	 */
	public function all(): array {
		if ( null !== $this->all ) {
			return $this->all;
		}
		$rows = wp_cache_get( self::CACHE_KEY, 'memberkin' );
		if ( ! is_array( $rows ) ) {
			global $wpdb;
			$table = Db::table_name( 'levels' );
			$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY display_order ASC, name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			wp_cache_set( self::CACHE_KEY, $rows, 'memberkin' );
		}
		$this->all = array();
		foreach ( $rows as $row ) {
			$level                   = self::hydrate( $row );
			$this->all[ $level->id ] = $level;
		}
		return $this->all;
	}

	/**
	 * Levels filtered by status and/or group.
	 *
	 * @param array{status?:string|string[],group_id?:int,purchasable?:bool} $filters Filters.
	 * @return array<int, Level>
	 */
	public function query( array $filters = array() ): array {
		return array_filter(
			$this->all(),
			static function ( Level $level ) use ( $filters ): bool {
				if ( isset( $filters['status'] ) && ! in_array( $level->status, (array) $filters['status'], true ) ) {
					return false;
				}
				if ( isset( $filters['group_id'] ) && $level->group_id !== (int) $filters['group_id'] ) {
					return false;
				}
				if ( ! empty( $filters['purchasable'] ) && ! $level->is_purchasable() ) {
					return false;
				}
				return true;
			}
		);
	}

	/**
	 * Level by id.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?Level {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Level by slug.
	 *
	 * @param string $slug Slug.
	 */
	public function find_by_slug( string $slug ): ?Level {
		foreach ( $this->all() as $level ) {
			if ( $level->slug === $slug ) {
				return $level;
			}
		}
		return null;
	}

	/**
	 * Inserts or updates a level. Returns the id.
	 *
	 * @param Level $level Level.
	 */
	public function save( Level $level ): int {
		global $wpdb;
		$now   = Db::now();
		$data  = array_merge(
			array(
				'name'          => $level->name,
				'slug'          => $level->slug,
				'description'   => $level->description,
				'group_id'      => $level->group_id,
				'status'        => $level->status,
				'allow_signups' => $level->allow_signups ? 1 : 0,
				'display_order' => $level->display_order,
				'tax_class'     => $level->tax_class,
				'updated_at'    => $now,
			),
			$level->schedule->to_row()
		);
		$table = Db::table_name( 'levels' );
		if ( $level->id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $level->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$level->id = (int) $wpdb->insert_id;
		}
		$this->flush();
		return $level->id;
	}

	/**
	 * Deletes a level and its meta.
	 *
	 * @param int $id Id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Db::table_name( 'levels' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Db::table_name( 'level_meta' ), array( 'memberkin_level_id' => $id ), array( '%d' ) );
		// phpcs:enable
		wp_cache_delete( $id, 'memberkin_level_meta' );
		$this->flush();
	}

	/**
	 * Whether a slug is taken by another level.
	 *
	 * @param string $slug       Slug.
	 * @param int    $exclude_id Level to ignore.
	 */
	public function slug_exists( string $slug, int $exclude_id = 0 ): bool {
		global $wpdb;
		$table = Db::table_name( 'levels' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s AND id <> %d LIMIT 1", $slug, $exclude_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Level meta value.
	 *
	 * @param int    $level_id Level id.
	 * @param string $key      Meta key.
	 * @param mixed  $fallback Default.
	 */
	public function meta( int $level_id, string $key, mixed $fallback = '' ): mixed {
		if ( ! metadata_exists( 'memberkin_level', $level_id, $key ) ) {
			return $fallback;
		}
		return get_metadata( 'memberkin_level', $level_id, $key, true );
	}

	/**
	 * Sets level meta.
	 *
	 * @param int    $level_id Level id.
	 * @param string $key      Meta key.
	 * @param mixed  $value    Value.
	 */
	public function set_meta( int $level_id, string $key, mixed $value ): void {
		update_metadata( 'memberkin_level', $level_id, $key, $value );
	}

	/**
	 * Clears caches.
	 */
	public function flush(): void {
		$this->all = null;
		wp_cache_delete( self::CACHE_KEY, 'memberkin' );
	}

	/**
	 * Row → entity.
	 *
	 * @param array $row DB row.
	 */
	public static function hydrate( array $row ): Level {
		return new Level(
			(int) $row['id'],
			(string) $row['name'],
			(string) $row['slug'],
			(string) ( $row['description'] ?? '' ),
			(int) $row['group_id'],
			(string) $row['status'],
			(bool) $row['allow_signups'],
			(int) $row['display_order'],
			(string) $row['tax_class'],
			PriceSchedule::from_row( $row ),
			Db::to_date( $row['created_at'] ?? null ),
			Db::to_date( $row['updated_at'] ?? null )
		);
	}
}
