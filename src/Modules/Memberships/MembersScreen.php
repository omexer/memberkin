<?php
/**
 * Members admin screen.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Memberships;

use Memberkin\Admin\Flash;
use Memberkin\Admin\Html;
use Memberkin\Core\Capabilities;
use Memberkin\Core\Events;
use Memberkin\Core\Plugin;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Membership;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\MembershipRepository;
use Memberkin\Service\MembershipService;

defined( 'ABSPATH' ) || exit;

/**
 * Members list and member detail.
 */
final class MembersScreen {

	public const PAGE = 'memberkin-members';

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
		add_action( 'admin_post_memberkin_member_action', array( $this, 'handle_action' ) );
		add_action( 'admin_init', array( $this, 'handle_bulk' ) );
	}

	/**
	 * Member detail URL.
	 *
	 * @param int $user_id User id.
	 */
	public static function member_url( int $user_id ): string {
		return add_query_arg(
			array(
				'page'    => self::PAGE,
				'user_id' => $user_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Status badge HTML.
	 *
	 * @param Membership $m Membership.
	 */
	public static function status_badge( Membership $m ): string {
		$label = Membership::statuses()[ $m->status ] ?? $m->status;
		$html  = '<span class="memberkin-badge memberkin-badge--' . esc_attr( $m->status ) . '">' . esc_html( $label ) . '</span>';
		if ( Membership::ENDED === $m->status && $m->end_reason ) {
			$html .= '<br /><span class="memberkin-muted">' . esc_html( Membership::end_reasons()[ $m->end_reason ] ?? $m->end_reason ) . '</span>';
		}
		if ( Membership::PAST_DUE === $m->status && $m->grace_until ) {
			/* translators: %s: date. */
			$html .= '<br /><span class="memberkin-muted">' . esc_html( sprintf( __( 'Access until %s', 'memberkin' ), wp_date( get_option( 'date_format' ), $m->grace_until->getTimestamp() ) ) ) . '</span>';
		}
		return $html;
	}

	/**
	 * Router.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_MEMBERS ) ) {
			wp_die( esc_html__( 'You are not allowed to view members.', 'memberkin' ), 403 );
		}
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap">';
		if ( $user_id ) {
			$this->render_member( $user_id );
		} else {
			$this->render_list();
		}
		echo '</div>';
	}

	/**
	 * List view.
	 */
	private function render_list(): void {
		$table = new MembersListTable();
		$table->prepare_items();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters passed to add-on title actions.
		$export_filters = array(
			'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'level_id' => isset( $_GET['level_id'] ) ? absint( $_GET['level_id'] ) : 0,
			's'        => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);
		// phpcs:enable
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Members', 'memberkin' ); ?></h1>
		<?php echo Html::screen_actions( 'members', $export_filters ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Html::screen_actions(). ?>
		<?php if ( current_user_can( Capabilities::MANAGE_MEMBERS ) ) : ?>
			<a href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'page' => self::PAGE,
						'add'  => 1,
					),
					admin_url( 'admin.php' )
				)
			);
			?>
						" class="page-title-action"><?php esc_html_e( 'Add Membership', 'memberkin' ); ?></a>
		<?php endif; ?>
		<hr class="wp-header-end" />
		<?php
		Flash::render();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['add'] ) && current_user_can( Capabilities::MANAGE_MEMBERS ) ) {
			$this->render_grant_form( 0 );
		}
		$table->views();
		?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['status'] ) ) :
				?>
				<input type="hidden" name="status" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>" />
			<?php endif; ?>
			<?php $table->search_box( __( 'Search members', 'memberkin' ), 'memberkin-members' ); ?>
			<?php $table->display(); ?>
		</form>
		<?php
	}

	/**
	 * Member detail view.
	 *
	 * @param int $user_id User id.
	 */
	private function render_member( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'User not found.', 'memberkin' ) . '</p></div>';
			return;
		}
		$levels      = $this->plugin->get( LevelRepository::class );
		$memberships = $this->plugin->get( MembershipRepository::class )->all_for_user( $user_id );
		$can_manage  = current_user_can( Capabilities::MANAGE_MEMBERS );
		$date        = static fn( ?\DateTimeImmutable $d ): string => $d ? wp_date( get_option( 'date_format' ), $d->getTimestamp() ) : '—';
		?>
		<h1 class="wp-heading-inline"><?php echo esc_html( $user->display_name ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( get_edit_user_link( $user_id ) ); ?>"><?php esc_html_e( 'Edit user', 'memberkin' ); ?></a>
		<hr class="wp-header-end" />
		<?php Flash::render(); ?>
		<p>
			<?php
			echo get_avatar(
				$user_id,
				48,
				'',
				'',
				array(
					'class'      => 'alignleft',
					'extra_attr' => 'style="margin-right:12px"',
				)
			);
			?>
			<strong><?php echo esc_html( $user->user_email ); ?></strong><br />
			<?php
			/* translators: %s: date. */
			echo esc_html( sprintf( __( 'Registered %s', 'memberkin' ), wp_date( get_option( 'date_format' ), strtotime( $user->user_registered . ' UTC' ) ) ) );
			?>
			· <a href="<?php echo esc_url( add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( '← All members', 'memberkin' ); ?></a>
		</p>
		<br class="clear" />

		<h2><?php esc_html_e( 'Memberships', 'memberkin' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Level', 'memberkin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'memberkin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Price', 'memberkin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Started', 'memberkin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Ends', 'memberkin' ); ?></th>
				<?php
				if ( $can_manage ) :
					?>
					<th scope="col"><?php esc_html_e( 'Actions', 'memberkin' ); ?></th><?php endif; ?>
			</tr></thead>
			<tbody>
			<?php if ( ! $memberships ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'This user has no memberships.', 'memberkin' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $memberships as $m ) : ?>
				<?php $level = $levels->find( $m->level_id ); ?>
				<tr>
					<td><strong><?php echo esc_html( $level ? $level->name : '#' . $m->level_id ); ?></strong><br /><span class="memberkin-muted">#<?php echo (int) $m->id; ?> · <?php echo esc_html( $m->source ); ?></span></td>
					<td><?php echo self::status_badge( $m ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in status_badge(). ?></td>
					<td><?php echo esc_html( $m->schedule->is_free() ? __( 'Free', 'memberkin' ) : $m->schedule->describe() ); ?></td>
					<td><?php echo esc_html( $date( $m->starts_at ) ); ?></td>
					<td><?php echo $m->ends_at ? esc_html( $date( $m->ends_at ) ) : esc_html__( 'Never', 'memberkin' ); ?></td>
					<?php if ( $can_manage ) : ?>
						<td><?php $this->render_membership_actions( $m ); ?></td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		if ( $can_manage ) {
			$this->render_grant_form( $user_id );
		}
		/**
		 * Fires on the member detail screen after the memberships table (subscriptions, orders, fields …).
		 *
		 * @param \WP_User $user User.
		 */
		do_action( 'memberkin_member_detail', $user );
		?>

		<h2 class="memberkin-form-section"><?php esc_html_e( 'History', 'memberkin' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th scope="col" style="width:14em;"><?php esc_html_e( 'Date', 'memberkin' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Event', 'memberkin' ); ?></th>
				<th scope="col" style="width:10em;"><?php esc_html_e( 'By', 'memberkin' ); ?></th>
			</tr></thead>
			<tbody>
			<?php $events = Events::for_user( $user_id, 100 ); ?>
			<?php if ( ! $events ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'No history yet.', 'memberkin' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $events as $event ) : ?>
				<tr>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event->created_at . ' UTC' ) ) ); ?></td>
					<td><?php echo esc_html( self::describe_event( $event ) ); ?></td>
					<td><?php echo esc_html( self::describe_actor( $event ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Action buttons for one membership.
	 *
	 * @param Membership $m Membership.
	 */
	private function render_membership_actions( Membership $m ): void {
		if ( ! $m->is_current() ) {
			echo '—';
			return;
		}
		$button = function ( string $verb, string $label, string $confirm = '', string $css_class = 'button button-small' ) use ( $m ): void {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 4px 4px 0;">';
			echo '<input type="hidden" name="action" value="memberkin_member_action" /><input type="hidden" name="do" value="' . esc_attr( $verb ) . '" /><input type="hidden" name="membership_id" value="' . (int) $m->id . '" />';
			wp_nonce_field( 'memberkin_member_' . $verb . '_' . $m->id );
			echo '<button type="submit" class="' . esc_attr( $css_class ) . '"' . ( $confirm ? ' data-memberkin-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
		};

		if ( Membership::PENDING === $m->status || Membership::PENDING_APPROVAL === $m->status ) {
			$button( 'activate', Membership::PENDING_APPROVAL === $m->status ? __( 'Approve', 'memberkin' ) : __( 'Activate', 'memberkin' ) );
		}
		if ( Membership::SUSPENDED === $m->status ) {
			$button( 'unsuspend', __( 'Lift suspension', 'memberkin' ) );
		} elseif ( Membership::can_transition( $m->status, Membership::SUSPENDED ) ) {
			$button( 'suspend', __( 'Suspend', 'memberkin' ), __( 'Suspend this membership? The member loses access until you lift the suspension.', 'memberkin' ) );
		}
		if ( Membership::PAUSED === $m->status ) {
			$button( 'resume', __( 'Resume', 'memberkin' ) );
		}
		if ( Membership::CANCELLING === $m->status ) {
			$button( 'reactivate', __( 'Undo cancellation', 'memberkin' ) );
		}
		$button( 'end', __( 'End now', 'memberkin' ), __( 'End this membership now? The member loses access immediately.', 'memberkin' ), 'button button-small button-link-delete' );

		// End date editor.
		$value = $m->ends_at ? wp_date( 'Y-m-d', $m->ends_at->getTimestamp() ) : '';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:4px;">';
		echo '<input type="hidden" name="action" value="memberkin_member_action" /><input type="hidden" name="do" value="set_dates" /><input type="hidden" name="membership_id" value="' . (int) $m->id . '" />';
		wp_nonce_field( 'memberkin_member_set_dates_' . $m->id );
		echo '<label class="screen-reader-text" for="memberkin-ends-' . (int) $m->id . '">' . esc_html__( 'End date', 'memberkin' ) . '</label>';
		echo '<input type="date" id="memberkin-ends-' . (int) $m->id . '" name="ends_at" value="' . esc_attr( $value ) . '" /> ';
		echo '<button type="submit" class="button button-small">' . esc_html__( 'Set end date', 'memberkin' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Leave empty for no end date.', 'memberkin' ) . '</p></form>';
	}

	/**
	 * "Add membership" form.
	 *
	 * @param int $user_id Fixed user (0 = choose by email/login).
	 */
	private function render_grant_form( int $user_id ): void {
		$levels = $this->plugin->get( LevelRepository::class )->query( array( 'status' => array( 'active', 'hidden' ) ) );
		?>
		<h2 class="memberkin-form-section"><?php esc_html_e( 'Add a membership', 'memberkin' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="memberkin_member_action" />
			<input type="hidden" name="do" value="grant" />
			<input type="hidden" name="membership_id" value="0" />
			<?php wp_nonce_field( 'memberkin_member_grant_0' ); ?>
			<table class="form-table" role="presentation"><tbody>
				<?php if ( ! $user_id ) : ?>
					<tr><th scope="row"><label for="memberkin-grant-user"><?php esc_html_e( 'User (email or username)', 'memberkin' ); ?></label></th>
						<td><input type="text" id="memberkin-grant-user" name="user" class="regular-text" required /></td></tr>
				<?php else : ?>
					<input type="hidden" name="user_id" value="<?php echo (int) $user_id; ?>" />
				<?php endif; ?>
				<tr><th scope="row"><label for="memberkin-grant-level"><?php esc_html_e( 'Level', 'memberkin' ); ?></label></th>
					<td><select id="memberkin-grant-level" name="level_id" required>
						<?php foreach ( $levels as $level ) : ?>
							<option value="<?php echo (int) $level->id; ?>"><?php echo esc_html( $level->name ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<tr><th scope="row"><label for="memberkin-grant-ends"><?php esc_html_e( 'End date', 'memberkin' ); ?></label></th>
					<td><input type="date" id="memberkin-grant-ends" name="ends_at" />
					<p class="description"><?php esc_html_e( 'Empty: use the level’s expiration settings. No payment is taken; the member gets access immediately.', 'memberkin' ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Notify', 'memberkin' ); ?></th>
					<td><label><input type="checkbox" name="notify" value="1" checked /> <?php esc_html_e( 'Send the member a welcome email', 'memberkin' ); ?></label></td></tr>
			</tbody></table>
			<?php submit_button( __( 'Add membership', 'memberkin' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Handles single membership actions.
	 *
	 * @throws ValidationException Internally for unknown memberships/actions (caught and shown as notices).
	 */
	public function handle_action(): void {
		if ( ! current_user_can( Capabilities::MANAGE_MEMBERS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage members.', 'memberkin' ), 403 );
		}
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$id = isset( $_POST['membership_id'] ) ? absint( $_POST['membership_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified just below.
		check_admin_referer( 'memberkin_member_' . $do . '_' . $id );

		$service = $this->plugin->get( MembershipService::class );
		$repo    = $this->plugin->get( MembershipRepository::class );
		$user_id = 0;
		try {
			if ( 'grant' === $do ) {
				$user_id = $this->resolve_user();
				$args    = array(
					'source'  => 'admin',
					'context' => array( 'notify' => ! empty( $_POST['notify'] ) ),
				);
				$ends    = $this->parse_date( isset( $_POST['ends_at'] ) ? sanitize_text_field( wp_unslash( $_POST['ends_at'] ) ) : '' );
				if ( $ends ) {
					$args['ends_at'] = $ends;
				}
				$service->grant( $user_id, isset( $_POST['level_id'] ) ? absint( $_POST['level_id'] ) : 0, $args );
				Flash::add( __( 'Membership added.', 'memberkin' ) );
			} else {
				$m = $repo->find( $id );
				if ( ! $m ) {
					throw new ValidationException( array( esc_html__( 'Membership not found.', 'memberkin' ) ) );
				}
				$user_id = $m->user_id;
				switch ( $do ) {
					case 'activate':
						/**
						 * Whether an add-on handled activating this membership (for example approving a pending application).
						 *
						 * @param bool       $handled Handled.
						 * @param Membership $m       Membership.
						 */
						if ( ! apply_filters( 'memberkin_admin_activate_membership', false, $m ) ) {
							$service->activate(
								$id,
								array(),
								array(
									'reason'        => 'admin',
									'skip_approval' => true,
								)
							);
						}
						break;
					case 'suspend':
						$service->suspend( $id );
						break;
					case 'unsuspend':
						$service->unsuspend( $id );
						break;
					case 'resume':
						$service->resume( $id );
						break;
					case 'reactivate':
						/**
						 * Filters the end date restored when an admin undoes a cancellation (billing sets it from the subscription).
						 *
						 * @param \DateTimeImmutable|null $ends_at End.
						 * @param Membership              $m       Membership.
						 */
						$service->reactivate( $id, apply_filters( 'memberkin_reactivated_membership_ends_at', null, $m ) );
						break;
					case 'end':
						$service->end( $id, 'admin' );
						break;
					case 'set_dates':
						$service->set_dates( $id, $this->parse_date( isset( $_POST['ends_at'] ) ? sanitize_text_field( wp_unslash( $_POST['ends_at'] ) ) : '' ) );
						break;
					default:
						throw new ValidationException( array( esc_html__( 'Unknown action.', 'memberkin' ) ) );
				}
				Flash::add( __( 'Membership updated.', 'memberkin' ) );
			}
		} catch ( ValidationException $e ) {
			Flash::errors( $e->errors() );
		}
		Flash::redirect( $user_id ? self::member_url( $user_id ) : add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) ) );
	}

	/**
	 * Bulk actions from the list table.
	 */
	public function handle_bulk(): void {
		if ( ! isset( $_GET['page'], $_GET['membership_ids'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}
		$action = isset( $_GET['action'] ) && '-1' !== $_GET['action'] ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ( isset( $_GET['action2'] ) ? sanitize_key( wp_unslash( $_GET['action2'] ) ) : '' );
		$ids    = array_map( 'absint', (array) wp_unslash( $_GET['membership_ids'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below once we know this is our request.
		if ( ! in_array( $action, array( 'suspend', 'unsuspend', 'end' ), true ) ) {
			return;
		}
		check_admin_referer( 'bulk-memberships' );
		if ( ! current_user_can( Capabilities::MANAGE_MEMBERS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage members.', 'memberkin' ), 403 );
		}
		$service = $this->plugin->get( MembershipService::class );
		$done    = 0;
		$failed  = 0;
		foreach ( $ids as $id ) {
			try {
				match ( $action ) {
					'suspend'   => $service->suspend( $id ),
					'unsuspend' => $service->unsuspend( $id ),
					'end'       => $service->end( $id, 'admin' ),
				};
				++$done;
			} catch ( ValidationException $e ) {
				++$failed;
			}
		}
		/* translators: %d: number of memberships. */
		Flash::add( sprintf( _n( '%d membership updated.', '%d memberships updated.', $done, 'memberkin' ), $done ) );
		if ( $failed ) {
			/* translators: %d: number of memberships. */
			Flash::add( sprintf( _n( '%d membership could not be changed in its current status.', '%d memberships could not be changed in their current status.', $failed, 'memberkin' ), $failed ), 'warning' );
		}
		Flash::redirect( remove_query_arg( array( 'action', 'action2', 'membership_ids', '_wpnonce', '_wp_http_referer' ) ) );
	}

	/**
	 * Resolves the user for "grant".
	 *
	 * @throws ValidationException When not found.
	 */
	private function resolve_user(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- caller verified the nonce.
		if ( ! empty( $_POST['user_id'] ) ) {
			return absint( $_POST['user_id'] );
		}
		$needle = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
		// phpcs:enable
		$user = is_email( $needle ) ? get_user_by( 'email', $needle ) : get_user_by( 'login', $needle );
		if ( ! $user ) {
			throw new ValidationException( array( esc_html__( 'No user with that email address or username.', 'memberkin' ) ) );
		}
		return $user->ID;
	}

	/**
	 * Parses Y-m-d in the site timezone (end of day) to UTC.
	 *
	 * @param string $value Date.
	 */
	private function parse_date( string $value ): ?\DateTimeImmutable {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		return ( new \DateTimeImmutable( $value . ' 23:59:59', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Human description of a history event.
	 *
	 * @param object $event Event row.
	 */
	public static function describe_event( object $event ): string {
		$levels = memberkin()->get( LevelRepository::class );
		$data   = (array) $event->data;
		$level  = null;
		if ( 'membership' === $event->object_type ) {
			$m     = memberkin()->get( MembershipRepository::class )->find( (int) $event->object_id );
			$level = $m ? $levels->find( $m->level_id ) : null;
		}
		$name = $level ? $level->name : '';
		$map  = array(
			/* translators: %s: level name. */
			'membership.created'       => __( 'Membership “%s” created', 'memberkin' ),
			/* translators: %s: level name. */
			'membership.active'        => __( 'Membership “%s” activated', 'memberkin' ),
			/* translators: %s: level name. */
			'membership.cancelling'    => __( 'Membership “%s” cancelled (access continues until the end date)', 'memberkin' ),
			/* translators: %s: level name. */
			'membership.past_due'      => __( 'Payment for “%s” is overdue', 'memberkin' ),
			/* translators: %s: level name. */
			'membership.paused'        => __( 'Membership “%s” paused', 'memberkin' ),
			/* translators: %s: level name. */
			'membership.suspended'     => __( 'Membership “%s” suspended', 'memberkin' ),
			/* translators: %s: level name. */
			'membership.ended'         => __( 'Membership “%s” ended', 'memberkin' ),
			/* translators: %s: level name. */
			'membership.dates_changed' => __( 'Dates of “%s” changed', 'memberkin' ),
		);
		/**
		 * Filters event descriptions (modules add their own events).
		 *
		 * @param array<string,string> $map   event => format with %s for the level name.
		 * @param object               $event Event.
		 */
		$map  = (array) apply_filters( 'memberkin_event_descriptions', $map, $event );
		$text = isset( $map[ $event->event ] ) ? sprintf( $map[ $event->event ], $name ) : $event->event;
		if ( 'membership.ended' === $event->event && ! empty( $data['reason'] ) ) {
			$text .= ' — ' . ( Membership::end_reasons()[ $data['reason'] ] ?? $data['reason'] );
		}
		if ( ! empty( $data['note'] ) ) {
			$text .= ' — ' . $data['note'];
		}
		return $text;
	}

	/**
	 * Who performed an event.
	 *
	 * @param object $event Event row.
	 */
	public static function describe_actor( object $event ): string {
		if ( in_array( $event->actor_type, array( 'admin', 'user' ), true ) && $event->actor_id ) {
			$user = get_userdata( (int) $event->actor_id );
			return $user ? $user->display_name : '#' . $event->actor_id;
		}
		return 'gateway' === $event->actor_type ? __( 'Payment gateway', 'memberkin' ) : __( 'System', 'memberkin' );
	}
}
