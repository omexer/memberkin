<?php
/**
 * Tax calculator contract.
 *
 * @package Memberkin
 */

namespace Memberkin\Service\Tax;

use Memberkin\Domain\Pricing\Quote;

defined( 'ABSPATH' ) || exit;

/**
 * Fills tax_rate/tax_amount on quote items and recurring terms and sets quote->tax flags.
 * The Tax module provides the real implementation; without it no tax is charged.
 */
interface TaxCalculator {

	/**
	 * Applies tax to a quote in place.
	 *
	 * @param Quote $quote Quote (its tax context holds the customer location).
	 */
	public function apply( Quote $quote ): void;
}
