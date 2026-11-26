<?php
/**
 * Saved payment method (gateway token only).
 *
 * @package Memberkin
 */

namespace Memberkin\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A reusable payment method stored at the gateway. Card numbers never reach this site.
 */
final class PaymentMethod implements \JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param int         $id           Id.
	 * @param int         $user_id      User.
	 * @param string      $gateway      Gateway.
	 * @param string      $gateway_mode Mode.
	 * @param string      $token        Gateway token / id.
	 * @param string      $type         card|sepa_debit|bank|paypal|other.
	 * @param string|null $brand        Brand.
	 * @param string|null $last4        Last 4.
	 * @param int|null    $exp_month    Expiry month.
	 * @param int|null    $exp_year     Expiry year.
	 * @param bool        $is_default   Default for the user and gateway.
	 */
	public function __construct(
		public int $id,
		public int $user_id,
		public string $gateway,
		public string $gateway_mode,
		public string $token,
		public string $type = 'card',
		public ?string $brand = null,
		public ?string $last4 = null,
		public ?int $exp_month = null,
		public ?int $exp_year = null,
		public bool $is_default = false
	) {}

	/**
	 * Human label, e.g. "Visa •••• 4242 (12/28)".
	 */
	public function label(): string {
		$label = $this->brand ? ucfirst( $this->brand ) : ucfirst( str_replace( '_', ' ', $this->type ) );
		if ( $this->last4 ) {
			$label .= ' •••• ' . $this->last4;
		}
		if ( $this->exp_month && $this->exp_year ) {
			$label .= sprintf( ' (%02d/%02d)', $this->exp_month, $this->exp_year % 100 );
		}
		return $label;
	}

	/**
	 * JSON form (token excluded).
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		$data = get_object_vars( $this );
		unset( $data['token'] );
		$data['label'] = $this->label();
		return $data;
	}
}
