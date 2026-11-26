<?php
/**
 * Gateway registry.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway;

use Memberkin\Domain\Pricing\Quote;
use Memberkin\Gateway\Contracts\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Holds gateway instances. Gateways register on the "memberkin_register_gateways" action or the "memberkin_payment_gateways" filter.
 */
class GatewayRegistry {

	/**
	 * Gateways by id.
	 *
	 * @var array<string, Gateway>
	 */
	private array $gateways = array();

	/**
	 * Whether the registration hook already ran.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

	/**
	 * Registers a gateway (replaces one with the same id).
	 *
	 * @param Gateway $gateway Gateway.
	 */
	public function register( Gateway $gateway ): void {
		$this->gateways[ $gateway->id() ] = $gateway;
	}

	/**
	 * Fires the registration hook once.
	 */
	private function load(): void {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;
		/**
		 * Register gateways: $registry->register( new MyGateway() ).
		 *
		 * @param GatewayRegistry $registry Registry.
		 */
		do_action( 'memberkin_register_gateways', $this );

		/**
		 * Filters the registered gateways (id => Gateway). Add-ons may add, replace or remove gateways here.
		 * Every value must implement the Gateway interface (most gateways extend AbstractGateway).
		 *
		 * @param array<string, Gateway> $gateways Gateways by id.
		 */
		$filtered       = (array) apply_filters( 'memberkin_payment_gateways', $this->gateways );
		$this->gateways = array();
		foreach ( $filtered as $gateway ) {
			if ( $gateway instanceof Gateway ) {
				$this->gateways[ $gateway->id() ] = $gateway;
			}
		}
	}

	/**
	 * All registered gateways.
	 *
	 * @return array<string, Gateway>
	 */
	public function all(): array {
		$this->load();
		return $this->gateways;
	}

	/**
	 * Gateway by id.
	 *
	 * @param string $id Id.
	 */
	public function get( string $id ): ?Gateway {
		$this->load();
		return $this->gateways[ $id ] ?? null;
	}

	/**
	 * Enabled gateways.
	 *
	 * @return array<string, Gateway>
	 */
	public function ready(): array {
		return array_filter( $this->all(), static fn( Gateway $g ) => $g->is_ready() );
	}

	/**
	 * Gateways usable for a quote (respects per-level allowed gateways).
	 *
	 * @param Quote $quote   Quote.
	 * @param array $allowed Allowed ids (empty = all).
	 * @return array<string, Gateway>
	 */
	public function available_for( Quote $quote, array $allowed = array() ): array {
		$list = array_filter(
			$this->all(),
			static fn( Gateway $g ) => $g->is_available_for( $quote ) && ( ! $allowed || in_array( $g->id(), $allowed, true ) )
		);
		/**
		 * Filters gateways offered for a quote.
		 *
		 * @param array<string, Gateway> $list  Gateways.
		 * @param Quote                  $quote Quote.
		 */
		return (array) apply_filters( 'memberkin_available_gateways', $list, $quote );
	}
}
