<?php
/**
 * A webhook whose authenticity was verified.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Verified webhook envelope (stored in memberkin_webhook_events and processed asynchronously).
 */
final class VerifiedWebhook {

	/**
	 * Constructor.
	 *
	 * @param string $gateway  Gateway id.
	 * @param string $mode     live|test.
	 * @param string $event_id Unique event id (for idempotency).
	 * @param string $type     Gateway event type.
	 * @param array  $payload  Decoded payload.
	 */
	public function __construct(
		public readonly string $gateway,
		public readonly string $mode,
		public readonly string $event_id,
		public readonly string $type,
		public readonly array $payload
	) {}
}
