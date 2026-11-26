<?php
/**
 * Checkout outcome.
 *
 * @package Memberkin
 */

namespace Memberkin\Service\Checkout;

use Memberkin\Domain\Order;

defined( 'ABSPATH' ) || exit;

/**
 * What the checkout form should do next.
 */
final class CheckoutResult implements \JsonSerializable {

	public const COMPLETED       = 'completed';
	public const PENDING         = 'pending';
	public const REDIRECT        = 'redirect';
	public const REQUIRES_ACTION = 'requires_action';
	public const FAILED          = 'failed';

	/**
	 * Constructor.
	 *
	 * @param string     $status        Status.
	 * @param array      $errors        Field => message.
	 * @param Order|null $order         Order.
	 * @param string     $redirect_url  Where to go next (confirmation page or offsite).
	 * @param string     $client_secret Browser confirmation secret.
	 * @param string     $token         Checkout session token.
	 */
	public function __construct(
		public readonly string $status,
		public readonly array $errors = array(),
		public readonly ?Order $order = null,
		public readonly string $redirect_url = '',
		public readonly string $client_secret = '',
		public readonly string $token = ''
	) {}

	/**
	 * Failure with messages.
	 *
	 * @param array  $errors Messages.
	 * @param string $token  Session token (to retry).
	 */
	public static function failed( array $errors, string $token = '' ): self {
		return new self( self::FAILED, $errors, null, '', '', $token );
	}

	/**
	 * JSON for AJAX checkout.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'status'        => $this->status,
			'errors'        => $this->errors,
			'order'         => $this->order?->number,
			'redirect_url'  => $this->redirect_url,
			'client_secret' => $this->client_secret,
			'token'         => $this->token,
		);
	}
}
