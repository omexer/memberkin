<?php
/**
 * PayPal API error.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\PayPal;

defined( 'ABSPATH' ) || exit;

/**
 * Carries PayPal's error name and first issue code.
 */
final class PayPalException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status (0 = network).
	 * @param array  $error   Error body.
	 */
	public function __construct( string $message, public readonly int $status = 0, public readonly array $error = array() ) {
		parent::__construct( wp_specialchars_decode( $message, ENT_QUOTES ) ); // Escaped where thrown; stored as plain text.
	}

	/**
	 * First issue code (e.g. INSTRUMENT_DECLINED, ORDER_ALREADY_CAPTURED) or the error name.
	 */
	public function issue(): string {
		return (string) ( $this->error['details'][0]['issue'] ?? ( $this->error['name'] ?? '' ) );
	}
}
