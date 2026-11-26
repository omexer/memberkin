<?php
/**
 * Price quote for a checkout.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain\Pricing;

use Memberkin\Core\Money;
use Memberkin\Domain\Coupon;
use Memberkin\Domain\CouponApplication;
use Memberkin\Domain\PriceSchedule;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the member will pay: today's lines and totals, plus recurring terms per level.
 * The same object drives the checkout summary, the order, the subscription and emails.
 */
final class Quote implements \JsonSerializable {

	/**
	 * Today's lines.
	 *
	 * @var QuoteItem[]
	 */
	public array $items = array();

	/**
	 * Recurring terms keyed by level id.
	 *
	 * @var array<int, RecurringTerms>
	 */
	public array $recurring = array();

	/**
	 * Effective price schedules keyed by level id (after coupon overrides/trial changes; snapshot for memberships).
	 *
	 * @var array<int, PriceSchedule>
	 */
	public array $schedules = array();

	/**
	 * Coupon applications keyed by level id.
	 *
	 * @var array<int, CouponApplication>
	 */
	public array $coupon_applications = array();

	/**
	 * Coupon used.
	 *
	 * @var Coupon|null
	 */
	public ?Coupon $coupon = null;

	/**
	 * Proration plans keyed by level id.
	 *
	 * @var array<int, ProrationPlan>
	 */
	public array $proration = array();

	/**
	 * Tax context.
	 *
	 * @var TaxContext
	 */
	public TaxContext $tax;

	/**
	 * Notices shown to the member (e.g. "trial already used").
	 *
	 * @var string[]
	 */
	public array $notices = array();

	/**
	 * Constructor.
	 *
	 * @param string $currency  Currency.
	 * @param int    $user_id   User (0 = guest).
	 * @param int[]  $level_ids Levels.
	 */
	public function __construct(
		public readonly string $currency,
		public readonly int $user_id,
		public readonly array $level_ids
	) {
		$this->tax = new TaxContext();
	}

	/**
	 * Sum of positive line amounts (net).
	 */
	public function subtotal(): Money {
		$sum = 0;
		foreach ( $this->items as $item ) {
			if ( $item->amount > 0 ) {
				$sum += $item->amount;
			}
		}
		return Money::of( $sum, $this->currency );
	}

	/**
	 * Discounts and credits as a positive amount, capped so the net never goes below zero.
	 */
	public function discount_total(): Money {
		$sum = 0;
		foreach ( $this->items as $item ) {
			if ( $item->amount < 0 ) {
				$sum -= $item->amount;
			}
		}
		return Money::of( min( $sum, $this->subtotal()->amount ), $this->currency );
	}

	/**
	 * Net amount due today (before tax).
	 */
	public function net_total(): Money {
		return $this->subtotal()->subtract( $this->discount_total() );
	}

	/**
	 * Tax due today.
	 */
	public function tax_total(): Money {
		$sum = 0;
		foreach ( $this->items as $item ) {
			$sum += $item->tax_amount;
		}
		return Money::of( max( 0, $sum ), $this->currency );
	}

	/**
	 * Total charged today.
	 */
	public function total(): Money {
		return $this->net_total()->add( $this->tax_total() );
	}

	/**
	 * Whether a payment method is needed (something charged today or later).
	 */
	public function requires_payment(): bool {
		return $this->total()->is_positive() || (bool) $this->recurring;
	}

	/**
	 * Whether anything recurs.
	 */
	public function is_recurring(): bool {
		return (bool) $this->recurring;
	}

	/**
	 * JSON form (used by the checkout summary and REST /quote).
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'currency'       => $this->currency,
			'items'          => $this->items,
			'subtotal'       => $this->subtotal(),
			'discount_total' => $this->discount_total(),
			'tax_total'      => $this->tax_total(),
			'total'          => $this->total(),
			'recurring'      => array_values( $this->recurring ),
			'coupon'         => $this->coupon ? array(
				'code'    => $this->coupon->code,
				'summary' => $this->coupon->summary( $this->currency ),
			) : null,
			'proration'      => array_values( $this->proration ),
			'tax'            => $this->tax,
			'notices'        => $this->notices,
		);
	}
}
