<?php
/**
 * Stripe API error.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Stripe;

defined( 'ABSPATH' ) || exit;

/**
 * Carries Stripe's error object (type, code, decline_code, payment_intent).
 */
final class StripeException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status (0 = network error).
	 * @param array  $error   Stripe "error" object.
	 */
	public function __construct( string $message, public readonly int $status = 0, public readonly array $error = array() ) {
		parent::__construct( wp_specialchars_decode( $message, ENT_QUOTES ) ); // Escaped where thrown; stored as plain text.
	}

	/**
	 * Error type (card_error, invalid_request_error, api_error…).
	 */
	public function type(): string {
		return (string) ( $this->error['type'] ?? '' );
	}

	/**
	 * Error code (e.g. authentication_required, resource_missing).
	 */
	public function error_code(): string {
		return (string) ( $this->error['code'] ?? '' );
	}

	/**
	 * Whether the message is safe to show to the customer (card errors are written for customers).
	 */
	public function is_card_error(): bool {
		return 'card_error' === $this->type();
	}
}
