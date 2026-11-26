<?php
/**
 * Outcome of a payment attempt.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Contracts;

use Memberkin\Domain\PaymentMethod;

defined( 'ABSPATH' ) || exit;

/**
 * Payment result. Status:
 * - completed:       money captured (or nothing due and the payment method/subscription is set up).
 * - pending:         the gateway will confirm later (offline payment, bank debit) — the webhook or admin completes it.
 * - redirect:        send the member to $redirect_url (offsite checkout); completion via return URL or webhook.
 * - requires_action: the browser must finish (3-D Secure) using $client_secret, then submit again.
 * - failed:          declined; $message is safe to show.
 */
final class PaymentResult {

	public const COMPLETED       = 'completed';
	public const PENDING         = 'pending';
	public const REDIRECT        = 'redirect';
	public const REQUIRES_ACTION = 'requires_action';
	public const FAILED          = 'failed';

	/**
	 * Constructor.
	 *
	 * @param string                  $status                  Status.
	 * @param string                  $message                 Member-facing message (failures).
	 * @param string|null             $transaction_id          Gateway payment id.
	 * @param string|null             $customer_id             Gateway customer id.
	 * @param string|null             $subscription_id         Gateway subscription id (gateway-managed billing).
	 * @param PaymentMethod|null      $payment_method          Saved method to store (site-managed billing).
	 * @param string|null             $redirect_url            Redirect target.
	 * @param string|null             $client_secret           Client secret for browser confirmation.
	 * @param \DateTimeImmutable|null $next_payment_at         Next charge date reported by the gateway.
	 * @param string|null             $payment_method_label    E.g. "Visa •••• 4242".
	 * @param array                   $data                    Extra data.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $message = '',
		public readonly ?string $transaction_id = null,
		public readonly ?string $customer_id = null,
		public readonly ?string $subscription_id = null,
		public readonly ?PaymentMethod $payment_method = null,
		public readonly ?string $redirect_url = null,
		public readonly ?string $client_secret = null,
		public readonly ?\DateTimeImmutable $next_payment_at = null,
		public readonly ?string $payment_method_label = null,
		public readonly array $data = array()
	) {}

	/**
	 * Failed result.
	 *
	 * @param string $message Message.
	 * @param array  $data    Data.
	 */
	public static function failed( string $message, array $data = array() ): self {
		return new self( self::FAILED, $message, data: $data );
	}

	/**
	 * Whether successful (money captured or setup done).
	 */
	public function is_completed(): bool {
		return self::COMPLETED === $this->status;
	}
}
