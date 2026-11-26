<?php
/**
 * Base class for gateways.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway;

use Memberkin\Core\Crypto;
use Memberkin\Core\Http;
use Memberkin\Core\HttpResponse;
use Memberkin\Core\Money;
use Memberkin\Core\Settings;
use Memberkin\Domain\Order;
use Memberkin\Domain\Pricing\Quote;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\Contracts\Capability;
use Memberkin\Gateway\Contracts\ChargeRequest;
use Memberkin\Gateway\Contracts\Gateway;
use Memberkin\Gateway\Contracts\InvalidSignature;
use Memberkin\Gateway\Contracts\OperationResult;
use Memberkin\Gateway\Contracts\PaymentContext;
use Memberkin\Gateway\Contracts\PaymentMethodUpdate;
use Memberkin\Gateway\Contracts\PaymentResult;
use Memberkin\Gateway\Contracts\UnsupportedOperation;
use Memberkin\Gateway\Contracts\VerifiedWebhook;

defined( 'ABSPATH' ) || exit;

/**
 * Settings, credentials, HTTP and default "unsupported" implementations.
 *
 * Credentials resolve in this order: constant MEMBERKIN_{ID}_{MODE}_{KEY} (e.g. MEMBERKIN_STRIPE_TEST_SECRET_KEY),
 * then constant MEMBERKIN_{ID}_{KEY}, then the encrypted setting "{mode}_{key}".
 */
abstract class AbstractGateway implements Gateway {

	/**
	 * Supported capabilities.
	 *
	 * @var string[]
	 */
	protected array $capabilities = array();

	/**
	 * Supported currencies (empty = any).
	 *
	 * @var string[]
	 */
	protected array $currencies = array();

	/**
	 * Settings section name.
	 */
	public function section(): string {
		return 'gateway_' . $this->id();
	}

	/**
	 * Setting value.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Default.
	 */
	public function setting( string $key, mixed $fallback = null ): mixed {
		return Settings::get( $this->section(), $key, $fallback );
	}

	/**
	 * {@inheritDoc}
	 */
	public function checkout_label(): string {
		$title = (string) $this->setting( 'title', '' );
		return '' !== $title ? $title : $this->label();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $capability Capability.
	 */
	public function supports( string $capability ): bool {
		/**
		 * Filters gateway capabilities.
		 *
		 * @param bool    $supported  Supported.
		 * @param string  $capability Capability.
		 * @param Gateway $gateway    Gateway.
		 */
		return (bool) apply_filters( 'memberkin_gateway_supports', in_array( $capability, $this->capabilities, true ), $capability, $this );
	}

	/**
	 * {@inheritDoc}
	 */
	public function mode(): string {
		if ( defined( 'MEMBERKIN_GATEWAY_MODE' ) && in_array( MEMBERKIN_GATEWAY_MODE, array( 'live', 'test' ), true ) ) {
			return MEMBERKIN_GATEWAY_MODE;
		}
		return 'live' === $this->setting( 'mode', 'test' ) ? 'live' : 'test';
	}

	/**
	 * Credential for the current mode.
	 *
	 * @param string $key Key, e.g. "secret_key".
	 */
	public function credential( string $key ): string {
		$id   = strtoupper( str_replace( '-', '_', $this->id() ) );
		$mode = strtoupper( $this->mode() );
		$name = strtoupper( $key );
		foreach ( array( "MEMBERKIN_{$id}_{$mode}_{$name}", "MEMBERKIN_{$id}_{$name}" ) as $constant ) {
			if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
				return (string) constant( $constant );
			}
		}
		return Crypto::decrypt( (string) $this->setting( $this->mode() . '_' . $key, '' ) );
	}

	/**
	 * Non-secret credential (publishable key, client id…) for the current mode: constant, then the plain setting.
	 *
	 * @param string $key Key.
	 */
	public function public_credential( string $key ): string {
		$constant = $this->credential_constant( $this->mode(), $key );
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
			return (string) constant( $constant );
		}
		return trim( (string) $this->setting( $this->mode() . '_' . $key, '' ) );
	}

	/**
	 * Text fields (both modes) for a non-secret credential.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @return array<int, array<string, mixed>>
	 */
	protected function public_fields( string $key, string $label ): array {
		$fields = array();
		foreach ( array(
			'test' => __( 'Test', 'memberkin' ),
			'live' => __( 'Live', 'memberkin' ),
		) as $mode => $mode_label ) {
			$fields[] = array(
				'tab'      => 'payments',
				'section'  => $this->section(),
				'key'      => $mode . '_' . $key,
				/* translators: 1: mode, 2: credential label. */
				'label'    => sprintf( __( '%1$s %2$s', 'memberkin' ), $mode_label, $label ),
				'type'     => 'text',
				'constant' => $this->credential_constant( $mode, $key ),
			);
		}
		return $fields;
	}

	/**
	 * Name of the wp-config constant that overrides a credential in a mode.
	 *
	 * @param string $mode live|test.
	 * @param string $key  Key.
	 */
	public function credential_constant( string $mode, string $key ): string {
		return 'MEMBERKIN_' . strtoupper( str_replace( '-', '_', $this->id() ) ) . '_' . strtoupper( $mode ) . '_' . strtoupper( $key );
	}

	/**
	 * Standard fields: enabled, mode, checkout title and description.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function common_fields(): array {
		$section = $this->section();
		return array(
			array(
				'tab'     => 'payments',
				'section' => $section,
				'key'     => 'enabled',
				'label'   => __( 'Enabled', 'memberkin' ),
				'type'    => 'checkbox',
			),
			array(
				'tab'      => 'payments',
				'section'  => $section,
				'key'      => 'mode',
				'label'    => __( 'Mode', 'memberkin' ),
				'type'     => 'select',
				'options'  => array(
					'test' => __( 'Test / sandbox', 'memberkin' ),
					'live' => __( 'Live', 'memberkin' ),
				),
				'constant' => 'MEMBERKIN_GATEWAY_MODE',
			),
			array(
				'tab'         => 'payments',
				'section'     => $section,
				'key'         => 'title',
				'label'       => __( 'Title at checkout', 'memberkin' ),
				'type'        => 'text',
				'placeholder' => $this->label(),
			),
			array(
				'tab'     => 'payments',
				'section' => $section,
				'key'     => 'description',
				'label'   => __( 'Description at checkout', 'memberkin' ),
				'type'    => 'textarea',
			),
		);
	}

	/**
	 * Secret field definitions for both modes.
	 *
	 * @param string $key   Credential key.
	 * @param string $label Label.
	 * @return array<int, array<string, mixed>>
	 */
	protected function secret_fields( string $key, string $label ): array {
		$fields = array();
		foreach ( array(
			'test' => __( 'Test', 'memberkin' ),
			'live' => __( 'Live', 'memberkin' ),
		) as $mode => $mode_label ) {
			$fields[] = array(
				'tab'      => 'payments',
				'section'  => $this->section(),
				'key'      => $mode . '_' . $key,
				/* translators: 1: mode, 2: credential label. */
				'label'    => sprintf( __( '%1$s %2$s', 'memberkin' ), $mode_label, $label ),
				'type'     => 'secret',
				'constant' => $this->credential_constant( $mode, $key ),
			);
		}
		return $fields;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_ready(): bool {
		return (bool) $this->setting( 'enabled', false );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Quote $quote Quote.
	 */
	public function is_available_for( Quote $quote ): bool {
		if ( ! $this->is_ready() ) {
			return false;
		}
		if ( $this->currencies && ! in_array( $quote->currency, $this->currencies, true ) ) {
			return false;
		}
		if ( $quote->is_recurring() && ! $this->supports( Capability::RECURRING_GATEWAY ) && ! $this->supports( Capability::RECURRING_SITE ) ) {
			return false;
		}
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Quote $quote Quote.
	 */
	public function render_payment_fields( Quote $quote ): string {
		$description = (string) $this->setting( 'description', '' );
		return '' !== $description ? '<p class="memberkin-gateway-description">' . esc_html( $description ) . '</p>' : '';
	}

	/**
	 * Webhook URL for this gateway.
	 */
	public function webhook_url(): string {
		return rest_url( 'memberkin/v1/webhooks/' . $this->id() );
	}

	/**
	 * HTTP request on the gateway's log channel.
	 *
	 * @param string $method  Method.
	 * @param string $url     URL.
	 * @param array  $options See Http::request().
	 */
	protected function http( string $method, string $url, array $options = array() ): HttpResponse {
		$options['channel'] = $this->id();
		return Http::request( $method, $url, $options );
	}

	/**
	 * Throws for unsupported operations.
	 *
	 * @param string $what Operation.
	 * @throws UnsupportedOperation Always.
	 */
	protected function unsupported( string $what ): never {
		/* translators: 1: gateway, 2: operation. */
		throw new UnsupportedOperation( sprintf( esc_html__( '%1$s does not support %2$s.', 'memberkin' ), esc_html( $this->label() ), esc_html( $what ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param PaymentContext $context Context.
	 * @param array          $params  Params.
	 */
	public function complete_payment( PaymentContext $context, array $params ): PaymentResult {
		$this->unsupported( 'offsite returns' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ChargeRequest $request Request.
	 */
	public function charge_saved_method( ChargeRequest $request ): PaymentResult {
		$this->unsupported( 'automatic renewals' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Subscription $subscription  Subscription.
	 * @param bool         $at_period_end At period end.
	 */
	public function cancel_subscription( Subscription $subscription, bool $at_period_end ): OperationResult {
		$this->unsupported( 'cancelling subscriptions' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Subscription            $subscription Subscription.
	 * @param \DateTimeImmutable|null $resume_at    Resume.
	 */
	public function pause_subscription( Subscription $subscription, ?\DateTimeImmutable $resume_at ): OperationResult {
		$this->unsupported( 'pausing subscriptions' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Subscription $subscription Subscription.
	 */
	public function resume_subscription( Subscription $subscription ): OperationResult {
		$this->unsupported( 'resuming subscriptions' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Order  $order  Order.
	 * @param Money  $amount Amount.
	 * @param string $reason Reason.
	 */
	public function refund( Order $order, Money $amount, string $reason ): OperationResult {
		$this->unsupported( 'refunds' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Subscription $subscription Subscription.
	 * @param string       $return_url   Return URL.
	 */
	public function payment_method_update( Subscription $subscription, string $return_url ): PaymentMethodUpdate {
		$this->unsupported( 'payment method updates' );
	}

	/**
	 * Payload keys holding gateway identifiers that may look like card numbers (13–19 digits). Stored webhook payloads
	 * are redacted; these keys keep their values (only plain identifier characters are allowed).
	 *
	 * @return string[]
	 */
	public function webhook_identifier_keys(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @throws InvalidSignature Always (no webhooks).
	 */
	public function verify_webhook( \WP_REST_Request $request ): VerifiedWebhook {
		throw new InvalidSignature( 'Webhooks are not supported by this gateway.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param VerifiedWebhook $webhook Webhook.
	 */
	public function parse_webhook( VerifiedWebhook $webhook ): array {
		return array();
	}

	/**
	 * Whether an unpaid (pending) order already gives access. Gateways that take payment
	 * outside the site (bank transfer, cheque) may return true.
	 */
	public function grants_access_before_payment(): bool {
		return false;
	}

	/**
	 * Payment instructions shown on the confirmation page and in emails while an order is unpaid.
	 *
	 * @param Order|null $order Order.
	 */
	public function instructions( ?Order $order = null ): string {
		return '';
	}
}
