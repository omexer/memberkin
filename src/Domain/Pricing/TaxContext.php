<?php
/**
 * Customer location and tax identity for a quote.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Inputs and outputs of tax calculation (set by the tax calculator).
 */
final class TaxContext implements \JsonSerializable {

	/**
	 * Whether the reverse charge applies (B2B EU VAT).
	 *
	 * @var bool
	 */
	public bool $reverse_charge = false;

	/**
	 * Whether the VAT number was validated.
	 *
	 * @var bool
	 */
	public bool $vat_number_valid = false;

	/**
	 * Whether entered prices include tax.
	 *
	 * @var bool
	 */
	public bool $prices_include_tax = false;

	/**
	 * Location evidence collected (e.g. billing country, IP country).
	 *
	 * @var array<string, string>
	 */
	public array $evidence = array();

	/**
	 * Whether a billing address is required to calculate tax.
	 *
	 * @var bool
	 */
	public bool $address_required = false;

	/**
	 * Constructor.
	 *
	 * @param string $country    ISO country.
	 * @param string $region     State/region.
	 * @param string $postcode   Postcode.
	 * @param string $vat_number VAT number as entered.
	 * @param string $ip_country Country derived from the request (CDN header), if any.
	 */
	public function __construct(
		public string $country = '',
		public string $region = '',
		public string $postcode = '',
		public string $vat_number = '',
		public string $ip_country = ''
	) {}

	/**
	 * Snapshot for orders.
	 *
	 * @return array<string, mixed>
	 */
	public function to_order_tax(): array {
		return array(
			'country'            => '' !== $this->country ? $this->country : null,
			'region'             => '' !== $this->region ? $this->region : null,
			'vat_number'         => '' !== $this->vat_number ? $this->vat_number : null,
			'vat_number_valid'   => $this->vat_number_valid,
			'reverse_charge'     => $this->reverse_charge,
			'prices_include_tax' => $this->prices_include_tax,
		);
	}

	/**
	 * JSON form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		$data = get_object_vars( $this );
		unset( $data['evidence'] );
		return $data;
	}
}
