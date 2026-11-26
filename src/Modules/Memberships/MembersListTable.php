<?php
/**
 * Members list table.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Memberships;

use Memberkin\Core\Capabilities;
use Memberkin\Domain\Membership;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\MembershipRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * One row per membership, filterable by level and status, with bulk actions.
 */
final class MembersListTable extends \WP_List_Table {

	/**
	 * Levels by id.
	 *
	 * @var array<int, \Memberkin\Domain\Level>
	 */
	private array $levels;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'membership',
				'plural'   => 'memberships',
				'ajax'     => false,
			)
		);
		$this->levels = memberkin()->get( LevelRepository::class )->all();
	}

	/**
	 * Current status filter ('' = current memberships).
	 */
	private function status_filter(): string {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return ( 'all' === $status || isset( Membership::statuses()[ $status ] ) ) ? $status : '';
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'        => '<input type="checkbox" />',
			'member'    => __( 'Member', 'memberkin' ),
			'level'     => __( 'Level', 'memberkin' ),
			'status'    => __( 'Status', 'memberkin' ),
			'price'     => __( 'Price', 'memberkin' ),
			'starts_at' => __( 'Started', 'memberkin' ),
			'ends_at'   => __( 'Ends', 'memberkin' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'starts_at' => array( 'starts_at', true ),
			'ends_at'   => array( 'ends_at', false ),
		);
	}

	/**
	 * Status views.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$current = $this->status_filter();
		$base    = remove_query_arg( array( 'status', 'paged' ) );
		$views   = array(
			''    => __( 'Current', 'memberkin' ),
			'all' => __( 'All', 'memberkin' ),
		);
		foreach ( array( Membership::ACTIVE, Membership::CANCELLING, Membership::PAST_DUE, Membership::PAUSED, Membership::SUSPENDED, Membership::PENDING_APPROVAL, Membership::ENDED ) as $status ) {
			$views[ $status ] = Membership::statuses()[ $status ];
		}
		$out = array();
		foreach ( $views as $key => $label ) {
			$url                                   = '' === $key ? $base : add_query_arg( 'status', $key, $base );
			$out[ '' === $key ? 'current' : $key ] = '<a href="' . esc_url( $url ) . '"' . ( $current === $key ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		return $out;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		if ( ! current_user_can( Capabilities::MANAGE_MEMBERS ) ) {
			return array();
		}
		return array(
			'suspend'   => __( 'Suspend', 'memberkin' ),
			'unsuspend' => __( 'Lift suspension', 'memberkin' ),
			'end'       => __( 'End membership now', 'memberkin' ),
		);
	}

	/**
	 * Level filter dropdown.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$selected = isset( $_GET['level_id'] ) ? absint( $_GET['level_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="alignleft actions"><label class="screen-reader-text" for="memberkin-filter-level">' . esc_html__( 'Filter by level', 'memberkin' ) . '</label>';
		echo '<select name="level_id" id="memberkin-filter-level"><option value="0">' . esc_html__( 'All levels', 'memberkin' ) . '</option>';
		foreach ( $this->levels as $level ) {
			echo '<option value="' . (int) $level->id . '" ' . selected( $selected, $level->id, false ) . '>' . esc_html( $level->name ) . '</option>';
		}
		echo '</select>';
		submit_button( __( 'Filter', 'memberkin' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Loads rows.
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status   = $this->status_filter();
		$statuses = match ( $status ) {
			''      => array_diff( Membership::CURRENT_STATUSES, array( Membership::PENDING ) ),
			'all'   => array(),
			default => array( $status ),
		};
		$per_page = $this->get_items_per_page( 'memberkin_members_per_page', 20 );
		$result   = memberkin()->get( MembershipRepository::class )->query(
			array(
				'statuses'  => $statuses,
				'level_ids' => ! empty( $_GET['level_id'] ) ? array( absint( $_GET['level_id'] ) ) : array(),
				'search'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
				'orderby'   => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at',
				'order'     => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
				'per_page'  => $per_page,
				'page'      => $this->get_pagenum(),
			)
		);
		// phpcs:enable
		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'member' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
		// Prime the user cache in one query.
		cache_users( array_unique( array_map( static fn( Membership $m ) => $m->user_id, $this->items ) ) );
	}

	/**
	 * Checkbox column.
	 *
	 * @param Membership $item Row.
	 */
	protected function column_cb( $item ): string {
		return '<label class="screen-reader-text" for="memberkin-m-' . (int) $item->id . '">' . esc_html__( 'Select membership', 'memberkin' ) . '</label><input type="checkbox" id="memberkin-m-' . (int) $item->id . '" name="membership_ids[]" value="' . (int) $item->id . '" />';
	}

	/**
	 * Member column.
	 *
	 * @param Membership $item Row.
	 */
	protected function column_member( Membership $item ): string {
		$user = get_userdata( $item->user_id );
		if ( ! $user ) {
			/* translators: %d: user id. */
			return esc_html( sprintf( __( 'Deleted user #%d', 'memberkin' ), $item->user_id ) );
		}
		$url  = MembersScreen::member_url( $item->user_id );
		$out  = get_avatar(
			$user->ID,
			32,
			'',
			'',
			array(
				'class'      => 'alignleft',
				'extra_attr' => 'style="margin-right:8px"',
			)
		);
		$out .= '<strong><a class="row-title" href="' . esc_url( $url ) . '">' . esc_html( $user->display_name ) . '</a></strong><br /><span class="memberkin-muted">' . esc_html( $user->user_email ) . '</span>';
		$out .= $this->row_actions(
			array(
				'view' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'View member', 'memberkin' ) . '</a>',
				'user' => '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html__( 'Edit user', 'memberkin' ) . '</a>',
			)
		);
		return $out;
	}

	/**
	 * Default columns.
	 *
	 * @param Membership $item        Row.
	 * @param string     $column_name Column.
	 */
	protected function column_default( $item, $column_name ): string {
		$date = static fn( ?\DateTimeImmutable $d ): string => $d ? esc_html( wp_date( get_option( 'date_format' ), $d->getTimestamp() ) ) : '—';
		switch ( $column_name ) {
			case 'level':
				return esc_html( isset( $this->levels[ $item->level_id ] ) ? $this->levels[ $item->level_id ]->name : '#' . $item->level_id );
			case 'status':
				return MembersScreen::status_badge( $item );
			case 'price':
				return esc_html( $item->schedule->is_free() ? __( 'Free', 'memberkin' ) : $item->schedule->describe() );
			case 'starts_at':
				return $date( $item->starts_at );
			case 'ends_at':
				return $item->ends_at ? $date( $item->ends_at ) : esc_html__( 'Never', 'memberkin' );
		}
		return '';
	}

	/**
	 * Empty state.
	 */
	public function no_items(): void {
		esc_html_e( 'No memberships found.', 'memberkin' );
	}
}
