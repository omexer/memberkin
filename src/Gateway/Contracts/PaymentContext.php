<?php
/**
 * Data passed to a gateway to take a payment at checkout.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Contracts;

use Memberkin\Domain\Order;
use Memberkin\Domain\Pricing\Quote;
use Memberkin\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout payment context.
 */
final class PaymentContext {

	/**
	 * Constructor.
	 *
	 * @param Order             $order         Pending order (total = amount due today).
	 * @param Quote             $quote         Quote (recurring terms for subscriptions).
	 * @param \WP_User          $user          Buyer.
	 * @param string            $return_url    Where offsite gateways send the member back (contains the checkout token).
	 * @param string            $cancel_url    Where the member goes when they cancel offsite.
	 * @param array             $fields        Gateway fields posted by the checkout form (tokens, nonces from JS SDKs).
	 * @param Subscription|null $subscription  Pending local subscription (null for one-time purchases).
	 * @param string            $session_token Checkout session token.
	 */
	public function __construct(
		public readonly Order $order,
		public readonly Quote $quote,
		public readonly \WP_User $user,
		public readonly string $return_url,
		public readonly string $cancel_url,
		public readonly array $fields = array(),
		public readonly ?Subscription $subscription = null,
		public readonly string $session_token = ''
	) {}
}
