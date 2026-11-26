<?php
/**
 * Payment gateway contract (ARCHITECTURE.md §7).
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Contracts;

use Memberkin\Core\Money;
use Memberkin\Domain\Order;
use Memberkin\Domain\Pricing\Quote;
use Memberkin\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Every gateway implements this interface (usually by extending AbstractGateway).
 */
interface Gateway {

	/**
	 * Unique id, e.g. "stripe".
	 */
	public function id(): string;

	/**
	 * Label shown to admins.
	 */
	public function label(): string;

	/**
	 * Label shown to members at checkout.
	 */
	public function checkout_label(): string;

	/**
	 * Whether a capability is supported (see Capability).
	 *
	 * @param string $capability Capability.
	 */
	public function supports( string $capability ): bool;

	/**
	 * Settings fields (SettingsFields format, section "gateway_{id}").
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function settings_fields(): array;

	/**
	 * Current mode: live or test.
	 */
	public function mode(): string;

	/**
	 * Whether enabled by the admin and configured for the current mode.
	 */
	public function is_ready(): bool;

	/**
	 * Whether usable for a quote (currency, amounts, recurring support).
	 *
	 * @param Quote $quote Quote.
	 */
	public function is_available_for( Quote $quote ): bool;

	/**
	 * Markup for on-site payment fields (empty for offsite gateways).
	 *
	 * @param Quote $quote Quote.
	 */
	public function render_payment_fields( Quote $quote ): string;

	/**
	 * Takes today's payment and sets up recurring billing if the quote recurs.
	 *
	 * @param PaymentContext $context Context.
	 */
	public function start_payment( PaymentContext $context ): PaymentResult;

	/**
	 * Completes a payment after an offsite return or browser confirmation.
	 *
	 * @param PaymentContext $context Context.
	 * @param array          $params  Return parameters.
	 */
	public function complete_payment( PaymentContext $context, array $params ): PaymentResult;

	/**
	 * Charges a saved payment method off-session (site-managed renewals).
	 *
	 * @param ChargeRequest $request Request.
	 */
	public function charge_saved_method( ChargeRequest $request ): PaymentResult;

	/**
	 * Cancels a gateway-managed subscription.
	 *
	 * @param Subscription $subscription  Subscription.
	 * @param bool         $at_period_end Keep billing until the period ends.
	 */
	public function cancel_subscription( Subscription $subscription, bool $at_period_end ): OperationResult;

	/**
	 * Pauses a gateway-managed subscription.
	 *
	 * @param Subscription            $subscription Subscription.
	 * @param \DateTimeImmutable|null $resume_at    Automatic resume.
	 */
	public function pause_subscription( Subscription $subscription, ?\DateTimeImmutable $resume_at ): OperationResult;

	/**
	 * Resumes a paused or cancelling gateway-managed subscription.
	 *
	 * @param Subscription $subscription Subscription.
	 */
	public function resume_subscription( Subscription $subscription ): OperationResult;

	/**
	 * Refunds (part of) an order.
	 *
	 * @param Order  $order  Order.
	 * @param Money  $amount Amount.
	 * @param string $reason Reason.
	 */
	public function refund( Order $order, Money $amount, string $reason ): OperationResult;

	/**
	 * Starts a payment method update for a subscription.
	 *
	 * @param Subscription $subscription Subscription.
	 * @param string       $return_url   Return URL.
	 */
	public function payment_method_update( Subscription $subscription, string $return_url ): PaymentMethodUpdate;

	/**
	 * Verifies a webhook request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @throws InvalidSignature When verification fails.
	 */
	public function verify_webhook( \WP_REST_Request $request ): VerifiedWebhook;

	/**
	 * Converts a verified webhook into normalised events.
	 *
	 * @param VerifiedWebhook $webhook Webhook.
	 * @return WebhookEvent[]
	 */
	public function parse_webhook( VerifiedWebhook $webhook ): array;
}
