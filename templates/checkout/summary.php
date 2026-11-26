<?php
/**
 * Checkout price summary.
 *
 * Override: yourtheme/memberkin/checkout/summary.php
 *
 * @version 1.0.0
 * @package Memberkin
 *
 * @var \Memberkin\Domain\Pricing\Quote $quote
 */

use Memberkin\Core\Money;
use Memberkin\Frontend\CheckoutController;

defined( 'ABSPATH' ) || exit;
?>
<table class="memberkin-summary">
	<caption class="screen-reader-text"><?php esc_html_e( 'Order summary', 'memberkin' ); ?></caption>
	<tbody>
	<?php foreach ( $quote->items as $memberkin_item ) : ?>
		<tr class="memberkin-summary__line memberkin-summary__line--<?php echo esc_attr( $memberkin_item->type ); ?>">
			<th scope="row"><?php echo esc_html( $memberkin_item->description ); ?></th>
			<td><?php echo esc_html( Money::of( $memberkin_item->amount, $quote->currency )->format() ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( $quote->tax_total()->is_positive() ) : ?>
		<tr class="memberkin-summary__tax"><th scope="row"><?php esc_html_e( 'Tax', 'memberkin' ); ?></th><td><?php echo esc_html( $quote->tax_total()->format() ); ?></td></tr>
	<?php endif; ?>
	<?php if ( $quote->tax->reverse_charge ) : ?>
		<tr class="memberkin-summary__note"><td colspan="2"><?php esc_html_e( 'VAT reverse charge applies.', 'memberkin' ); ?></td></tr>
	<?php endif; ?>
	</tbody>
	<tfoot>
		<tr class="memberkin-summary__total"><th scope="row"><?php esc_html_e( 'Due today', 'memberkin' ); ?></th><td><strong><?php echo esc_html( $quote->total()->format() ); ?></strong></td></tr>
	</tfoot>
</table>
<?php $memberkin_recurring = CheckoutController::recurring_lines( $quote ); ?>
<?php if ( $memberkin_recurring ) : ?>
	<ul class="memberkin-summary__recurring">
		<?php foreach ( $memberkin_recurring as $memberkin_line ) : ?>
			<li><?php echo esc_html( $memberkin_line ); ?></li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
<?php foreach ( $quote->notices as $memberkin_notice ) : ?>
	<p class="memberkin-notice"><?php echo esc_html( $memberkin_notice ); ?></p>
<?php endforeach; ?>
