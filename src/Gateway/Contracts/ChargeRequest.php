<?php
/**
 * Off-session charge of a saved payment method (site-managed renewals).
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Contracts;

use Memberkin\Core\Money;
use Memberkin\Domain\Order;
use Memberkin\Domain\PaymentMethod;
use Memberkin\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Renewal or retry charge.
 */
final class ChargeRequest {

	/**
	 * Constructor.
	 *
	 * @param Subscription  $subscription    Subscription.
	 * @param Order         $order           Pending renewal order.
	 * @param PaymentMethod $payment_method  Method to charge.
	 * @param Money         $amount          Amount (gross).
	 * @param string        $description     Statement description.
	 * @param string        $idempotency_key Unique per subscription period and attempt.
	 */
	public function __construct(
		public readonly Subscription $subscription,
		public readonly Order $order,
		public readonly PaymentMethod $payment_method,
		public readonly Money $amount,
		public readonly string $description,
		public readonly string $idempotency_key
	) {}
}
