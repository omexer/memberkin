<?php
/**
 * Subscription persistence.
 *
 * @package Memberkin
 */

namespace Memberkin\Repository;

use Memberkin\Core\Db;
use Memberkin\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * SQL for memberkin_subscriptions.
 */
class SubscriptionRepository {

	private const DATE_FIELDS = array( 'trial_ends_at', 'current_period_start', 'current_period_end', 'next_payment_at', 'cancelled_at', 'paused_at', 'resume_at', 'next_retry_at' );

	/**
	 * By id.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?Subscription {
		global $wpdb;
		$table = Db::table_name( 'subscriptions' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * By gateway subscription id.
	 *
	 * @param string $gateway Gateway.
	 * @param string $remote  Gateway subscription id.
	 * @param string $mode    Mode (empty = any).
	 */
	public function find_by_gateway_id( string $gateway, string $remote, string $mode = '' ): ?Subscription {
		global $wpdb;
		$table = Db::table_name( 'subscriptions' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = '' === $mode
			? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE gateway = %s AND gateway_subscription_id = %s ORDER BY id DESC LIMIT 1", $gateway, $remote ), ARRAY_A )
			: $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE gateway = %s AND gateway_mode = %s AND gateway_subscription_id = %s", $gateway, $mode, $remote ), ARRAY_A );
		// phpcs:enable
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * By membership.
	 *
	 * @param int $membership_id Membership.
	 */
	public function find_by_membership( int $membership_id ): ?Subscription {
		global $wpdb;
		$table = Db::table_name( 'subscriptions' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE membership_id = %d ORDER BY id DESC LIMIT 1", $membership_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Paged query.
	 *
	 * @param array $args {
	 *     Arguments.
	 *
	 *     @type int      $user_id  User.
	 *     @type string[] $statuses Statuses.
	 *     @type string   $gateway  Gateway.
	 *     @type int      $level_id Level.
	 *     @type int      $per_page Rows.
	 *     @type int      $page     Page.
	 * }
	 * @return array{items: Subscription[], total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;
		$table  = Db::table_name( 'subscriptions' );
		$where  = array( '1=1' );
		$params = array();
		foreach ( array( 'user_id', 'level_id' ) as $int_key ) {
			if ( ! empty( $args[ $int_key ] ) ) {
				$where[]  = "{$int_key} = %d";
				$params[] = (int) $args[ $int_key ];
			}
		}
		if ( ! empty( $args['gateway'] ) ) {
			$where[]  = 'gateway = %s';
			$params[] = (string) $args['gateway'];
		}
		if ( ! empty( $args['statuses'] ) ) {
			$statuses = array_map( 'strval', (array) $args['statuses'] );
			$where[]  = 'status IN (' . Db::placeholders( $statuses, '%s' ) . ')';
			$params   = array_merge( $params, $statuses );
		}
		$per_page  = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset    = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$where_sql = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- list filters: the WHERE clause is joined from constant fragments with placeholders and every value is bound by prepare(); fixed fragments with placeholders.
		$count = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count, $params ) ) : $wpdb->get_var( $count ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id " . ( empty( $args['oldest_first'] ) ? 'DESC' : 'ASC' ) . ' LIMIT %d OFFSET %d', array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'items' => array_map( array( self::class, 'hydrate' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * Site-managed subscriptions whose renewal is due.
	 *
	 * @param \DateTimeImmutable $now   Now.
	 * @param int                $limit Batch.
	 * @return Subscription[]
	 */
	public function due_for_renewal( \DateTimeImmutable $now, int $limit = 100 ): array {
		global $wpdb;
		$table = Db::table_name( 'subscriptions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE billing_model = 'site' AND status IN ('trialing','active') AND cancel_at_period_end = 0 AND next_payment_at IS NOT NULL AND next_payment_at <= %s ORDER BY next_payment_at ASC LIMIT %d", Db::datetime( $now ), $limit ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Site-managed subscriptions whose payment retry is due.
	 *
	 * @param \DateTimeImmutable $now   Now.
	 * @param int                $limit Batch.
	 * @return Subscription[]
	 */
	public function due_for_retry( \DateTimeImmutable $now, int $limit = 100 ): array {
		global $wpdb;
		$table = Db::table_name( 'subscriptions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE billing_model = 'site' AND status = 'past_due' AND next_retry_at IS NOT NULL AND next_retry_at <= %s ORDER BY next_retry_at ASC LIMIT %d", Db::datetime( $now ), $limit ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Live subscriptions whose next payment falls in a window (renewal reminders).
	 *
	 * @param \DateTimeImmutable $from  From (exclusive).
	 * @param \DateTimeImmutable $to    To (inclusive).
	 * @param int                $limit Batch.
	 * @return Subscription[]
	 */
	public function renewing_between( \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 500 ): array {
		global $wpdb;
		$table = Db::table_name( 'subscriptions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ('active','trialing') AND cancel_at_period_end = 0 AND next_payment_at > %s AND next_payment_at <= %s ORDER BY next_payment_at ASC LIMIT %d", Db::datetime( $from ), Db::datetime( $to ), $limit ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Trials ending in a window.
	 *
	 * @param \DateTimeImmutable $from  From (exclusive).
	 * @param \DateTimeImmutable $to    To (inclusive).
	 * @param int                $limit Batch.
	 * @return Subscription[]
	 */
	public function trials_ending_between( \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 500 ): array {
		global $wpdb;
		$table = Db::table_name( 'subscriptions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'trialing' AND cancel_at_period_end = 0 AND trial_ends_at > %s AND trial_ends_at <= %s ORDER BY trial_ends_at ASC LIMIT %d", Db::datetime( $from ), Db::datetime( $to ), $limit ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Live subscriptions charged with a given saved payment method.
	 *
	 * @param int $payment_method_id Payment method.
	 * @return Subscription[]
	 */
	public function live_for_payment_method( int $payment_method_id ): array {
		global $wpdb;
		$table = Db::table_name( 'subscriptions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE payment_method_id = %d AND status IN ('active','trialing','past_due') AND cancel_at_period_end = 0", $payment_method_id ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Subscriptions with a scheduled cancellation whose period ended, or with a due auto-resume.
	 *
	 * @param \DateTimeImmutable $now   Now.
	 * @param int                $limit Batch.
	 * @return Subscription[]
	 */
	public function due_for_scheduled_change( \DateTimeImmutable $now, int $limit = 100 ): array {
		global $wpdb;
		$table = Db::table_name( 'subscriptions' );
		$at    = Db::datetime( $now );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "(SELECT * FROM {$table} WHERE cancel_at_period_end = 1 AND status IN ('trialing','active','past_due','paused') AND current_period_end IS NOT NULL AND current_period_end <= %s LIMIT %d) UNION (SELECT * FROM {$table} WHERE status = 'paused' AND resume_at IS NOT NULL AND resume_at <= %s LIMIT %d)", $at, $limit, $at, $limit ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Inserts a subscription.
	 *
	 * @param Subscription $s Subscription.
	 */
	public function insert( Subscription $s ): int {
		global $wpdb;
		$now  = Db::now();
		$data = array_merge(
			$this->to_row( $s ),
			array(
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$wpdb->insert( Db::table_name( 'subscriptions' ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$s->id = (int) $wpdb->insert_id;
		return $s->id;
	}

	/**
	 * Updates columns; optional optimistic status check. Returns whether a row changed.
	 *
	 * @param int         $id              Id.
	 * @param array       $fields          Columns.
	 * @param string|null $expected_status Required status.
	 */
	public function update( int $id, array $fields, ?string $expected_status = null ): bool {
		global $wpdb;
		foreach ( $fields as $key => $value ) {
			if ( $value instanceof \DateTimeInterface ) {
				$fields[ $key ] = Db::datetime( $value );
			} elseif ( is_bool( $value ) ) {
				$fields[ $key ] = $value ? 1 : 0;
			}
		}
		$fields['updated_at'] = Db::now();
		$where                = array( 'id' => $id );
		if ( null !== $expected_status ) {
			$where['status'] = $expected_status;
		}
		return (bool) $wpdb->update( Db::table_name( 'subscriptions' ), $fields, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Entity → row.
	 *
	 * @param Subscription $s Subscription.
	 * @return array<string, mixed>
	 */
	private function to_row( Subscription $s ): array {
		$row = array(
			'user_id'                 => $s->user_id,
			'level_id'                => $s->level_id,
			'membership_id'           => $s->membership_id,
			'gateway'                 => $s->gateway,
			'gateway_mode'            => $s->gateway_mode,
			'gateway_subscription_id' => $s->gateway_subscription_id,
			'gateway_customer_id'     => $s->gateway_customer_id,
			'payment_method_id'       => $s->payment_method_id,
			'billing_model'           => $s->billing_model,
			'status'                  => $s->status,
			'currency'                => $s->currency,
			'recurring_amount'        => $s->recurring_amount,
			'tax_amount'              => $s->tax_amount,
			'billing_interval'        => $s->billing_interval,
			'billing_period'          => $s->billing_period,
			'billing_cycles'          => $s->billing_cycles,
			'cycles_completed'        => $s->cycles_completed,
			'coupon_id'               => $s->coupon_id,
			'coupon_cycles_remaining' => $s->coupon_cycles_remaining,
			'cancel_at_period_end'    => $s->cancel_at_period_end ? 1 : 0,
			'cancel_reason'           => $s->cancel_reason,
			'retry_count'             => $s->retry_count,
			'last_payment_error'      => $s->last_payment_error,
		);
		foreach ( self::DATE_FIELDS as $field ) {
			$row[ $field ] = Db::datetime( $s->{$field} );
		}
		return $row;
	}

	/**
	 * Row → entity.
	 *
	 * @param array $row Row.
	 */
	public static function hydrate( array $row ): Subscription {
		return new Subscription(
			(int) $row['id'],
			(int) $row['user_id'],
			(int) $row['level_id'],
			(string) $row['gateway'],
			(string) $row['status'],
			(string) $row['currency'],
			(int) $row['recurring_amount'],
			(int) $row['billing_interval'],
			(string) $row['billing_period'],
			null !== $row['membership_id'] ? (int) $row['membership_id'] : null,
			(string) $row['gateway_mode'],
			$row['gateway_subscription_id'],
			$row['gateway_customer_id'],
			null !== $row['payment_method_id'] ? (int) $row['payment_method_id'] : null,
			(string) $row['billing_model'],
			(int) $row['tax_amount'],
			(int) $row['billing_cycles'],
			(int) $row['cycles_completed'],
			null !== $row['coupon_id'] ? (int) $row['coupon_id'] : null,
			null !== $row['coupon_cycles_remaining'] ? (int) $row['coupon_cycles_remaining'] : null,
			Db::to_date( $row['trial_ends_at'] ),
			Db::to_date( $row['current_period_start'] ),
			Db::to_date( $row['current_period_end'] ),
			Db::to_date( $row['next_payment_at'] ),
			(bool) $row['cancel_at_period_end'],
			Db::to_date( $row['cancelled_at'] ),
			$row['cancel_reason'],
			Db::to_date( $row['paused_at'] ),
			Db::to_date( $row['resume_at'] ),
			(int) $row['retry_count'],
			Db::to_date( $row['next_retry_at'] ),
			$row['last_payment_error'],
			Db::to_date( $row['created_at'] )
		);
	}
}
