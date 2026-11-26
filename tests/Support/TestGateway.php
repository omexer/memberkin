<?php
/**
 * Test gateway: simulates every gateway behaviour without contacting a payment provider.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Support;

use Memberkin\Core\Clock;
use Memberkin\Core\Crypto;
use Memberkin\Core\Money;
use Memberkin\Domain\Order;
use Memberkin\Domain\PaymentMethod;
use Memberkin\Domain\Pricing\Quote;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\AbstractGateway;
use Memberkin\Gateway\Contracts\Capability;
use Memberkin\Gateway\Contracts\ChargeRequest;
use Memberkin\Gateway\Contracts\InvalidSignature;
use Memberkin\Gateway\Contracts\OperationResult;
use Memberkin\Gateway\Contracts\PaymentContext;
use Memberkin\Gateway\Contracts\PaymentMethodUpdate;
use Memberkin\Gateway\Contracts\PaymentResult;
use Memberkin\Gateway\Contracts\VerifiedWebhook;
use Memberkin\Gateway\Contracts\WebhookEvent;

defined( 'ABSPATH' ) || exit;

/**
 * Only available when MEMBERKIN_TEST_GATEWAY is true (never on production sites).
 *
 * Outcomes are chosen with the "test_card" field: success | decline | action | pending | redirect.
 * Saved tokens containing "decline" fail on renewal. Webhooks are signed with HMAC-SHA256 over "{t}.{body}"
 * using the "webhook_secret" credential, header "X-Memberkin-Test-Signature: t=…,v1=…".
 */
final class TestGateway extends AbstractGateway {

	/**
	 * Capabilities.
	 *
	 * @var string[]
	 */
	protected array $capabilities = array(
		Capability::ONE_TIME,
		Capability::RECURRING_SITE,
		Capability::TRIALS,
		Capability::REFUND_FULL,
		Capability::REFUND_PARTIAL,
		Capability::CANCEL,
		Capability::PAUSE,
		Capability::CHANGE_PLAN,
		Capability::PAYMENT_METHOD_UPDATE,
		Capability::SAVED_METHODS,
		Capability::ONSITE_FIELDS,
		Capability::OFFSITE_REDIRECT,
		Capability::WEBHOOKS,
	);

	/**
	 * Whether the test gateway may be used on this site.
	 */
	public static function allowed(): bool {
		return defined( 'MEMBERKIN_TEST_GATEWAY' ) && MEMBERKIN_TEST_GATEWAY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'test';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Test gateway (no real payments)', 'memberkin' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function mode(): string {
		return 'test';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_ready(): bool {
		return self::allowed() && parent::is_ready();
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_fields(): array {
		$fields = array_values( array_filter( $this->common_fields(), static fn( $f ) => 'mode' !== $f['key'] ) );
		return array_merge( $fields, array_slice( $this->secret_fields( 'webhook_secret', __( 'webhook secret', 'memberkin' ) ), 0, 1 ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Quote $quote Quote.
	 */
	public function render_payment_fields( Quote $quote ): string {
		$options = array(
			'success'  => __( 'Payment succeeds', 'memberkin' ),
			'decline'  => __( 'Card is declined', 'memberkin' ),
			'action'   => __( 'Needs authentication (3-D Secure)', 'memberkin' ),
			'pending'  => __( 'Payment is pending (settles later)', 'memberkin' ),
			'redirect' => __( 'Offsite redirect', 'memberkin' ),
		);
		$html    = '<p><label for="memberkin-test-card">' . esc_html__( 'Simulated result', 'memberkin' ) . '</label> <select id="memberkin-test-card" name="memberkin_gateway[test_card]">';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		return $html . '</select></p>';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param PaymentContext $context Context.
	 */
	public function start_payment( PaymentContext $context ): PaymentResult {
		$card = (string) ( $context->fields['test_card'] ?? 'success' );
		switch ( $card ) {
			case 'decline':
				return PaymentResult::failed( __( 'Your card was declined.', 'memberkin' ) );
			case 'action':
				if ( ! empty( $context->fields['confirmed'] ) ) {
					return $this->success( $context->user->ID );
				}
				return new PaymentResult( PaymentResult::REQUIRES_ACTION, '', null, null, null, null, null, 'test_secret_' . Crypto::token( 8 ) );
			case 'pending':
				return new PaymentResult( PaymentResult::PENDING, '', 'test_pi_' . Crypto::token( 8 ) );
			case 'redirect':
				return new PaymentResult( PaymentResult::REDIRECT, '', null, null, null, null, add_query_arg( 'test_result', 'success', $context->return_url ) );
			default:
				return $this->success( $context->user->ID );
		}
	}

	/**
	 * Successful result with a saved method.
	 *
	 * @param int    $user_id User.
	 * @param string $token   Token.
	 */
	private function success( int $user_id, string $token = '' ): PaymentResult {
		$token  = '' !== $token ? $token : 'tok_' . Crypto::token( 10 );
		$method = new PaymentMethod( 0, $user_id, 'test', 'test', $token, 'card', 'visa', '4242', 12, (int) gmdate( 'Y' ) + 3, true );
		return new PaymentResult( PaymentResult::COMPLETED, '', 'test_ch_' . Crypto::token( 8 ), 'test_cus_' . $user_id, null, $method, null, null, null, $method->label() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param PaymentContext $context Context.
	 * @param array          $params  Params.
	 */
	public function complete_payment( PaymentContext $context, array $params ): PaymentResult {
		if ( 'success' === ( $params['test_result'] ?? '' ) ) {
			return $this->success( $context->user->ID );
		}
		return PaymentResult::failed( __( 'The payment was not completed.', 'memberkin' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ChargeRequest $request Request.
	 */
	public function charge_saved_method( ChargeRequest $request ): PaymentResult {
		if ( str_contains( $request->payment_method->token, 'decline' ) ) {
			return PaymentResult::failed( __( 'Your card was declined.', 'memberkin' ) );
		}
		if ( str_contains( $request->payment_method->token, 'action' ) ) {
			return new PaymentResult( PaymentResult::REQUIRES_ACTION, __( 'Authentication required.', 'memberkin' ) );
		}
		return new PaymentResult( PaymentResult::COMPLETED, '', 'test_ch_' . Crypto::token( 8 ), null, null, null, null, null, null, $request->payment_method->label() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Order  $order  Order.
	 * @param Money  $amount Amount.
	 * @param string $reason Reason.
	 */
	public function refund( Order $order, Money $amount, string $reason ): OperationResult {
		return OperationResult::ok( 'test_re_' . Crypto::token( 8 ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Subscription $subscription  Subscription.
	 * @param bool         $at_period_end At period end.
	 */
	public function cancel_subscription( Subscription $subscription, bool $at_period_end ): OperationResult {
		return OperationResult::ok();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Subscription            $subscription Subscription.
	 * @param \DateTimeImmutable|null $resume_at    Resume.
	 */
	public function pause_subscription( Subscription $subscription, ?\DateTimeImmutable $resume_at ): OperationResult {
		return OperationResult::ok();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Subscription $subscription Subscription.
	 */
	public function resume_subscription( Subscription $subscription ): OperationResult {
		return OperationResult::ok();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Subscription $subscription Subscription.
	 * @param string       $return_url   Return URL.
	 */
	public function payment_method_update( Subscription $subscription, string $return_url ): PaymentMethodUpdate {
		return new PaymentMethodUpdate( 'form', '', '', array( 'fields' => $this->render_payment_fields( new Quote( $subscription->currency, $subscription->user_id, array( $subscription->level_id ) ) ) ) );
	}

	/**
	 * Signs a body (used by tests and the "send test webhook" tool).
	 *
	 * @param string   $body Body.
	 * @param int|null $time Timestamp.
	 */
	public function sign( string $body, ?int $time = null ): string {
		$time = $time ?? Clock::timestamp();
		return 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $body, $this->credential( 'webhook_secret' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @throws InvalidSignature When the signature is missing, wrong or too old.
	 */
	public function verify_webhook( \WP_REST_Request $request ): VerifiedWebhook {
		$secret = $this->credential( 'webhook_secret' );
		$header = (string) $request->get_header( 'x_memberkin_test_signature' );
		$body   = (string) $request->get_body();
		if ( '' === $secret || ! preg_match( '/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $m ) ) {
			throw new InvalidSignature( 'Missing signature.' );
		}
		if ( abs( Clock::timestamp() - (int) $m[1] ) > 300 ) {
			throw new InvalidSignature( 'Signature timestamp outside tolerance.' );
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $m[1] . '.' . $body, $secret ), $m[2] ) ) {
			throw new InvalidSignature( 'Signature mismatch.' );
		}
		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) || empty( $payload['id'] ) || empty( $payload['type'] ) ) {
			throw new InvalidSignature( 'Malformed payload.' );
		}
		return new VerifiedWebhook( 'test', 'test', (string) $payload['id'], (string) $payload['type'], $payload );
	}

	/**
	 * Payload "data" keys map directly onto WebhookEvent fields.
	 *
	 * @param VerifiedWebhook $webhook Webhook.
	 */
	public function parse_webhook( VerifiedWebhook $webhook ): array {
		$d = (array) ( $webhook->payload['data'] ?? array() );
		return array(
			new WebhookEvent(
				$webhook->type,
				isset( $d['transaction_id'] ) ? (string) $d['transaction_id'] : null,
				isset( $d['subscription_id'] ) ? (string) $d['subscription_id'] : null,
				isset( $d['order_id'] ) ? (int) $d['order_id'] : null,
				isset( $d['amount'] ) ? (int) $d['amount'] : null,
				isset( $d['currency'] ) ? (string) $d['currency'] : null,
				isset( $d['refund_id'] ) ? (string) $d['refund_id'] : null,
				(string) ( $d['message'] ?? '' ),
				$d
			),
		);
	}
}
