<?php
/**
 * Orders admin screen.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Billing\Admin;

use Memberkin\Admin\Flash;
use Memberkin\Admin\Html;
use Memberkin\Core\Capabilities;
use Memberkin\Core\Money;
use Memberkin\Core\Plugin;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Order;
use Memberkin\Gateway\Contracts\Capability;
use Memberkin\Gateway\GatewayRegistry;
use Memberkin\Repository\OrderRepository;
use Memberkin\Service\LevelService;
use Memberkin\Service\OrderService;
use Memberkin\Service\RefundService;

defined( 'ABSPATH' ) || exit;

/**
 * Orders list and detail with mark paid, cancel, refund and notes.
 */
final class OrdersScreen {

	public const PAGE = 'memberkin-orders';

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
		add_action( 'admin_post_memberkin_order_action', array( $this, 'handle' ) );
	}

	/**
	 * Router.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ORDERS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage orders.', 'memberkin' ), 403 );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap">';
		if ( $id ) {
			$this->render_order( $id );
		} else {
			$table = new OrdersListTable();
			$table->prepare_items();
			echo '<h1 class="wp-heading-inline">' . esc_html__( 'Orders', 'memberkin' ) . '</h1>';
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
			$filters = array(
				'status'  => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
				'gateway' => isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '',
				's'       => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			);
			// phpcs:enable
			echo Html::screen_actions( 'orders', $filters ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Html::screen_actions().
			echo '<hr class="wp-header-end" />';
			Flash::render();
			$table->views();
			echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET['status'] ) ) {
				echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ) . '" />'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			$table->search_box( __( 'Search orders', 'memberkin' ), 'memberkin-orders' );
			$table->display();
			echo '</form>';
		}
		echo '</div>';
	}

	/**
	 * Order detail.
	 *
	 * @param int $id Order id.
	 */
	private function render_order( int $id ): void {
		$order = $this->plugin->get( OrderRepository::class )->find( $id );
		if ( ! $order ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Order not found.', 'memberkin' ) . '</p></div>';
			return;
		}
		$gateway  = $this->plugin->get( GatewayRegistry::class )->get( $order->gateway );
		$refunds  = $this->plugin->get( OrderRepository::class )->refunds( $order->id );
		$currency = $order->currency;
		?>
		<h1 class="wp-heading-inline">
			<?php
			/* translators: %s: order number. */
			echo esc_html( sprintf( __( 'Order %s', 'memberkin' ), $order->number ) );
			?>
		</h1>
		<?php echo Html::badge( $order->status, Order::statuses()[ $order->status ] ?? $order->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Html::badge(). ?>
		<hr class="wp-header-end" />
		<?php Flash::render(); ?>
		<p><a href="<?php echo esc_url( Html::url( self::PAGE ) ); ?>"><?php esc_html_e( '← All orders', 'memberkin' ); ?></a></p>

		<div id="poststuff"><div id="post-body" class="metabox-holder columns-2">
		<div id="post-body-content">
			<table class="widefat striped">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Item', 'memberkin' ); ?></th>
					<th scope="col" style="width:12%;"><?php esc_html_e( 'Amount', 'memberkin' ); ?></th>
					<th scope="col" style="width:12%;"><?php esc_html_e( 'Tax', 'memberkin' ); ?></th>
					<th scope="col" style="width:12%;"><?php esc_html_e( 'Total', 'memberkin' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $order->items as $item ) : ?>
					<tr>
						<td><?php echo esc_html( $item->description ); ?></td>
						<td><?php echo Html::money( $item->net(), $currency ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo Html::money( $item->tax_amount, $currency ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo '0' !== $item->tax_rate && (float) $item->tax_rate > 0 ? ' <span class="memberkin-muted">(' . esc_html( rtrim( rtrim( $item->tax_rate, '0' ), '.' ) ) . '%)</span>' : ''; ?></td>
						<td><?php echo Html::money( $item->total(), $currency ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr><th scope="row" colspan="3" style="text-align:right;"><?php esc_html_e( 'Subtotal', 'memberkin' ); ?></th><td><?php echo Html::money( $order->subtotal, $currency ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					<?php if ( $order->discount_total ) : ?>
						<tr><th scope="row" colspan="3" style="text-align:right;"><?php echo $order->coupon_code ? esc_html( sprintf( /* translators: %s: coupon code. */ __( 'Discounts (coupon %s)', 'memberkin' ), $order->coupon_code ) ) : esc_html__( 'Discounts and credits', 'memberkin' ); ?></th><td>−<?php echo Html::money( $order->discount_total, $currency ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					<?php endif; ?>
					<tr><th scope="row" colspan="3" style="text-align:right;"><?php esc_html_e( 'Tax', 'memberkin' ); ?></th><td><?php echo Html::money( $order->tax_total, $currency ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					<tr><th scope="row" colspan="3" style="text-align:right;"><strong><?php esc_html_e( 'Total', 'memberkin' ); ?></strong></th><td><strong><?php echo Html::money( $order->total, $currency ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong></td></tr>
					<?php if ( $order->amount_refunded ) : ?>
						<tr><th scope="row" colspan="3" style="text-align:right;"><?php esc_html_e( 'Refunded', 'memberkin' ); ?></th><td>−<?php echo Html::money( $order->amount_refunded, $currency ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					<?php endif; ?>
				</tfoot>
			</table>

			<?php if ( $refunds ) : ?>
				<h2><?php esc_html_e( 'Refunds', 'memberkin' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th scope="col"><?php esc_html_e( 'Date', 'memberkin' ); ?></th><th scope="col"><?php esc_html_e( 'Amount', 'memberkin' ); ?></th><th scope="col"><?php esc_html_e( 'Credit note', 'memberkin' ); ?></th><th scope="col"><?php esc_html_e( 'Reason', 'memberkin' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $refunds as $refund ) : ?>
						<tr>
							<td><?php echo Html::date( \Memberkin\Core\Db::to_date( $refund->created_at ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo Html::money( (int) $refund->amount, $currency ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo esc_html( (string) $refund->credit_note_number ); ?></td>
							<td><?php echo esc_html( (string) $refund->reason ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php $this->render_actions( $order, $gateway ); ?>

			<h2><?php esc_html_e( 'Notes', 'memberkin' ); ?></h2>
			<?php if ( '' !== $order->notes ) : ?>
				<pre style="white-space:pre-wrap;background:#fff;border:1px solid #c3c4c7;padding:8px;"><?php echo esc_html( $order->notes ); ?></pre>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="memberkin_order_action" />
				<input type="hidden" name="do" value="note" />
				<input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>" />
				<?php wp_nonce_field( 'memberkin_order_note_' . $order->id ); ?>
				<label class="screen-reader-text" for="memberkin-note"><?php esc_html_e( 'Add a note', 'memberkin' ); ?></label>
				<textarea id="memberkin-note" name="note" class="large-text" rows="2" required></textarea>
				<?php submit_button( __( 'Add note', 'memberkin' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>

		<div id="postbox-container-1" class="postbox-container">
			<div class="postbox"><div class="postbox-header"><h2><?php esc_html_e( 'Member', 'memberkin' ); ?></h2></div><div class="inside">
				<p><?php echo Html::member_link( $order->user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Html. ?></p>
				<?php if ( $order->billing ) : ?>
					<p><strong><?php esc_html_e( 'Billing', 'memberkin' ); ?></strong><br />
					<?php echo nl2br( esc_html( implode( "\n", array_filter( array( $order->billing['name'] ?? '', $order->billing['company'] ?? '', $order->billing['line1'] ?? '', $order->billing['line2'] ?? '', trim( ( $order->billing['postcode'] ?? '' ) . ' ' . ( $order->billing['city'] ?? '' ) ), $order->billing['region'] ?? '', $order->billing['country'] ?? '', $order->billing['phone'] ?? '' ) ) ) ) ); ?></p>
				<?php endif; ?>
			</div></div>
			<div class="postbox"><div class="postbox-header"><h2><?php esc_html_e( 'Details', 'memberkin' ); ?></h2></div><div class="inside">
				<p>
					<strong><?php esc_html_e( 'Type:', 'memberkin' ); ?></strong> <?php echo esc_html( Order::types()[ $order->type ] ?? $order->type ); ?><br />
					<strong><?php esc_html_e( 'Created:', 'memberkin' ); ?></strong> <?php echo Html::date( $order->created_at, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><br />
					<strong><?php esc_html_e( 'Paid:', 'memberkin' ); ?></strong> <?php echo Html::date( $order->paid_at, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><br />
					<strong><?php esc_html_e( 'Invoice:', 'memberkin' ); ?></strong> <?php echo esc_html( $order->invoice_number ?? '—' ); ?><br />
					<strong><?php esc_html_e( 'Gateway:', 'memberkin' ); ?></strong> <?php echo esc_html( $gateway ? $gateway->label() : $order->gateway ); ?> (<?php echo esc_html( $order->gateway_mode ); ?>)<br />
					<strong><?php esc_html_e( 'Transaction:', 'memberkin' ); ?></strong> <code><?php echo esc_html( $order->gateway_transaction_id ?? '—' ); ?></code><br />
					<?php if ( $order->payment_method_label ) : ?>
						<strong><?php esc_html_e( 'Paid with:', 'memberkin' ); ?></strong> <?php echo esc_html( $order->payment_method_label ); ?><br />
					<?php endif; ?>
					<?php if ( $order->subscription_id ) : ?>
						<strong><?php esc_html_e( 'Subscription:', 'memberkin' ); ?></strong> <a href="<?php echo esc_url( Html::url( SubscriptionsScreen::PAGE, array( 'id' => $order->subscription_id ) ) ); ?>">#<?php echo (int) $order->subscription_id; ?></a><br />
					<?php endif; ?>
					<?php if ( ! empty( $order->tax['country'] ) ) : ?>
						<strong><?php esc_html_e( 'Tax country:', 'memberkin' ); ?></strong> <?php echo esc_html( (string) $order->tax['country'] ); ?><br />
					<?php endif; ?>
					<?php if ( ! empty( $order->tax['vat_number'] ) ) : ?>
						<strong><?php esc_html_e( 'VAT number:', 'memberkin' ); ?></strong> <?php echo esc_html( (string) $order->tax['vat_number'] ); ?> <?php echo $order->tax['vat_number_valid'] ? esc_html__( '(validated)', 'memberkin' ) : esc_html__( '(not validated)', 'memberkin' ); ?><br />
					<?php endif; ?>
					<?php if ( ! empty( $order->tax['reverse_charge'] ) ) : ?>
						<em><?php esc_html_e( 'Reverse charge applied', 'memberkin' ); ?></em><br />
					<?php endif; ?>
				</p>
			</div></div>
		</div>
		</div></div>
		<?php
	}

	/**
	 * Action forms.
	 *
	 * @param Order                                     $order   Order.
	 * @param \Memberkin\Gateway\Contracts\Gateway|null $gateway Gateway.
	 */
	private function render_actions( Order $order, $gateway ): void {
		echo '<h2>' . esc_html__( 'Actions', 'memberkin' ) . '</h2><p>';
		if ( Order::can_transition( $order->status, Order::PAID ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- a form built by Html::action_button(), which escapes every value.
			echo Html::action_button(
				'memberkin_order_action',
				'memberkin_order_mark_paid_' . $order->id,
				array(
					'do'       => 'mark_paid',
					'order_id' => $order->id,
				),
				__( 'Mark as paid', 'memberkin' ),
				'button button-primary',
				__( 'Mark this order as paid? Access is granted immediately.', 'memberkin' )
			);
			// phpcs:enable
		}
		if ( Order::can_transition( $order->status, Order::CANCELLED ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- a form built by Html::action_button(), which escapes every value.
			echo Html::action_button(
				'memberkin_order_action',
				'memberkin_order_cancel_' . $order->id,
				array(
					'do'       => 'cancel',
					'order_id' => $order->id,
				),
				__( 'Cancel order', 'memberkin' ),
				'button',
				__( 'Cancel this unpaid order?', 'memberkin' )
			);
			// phpcs:enable
		}
		/**
		 * Prints extra action buttons on the order screen (e.g. invoice downloads).
		 *
		 * @param Order $order Order.
		 */
		do_action( 'memberkin_admin_order_actions', $order );
		echo '</p>';

		if ( $order->is_paid() && $order->refundable()->is_positive() && current_user_can( Capabilities::REFUND_ORDERS ) ) {
			$partial = ! $gateway || $gateway->supports( Capability::REFUND_PARTIAL ) || 'free' === $order->gateway;
			?>
			<h3><?php esc_html_e( 'Refund', 'memberkin' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="memberkin_order_action" />
				<input type="hidden" name="do" value="refund" />
				<input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>" />
				<?php wp_nonce_field( 'memberkin_order_refund_' . $order->id ); ?>
				<p class="memberkin-inline-fields">
					<label for="memberkin-refund-amount"><?php esc_html_e( 'Amount', 'memberkin' ); ?></label>
					<input type="text" inputmode="decimal" id="memberkin-refund-amount" name="amount" class="small-text" value="<?php echo esc_attr( $order->refundable()->to_decimal() ); ?>" <?php echo $partial ? '' : 'readonly'; ?> />
					<?php echo esc_html( $order->currency ); ?>
					<label for="memberkin-refund-reason"><?php esc_html_e( 'Reason', 'memberkin' ); ?></label>
					<input type="text" id="memberkin-refund-reason" name="reason" class="regular-text" />
				</p>
				<p><label><input type="checkbox" name="revoke" value="1" /> <?php esc_html_e( 'Also end the membership and cancel the subscription now', 'memberkin' ); ?></label></p>
				<?php
				$order_gateway = memberkin()->get( \Memberkin\Gateway\GatewayRegistry::class )->get( $order->gateway );
				if ( $order_gateway && $order_gateway->supports( \Memberkin\Gateway\Contracts\Capability::MANUAL_CONFIRMATION ) ) :
					?>
					<p class="description"><?php esc_html_e( 'Manual payments are refunded outside this site; this only records the refund.', 'memberkin' ); ?></p>
				<?php elseif ( ! $partial ) : ?>
					<p class="description"><?php esc_html_e( 'This gateway only supports full refunds.', 'memberkin' ); ?></p>
				<?php endif; ?>
				<?php submit_button( __( 'Refund', 'memberkin' ), 'secondary', 'submit', false, array( 'data-memberkin-confirm' => __( 'Refund this amount? This cannot be undone.', 'memberkin' ) ) ); ?>
			</form>
			<?php
		}
	}

	/**
	 * Action handler.
	 */
	public function handle(): void {
		$do       = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified right below.
		check_admin_referer( 'memberkin_order_' . $do . '_' . $order_id );
		$cap = 'refund' === $do ? Capabilities::REFUND_ORDERS : Capabilities::MANAGE_ORDERS;
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'memberkin' ), 403 );
		}
		$orders = $this->plugin->get( OrderService::class );
		try {
			switch ( $do ) {
				case 'mark_paid':
					$orders->mark_paid( $order_id );
					$orders->add_note( $order_id, sprintf( /* translators: %s: user name. */ __( 'Marked as paid by %s.', 'memberkin' ), wp_get_current_user()->display_name ) );
					Flash::add( __( 'Order marked as paid.', 'memberkin' ) );
					break;
				case 'cancel':
					$orders->cancel( $order_id, sprintf( /* translators: %s: user name. */ __( 'Cancelled by %s.', 'memberkin' ), wp_get_current_user()->display_name ) );
					memberkin()->get( \Memberkin\Service\Checkout\CheckoutService::class )->abandon( $order_id, '' );
					Flash::add( __( 'Order cancelled.', 'memberkin' ) );
					break;
				case 'refund':
					$order  = $this->plugin->get( OrderRepository::class )->find( $order_id );
					$amount = Money::from_decimal( isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '0', $order ? $order->currency : 'USD', LevelService::decimal_separator() );
					$this->plugin->get( RefundService::class )->refund(
						$order_id,
						$amount,
						isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '',
						! empty( $_POST['revoke'] ),
						get_current_user_id()
					);
					Flash::add( $this->plugin->get( RefundService::class )->last_pending ? __( 'Refund requested. It is recorded here once the payment gateway approves it.', 'memberkin' ) : __( 'Refund recorded.', 'memberkin' ) );
					break;
				case 'note':
					$orders->add_note( $order_id, isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '' );
					Flash::add( __( 'Note added.', 'memberkin' ) );
					break;
			}
		} catch ( ValidationException $e ) {
			Flash::errors( $e->errors() );
		} catch ( \InvalidArgumentException $e ) {
			Flash::add( __( 'Enter a valid amount.', 'memberkin' ), 'error' );
		}
		Flash::redirect( Html::url( self::PAGE, array( 'id' => $order_id ) ) );
	}
}
