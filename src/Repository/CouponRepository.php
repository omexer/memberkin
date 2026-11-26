<?php
/**
 * Coupon persistence.
 *
 * @package Memberkin
 */

namespace Memberkin\Repository;

use Memberkin\Core\Db;
use Memberkin\Domain\Coupon;
use Memberkin\Domain\PriceSchedule;

defined( 'ABSPATH' ) || exit;

/**
 * SQL for memberkin_coupons, memberkin_coupon_levels and memberkin_coupon_uses.
 */
class CouponRepository {

	/**
	 * Coupon by id.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?Coupon {
		global $wpdb;
		$table = Db::table_name( 'coupons' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Coupon by code (case-insensitive).
	 *
	 * @param string $code Code.
	 */
	public function find_by_code( string $code ): ?Coupon {
		global $wpdb;
		$code = trim( $code );
		if ( '' === $code ) {
			return null;
		}
		$table = Db::table_name( 'coupons' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Active coupons auto-applied to a level group.
	 *
	 * @param int $group_id Group id.
	 * @return Coupon[]
	 */
	public function auto_apply_for_group( int $group_id ): array {
		global $wpdb;
		$table = Db::table_name( 'coupons' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE auto_apply_group_id = %d AND status = 'active' ORDER BY id ASC", $group_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Paged list.
	 *
	 * @param array $args {
	 *     Arguments.
	 *
	 *     @type string $search   Code search.
	 *     @type string $status   Status filter.
	 *     @type int    $per_page Rows.
	 *     @type int    $page     Page.
	 * }
	 * @return array{items: Coupon[], total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;
		$table  = Db::table_name( 'coupons' );
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'code LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		$per_page  = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset    = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$where_sql = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- list filters: the WHERE clause is joined from constant fragments with placeholders and every value is bound by prepare(); fixed fragments with placeholders.
		$count = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count, $params ) ) : $wpdb->get_var( $count ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'items' => array_map( array( $this, 'hydrate' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * Whether a code is used by another coupon.
	 *
	 * @param string $code       Code.
	 * @param int    $exclude_id Coupon being edited.
	 */
	public function code_exists( string $code, int $exclude_id = 0 ): bool {
		global $wpdb;
		$table = Db::table_name( 'coupons' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s AND id <> %d", $code, $exclude_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Inserts or updates a coupon and its level rows.
	 *
	 * @param Coupon $coupon Coupon.
	 */
	public function save( Coupon $coupon ): int {
		global $wpdb;
		$data  = array(
			'code'                  => $coupon->code,
			'description'           => $coupon->description,
			'status'                => $coupon->status,
			'discount_type'         => $coupon->discount_type,
			'amount'                => $coupon->amount,
			'applies_to'            => $coupon->applies_to,
			'recurring_cycles'      => $coupon->recurring_cycles,
			'first_payment_type'    => $coupon->first_payment_type,
			'first_payment_amount'  => $coupon->first_payment_amount,
			'trial_override_length' => $coupon->trial_override_length,
			'trial_override_period' => $coupon->trial_override_period,
			'trial_override_amount' => $coupon->trial_override_amount,
			'starts_at'             => Db::datetime( $coupon->starts_at ),
			'expires_at'            => Db::datetime( $coupon->expires_at ),
			'max_uses'              => $coupon->max_uses,
			'max_uses_per_user'     => $coupon->max_uses_per_user,
			'per_user_window_days'  => $coupon->per_user_window_days,
			'allow_on_change'       => $coupon->allow_on_change ? 1 : 0,
			'auto_apply_group_id'   => $coupon->auto_apply_group_id,
			'updated_at'            => Db::now(),
		);
		$table = Db::table_name( 'coupons' );
		if ( $coupon->id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $coupon->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$data['created_at'] = Db::now();
			$data['uses_count'] = 0;
			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$coupon->id = (int) $wpdb->insert_id;
		}

		$levels_table = Db::table_name( 'coupon_levels' );
		$wpdb->delete( $levels_table, array( 'coupon_id' => $coupon->id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $coupon->levels as $level_id => $settings ) {
			$row = array(
				'coupon_id' => $coupon->id,
				'level_id'  => (int) $level_id,
				'override'  => $settings['override'] ? 1 : 0,
			);
			if ( $settings['override'] && $settings['schedule'] ) {
				$schedule = $settings['schedule']->to_row();
				unset( $schedule['currency'], $schedule['expiration_date'] );
				$row = array_merge( $row, $schedule );
			}
			$wpdb->insert( $levels_table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $coupon->id;
	}

	/**
	 * Deletes a coupon (its use history is kept for reporting).
	 *
	 * @param int $id Id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Db::table_name( 'coupons' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Db::table_name( 'coupon_levels' ), array( 'coupon_id' => $id ), array( '%d' ) );
		// phpcs:enable
	}

	/**
	 * Atomically consumes one use if the global limit allows it. Returns false when the limit is reached.
	 *
	 * @param int $id Coupon id.
	 */
	public function consume( int $id ): bool {
		global $wpdb;
		$table = Db::table_name( 'coupons' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET uses_count = uses_count + 1 WHERE id = %d AND (max_uses = 0 OR uses_count < max_uses)", $id ) );
	}

	/**
	 * Gives back one use (e.g. when the order is refunded or abandoned).
	 *
	 * @param int $id Coupon id.
	 */
	public function release( int $id ): void {
		global $wpdb;
		$table = Db::table_name( 'coupons' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET uses_count = uses_count - 1 WHERE id = %d AND uses_count > 0", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Records a use.
	 *
	 * @param int $coupon_id Coupon.
	 * @param int $user_id   User.
	 * @param int $order_id  Order.
	 */
	public function record_use( int $coupon_id, int $user_id, int $order_id ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::table_name( 'coupon_uses' ),
			array(
				'coupon_id' => $coupon_id,
				'user_id'   => $user_id,
				'order_id'  => $order_id,
				'used_at'   => Db::now(),
			)
		);
	}

	/**
	 * Number of uses by a user, optionally since a date.
	 *
	 * @param int                     $coupon_id Coupon.
	 * @param int                     $user_id   User.
	 * @param \DateTimeImmutable|null $since     Since.
	 */
	public function user_uses( int $coupon_id, int $user_id, ?\DateTimeImmutable $since = null ): int {
		global $wpdb;
		$table = Db::table_name( 'coupon_uses' );
		if ( $since ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE coupon_id = %d AND user_id = %d AND used_at >= %s", $coupon_id, $user_id, Db::datetime( $since ) ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE coupon_id = %d AND user_id = %d", $coupon_id, $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Row → entity (with levels).
	 *
	 * @param array $row Row.
	 */
	private function hydrate( array $row ): Coupon {
		$coupon = new Coupon(
			(int) $row['id'],
			(string) $row['code'],
			(string) $row['description'],
			(string) $row['status'],
			(string) $row['discount_type'],
			(int) $row['amount'],
			(string) $row['applies_to'],
			null !== $row['recurring_cycles'] ? (int) $row['recurring_cycles'] : null,
			'' !== (string) $row['first_payment_type'] ? (string) $row['first_payment_type'] : null,
			null !== $row['first_payment_amount'] ? (int) $row['first_payment_amount'] : null,
			null !== $row['trial_override_length'] ? (int) $row['trial_override_length'] : null,
			'' !== (string) $row['trial_override_period'] ? (string) $row['trial_override_period'] : null,
			null !== $row['trial_override_amount'] ? (int) $row['trial_override_amount'] : null,
			Db::to_date( $row['starts_at'] ),
			Db::to_date( $row['expires_at'] ),
			(int) $row['max_uses'],
			(int) $row['uses_count'],
			(int) $row['max_uses_per_user'],
			(int) $row['per_user_window_days'],
			(bool) $row['allow_on_change'],
			null !== $row['auto_apply_group_id'] ? (int) $row['auto_apply_group_id'] : null
		);
		global $wpdb;
		$table = Db::table_name( 'coupon_levels' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE coupon_id = %d", $coupon->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $level_row ) {
			$override                                       = (bool) $level_row['override'];
			$coupon->levels[ (int) $level_row['level_id'] ] = array(
				'override' => $override,
				'schedule' => $override ? PriceSchedule::from_row( $level_row ) : null,
			);
		}
		return $coupon;
	}
}
