<?php
/**
 * Emails admin screen and email log.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Emails\Admin;

use Memberkin\Admin\Flash;
use Memberkin\Admin\Html;
use Memberkin\Core\Capabilities;
use Memberkin\Core\Db;
use Memberkin\Core\Plugin;
use Memberkin\Modules\Emails\EmailService;
use Memberkin\Modules\Emails\EmailTemplates;

defined( 'ABSPATH' ) || exit;

/**
 * Turn emails on or off; view the log. Add-ons may add a template editor through "memberkin_email_editor".
 */
final class EmailsScreen {

	public const PAGE = 'memberkin-emails';

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
		add_action( 'admin_post_memberkin_toggle_email', array( $this, 'handle_toggle' ) );
		add_action( 'admin_post_memberkin_resend_email', array( $this, 'handle_resend' ) );
		add_filter(
			'memberkin_tools_tabs',
			static function ( array $tabs ): array {
				$tabs['emails'] = __( 'Email log', 'memberkin' );
				return $tabs;
			}
		);
		add_action( 'memberkin_tools_tab_emails', array( $this, 'render_log' ) );
	}

	/**
	 * Capability guard.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_EMAILS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage emails.', 'memberkin' ), 403 );
		}
	}

	/**
	 * Router.
	 */
	public function render(): void {
		$this->authorize();
		$id = isset( $_GET['email'] ) ? sanitize_key( wp_unslash( $_GET['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap">';
		if ( '' !== $id && EmailTemplates::get( $id ) && has_action( 'memberkin_email_editor' ) ) {
			/**
			 * Renders an email template editor (inside the screen's wrap).
			 *
			 * @param string $id Template id.
			 */
			do_action( 'memberkin_email_editor', $id );
		} else {
			$this->render_list();
		}
		echo '</div>';
	}

	/**
	 * Template list.
	 */
	private function render_list(): void {
		echo '<h1>' . esc_html__( 'Emails', 'memberkin' ) . '</h1>';
		Flash::render();
		echo '<p>' . esc_html__( 'Sender name, address and admin recipients are set under Settings → Emails.', 'memberkin' ) . ' <a href="' . esc_url( Html::url( 'memberkin-settings', array( 'tab' => 'emails' ) ) ) . '">' . esc_html__( 'Email settings', 'memberkin' ) . '</a></p>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Email', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Recipient', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Subject', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Status', 'memberkin' ) . '</th></tr></thead><tbody>';
		$editable = has_action( 'memberkin_email_editor' );
		foreach ( array_keys( EmailTemplates::defaults() ) as $id ) {
			$t = EmailTemplates::get( $id );
			echo '<tr><td><strong>';
			if ( $editable ) {
				echo '<a class="row-title" href="' . esc_url( Html::url( self::PAGE, array( 'email' => $id ) ) ) . '">' . esc_html( $t['label'] ) . '</a>';
			} else {
				echo esc_html( $t['label'] );
			}
			echo '</strong><br /><span class="memberkin-muted">' . esc_html( $t['description'] ) . '</span></td>';
			echo '<td>' . esc_html( 'admin' === $t['recipient'] ? __( 'Admins', 'memberkin' ) : __( 'Member', 'memberkin' ) ) . '</td>';
			echo '<td>' . esc_html( $t['subject'] ) . '</td>';
			echo '<td>' . Html::badge( $t['enabled'] ? 'active' : 'ended', $t['enabled'] ? __( 'On', 'memberkin' ) : __( 'Off', 'memberkin' ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Html helpers escape.
			$toggle = Html::action_button(
				'memberkin_toggle_email',
				'memberkin_toggle_email_' . $id,
				array(
					'email'   => $id,
					'enabled' => $t['enabled'] ? '0' : '1',
				),
				$t['enabled'] ? __( 'Turn off', 'memberkin' ) : __( 'Turn on', 'memberkin' ),
				'button button-small'
			);
			echo $toggle . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a form built by Html::action_button(), which escapes every value.
		}
		echo '</tbody></table>';
	}

	/**
	 * Turns an email on or off.
	 */
	public function handle_toggle(): void {
		$this->authorize();
		$id = isset( $_POST['email'] ) ? sanitize_key( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next.
		check_admin_referer( 'memberkin_toggle_email_' . $id );
		if ( ! EmailTemplates::get( $id ) ) {
			wp_die( esc_html__( 'Unknown email.', 'memberkin' ), 404 );
		}
		$enabled = ! empty( $_POST['enabled'] );
		EmailTemplates::set_enabled( $id, $enabled );
		Flash::add( $enabled ? __( 'Email turned on.', 'memberkin' ) : __( 'Email turned off.', 'memberkin' ) );
		Flash::redirect( Html::url( self::PAGE ) );
	}

	/**
	 * Email log (Tools tab).
	 */
	public function render_log(): void {
		if ( ! current_user_can( Capabilities::MANAGE_EMAILS ) ) {
			return;
		}
		global $wpdb;
		$table = Db::table_name( 'email_log' );
		$page  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id, template, recipient, subject, status, error, attempts, created_at, sent_at, (body IS NOT NULL AND body <> '') AS has_body FROM {$table} ORDER BY id DESC LIMIT 50 OFFSET %d", ( $page - 1 ) * 50 ) );
		// phpcs:enable
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Date', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'To', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Subject', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Status', 'memberkin' ) . '</th><th scope="col"></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No emails sent yet.', 'memberkin' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$badge = 'sent' === $row->status ? 'paid' : ( 'failed' === $row->status ? 'failed' : 'pending' );
			echo '<tr><td>' . Html::date( Db::to_date( $row->created_at ), true ) . '</td><td>' . esc_html( $row->recipient ) . '</td><td>' . esc_html( $row->subject ) . '<br /><span class="memberkin-muted">' . esc_html( $row->template ) . '</span></td><td>' . Html::badge( $badge, $row->status ) . ( $row->error ? '<br /><span class="memberkin-muted">' . esc_html( $row->error ) . '</span>' : '' ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Html helpers escape.
			if ( (int) $row->has_body ) {
				echo Html::action_button( 'memberkin_resend_email', 'memberkin_resend_email_' . $row->id, array( 'id' => $row->id ), __( 'Resend', 'memberkin' ), 'button button-small' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $total > 50 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => (int) ceil( $total / 50 ),
					)
				)
			) . '</div></div>';
		}
		echo '<p class="description">' . esc_html__( 'Message bodies are kept only while unsent, unless "Store email bodies" is enabled under Settings → Emails.', 'memberkin' ) . '</p>';
	}

	/**
	 * Resend handler.
	 */
	public function handle_resend(): void {
		$this->authorize();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next.
		check_admin_referer( 'memberkin_resend_email_' . $id );
		Flash::add( $this->plugin->get( EmailService::class )->resend( $id ) ? __( 'Email queued again.', 'memberkin' ) : __( 'This email can no longer be resent (its body was not stored).', 'memberkin' ), 'success' );
		Flash::redirect( Html::url( 'memberkin-tools', array( 'tab' => 'emails' ) ) );
	}
}
