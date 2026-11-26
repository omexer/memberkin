<?php
/**
 * Calculator used when the Tax module is disabled.
 *
 * @package Memberkin
 */

namespace Memberkin\Service\Tax;

use Memberkin\Domain\Pricing\Quote;

defined( 'ABSPATH' ) || exit;

/**
 * Charges no tax.
 */
final class NoTax implements TaxCalculator {

	/**
	 * {@inheritDoc}
	 *
	 * @param Quote $quote Quote.
	 */
	public function apply( Quote $quote ): void {
		foreach ( $quote->items as $item ) {
			$item->tax_amount = 0;
			$item->tax_rate   = '0';
		}
		foreach ( $quote->recurring as $terms ) {
			$terms->tax_amount = 0;
			$terms->tax_rate   = '0';
		}
	}
}
