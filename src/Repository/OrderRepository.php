<?php
/**
 * Order persistence (orders, items, refunds, meta).
 *
 * @package Memberkin
 */

namespace Memberkin\Repository;

use Memberkin\Core\Crypto;
use Memberkin\Core\Db;
use Memberkin\Domain\Order;
use Memberkin\Domain\OrderItem;

defined( 'ABSPATH' ) || exit;

/**
 * SQL for memberkin_orders, memberkin_order_items and memberkin_refunds.
 */
class OrderRepository {

	private const BILLING_FIELDS = array( 'name', 'company', 'email', 'line1', 'line2', 'city', 'region', 'postcode', 'country', 'phone' );

	/**
	 * Order by id (with items).
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?Order {
		return $this->find_by( 'id', $id );
	}

	/**
	 * Order by public number.
	 *
	 * @param string $number Number.
	 */
	public function find_by_number( string $number ): ?Order {
		return $this->find_by( 'number', $number );
	}

	/**
	 * Order by gateway transaction id.
	 *
	 * @param string $gateway        Gateway.
	 * @param string $transaction_id Transaction id.
	 */
	public function find_by_gateway_transaction( string $gateway, string $transaction_id ): ?Order {
		global $wpdb;
		$table = Db::table_name( 'orders' );
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE gateway = %s AND gateway_transaction_id = %s ORDER BY id DESC LIMIT 1", $gateway, $transaction_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $id ? $this->find( (int) $id ) : null;
	}

	/**
	 * Generic single-column lookup.
	 *
	 * @param string     $column id|number.
	 * @param int|string $value  Value.
	 */
	private function find_by( string $column, int|string $value ): ?Order {
		global $wpdb;
		$table = Db::table_name( 'orders' );
		$sql   = 'id' === $column ? "SELECT * FROM {$table} WHERE id = %d" : "SELECT * FROM {$table} WHERE number = %s";
		$row   = $wpdb->get_row( $wpdb->prepare( $sql, $value ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $this->hydrate( $row, true ) : null;
	}

	/**
	 * Orders of a user, newest first.
	 *
	 * @param int $user_id User.
	 * @param int $limit   Max.
	 * @return Order[]
	 */
	public function for_user( int $user_id, int $limit = 100 ): array {
		return $this->query(
			array(
				'user_id'  => $user_id,
				'per_page' => $limit,
			)
		)['items'];
	}

	/**
	 * Orders of a subscription, newest first.
	 *
	 * @param int $subscription_id Subscription.
	 * @return Order[]
	 */
	public function for_subscription( int $subscription_id ): array {
		return $this->query(
			array(
				'subscription_id' => $subscription_id,
				'per_page'        => 500,
			)
		)['items'];
	}

	/**
	 * Paged query.
	 *
	 * @param array $args {
	 *     Arguments.
	 *
	 *     @type int      $user_id         User.
	 *     @type int      $subscription_id Subscription.
	 *     @type string[] $statuses        Statuses.
	 *     @type string   $gateway         Gateway.
	 *     @type string   $search          Number, invoice number, transaction id, email or name.
	 *     @type string   $from            Created from (Y-m-d H:i:s UTC).
	 *     @type string   $to              Created before (Y-m-d H:i:s UTC).
	 *     @type int      $per_page        Rows.
	 *     @type bool     $oldest_first    Stable id order (exports).
	 *     @type int      $page            Page.
	 * }
	 * @return array{items: Order[], total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;
		$table  = Db::table_name( 'orders' );
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}
		if ( ! empty( $args['subscription_id'] ) ) {
			$where[]  = 'subscription_id = %d';
			$params[] = (int) $args['subscription_id'];
		}
		if ( ! empty( $args['statuses'] ) ) {
			$statuses = array_map( 'strval', (array) $args['statuses'] );
			$where[]  = 'status IN (' . Db::placeholders( $statuses, '%s' ) . ')';
			$params   = array_merge( $params, $statuses );
		}
		if ( ! empty( $args['gateway'] ) ) {
			$where[]  = 'gateway = %s';
			$params[] = (string) $args['gateway'];
		}
		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'created_at < %s';
			$params[] = (string) $args['to'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[] = '(number LIKE %s OR invoice_number LIKE %s OR gateway_transaction_id LIKE %s OR billing_email LIKE %s OR billing_name LIKE %s)';
			$params  = array_merge( $params, array( $like, $like, $like, $like, $like ) );
		}
		$per_page  = max( 1, min( 500, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset    = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$where_sql = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- list filters: the WHERE clause is joined from constant fragments with placeholders and every value is bound by prepare(); fixed fragments with placeholders.
		$count = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count, $params ) ) : $wpdb->get_var( $count ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} " . ( empty( $args['oldest_first'] ) ? 'ORDER BY created_at DESC, id DESC' : 'ORDER BY id ASC' ) . ' LIMIT %d OFFSET %d', array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable
		$orders = array_map( fn( $row ) => $this->hydrate( $row, false ), $rows );
		$this->attach_items( $orders );
		return array(
			'items' => $orders,
			'total' => $total,
		);
	}

	/**
	 * Inserts an order with its items. Generates a unique public number.
	 *
	 * @param Order $order Order.
	 */
	public function insert( Order $order ): int {
		global $wpdb;
		if ( '' === $order->number ) {
			do {
				$order->number = 'MK-' . Crypto::code( 10 );
			} while ( $this->find_by_number( $order->number ) );
		}
		$now  = Db::now();
		$data = array_merge(
			$this->to_row( $order ),
			array(
				'number'     => $order->number,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$wpdb->insert( Db::table_name( 'orders' ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$order->id         = (int) $wpdb->insert_id;
		$order->created_at = Db::to_date( $now );
		$this->replace_items( $order );
		return $order->id;
	}

	/**
	 * Updates columns; with $expected_status only if the status still matches. Returns whether a row changed.
	 *
	 * @param int         $id              Order id.
	 * @param array       $fields          Columns.
	 * @param string|null $expected_status Required status.
	 */
	public function update( int $id, array $fields, ?string $expected_status = null ): bool {
		global $wpdb;
		foreach ( $fields as $key => $value ) {
			if ( $value instanceof \DateTimeInterface ) {
				$fields[ $key ] = Db::datetime( $value );
			}
		}
		$fields['updated_at'] = Db::now();
		$where                = array( 'id' => $id );
		if ( null !== $expected_status ) {
			$where['status'] = $expected_status;
		}
		return (bool) $wpdb->update( Db::table_name( 'orders' ), $fields, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Saves all mutable fields of an order and replaces its items.
	 *
	 * @param Order $order Order.
	 */
	public function save( Order $order ): void {
		$this->update( $order->id, $this->to_row( $order ) );
		$this->replace_items( $order );
	}

	/**
	 * Replaces line items.
	 *
	 * @param Order $order Order.
	 */
	private function replace_items( Order $order ): void {
		global $wpdb;
		$table = Db::table_name( 'order_items' );
		$wpdb->delete( $table, array( 'order_id' => $order->id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $order->items as $item ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'order_id'        => $order->id,
					'type'            => $item->type,
					'level_id'        => $item->level_id,
					'description'     => mb_substr( $item->description, 0, 255 ),
					'quantity'        => $item->quantity,
					'unit_amount'     => $item->unit_amount,
					'discount_amount' => $item->discount_amount,
					'tax_rate_id'     => $item->tax_rate_id,
					'tax_rate'        => $item->tax_rate,
					'tax_amount'      => $item->tax_amount,
					'total'           => $item->total(),
				)
			);
			$item->id = (int) $wpdb->insert_id;
		}
	}

	/**
	 * Loads items for several orders with one query.
	 *
	 * @param Order[] $orders Orders.
	 */
	private function attach_items( array $orders ): void {
		if ( ! $orders ) {
			return;
		}
		global $wpdb;
		$table = Db::table_name( 'order_items' );
		$ids   = array_map( static fn( Order $o ) => $o->id, $orders );
		$ids   = wp_parse_id_list( $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table; results are cached per order by the caller.
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id ASC', array_merge( array( $table ), $ids ) ), ARRAY_A );
		$by_id = array();
		foreach ( $orders as $order ) {
			$order->items        = array();
			$by_id[ $order->id ] = $order;
		}
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['order_id'] ]->items[] = new OrderItem(
				(string) $row['type'],
				(string) $row['description'],
				(int) $row['unit_amount'],
				(int) $row['quantity'],
				null !== $row['level_id'] ? (int) $row['level_id'] : null,
				(int) $row['discount_amount'],
				self::normalize_rate( (string) $row['tax_rate'] ),
				(int) $row['tax_amount'],
				null !== $row['tax_rate_id'] ? (int) $row['tax_rate_id'] : null,
				(int) $row['id']
			);
		}
	}

	/**
	 * Inserts a refund row.
	 *
	 * @param array $refund order_id, amount, reason, gateway_refund_id, status, credit_note_number, created_by.
	 */
	public function insert_refund( array $refund ): int {
		global $wpdb;
		$wpdb->insert( Db::table_name( 'refunds' ), array_merge( $refund, array( 'created_at' => Db::now() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Refunds of an order.
	 *
	 * @param int $order_id Order.
	 * @return array<int, object>
	 */
	public function refunds( int $order_id ): array {
		global $wpdb;
		$table = Db::table_name( 'refunds' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Whether a gateway refund id was already recorded (webhook idempotency).
	 *
	 * @param string $gateway_refund_id Gateway refund id.
	 */
	public function refund_exists( string $gateway_refund_id ): bool {
		global $wpdb;
		$table = Db::table_name( 'refunds' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE gateway_refund_id = %s LIMIT 1", $gateway_refund_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Entity → row (mutable columns).
	 *
	 * @param Order $o Order.
	 * @return array<string, mixed>
	 */
	private function to_row( Order $o ): array {
		$row = array(
			'invoice_number'         => $o->invoice_number,
			'user_id'                => $o->user_id,
			'subscription_id'        => $o->subscription_id,
			'type'                   => $o->type,
			'status'                 => $o->status,
			'currency'               => $o->currency,
			'subtotal'               => $o->subtotal,
			'discount_total'         => $o->discount_total,
			'tax_total'              => $o->tax_total,
			'total'                  => $o->total,
			'amount_refunded'        => $o->amount_refunded,
			'coupon_id'              => $o->coupon_id,
			'coupon_code'            => $o->coupon_code,
			'tax_country'            => $o->tax['country'] ?? null,
			'tax_region'             => $o->tax['region'] ?? null,
			'vat_number'             => $o->tax['vat_number'] ?? null,
			'vat_number_valid'       => empty( $o->tax['vat_number_valid'] ) ? 0 : 1,
			'reverse_charge'         => empty( $o->tax['reverse_charge'] ) ? 0 : 1,
			'prices_include_tax'     => empty( $o->tax['prices_include_tax'] ) ? 0 : 1,
			'gateway'                => $o->gateway,
			'gateway_mode'           => $o->gateway_mode,
			'gateway_transaction_id' => $o->gateway_transaction_id,
			'gateway_customer_id'    => $o->gateway_customer_id,
			'payment_method_label'   => $o->payment_method_label,
			'ip_address'             => $o->ip_address,
			'checkout_session_id'    => $o->checkout_session_id,
			'paid_at'                => Db::datetime( $o->paid_at ),
			'refunded_at'            => Db::datetime( $o->refunded_at ),
			'notes'                  => $o->notes,
		);
		foreach ( self::BILLING_FIELDS as $field ) {
			$row[ 'billing_' . $field ] = isset( $o->billing[ $field ] ) && '' !== $o->billing[ $field ] ? (string) $o->billing[ $field ] : null;
		}
		return $row;
	}

	/**
	 * Row → entity.
	 *
	 * @param array $row        Row.
	 * @param bool  $with_items Load items.
	 */
	private function hydrate( array $row, bool $with_items ): Order {
		$billing = array();
		foreach ( self::BILLING_FIELDS as $field ) {
			if ( null !== $row[ 'billing_' . $field ] ) {
				$billing[ $field ] = (string) $row[ 'billing_' . $field ];
			}
		}
		$order = new Order(
			(int) $row['id'],
			(string) $row['number'],
			(int) $row['user_id'],
			(string) $row['type'],
			(string) $row['status'],
			(string) $row['currency'],
			(int) $row['subtotal'],
			(int) $row['discount_total'],
			(int) $row['tax_total'],
			(int) $row['total'],
			(int) $row['amount_refunded'],
			(string) $row['gateway'],
			(string) $row['gateway_mode'],
			null !== $row['subscription_id'] ? (int) $row['subscription_id'] : null,
			$row['invoice_number'],
			null !== $row['coupon_id'] ? (int) $row['coupon_id'] : null,
			$row['coupon_code'],
			$row['gateway_transaction_id'],
			$row['gateway_customer_id'],
			$row['payment_method_label'],
			$billing,
			array(
				'country'            => $row['tax_country'],
				'region'             => $row['tax_region'],
				'vat_number'         => $row['vat_number'],
				'vat_number_valid'   => (bool) $row['vat_number_valid'],
				'reverse_charge'     => (bool) $row['reverse_charge'],
				'prices_include_tax' => (bool) $row['prices_include_tax'],
			),
			$row['ip_address'],
			null !== $row['checkout_session_id'] ? (int) $row['checkout_session_id'] : null,
			Db::to_date( $row['paid_at'] ),
			Db::to_date( $row['refunded_at'] ),
			Db::to_date( $row['created_at'] ),
			(string) ( $row['notes'] ?? '' )
		);
		if ( $with_items ) {
			$this->attach_items( array( $order ) );
		}
		return $order;
	}

	/**
	 * "20.0000" → "20", "9.9750" → "9.975".
	 *
	 * @param string $rate Rate from the database.
	 */
	public static function normalize_rate( string $rate ): string {
		if ( ! str_contains( $rate, '.' ) ) {
			return '' === $rate ? '0' : $rate;
		}
		$rate = rtrim( rtrim( $rate, '0' ), '.' );
		return '' === $rate ? '0' : $rate;
	}
}
