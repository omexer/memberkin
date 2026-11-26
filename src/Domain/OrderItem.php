<?php
/**
 * Order line item.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One line of an order (level, signup fee, credit, discount, fee).
 */
final class OrderItem implements \JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param string   $type            level|signup_fee|proration_credit|discount|fee.
	 * @param string   $description     Description.
	 * @param int      $unit_amount     Unit amount (negative for credits/discounts).
	 * @param int      $quantity        Quantity.
	 * @param int|null $level_id        Level.
	 * @param int      $discount_amount Discount on this line.
	 * @param string   $tax_rate        Tax rate percent (decimal string).
	 * @param int      $tax_amount      Tax on this line.
	 * @param int|null $tax_rate_id     Tax rate id.
	 * @param int      $id              Id.
	 */
	public function __construct(
		public string $type,
		public string $description,
		public int $unit_amount,
		public int $quantity = 1,
		public ?int $level_id = null,
		public int $discount_amount = 0,
		public string $tax_rate = '0',
		public int $tax_amount = 0,
		public ?int $tax_rate_id = null,
		public int $id = 0
	) {}

	/**
	 * Net line total (after discount, before tax).
	 */
	public function net(): int {
		return $this->unit_amount * $this->quantity - $this->discount_amount;
	}

	/**
	 * Line total including tax.
	 */
	public function total(): int {
		return $this->net() + $this->tax_amount;
	}

	/**
	 * JSON form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return get_object_vars( $this ) + array( 'total' => $this->total() );
	}
}
