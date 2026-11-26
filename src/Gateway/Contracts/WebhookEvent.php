<?php
/**
 * Verified webhook and normalised events.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * A normalised event produced by Gateway::parse_webhook().
 *
 * Types: payment.succeeded, payment.failed, payment.action_required, refund.succeeded, dispute.opened, dispute.closed,
 * subscription.activated, subscription.renewed, subscription.updated, subscription.past_due, subscription.paused,
 * subscription.resumed, subscription.cancelled, subscription.expired, payment_method.updated, ignored.
 */
final class WebhookEvent {

	/**
	 * Constructor.
	 *
	 * @param string      $type                    Normalised type.
	 * @param string|null $transaction_id          Gateway payment id.
	 * @param string|null $subscription_id         Gateway subscription id.
	 * @param int|null    $order_id                Local order id (from metadata).
	 * @param int|null    $amount                  Amount (minor units).
	 * @param string|null $currency                Currency.
	 * @param string|null $refund_id               Gateway refund id.
	 * @param string      $message                 Failure reason etc.
	 * @param array       $data                    Extra (period_end, payment_method, dispute status …).
	 */
	public function __construct(
		public readonly string $type,
		public readonly ?string $transaction_id = null,
		public readonly ?string $subscription_id = null,
		public readonly ?int $order_id = null,
		public readonly ?int $amount = null,
		public readonly ?string $currency = null,
		public readonly ?string $refund_id = null,
		public readonly string $message = '',
		public readonly array $data = array()
	) {}
}
