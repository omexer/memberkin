<?php
/**
 * How a member updates their payment method.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Either a redirect to a gateway-hosted page, or data for an on-site form.
 */
final class PaymentMethodUpdate {

	/**
	 * Constructor.
	 *
	 * @param string $type          redirect|form.
	 * @param string $url           Redirect URL.
	 * @param string $client_secret Client secret / session for JS SDKs.
	 * @param array  $data          Extra data for the form.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $url = '',
		public readonly string $client_secret = '',
		public readonly array $data = array()
	) {}
}
