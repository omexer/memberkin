<?php
/**
 * Recurring billing terms produced by a quote.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain\Pricing;

use Memberkin\Core\Money;

defined( 'ABSPATH' ) || exit;

/**
 * What happens after today's payment for one level.
 */
final class RecurringTerms implements \JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param int                $level_id          Level.
	 * @param Money              $first_amount      Next renewal amount (net, after discount).
	 * @param Money              $regular_amount    Renewal amount once discounts and trial cycles end (net).
	 * @param int                $billing_interval  Interval.
	 * @param string             $billing_period    Period.
	 * @param \DateTimeImmutable $first_renewal_at  First renewal date.
	 * @param int                $renewals_limit    Number of renewals (0 = until cancelled).
	 * @param int|null           $discount_cycles   Discounted renewals (null = all).
	 * @param int                $tax_amount        Tax on $first_amount.
	 * @param string             $tax_rate          Tax rate percent.
	 * @param int                $trial_cycles_left Remaining renewals at the trial price ("cycle" trials).
	 */
	public function __construct(
		public readonly int $level_id,
		public readonly Money $first_amount,
		public readonly Money $regular_amount,
		public readonly int $billing_interval,
		public readonly string $billing_period,
		public readonly \DateTimeImmutable $first_renewal_at,
		public readonly int $renewals_limit = 0,
		public readonly ?int $discount_cycles = null,
		public int $tax_amount = 0,
		public string $tax_rate = '0',
		public readonly int $trial_cycles_left = 0
	) {}

	/**
	 * JSON form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'level_id'          => $this->level_id,
			'first_amount'      => $this->first_amount,
			'regular_amount'    => $this->regular_amount,
			'billing_interval'  => $this->billing_interval,
			'billing_period'    => $this->billing_period,
			'first_renewal_at'  => $this->first_renewal_at->format( DATE_ATOM ),
			'renewals_limit'    => $this->renewals_limit,
			'discount_cycles'   => $this->discount_cycles,
			'tax_amount'        => $this->tax_amount,
			'tax_rate'          => $this->tax_rate,
			'trial_cycles_left' => $this->trial_cycles_left,
		);
	}
}
