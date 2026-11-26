<?php
/**
 * Coupons list table.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Coupons;

use Memberkin\Core\Currency;
use Memberkin\Domain\Coupon;
use Memberkin\Repository\CouponRepository;
use Memberkin\Repository\LevelRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Coupons table.
 */
final class CouponsListTable extends \WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'coupon',
				'plural'   => 'coupons',
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
			'code'     => __( 'Code', 'memberkin' ),
			'discount' => __( 'Discount', 'memberkin' ),
			'levels'   => __( 'Levels', 'memberkin' ),
			'uses'     => __( 'Uses', 'memberkin' ),
			'valid'    => __( 'Valid', 'memberkin' ),
			'status'   => __( 'Status', 'memberkin' ),
		);
	}

	/**
	 * Loads rows.
	 */
	public function prepare_items(): void {
		$result                = memberkin()->get( CouponRepository::class )->query(
			array(
				'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'per_page' => 20,
				'page'     => $this->get_pagenum(),
			)
		);
		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'code' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => 20,
			)
		);
	}

	/**
	 * Code column.
	 *
	 * @param Coupon $item Row.
	 */
	protected function column_code( Coupon $item ): string {
		$edit   = add_query_arg(
			array(
				'page' => 'memberkin-coupons',
				'view' => 'edit',
				'id'   => $item->id,
			),
			admin_url( 'admin.php' )
		);
		$delete = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'memberkin_delete_coupon',
					'id'     => $item->id,
				),
				admin_url( 'admin-post.php' )
			),
			'memberkin_delete_coupon_' . $item->id
		);
		$out    = '<strong><a class="row-title" href="' . esc_url( $edit ) . '"><code>' . esc_html( $item->code ) . '</code></a></strong>';
		if ( $item->description ) {
			$out .= '<br /><span class="memberkin-muted">' . esc_html( $item->description ) . '</span>';
		}
		return $out . $this->row_actions(
			array(
				'edit'  => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'memberkin' ) . '</a>',
				'trash' => '<a href="' . esc_url( $delete ) . '" data-memberkin-confirm="' . esc_attr__( 'Delete this coupon?', 'memberkin' ) . '">' . esc_html__( 'Delete', 'memberkin' ) . '</a>',
			)
		);
	}

	/**
	 * Other columns.
	 *
	 * @param Coupon $item        Row.
	 * @param string $column_name Column.
	 */
	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'discount':
				return esc_html( $item->summary( Currency::default() ) );
			case 'levels':
				if ( ! $item->levels ) {
					return esc_html__( 'All levels', 'memberkin' );
				}
				$repo  = memberkin()->get( LevelRepository::class );
				$names = array();
				foreach ( array_keys( $item->levels ) as $level_id ) {
					$level   = $repo->find( (int) $level_id );
					$names[] = $level ? $level->name : '#' . $level_id;
				}
				return esc_html( implode( ', ', $names ) );
			case 'uses':
				return esc_html( $item->uses_count . ( $item->max_uses ? ' / ' . $item->max_uses : '' ) );
			case 'valid':
				$fmt  = get_option( 'date_format' );
				$from = $item->starts_at ? wp_date( $fmt, $item->starts_at->getTimestamp() ) : '';
				$to   = $item->expires_at ? wp_date( $fmt, $item->expires_at->getTimestamp() ) : '';
				if ( ! $from && ! $to ) {
					return esc_html__( 'Always', 'memberkin' );
				}
				return esc_html( trim( $from . ' – ' . $to, ' –' ) );
			case 'status':
				$live = 'active' === $item->status && $item->is_within_dates() && ( ! $item->max_uses || $item->uses_count < $item->max_uses );
				return '<span class="memberkin-badge memberkin-badge--' . ( $live ? 'active' : 'ended' ) . '">' . esc_html( $live ? __( 'Usable', 'memberkin' ) : __( 'Not usable', 'memberkin' ) ) . '</span>';
		}
		return '';
	}

	/**
	 * Empty state.
	 */
	public function no_items(): void {
		esc_html_e( 'No coupons yet.', 'memberkin' );
	}
}
