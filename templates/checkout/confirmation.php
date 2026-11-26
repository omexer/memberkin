<?php
/**
 * Checkout confirmation.
 *
 * Override: yourtheme/memberkin/checkout/confirmation.php
 *
 * @version 1.2.0
 * @package Memberkin
 *
 * @var \Memberkin\Domain\Order        $order        Order.
 * @var \Memberkin\Domain\Level[]      $levels       Levels bought.
 * @var \Memberkin\Domain\Membership[] $memberships  Member's active memberships.
 * @var string                              $message      Level confirmation messages (HTML).
 * @var string                              $instructions Payment instructions (HTML).
 * @var string                              $account_url  Account page URL.
 * @var string                              $header       Heading HTML from add-ons ('' = default heading).
 */

use Memberkin\Core\Money;

defined( 'ABSPATH' ) || exit;
?>
<div class="memberkin memberkin-confirmation">
	<?php if ( ! empty( $header ) ) : ?>
		<?php echo wp_kses_post( $header ); ?>
	<?php elseif ( $order->is_paid() ) : ?>
		<h2><?php esc_html_e( 'Thank you! Your membership is active.', 'memberkin' ); ?></h2>
	<?php else : ?>
		<h2><?php esc_html_e( 'Thank you! Your order is waiting for payment.', 'memberkin' ); ?></h2>
	<?php endif; ?>

	<?php if ( '' !== $message ) : ?>
		<div class="memberkin-confirmation__message"><?php echo wp_kses_post( wpautop( $message ) ); ?></div>
	<?php endif; ?>

	<?php if ( '' !== $instructions ) : ?>
		<div class="memberkin-confirmation__instructions">
			<h3><?php esc_html_e( 'How to pay', 'memberkin' ); ?></h3>
			<?php echo wp_kses_post( wpautop( $instructions ) ); ?>
		</div>
	<?php endif; ?>

	<table class="memberkin-summary">
		<caption><?php esc_html_e( 'Order details', 'memberkin' ); ?></caption>
		<tbody>
			<tr><th scope="row"><?php esc_html_e( 'Order number', 'memberkin' ); ?></th><td><?php echo esc_html( $order->number ); ?></td></tr>
			<?php if ( $order->invoice_number ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Invoice', 'memberkin' ); ?></th><td><?php echo esc_html( $order->invoice_number ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $order->items as $memberkin_item ) : ?>
				<tr><th scope="row"><?php echo esc_html( $memberkin_item->description ); ?></th><td><?php echo esc_html( Money::of( $memberkin_item->total(), $order->currency )->format() ); ?></td></tr>
			<?php endforeach; ?>
			<tr><th scope="row"><?php esc_html_e( 'Total', 'memberkin' ); ?></th><td><strong><?php echo esc_html( $order->total()->format() ); ?></strong></td></tr>
			<?php if ( $order->payment_method_label ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Payment method', 'memberkin' ); ?></th><td><?php echo esc_html( $order->payment_method_label ); ?></td></tr>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $account_url ) : ?>
		<p><a class="memberkin-button" href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Go to your account', 'memberkin' ); ?></a></p>
	<?php endif; ?>
</div>
