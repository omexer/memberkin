<?php
/**
 * Tools: webhook events and technical logs.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Billing\Admin;

use Memberkin\Admin\Flash;
use Memberkin\Admin\Html;
use Memberkin\Core\Capabilities;
use Memberkin\Core\Db;
use Memberkin\Core\Jobs;
use Memberkin\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only diagnostics with a "replay" action for webhook events.
 */
final class ToolsScreen {

	public const PAGE = 'memberkin-tools';

	private const PER_PAGE = 50;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_memberkin_replay_webhook', array( $this, 'replay' ) );
	}

	/**
	 * Tabs.
	 *
	 * @return array<string, string>
	 */
	private function tabs(): array {
		/**
		 * Filters Tools tabs (modules add import/export, migration …).
		 *
		 * @param array<string,string> $tabs Tabs.
		 */
		return (array) apply_filters(
			'memberkin_tools_tabs',
			array(
				'webhooks' => __( 'Webhook events', 'memberkin' ),
				'logs'     => __( 'Logs', 'memberkin' ),
			)
		);
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'memberkin' ), 403 );
		}
		$tabs = $this->tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'webhooks'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'webhooks';
		echo '<div class="wrap"><h1>' . esc_html__( 'Membership Tools', 'memberkin' ) . '</h1>';
		Flash::render();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $id => $label ) {
			echo '<a class="nav-tab' . ( $id === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( Html::url( self::PAGE, array( 'tab' => $id ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( 'webhooks' === $tab ) {
			$this->webhooks();
		} elseif ( 'logs' === $tab ) {
			$this->logs();
		} else {
			/**
			 * Renders a custom Tools tab.
			 *
			 * @param string $tab Tab id.
			 */
			do_action( 'memberkin_tools_tab_' . $tab );
		}
		echo '</div>';
	}

	/**
	 * Current page number.
	 */
	private function paged(): int {
		return max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Simple pager.
	 *
	 * @param int $total Total rows.
	 */
	private function pager( int $total ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
			(string) paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $this->paged(),
					'total'   => $pages,
				)
			)
		) . '</div></div>';
	}

	/**
	 * Webhook events tab.
	 */
	private function webhooks(): void {
		global $wpdb;
		$table  = Db::table_name( 'webhook_events' );
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = in_array( $status, array( 'received', 'processing', 'processed', 'ignored', 'failed' ), true ) ? $status : '';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table, admin diagnostics; nothing to cache.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ( %s = '' OR status = %s )", $table, $status, $status ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, gateway, gateway_mode, event_id, event_type, status, attempts, last_error, received_at, processed_at FROM %i WHERE ( %s = '' OR status = %s ) ORDER BY id DESC LIMIT %d OFFSET %d", $table, $status, $status, self::PER_PAGE, ( $this->paged() - 1 ) * self::PER_PAGE ) );
		// phpcs:enable
		echo '<p>';
		foreach ( array(
			''          => __( 'All', 'memberkin' ),
			'failed'    => __( 'Failed', 'memberkin' ),
			'received'  => __( 'Waiting', 'memberkin' ),
			'processed' => __( 'Processed', 'memberkin' ),
			'ignored'   => __( 'Ignored', 'memberkin' ),
		) as $key => $label ) {
			echo '<a href="' . esc_url(
				Html::url(
					self::PAGE,
					array_filter(
						array(
							'tab'    => 'webhooks',
							'status' => $key,
						)
					)
				)
			) . '" class="' . ( $status === $key ? 'current' : '' ) . '">' . esc_html( $label ) . '</a> &nbsp; ';
		}
		echo '</p><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Received', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Gateway', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Event', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Status', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Error', 'memberkin' ) . '</th><th scope="col"></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No webhook events yet.', 'memberkin' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			echo '<tr><td>' . Html::date( Db::to_date( $row->received_at ), true ) . '</td><td>' . esc_html( $row->gateway . ' (' . $row->gateway_mode . ')' ) . '</td><td>' . esc_html( $row->event_type ) . '<br /><code class="memberkin-muted">' . esc_html( $row->event_id ) . '</code></td><td>' . Html::badge( 'processed' === $row->status ? 'paid' : ( 'failed' === $row->status ? 'failed' : 'pending' ), $row->status ) . ( $row->attempts > 1 ? ' <span class="memberkin-muted">×' . (int) $row->attempts . '</span>' : '' ) . '</td><td>' . esc_html( (string) $row->last_error ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Html helpers escape.
			if ( in_array( $row->status, array( 'failed', 'received', 'ignored' ), true ) ) {
				echo Html::action_button( 'memberkin_replay_webhook', 'memberkin_replay_webhook_' . $row->id, array( 'id' => $row->id ), __( 'Process again', 'memberkin' ), 'button button-small' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$this->pager( $total );
	}

	/**
	 * Logs tab.
	 */
	private function logs(): void {
		global $wpdb;
		$table   = Db::table_name( 'logs' );
		$channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table, admin diagnostics; nothing to cache.
		$channels = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT channel FROM %i ORDER BY channel', $table ) );
		$total    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ( %s = '' OR channel = %s )", $table, $channel, $channel ) );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %s = '' OR channel = %s ) ORDER BY id DESC LIMIT %d OFFSET %d", $table, $channel, $channel, self::PER_PAGE, ( $this->paged() - 1 ) * self::PER_PAGE ) );
		// phpcs:enable
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" /><input type="hidden" name="tab" value="logs" /><p><label for="memberkin-channel">' . esc_html__( 'Channel', 'memberkin' ) . '</label> <select id="memberkin-channel" name="channel"><option value="">' . esc_html__( 'All', 'memberkin' ) . '</option>';
		foreach ( $channels as $c ) {
			echo '<option value="' . esc_attr( $c ) . '" ' . selected( $channel, $c, false ) . '>' . esc_html( $c ) . '</option>';
		}
		echo '</select> <button class="button">' . esc_html__( 'Filter', 'memberkin' ) . '</button></p></form>';
		echo '<table class="widefat striped"><thead><tr><th scope="col" style="width:14em;">' . esc_html__( 'Date', 'memberkin' ) . '</th><th scope="col" style="width:6em;">' . esc_html__( 'Level', 'memberkin' ) . '</th><th scope="col" style="width:9em;">' . esc_html__( 'Channel', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Message', 'memberkin' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="4">' . esc_html__( 'The log is empty.', 'memberkin' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			echo '<tr><td>' . Html::date( Db::to_date( $row->created_at ), true ) . '</td><td>' . esc_html( $row->level ) . '</td><td>' . esc_html( $row->channel ) . '</td><td>' . esc_html( $row->message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( $row->context ) {
				echo '<details><summary>' . esc_html__( 'Details', 'memberkin' ) . '</summary><pre style="white-space:pre-wrap;">' . esc_html( (string) wp_json_encode( json_decode( (string) $row->context, true ), JSON_PRETTY_PRINT ) ) . '</pre></details>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$this->pager( $total );
	}

	/**
	 * Replays a stored webhook event.
	 */
	public function replay(): void {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next.
		check_admin_referer( 'memberkin_replay_webhook_' . $id );
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'memberkin' ), 403 );
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Db::table_name( 'webhook_events' ),
			array(
				'status'     => 'received',
				'last_error' => null,
			),
			array( 'id' => $id )
		);
		// phpcs:enable
		Jobs::async( 'memberkin_process_webhook', array( $id ), true );
		Flash::add( __( 'The event was queued for processing.', 'memberkin' ) );
		Flash::redirect( Html::url( self::PAGE, array( 'tab' => 'webhooks' ) ) );
	}
}
