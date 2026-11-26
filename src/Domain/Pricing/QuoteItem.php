<?php
/**
 * Quote line.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * A line of a quote. Amounts are minor units; credits and discounts are negative.
 */
final class QuoteItem implements \JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param string   $type        level|signup_fee|discount|proration_credit|fee.
	 * @param string   $description Description.
	 * @param int      $amount      Net amount (before tax; for tax-inclusive prices the tax part is split out by the calculator).
	 * @param int|null $level_id    Level.
	 * @param string   $tax_rate    Percent as decimal string.
	 * @param int      $tax_amount  Tax.
	 * @param int|null $tax_rate_id Tax rate id.
	 */
	public function __construct(
		public string $type,
		public string $description,
		public int $amount,
		public ?int $level_id = null,
		public string $tax_rate = '0',
		public int $tax_amount = 0,
		public ?int $tax_rate_id = null
	) {}

	/**
	 * JSON form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return get_object_vars( $this );
	}
}
