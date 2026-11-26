<?php
/**
 * Orders list table.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Billing\Admin;

use Memberkin\Admin\Html;
use Memberkin\Domain\Order;
use Memberkin\Gateway\GatewayRegistry;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\OrderRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Orders with status views, gateway filter and search.
 */
final class OrdersListTable extends \WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'order',
				'plural'   => 'orders',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'number'  => __( 'Order', 'memberkin' ),
			'member'  => __( 'Member', 'memberkin' ),
			'items'   => __( 'Items', 'memberkin' ),
			'total'   => __( 'Total', 'memberkin' ),
			'gateway' => __( 'Payment', 'memberkin' ),
			'status'  => __( 'Status', 'memberkin' ),
			'date'    => __( 'Date', 'memberkin' ),
		);
	}

	/**
	 * Status filter.
	 */
	private function status(): string {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( Order::statuses()[ $status ] ) ? $status : '';
	}

	/**
	 * Views.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$base  = remove_query_arg( array( 'status', 'paged' ) );
		$views = array( 'all' => '<a href="' . esc_url( $base ) . '"' . ( '' === $this->status() ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'All', 'memberkin' ) . '</a>' );
		foreach ( Order::statuses() as $key => $label ) {
			$views[ $key ] = '<a href="' . esc_url( add_query_arg( 'status', $key, $base ) ) . '"' . ( $this->status() === $key ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		return $views;
	}

	/**
	 * Gateway filter.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$selected = isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="alignleft actions"><label class="screen-reader-text" for="memberkin-filter-gateway">' . esc_html__( 'Filter by gateway', 'memberkin' ) . '</label><select name="gateway" id="memberkin-filter-gateway"><option value="">' . esc_html__( 'All gateways', 'memberkin' ) . '</option>';
		foreach ( memberkin()->get( GatewayRegistry::class )->all() as $gateway ) {
			echo '<option value="' . esc_attr( $gateway->id() ) . '" ' . selected( $selected, $gateway->id(), false ) . '>' . esc_html( $gateway->label() ) . '</option>';
		}
		echo '<option value="free" ' . selected( $selected, 'free', false ) . '>' . esc_html__( 'Free', 'memberkin' ) . '</option></select>';
		submit_button( __( 'Filter', 'memberkin' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Loads rows.
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$result = memberkin()->get( OrderRepository::class )->query(
			array(
				'statuses' => '' !== $this->status() ? array( $this->status() ) : array(),
				'gateway'  => isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '',
				'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
				'per_page' => 20,
				'page'     => $this->get_pagenum(),
			)
		);
		// phpcs:enable
		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'number' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => 20,
			)
		);
		cache_users( array_unique( array_map( static fn( Order $o ) => $o->user_id, $this->items ) ) );
	}

	/**
	 * Number column.
	 *
	 * @param Order $item Row.
	 */
	protected function column_number( Order $item ): string {
		$url = Html::url( OrdersScreen::PAGE, array( 'id' => $item->id ) );
		$out = '<strong><a class="row-title" href="' . esc_url( $url ) . '">' . esc_html( $item->number ) . '</a></strong>';
		if ( $item->invoice_number ) {
			$out .= '<br /><span class="memberkin-muted">' . esc_html( $item->invoice_number ) . '</span>';
		}
		return $out . $this->row_actions( array( 'view' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'View', 'memberkin' ) . '</a>' ) );
	}

	/**
	 * Other columns.
	 *
	 * @param Order  $item        Row.
	 * @param string $column_name Column.
	 */
	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'member':
				return Html::member_link( $item->user_id );
			case 'items':
				$levels = memberkin()->get( LevelRepository::class );
				$names  = array();
				foreach ( $item->level_ids() as $level_id ) {
					$level   = $levels->find( $level_id );
					$names[] = $level ? $level->name : '#' . $level_id;
				}
				return esc_html( implode( ', ', $names ) ) . '<br /><span class="memberkin-muted">' . esc_html( Order::types()[ $item->type ] ?? $item->type ) . '</span>';
			case 'total':
				$out = Html::money( $item->total, $item->currency );
				if ( $item->amount_refunded ) {
					/* translators: %s: amount. */
					$out .= '<br /><span class="memberkin-muted">' . esc_html( sprintf( __( '%s refunded', 'memberkin' ), \Memberkin\Core\Money::of( $item->amount_refunded, $item->currency )->format() ) ) . '</span>';
				}
				return $out;
			case 'gateway':
				$gateway = memberkin()->get( GatewayRegistry::class )->get( $item->gateway );
				return esc_html( $gateway ? $gateway->label() : ( 'free' === $item->gateway ? __( 'Free', 'memberkin' ) : $item->gateway ) ) . ( $item->payment_method_label ? '<br /><span class="memberkin-muted">' . esc_html( $item->payment_method_label ) . '</span>' : '' );
			case 'status':
				return Html::badge( $item->status, Order::statuses()[ $item->status ] ?? $item->status );
			case 'date':
				return Html::date( $item->created_at, true );
		}
		return '';
	}

	/**
	 * Empty state.
	 */
	public function no_items(): void {
		esc_html_e( 'No orders found.', 'memberkin' );
	}
}
