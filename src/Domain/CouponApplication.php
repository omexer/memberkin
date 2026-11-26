<?php
/**
 * Result of applying a coupon to a level's price schedule.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain;

use Memberkin\Core\Money;

defined( 'ABSPATH' ) || exit;

/**
 * What the member pays with the coupon.
 */
final class CouponApplication implements \JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param Coupon        $coupon             Coupon.
	 * @param PriceSchedule $schedule           Effective schedule (override / trial override applied, no discounts).
	 * @param Money         $first_discount     Amount taken off the first payment.
	 * @param Money         $recurring_discount Amount taken off each discounted recurring payment.
	 * @param int|null      $recurring_cycles   Number of discounted recurring payments (null = all, 0 = none).
	 */
	public function __construct(
		public readonly Coupon $coupon,
		public readonly PriceSchedule $schedule,
		public readonly Money $first_discount,
		public readonly Money $recurring_discount,
		public readonly ?int $recurring_cycles
	) {}

	/**
	 * First payment after discount.
	 */
	public function first_payment(): Money {
		return $this->schedule->first_payment()->subtract( $this->first_discount )->non_negative();
	}

	/**
	 * Recurring payment number $n (1-based) after discount.
	 *
	 * @param int $n Recurring payment number.
	 */
	public function recurring_payment( int $n ): Money {
		$base = $this->schedule->recurring_payment_amount( $n );
		if ( $this->recurring_discount->is_zero() || ( null !== $this->recurring_cycles && $n > $this->recurring_cycles ) ) {
			return $base;
		}
		return $base->subtract( $this->recurring_discount )->non_negative();
	}

	/**
	 * JSON form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'coupon_id'          => $this->coupon->id,
			'code'               => $this->coupon->code,
			'first_discount'     => $this->first_discount,
			'recurring_discount' => $this->recurring_discount,
			'recurring_cycles'   => $this->recurring_cycles,
		);
	}
}
