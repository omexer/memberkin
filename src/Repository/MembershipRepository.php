<?php
/**
 * Membership persistence.
 *
 * @package Memberkin
 */

namespace Memberkin\Repository;

use Memberkin\Core\Db;
use Memberkin\Domain\Membership;
use Memberkin\Domain\PriceSchedule;

defined( 'ABSPATH' ) || exit;

/**
 * All SQL for memberkin_memberships. A user's current memberships are cached per user.
 */
class MembershipRepository {

	private const USER_CACHE_GROUP = 'memberkin_user_memberships';

	/**
	 * Membership by id.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?Membership {
		global $wpdb;
		$table = Db::table_name( 'memberships' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Current (not ended) memberships of a user, cached.
	 *
	 * @param int $user_id User id.
	 * @return Membership[]
	 */
	public function current_for_user( int $user_id ): array {
		$rows = wp_cache_get( $user_id, self::USER_CACHE_GROUP );
		if ( ! is_array( $rows ) ) {
			global $wpdb;
			$table = Db::table_name( 'memberships' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND status <> %s ORDER BY starts_at ASC, id ASC", $user_id, Membership::ENDED ), ARRAY_A );
			wp_cache_set( $user_id, $rows, self::USER_CACHE_GROUP, HOUR_IN_SECONDS );
		}
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * All memberships of a user (history included), newest first.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Max rows.
	 * @return Membership[]
	 */
	public function all_for_user( int $user_id, int $limit = 200 ): array {
		global $wpdb;
		$table = Db::table_name( 'memberships' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d", $user_id, $limit ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Whether the user has ever had a membership of a level with a trial (any status).
	 *
	 * @param int $user_id  User id.
	 * @param int $level_id Level id.
	 */
	public function user_had_trial( int $user_id, int $level_id ): bool {
		global $wpdb;
		$table = Db::table_name( 'memberships' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE user_id = %d AND level_id = %d AND trial_length > 0 AND status NOT IN (%s, %s) LIMIT 1", $user_id, $level_id, Membership::PENDING, Membership::PENDING_APPROVAL ) );
	}

	/**
	 * Inserts a membership; sets its id.
	 *
	 * @param Membership $m Membership.
	 */
	public function insert( Membership $m ): int {
		global $wpdb;
		$now  = Db::now();
		$data = array_merge(
			$this->to_row( $m ),
			array(
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$wpdb->insert( Db::table_name( 'memberships' ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$m->id = (int) $wpdb->insert_id;
		$this->flush_user( $m->user_id );
		return $m->id;
	}

	/**
	 * Updates columns. With $expected_status the update only applies if the row still has that status
	 * (optimistic concurrency); returns whether a row changed.
	 *
	 * @param Membership  $m               Membership (its user cache is flushed).
	 * @param array       $fields          Column => value (DateTimeInterface values are converted).
	 * @param string|null $expected_status Required current status.
	 */
	public function update( Membership $m, array $fields, ?string $expected_status = null ): bool {
		global $wpdb;
		foreach ( $fields as $key => $value ) {
			if ( $value instanceof \DateTimeInterface ) {
				$fields[ $key ] = Db::datetime( $value );
			}
		}
		$fields['updated_at'] = Db::now();
		$where                = array( 'id' => $m->id );
		if ( null !== $expected_status ) {
			$where['status'] = $expected_status;
		}
		$changed = $wpdb->update( Db::table_name( 'memberships' ), $fields, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->flush_user( $m->user_id );
		return (bool) $changed;
	}

	/**
	 * Paged query for admin lists and the REST API.
	 *
	 * @param array $args {
	 *     Arguments.
	 *
	 *     @type int[]    $level_ids Levels.
	 *     @type string[] $statuses  Statuses.
	 *     @type int      $user_id   User.
	 *     @type string   $search    Search in user login/email/display name.
	 *     @type string   $orderby   created_at|starts_at|ends_at|id.
	 *     @type string   $order     ASC|DESC.
	 *     @type int      $per_page  Rows per page.
	 *     @type int      $page      Page (1-based).
	 * }
	 * @return array{items: Membership[], total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;
		$table  = Db::table_name( 'memberships' );
		$where  = array( '1=1' );
		$params = array();
		$join   = '';

		if ( ! empty( $args['level_ids'] ) ) {
			$ids     = array_map( 'intval', (array) $args['level_ids'] );
			$where[] = 'm.level_id IN (' . Db::placeholders( $ids ) . ')';
			$params  = array_merge( $params, $ids );
		}
		if ( ! empty( $args['statuses'] ) ) {
			$statuses = array_map( 'strval', (array) $args['statuses'] );
			$where[]  = 'm.status IN (' . Db::placeholders( $statuses, '%s' ) . ')';
			$params   = array_merge( $params, $statuses );
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'm.user_id = %d';
			$params[] = (int) $args['user_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$join     = " INNER JOIN {$wpdb->users} u ON u.ID = m.user_id";
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$orderby  = in_array( $args['orderby'] ?? '', array( 'created_at', 'starts_at', 'ends_at', 'id' ), true ) ? $args['orderby'] : 'created_at';
		$order    = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- list filters: the WHERE clause is joined from constant fragments with placeholders and every value is bound by prepare(); SQL assembled from fixed fragments with placeholders; ORDER BY values come from allow-lists.
		$count_sql = "SELECT COUNT(*) FROM {$table} m{$join} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$list_sql  = "SELECT m.* FROM {$table} m{$join} WHERE {$where_sql} ORDER BY m.{$orderby} {$order}, m.id {$order} LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'items' => array_map( array( self::class, 'hydrate' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * Number of access-granting memberships per level.
	 *
	 * @return array<int, int>
	 */
	public function active_counts_by_level(): array {
		global $wpdb;
		$table = Db::table_name( 'memberships' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT level_id, COUNT(*) AS c FROM {$table} WHERE status IN (%s, %s, %s) GROUP BY level_id", Membership::ACTIVE, Membership::CANCELLING, Membership::PAST_DUE ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->level_id ] = (int) $row->c;
		}
		return $out;
	}

	/**
	 * Memberships whose end date passed (or whose grace period ran out) and that still look current.
	 *
	 * @param \DateTimeImmutable $now   Now.
	 * @param int                $limit Batch size.
	 * @return Membership[]
	 */
	public function due_for_expiry( \DateTimeImmutable $now, int $limit = 200 ): array {
		global $wpdb;
		$table = Db::table_name( 'memberships' );
		$at    = Db::datetime( $now );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"(SELECT * FROM {$table} WHERE status IN (%s, %s, %s, %s) AND ends_at IS NOT NULL AND ends_at <= %s ORDER BY ends_at ASC LIMIT %d)
				UNION
				(SELECT * FROM {$table} WHERE status = %s AND grace_until IS NOT NULL AND grace_until <= %s ORDER BY grace_until ASC LIMIT %d)",
				Membership::ACTIVE,
				Membership::CANCELLING,
				Membership::PAUSED,
				Membership::SUSPENDED,
				$at,
				$limit,
				Membership::PAST_DUE,
				$at,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Paused memberships whose resume date passed.
	 *
	 * @param \DateTimeImmutable $now   Now.
	 * @param int                $limit Batch size.
	 * @return Membership[]
	 */
	public function due_for_resume( \DateTimeImmutable $now, int $limit = 200 ): array {
		global $wpdb;
		$table = Db::table_name( 'memberships' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND resume_at IS NOT NULL AND resume_at <= %s ORDER BY resume_at ASC LIMIT %d", Membership::PAUSED, Db::datetime( $now ), $limit ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Memberships ending within a window (for reminders).
	 *
	 * @param \DateTimeImmutable $from  From.
	 * @param \DateTimeImmutable $to    To.
	 * @param int                $limit Batch size.
	 * @return Membership[]
	 */
	public function ending_between( \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 500 ): array {
		global $wpdb;
		$table = Db::table_name( 'memberships' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN (%s, %s) AND ends_at > %s AND ends_at <= %s ORDER BY ends_at ASC LIMIT %d", Membership::ACTIVE, Membership::CANCELLING, Db::datetime( $from ), Db::datetime( $to ), $limit ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Child memberships (group seats) of a parent.
	 *
	 * @param int $parent_id Parent membership id.
	 * @return Membership[]
	 */
	public function children_of( int $parent_id ): array {
		global $wpdb;
		$table = Db::table_name( 'memberships' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE parent_membership_id = %d AND status <> %s", $parent_id, Membership::ENDED ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Clears a user's cache.
	 *
	 * @param int $user_id User id.
	 */
	public function flush_user( int $user_id ): void {
		wp_cache_delete( $user_id, self::USER_CACHE_GROUP );
		/**
		 * Fires when a user's memberships changed (caches depending on them should be cleared).
		 *
		 * @param int $user_id User id.
		 */
		do_action( 'memberkin_user_memberships_flushed', $user_id );
	}

	/**
	 * Entity → row (without id/timestamps).
	 *
	 * @param Membership $m Membership.
	 * @return array<string, mixed>
	 */
	private function to_row( Membership $m ): array {
		$s = $m->schedule;
		return array(
			'user_id'              => $m->user_id,
			'level_id'             => $m->level_id,
			'status'               => $m->status,
			'source'               => $m->source,
			'subscription_id'      => $m->subscription_id,
			'initial_order_id'     => $m->initial_order_id,
			'parent_membership_id' => $m->parent_membership_id,
			'coupon_id'            => $m->coupon_id,
			'currency'             => $s->currency,
			'initial_amount'       => $s->initial_amount + $s->signup_fee,
			'recurring_amount'     => $s->recurring_amount,
			'billing_interval'     => $s->billing_interval,
			'billing_period'       => $s->billing_period,
			'billing_cycles'       => $s->billing_cycles,
			'trial_amount'         => $s->trial_amount,
			'trial_length'         => $s->trial_length,
			'trial_period'         => $s->trial_period,
			'starts_at'            => Db::datetime( $m->starts_at ),
			'ends_at'              => Db::datetime( $m->ends_at ),
			'grace_until'          => Db::datetime( $m->grace_until ),
			'paused_at'            => Db::datetime( $m->paused_at ),
			'resume_at'            => Db::datetime( $m->resume_at ),
			'suspended_at'         => Db::datetime( $m->suspended_at ),
			'ended_at'             => Db::datetime( $m->ended_at ),
			'end_reason'           => $m->end_reason,
		);
	}

	/**
	 * Row → entity.
	 *
	 * @param array $row Row.
	 */
	public static function hydrate( array $row ): Membership {
		return new Membership(
			(int) $row['id'],
			(int) $row['user_id'],
			(int) $row['level_id'],
			(string) $row['status'],
			(string) $row['source'],
			PriceSchedule::from_row( $row ),
			Db::to_date( $row['starts_at'] ) ?? new \DateTimeImmutable( '@0' ),
			Db::to_date( $row['ends_at'] ),
			null !== $row['subscription_id'] ? (int) $row['subscription_id'] : null,
			null !== $row['initial_order_id'] ? (int) $row['initial_order_id'] : null,
			null !== $row['parent_membership_id'] ? (int) $row['parent_membership_id'] : null,
			null !== $row['coupon_id'] ? (int) $row['coupon_id'] : null,
			Db::to_date( $row['grace_until'] ),
			Db::to_date( $row['paused_at'] ),
			Db::to_date( $row['resume_at'] ),
			Db::to_date( $row['suspended_at'] ),
			Db::to_date( $row['ended_at'] ),
			$row['end_reason'],
			Db::to_date( $row['created_at'] ?? null ),
			Db::to_date( $row['updated_at'] ?? null )
		);
	}
}
