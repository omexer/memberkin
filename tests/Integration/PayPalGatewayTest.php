<?php
/**
 * PayPal gateway against a faked PayPal API.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Crypto;
use Memberkin\Core\Money;
use Memberkin\Core\Settings;
use Memberkin\Domain\Order;
use Memberkin\Domain\Pricing\RecurringTerms;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\GatewayRegistry;
use Memberkin\Gateway\PayPal\PayPalGateway;
use Memberkin\Repository\OrderRepository;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\Checkout\CheckoutResult;
use Memberkin\Service\Checkout\CheckoutService;
use Memberkin\Service\RefundService;
use Memberkin\Service\SubscriptionService;

/**
 * @covers \Memberkin\Gateway\PayPal\PayPalGateway
 * @covers \Memberkin\Gateway\PayPal\PayPalClient
 */
final class PayPalGatewayTest extends GatewayTestCase {

	private string $verification = 'SUCCESS';

	public function set_up(): void {
		parent::set_up();
		Settings::update(
			'gateway_paypal',
			array(
				'enabled'            => true,
				'mode'               => 'test',
				'test_client_id'     => 'client-abc',
				'test_client_secret' => Crypto::encrypt( 'secret-xyz' ),
				'test_webhook_id'    => 'WH-ID-1',
			)
		);
		delete_option( 'memberkin_paypal_plans' );
		$this->verification = 'SUCCESS';
		$this->route(
			'POST',
			'#/v1/oauth2/token$#',
			array(
				200,
				array(
					'access_token' => 'A21-token',
					'expires_in'   => 32400,
				),
			)
		);
		$this->route( 'POST', '#/v1/notifications/verify-webhook-signature$#', fn() => array( 200, array( 'verification_status' => $this->verification ) ) );
	}

	private function gateway(): PayPalGateway {
		return memberkin()->get( GatewayRegistry::class )->get( 'paypal' );
	}

	private function checkout( int $user, $level ): CheckoutResult {
		return $this->member_checkout( $user, $level, array( 'gateway' => 'paypal', 'gateway_fields' => array() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	private function event( string $id, string $type, array $resource ): \WP_REST_Response {
		return $this->webhook(
			'paypal',
			(string) wp_json_encode(
				array(
					'id'         => $id,
					'event_type' => $type,
					'resource'   => $resource,
				)
			),
			array(
				'paypal-auth-algo'         => 'SHA256withRSA',
				'paypal-cert-url'          => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
				'paypal-transmission-id'   => 'tx-' . $id,
				'paypal-transmission-sig'  => 'sig',
				'paypal-transmission-time' => gmdate( 'c' ),
			)
		);
	}

	public function test_one_time_order_capture(): void {
		$level = $this->level( array( 'initial_price' => '15' ) );
		$user  = self::factory()->user->create();
		$this->route(
			'POST',
			'#/v2/checkout/orders$#',
			array(
				201,
				array(
					'id'    => 'PPO1',
					'links' => array( array( 'rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PPO1' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				),
			)
		);
		$res = $this->checkout( $user, $level );
		$this->assertSame( CheckoutResult::REDIRECT, $res->status, wp_json_encode( $res->errors ) );
		$this->assertSame( 'https://www.sandbox.paypal.com/checkoutnow?token=PPO1', $res->redirect_url );
		$create = $this->sent( 'POST', '#/v2/checkout/orders$#' )[0];
		$this->assertSame( '15.00', $create['body']['purchase_units'][0]['amount']['value'] );
		$this->assertSame( (string) $res->order->id, $create['body']['purchase_units'][0]['custom_id'] );
		$this->assertSame( 'Bearer A21-token', $create['headers']['Authorization'] );
		$this->assertArrayHasKey( 'PayPal-Request-Id', $create['headers'] );
		$this->assertStringStartsWith( 'Basic ', $this->sent( 'POST', '#/v1/oauth2/token$#' )[0]['headers']['Authorization'] );

		// A token from another PayPal order is refused without calling PayPal.
		$done = memberkin()->get( CheckoutService::class )->confirm( $res->token, array( 'token' => 'OTHER' ) );
		$this->assertSame( CheckoutResult::FAILED, $done->status );

		$res = $this->checkout( $user, $level );
		$this->route(
			'POST',
			'#/v2/checkout/orders/PPO1/capture$#',
			fn() => array(
				201,
				array(
					'id'             => 'PPO1',
					'status'         => 'COMPLETED',
					'payer'          => array( 'payer_id' => 'PAYER1', 'email_address' => 'buyer@example.com' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					'purchase_units' => array( array( 'custom_id' => (string) $res->order->id, 'payments' => array( 'captures' => array( array( 'id' => 'CAP1', 'status' => 'COMPLETED' ) ) ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				),
			)
		);
		$done = memberkin()->get( CheckoutService::class )->confirm( $res->token, array( 'token' => 'PPO1' ) );
		$this->assertSame( CheckoutResult::COMPLETED, $done->status, wp_json_encode( $done->errors ) );
		$order = memberkin()->get( OrderRepository::class )->find( $res->order->id );
		$this->assertSame( 'CAP1', $order->gateway_transaction_id );
		$this->assertSame( 'PayPal (buyer@example.com)', $order->payment_method_label );
		$this->assertCount( 1, $this->sent( 'POST', '#/v1/oauth2/token$#' ), 'access token cached' );

		// Refund of a capture.
		$this->route(
			'POST',
			'#/v2/payments/captures/CAP1/refund$#',
			array(
				201,
				array(
					'id'     => 'RF1',
					'status' => 'COMPLETED',
				),
			)
		);
		$order = memberkin()->get( RefundService::class )->refund( $order->id, Money::of( 500, 'USD' ) );
		$this->assertSame( 500, $order->amount_refunded );
		$this->assertSame( '5.00', $this->sent( 'POST', '#/refund$#' )[0]['body']['amount']['value'] );
	}

	public function test_subscription_flow_with_setup_fee_renewal_and_cancel(): void {
		$level = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '20',
				'recurring_price' => '20',
			)
		);
		$this->route( 'POST', '#/v1/catalogs/products$#', array( 201, array( 'id' => 'PROD-1' ) ) );
		$this->route( 'POST', '#/v1/billing/plans$#', array( 201, array( 'id' => 'P-1' ) ) );
		$n = 0;
		$this->route(
			'POST',
			'#/v1/billing/subscriptions$#',
			static function () use ( &$n ) {
				++$n;
				return array(
					201,
					array(
						'id'     => 'I-SUB' . $n,
						'status' => 'APPROVAL_PENDING',
						'links'  => array( array( 'rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=BA-' . $n ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					),
				);
			}
		);
		$user = self::factory()->user->create();
		$res  = $this->checkout( $user, $level );
		$this->assertSame( CheckoutResult::REDIRECT, $res->status, wp_json_encode( $res->errors ) );
		$sub = memberkin()->get( SubscriptionRepository::class )->find( $res->order->subscription_id );
		$this->assertSame( 'gateway', $sub->billing_model );
		$this->assertSame( 'I-SUB1', $sub->gateway_subscription_id );

		$plan = $this->sent( 'POST', '#/v1/billing/plans$#' )[0]['body'];
		$this->assertSame( 'PROD-1', $plan['product_id'] );
		$this->assertSame( 'MONTH', $plan['billing_cycles'][0]['frequency']['interval_unit'] );
		$this->assertSame( '20.00', $plan['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'] );
		$create = $this->sent( 'POST', '#/v1/billing/subscriptions$#' )[0]['body'];
		$this->assertSame( 'P-1', $create['plan_id'] );
		$this->assertSame( '20.00', $create['plan']['payment_preferences']['setup_fee']['value'] );
		$this->assertSame( (string) $res->order->id, $create['custom_id'] );

		// Same price again: product and plan are reused.
		$this->checkout( self::factory()->user->create(), $level );
		$this->assertCount( 1, $this->sent( 'POST', '#/v1/billing/plans$#' ) );
		$this->assertCount( 1, $this->sent( 'POST', '#/v1/catalogs/products$#' ) );

		// Return from PayPal.
		$order_id = $res->order->id;
		$cycles   = 0;
		$this->route(
			'GET',
			'#/v1/billing/subscriptions/I-SUB1$#',
			static function () use ( $order_id, &$cycles ) {
				return array(
					200,
					array(
						'id'           => 'I-SUB1',
						'status'       => 'ACTIVE',
						'custom_id'    => (string) $order_id,
						'subscriber'   => array( 'payer_id' => 'PAYER9', 'email_address' => 'sub@example.com' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						'billing_info' => array(
							'next_billing_time' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 30 * DAY_IN_SECONDS ),
							'cycle_executions'  => array( array( 'tenure_type' => 'REGULAR', 'cycles_completed' => $cycles ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						),
					),
				);
			}
		);
		$done = memberkin()->get( CheckoutService::class )->confirm(
			$res->token,
			array(
				'subscription_id' => 'I-SUB1',
				'ba_token'        => 'BA-1',
			)
		);
		$this->assertSame( CheckoutResult::COMPLETED, $done->status, wp_json_encode( $done->errors ) );
		$this->assertTrue( memberkin_has_level( $level->id, $user ) );
		$this->assertSame( Subscription::ACTIVE, memberkin()->get( SubscriptionRepository::class )->find( $sub->id )->status );

		// Setup-fee sale: attached to the initial order, no renewal created.
		$sale   = static fn( string $id ) => array(
			'id'                   => $id,
			'billing_agreement_id' => 'I-SUB1',
			'amount'               => array( 'total' => '20.00', 'currency' => 'USD' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
		$this->assertSame( 200, $this->event( 'WH-1', 'PAYMENT.SALE.COMPLETED', $sale( 'SALE-SETUP' ) )->get_status() );
		$verify = $this->sent( 'POST', '#verify-webhook-signature$#' )[0];
		$this->assertSame( 'WH-ID-1', $verify['body']['webhook_id'] );
		$this->assertSame( 'WH-1', $verify['body']['webhook_event']['id'] );
		$this->assertSame( 'SALE-SETUP', memberkin()->get( OrderRepository::class )->find( $order_id )->gateway_transaction_id );
		$renewals = static fn() => array_values( array_filter( memberkin()->get( OrderRepository::class )->for_subscription( $sub->id ), static fn( Order $o ) => 'renewal' === $o->type ) );
		$this->assertCount( 0, $renewals() );

		// First cycle billed by PayPal: renewal order.
		$cycles = 1;
		$this->event( 'WH-2', 'PAYMENT.SALE.COMPLETED', $sale( 'SALE-R1' ) );
		$this->assertCount( 1, $renewals() );
		$this->assertSame( 'SALE-R1', $renewals()[0]->gateway_transaction_id );
		$this->assertSame( 2000, $renewals()[0]->total );

		// Refund of a subscription sale falls back to the v1 sale endpoint.
		$this->route( 'POST', '#/v2/payments/captures/SALE-R1/refund$#', array( 404, array( 'name' => 'RESOURCE_NOT_FOUND', 'details' => array( array( 'issue' => 'INVALID_RESOURCE_ID' ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->route(
			'POST',
			'#/v1/payments/sale/SALE-R1/refund$#',
			array(
				201,
				array(
					'id'    => 'SRF1',
					'state' => 'completed',
				),
			)
		);
		$refunded = memberkin()->get( RefundService::class )->refund( $renewals()[0]->id, Money::of( 2000, 'USD' ) );
		$this->assertSame( Order::REFUNDED, $refunded->status );

		// Scheduled cancellation suspends billing at PayPal; the SUSPENDED webhook is not mirrored as a pause.
		$this->route( 'POST', '#/v1/billing/subscriptions/I-SUB1/(suspend|cancel|activate)$#', array( 204, '' ) );
		memberkin()->get( SubscriptionService::class )->cancel( $sub->id, true, 'member' );
		$this->assertCount( 1, $this->sent( 'POST', '#I-SUB1/suspend$#' ) );
		$this->event( 'WH-3', 'BILLING.SUBSCRIPTION.SUSPENDED', array( 'id' => 'I-SUB1' ) );
		$this->assertNotSame( Subscription::PAUSED, memberkin()->get( SubscriptionRepository::class )->find( $sub->id )->status );

		// Undo: re-activated at PayPal.
		memberkin()->get( SubscriptionService::class )->reactivate( $sub->id );
		$this->assertCount( 1, $this->sent( 'POST', '#I-SUB1/activate$#' ) );

		// Ending it here cancels at PayPal.
		memberkin()->get( SubscriptionService::class )->cancel( $sub->id, false, 'admin' );
		$this->assertNotEmpty( $this->sent( 'POST', '#I-SUB1/cancel$#' ) );
		$this->assertSame( Subscription::CANCELLED, memberkin()->get( SubscriptionRepository::class )->find( $sub->id )->status );
	}

	public function test_unverified_webhooks_are_rejected(): void {
		$this->verification = 'FAILURE';
		$this->assertSame( 400, $this->event( 'WH-X', 'PAYMENT.CAPTURE.COMPLETED', array( 'id' => 'CAP9' ) )->get_status() );
		$res = $this->webhook( 'paypal', '{"id":"WH-Y","event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{}}', array() );
		$this->assertSame( 400, $res->get_status(), 'missing transmission headers' );
	}

	public function test_billing_cycles_and_availability(): void {
		$start  = new \DateTimeImmutable( '+1 month' );
		$intro  = new RecurringTerms( 1, Money::of( 500, 'USD' ), Money::of( 1000, 'USD' ), 1, 'month', $start, 12, 2, 100, '20' );
		$cycles = PayPalGateway::billing_cycles( $intro, 'USD' );
		$this->assertSame( 'TRIAL', $cycles[0]['tenure_type'] );
		$this->assertSame( 2, $cycles[0]['total_cycles'] );
		$this->assertSame( '6.00', $cycles[0]['pricing_scheme']['fixed_price']['value'], 'net 5.00 + tax 1.00' );
		$this->assertSame( 'REGULAR', $cycles[1]['tenure_type'] );
		$this->assertSame( 10, $cycles[1]['total_cycles'] );
		$this->assertSame( '12.00', $cycles[1]['pricing_scheme']['fixed_price']['value'], 'net 10.00 + 20% tax' );

		$plain = PayPalGateway::billing_cycles( new RecurringTerms( 1, Money::of( 1000, 'USD' ), Money::of( 1000, 'USD' ), 3, 'month', $start ), 'USD' );
		$this->assertCount( 1, $plain );
		$this->assertSame( 0, $plain[0]['total_cycles'] );
		$this->assertSame( 3, $plain[0]['frequency']['interval_count'] );

		$this->assertSame( '1235', PayPalGateway::value( Money::of( 123450, 'HUF' ) ) );
		$this->assertSame( '12.50', PayPalGateway::value( Money::of( 1250, 'EUR' ) ) );

		$long  = $this->level(
			array(
				'billing_type'     => 'recurring',
				'initial_price'    => '5',
				'recurring_price'  => '5',
				'billing_interval' => 2,
				'billing_period'   => 'year',
			)
		);
		$quote = memberkin()->get( \Memberkin\Service\PricingService::class )->quote( array( 'level_ids' => array( $long->id ) ) );
		$this->assertFalse( $this->gateway()->is_available_for( $quote ), 'PayPal bills at most yearly' );
	}
}
