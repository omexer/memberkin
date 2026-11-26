<?php
/**
 * Subscriptions admin screen and member-detail panels.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Billing\Admin;

use Memberkin\Admin\Flash;
use Memberkin\Admin\Html;
use Memberkin\Core\Capabilities;
use Memberkin\Core\Plugin;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Order;
use Memberkin\Domain\Period;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\Contracts\Capability;
use Memberkin\Gateway\GatewayRegistry;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\OrderRepository;
use Memberkin\Repository\PaymentMethodRepository;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\CouponService;
use Memberkin\Service\SubscriptionService;

defined( 'ABSPATH' ) || exit;

/**
 * Subscription list/detail and actions.
 */
final class SubscriptionsScreen {

	public const PAGE = 'memberkin-subscriptions';

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
		add_action( 'admin_post_memberkin_subscription_action', array( $this, 'handle' ) );
		add_action( 'memberkin_member_detail', array( $this, 'member_panels' ) );
	}

	/**
	 * Router.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ORDERS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage subscriptions.', 'memberkin' ), 403 );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap">';
		if ( $id ) {
			$this->render_subscription( $id );
		} else {
			$table = new SubscriptionsListTable();
			$table->prepare_items();
			echo '<h1 class="wp-heading-inline">' . esc_html__( 'Subscriptions', 'memberkin' ) . '</h1>';
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
			$filters = array(
				'status'  => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
				'gateway' => isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '',
				's'       => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			);
			// phpcs:enable
			echo Html::screen_actions( 'subscriptions', $filters ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Html::screen_actions().
			echo '<hr class="wp-header-end" />';
			Flash::render();
			$table->views();
			echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
			$table->display();
			echo '</form>';
		}
		echo '</div>';
	}

	/**
	 * Detail view.
	 *
	 * @param int $id Subscription id.
	 */
	private function render_subscription( int $id ): void {
		$sub = $this->plugin->get( SubscriptionRepository::class )->find( $id );
		if ( ! $sub ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Subscription not found.', 'memberkin' ) . '</p></div>';
			return;
		}
		$level   = $this->plugin->get( LevelRepository::class )->find( $sub->level_id );
		$gateway = $this->plugin->get( GatewayRegistry::class )->get( $sub->gateway );
		$method  = $sub->payment_method_id ? $this->plugin->get( PaymentMethodRepository::class )->find( $sub->payment_method_id ) : null;
		$orders  = $this->plugin->get( OrderRepository::class )->for_subscription( $sub->id );
		?>
		<h1 class="wp-heading-inline">
			<?php
			/* translators: %d: subscription id. */
			echo esc_html( sprintf( __( 'Subscription #%d', 'memberkin' ), $sub->id ) );
			?>
		</h1>
		<?php echo Html::badge( $sub->status, Subscription::statuses()[ $sub->status ] ?? $sub->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<hr class="wp-header-end" />
		<?php Flash::render(); ?>
		<p><a href="<?php echo esc_url( Html::url( self::PAGE ) ); ?>"><?php esc_html_e( '← All subscriptions', 'memberkin' ); ?></a></p>
		<table class="form-table" role="presentation"><tbody>
			<tr><th scope="row"><?php esc_html_e( 'Member', 'memberkin' ); ?></th><td><?php echo Html::member_link( $sub->user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Level', 'memberkin' ); ?></th><td><?php echo esc_html( $level ? $level->name : '#' . $sub->level_id ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Amount', 'memberkin' ); ?></th><td>
				<?php
				echo Html::money( $sub->recurring_amount + $sub->tax_amount, $sub->currency ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo ' ' . esc_html(
					sprintf(
						/* translators: 1: interval, 2: unit. */
						__( 'every %1$d %2$s', 'memberkin' ),
						$sub->billing_interval,
						Period::label( $sub->billing_period, $sub->billing_interval )
					)
				);
				if ( $sub->billing_cycles ) {
					/* translators: 1: completed renewals, 2: total renewals. */
					echo ' · ' . esc_html( sprintf( __( '%1$d of %2$d renewals charged', 'memberkin' ), $sub->cycles_completed, $sub->billing_cycles ) );
				}
				?>
			</td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Current period', 'memberkin' ); ?></th><td><?php echo Html::date( $sub->current_period_start ) . ' – ' . Html::date( $sub->current_period_end ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Next payment', 'memberkin' ); ?></th><td><?php echo $sub->cancel_at_period_end ? esc_html__( 'None — cancels at the end of the period', 'memberkin' ) : Html::date( $sub->next_payment_at, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
			<?php if ( $sub->trial_ends_at ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Trial ends', 'memberkin' ); ?></th><td><?php echo Html::date( $sub->trial_ends_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
			<?php endif; ?>
			<?php if ( $sub->retry_count ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Failed payments', 'memberkin' ); ?></th><td>
					<?php
					/* translators: 1: attempts, 2: error. */
					echo esc_html( sprintf( __( '%1$d failed attempts. Last error: %2$s', 'memberkin' ), $sub->retry_count, (string) $sub->last_payment_error ) );
					if ( $sub->next_retry_at ) {
						echo '<br />' . esc_html__( 'Next retry:', 'memberkin' ) . ' ' . Html::date( $sub->next_retry_at, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>
				</td></tr>
			<?php endif; ?>
			<tr><th scope="row"><?php esc_html_e( 'Gateway', 'memberkin' ); ?></th><td>
				<?php echo esc_html( ( $gateway ? $gateway->label() : $sub->gateway ) . ' (' . $sub->gateway_mode . ') · ' . ( 'site' === $sub->billing_model ? __( 'renewals charged by this site', 'memberkin' ) : __( 'renewals billed by the gateway', 'memberkin' ) ) ); ?>
				<?php if ( $sub->gateway_subscription_id ) : ?>
					<br /><code><?php echo esc_html( $sub->gateway_subscription_id ); ?></code>
				<?php endif; ?>
				<?php if ( $method ) : ?>
					<br /><?php echo esc_html( $method->label() ); ?>
				<?php endif; ?>
			</td></tr>
			<?php if ( $sub->coupon_id ) : ?>
				<?php $coupon = $this->plugin->get( \Memberkin\Repository\CouponRepository::class )->find( $sub->coupon_id ); ?>
				<tr><th scope="row"><?php esc_html_e( 'Coupon', 'memberkin' ); ?></th><td><?php echo esc_html( $coupon ? $coupon->code : '#' . $sub->coupon_id ); ?><?php echo null !== $sub->coupon_cycles_remaining ? ' · ' . esc_html( sprintf( /* translators: %d: renewals. */ _n( '%d discounted renewal left', '%d discounted renewals left', $sub->coupon_cycles_remaining, 'memberkin' ), $sub->coupon_cycles_remaining ) ) : ''; ?></td></tr>
			<?php endif; ?>
		</tbody></table>

		<h2><?php esc_html_e( 'Actions', 'memberkin' ); ?></h2>
		<p>
		<?php
		$fields = array( 'subscription_id' => $sub->id );
		if ( $sub->is_live() && ! $sub->cancel_at_period_end && $sub->current_period_end && $sub->current_period_end > \Memberkin\Core\Clock::now() ) {
			echo Html::action_button( 'memberkin_subscription_action', 'memberkin_sub_cancel_end_' . $sub->id, $fields + array( 'do' => 'cancel_end' ), __( 'Cancel at period end', 'memberkin' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( $sub->is_live() ) {
			echo Html::action_button( 'memberkin_subscription_action', 'memberkin_sub_cancel_now_' . $sub->id, $fields + array( 'do' => 'cancel_now' ), __( 'Cancel now', 'memberkin' ), 'button button-link-delete', __( 'Cancel now? The member loses access immediately and is not refunded automatically.', 'memberkin' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( $sub->cancel_at_period_end && $sub->is_live() ) {
			echo Html::action_button( 'memberkin_subscription_action', 'memberkin_sub_reactivate_' . $sub->id, $fields + array( 'do' => 'reactivate' ), __( 'Undo cancellation', 'memberkin' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( Subscription::PAUSED === $sub->status ) {
			echo Html::action_button( 'memberkin_subscription_action', 'memberkin_sub_resume_' . $sub->id, $fields + array( 'do' => 'resume' ), __( 'Resume', 'memberkin' ), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		</p>
		<?php if ( in_array( $sub->status, array( Subscription::ACTIVE, Subscription::TRIALING ), true ) && ( 'site' === $sub->billing_model || ( $gateway && $gateway->supports( Capability::PAUSE ) ) ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="memberkin-inline-fields">
				<input type="hidden" name="action" value="memberkin_subscription_action" />
				<input type="hidden" name="do" value="pause" />
				<input type="hidden" name="subscription_id" value="<?php echo (int) $sub->id; ?>" />
				<?php wp_nonce_field( 'memberkin_sub_pause_' . $sub->id ); ?>
				<label for="memberkin-resume-at"><?php esc_html_e( 'Pause until', 'memberkin' ); ?></label>
				<input type="date" id="memberkin-resume-at" name="resume_at" />
				<button type="submit" class="button"><?php esc_html_e( 'Pause', 'memberkin' ); ?></button>
				<span class="description"><?php esc_html_e( 'Leave the date empty to pause until resumed manually.', 'memberkin' ); ?></span>
			</form>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Payments', 'memberkin' ); ?></h2>
		<?php $this->orders_table( $orders ); ?>
		<?php
	}

	/**
	 * Compact orders table.
	 *
	 * @param Order[] $orders Orders.
	 */
	private function orders_table( array $orders ): void {
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Order', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Date', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Type', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Total', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Status', 'memberkin' ) . '</th></tr></thead><tbody>';
		if ( ! $orders ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No orders.', 'memberkin' ) . '</td></tr>';
		}
		foreach ( $orders as $order ) {
			echo '<tr><td><a href="' . esc_url( Html::url( OrdersScreen::PAGE, array( 'id' => $order->id ) ) ) . '">' . esc_html( $order->number ) . '</a></td><td>' . Html::date( $order->created_at, true ) . '</td><td>' . esc_html( Order::types()[ $order->type ] ?? $order->type ) . '</td><td>' . Html::money( $order->total, $order->currency ) . '</td><td>' . Html::badge( $order->status, Order::statuses()[ $order->status ] ?? $order->status ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Html helpers escape.
		}
		echo '</tbody></table>';
	}

	/**
	 * Subscriptions and orders on the member detail screen.
	 *
	 * @param \WP_User $user User.
	 */
	public function member_panels( \WP_User $user ): void {
		if ( ! current_user_can( Capabilities::MANAGE_ORDERS ) ) {
			return;
		}
		$subs = $this->plugin->get( SubscriptionRepository::class )->query(
			array(
				'user_id'  => $user->ID,
				'per_page' => 50,
			)
		)['items'];
		echo '<h2 class="memberkin-form-section">' . esc_html__( 'Subscriptions', 'memberkin' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Subscription', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Level', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Amount', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Status', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Next payment', 'memberkin' ) . '</th></tr></thead><tbody>';
		if ( ! $subs ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No subscriptions.', 'memberkin' ) . '</td></tr>';
		}
		$levels = $this->plugin->get( LevelRepository::class );
		foreach ( $subs as $sub ) {
			$level = $levels->find( $sub->level_id );
			echo '<tr><td><a href="' . esc_url( Html::url( self::PAGE, array( 'id' => $sub->id ) ) ) . '">#' . (int) $sub->id . '</a></td><td>' . esc_html( $level ? $level->name : '#' . $sub->level_id ) . '</td><td>' . Html::money( $sub->recurring_amount + $sub->tax_amount, $sub->currency ) . '</td><td>' . Html::badge( $sub->status, Subscription::statuses()[ $sub->status ] ?? $sub->status ) . '</td><td>' . ( $sub->cancel_at_period_end ? '—' : Html::date( $sub->next_payment_at ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Html helpers escape.
		}
		echo '</tbody></table>';
		echo '<h2 class="memberkin-form-section">' . esc_html__( 'Orders', 'memberkin' ) . '</h2>';
		$this->orders_table( $this->plugin->get( OrderRepository::class )->for_user( $user->ID, 50 ) );
	}

	/**
	 * Action handler.
	 */
	public function handle(): void {
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified right below.
		check_admin_referer( 'memberkin_sub_' . $do . '_' . $id );
		if ( ! current_user_can( Capabilities::MANAGE_ORDERS ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'memberkin' ), 403 );
		}
		$service = $this->plugin->get( SubscriptionService::class );
		try {
			switch ( $do ) {
				case 'cancel_end':
					$service->cancel( $id, true, 'admin' );
					break;
				case 'cancel_now':
					$service->cancel( $id, false, 'admin' );
					break;
				case 'reactivate':
					$service->reactivate( $id );
					break;
				case 'resume':
					$service->resume( $id );
					break;
				case 'pause':
					$date = isset( $_POST['resume_at'] ) ? CouponService::parse_local_datetime( sanitize_text_field( wp_unslash( $_POST['resume_at'] ) ), false ) : null;
					$service->pause( $id, $date );
					break;
			}
			Flash::add( __( 'Subscription updated.', 'memberkin' ) );
		} catch ( ValidationException $e ) {
			Flash::errors( $e->errors() );
		}
		Flash::redirect( Html::url( self::PAGE, array( 'id' => $id ) ) );
	}
}
