<?php
/**
 * Subscriptions list table.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Billing\Admin;

use Memberkin\Admin\Html;
use Memberkin\Domain\Period;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\GatewayRegistry;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\SubscriptionRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Subscriptions with status views.
 */
final class SubscriptionsListTable extends \WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'subscription',
				'plural'   => 'subscriptions',
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
			'id'      => __( 'Subscription', 'memberkin' ),
			'member'  => __( 'Member', 'memberkin' ),
			'level'   => __( 'Level', 'memberkin' ),
			'amount'  => __( 'Amount', 'memberkin' ),
			'status'  => __( 'Status', 'memberkin' ),
			'next'    => __( 'Next payment', 'memberkin' ),
			'gateway' => __( 'Gateway', 'memberkin' ),
		);
	}

	/**
	 * Status filter.
	 */
	private function status(): string {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( Subscription::statuses()[ $status ] ) ? $status : '';
	}

	/**
	 * Views.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$base  = remove_query_arg( array( 'status', 'paged' ) );
		$views = array( 'all' => '<a href="' . esc_url( $base ) . '"' . ( '' === $this->status() ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'All', 'memberkin' ) . '</a>' );
		foreach ( Subscription::statuses() as $key => $label ) {
			$views[ $key ] = '<a href="' . esc_url( add_query_arg( 'status', $key, $base ) ) . '"' . ( $this->status() === $key ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		return $views;
	}

	/**
	 * Loads rows.
	 */
	public function prepare_items(): void {
		$result                = memberkin()->get( SubscriptionRepository::class )->query(
			array(
				'statuses' => '' !== $this->status() ? array( $this->status() ) : array(),
				'per_page' => 20,
				'page'     => $this->get_pagenum(),
			)
		);
		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'id' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => 20,
			)
		);
		cache_users( array_unique( array_map( static fn( Subscription $s ) => $s->user_id, $this->items ) ) );
	}

	/**
	 * Id column.
	 *
	 * @param Subscription $item Row.
	 */
	protected function column_id( Subscription $item ): string {
		$url = Html::url( SubscriptionsScreen::PAGE, array( 'id' => $item->id ) );
		return '<strong><a class="row-title" href="' . esc_url( $url ) . '">#' . (int) $item->id . '</a></strong>' . ( $item->gateway_subscription_id ? '<br /><code class="memberkin-muted">' . esc_html( $item->gateway_subscription_id ) . '</code>' : '' );
	}

	/**
	 * Other columns.
	 *
	 * @param Subscription $item        Row.
	 * @param string       $column_name Column.
	 */
	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'member':
				return Html::member_link( $item->user_id );
			case 'level':
				$level = memberkin()->get( LevelRepository::class )->find( $item->level_id );
				return esc_html( $level ? $level->name : '#' . $item->level_id );
			case 'amount':
				return Html::money( $item->recurring_amount + $item->tax_amount, $item->currency ) . ' / ' . esc_html( ( $item->billing_interval > 1 ? $item->billing_interval . ' ' : '' ) . Period::label( $item->billing_period, $item->billing_interval ) );
			case 'status':
				$out = Html::badge( $item->status, Subscription::statuses()[ $item->status ] ?? $item->status );
				return $item->cancel_at_period_end ? $out . '<br /><span class="memberkin-muted">' . esc_html__( 'Cancels at period end', 'memberkin' ) . '</span>' : $out;
			case 'next':
				return $item->cancel_at_period_end ? '—' : Html::date( $item->next_payment_at );
			case 'gateway':
				$gateway = memberkin()->get( GatewayRegistry::class )->get( $item->gateway );
				return esc_html( ( $gateway ? $gateway->label() : $item->gateway ) . ' · ' . ( 'site' === $item->billing_model ? __( 'charged by this site', 'memberkin' ) : __( 'billed by gateway', 'memberkin' ) ) );
		}
		return '';
	}

	/**
	 * Empty state.
	 */
	public function no_items(): void {
		esc_html_e( 'No subscriptions found.', 'memberkin' );
	}
}
