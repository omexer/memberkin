<?php
/**
 * Stripe gateway against a faked Stripe API.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Clock;
use Memberkin\Core\Crypto;
use Memberkin\Core\Logger;
use Memberkin\Core\Money;
use Memberkin\Core\Settings;
use Memberkin\Domain\Order;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\GatewayRegistry;
use Memberkin\Gateway\Stripe\StripeClient;
use Memberkin\Gateway\Stripe\StripeGateway;
use Memberkin\Repository\OrderRepository;
use Memberkin\Repository\PaymentMethodRepository;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\Checkout\CheckoutResult;
use Memberkin\Service\Checkout\CheckoutService;
use Memberkin\Service\RefundService;
use Memberkin\Service\SubscriptionService;

/**
 * @covers \Memberkin\Gateway\Stripe\StripeGateway
 * @covers \Memberkin\Gateway\Stripe\StripeClient
 */
final class StripeGatewayTest extends GatewayTestCase {

	private const WHSEC = 'whsec_testsecret123';

	public function set_up(): void {
		parent::set_up();
		Settings::update(
			'gateway_stripe',
			array(
				'enabled'              => true,
				'mode'                 => 'test',
				'test_publishable_key' => 'pk_test_abc',
				'test_secret_key'      => Crypto::encrypt( 'sk_test_abc123456789' ),
				'test_webhook_secret'  => Crypto::encrypt( self::WHSEC ),
			)
		);
		$this->route(
			'POST',
			'#/v1/customers$#',
			array(
				200,
				array(
					'id'     => 'cus_1',
					'object' => 'customer',
				),
			)
		);
	}

	private function gateway(): StripeGateway {
		return memberkin()->get( GatewayRegistry::class )->get( 'stripe' );
	}

	private static function pm( string $id = 'pm_card' ): array {
		return array(
			'id'       => $id,
			'type'     => 'card',
			'livemode' => false,
			'card'     => array(
				'brand'     => 'visa',
				'last4'     => '4242',
				'exp_month' => 12,
				'exp_year'  => 2031,
			),
		);
	}

	private function checkout( int $user, $level, string $pm = 'pm_card' ): CheckoutResult {
		return $this->member_checkout(
			$user,
			$level,
			array(
				'gateway'        => 'stripe',
				'gateway_fields' => array( 'payment_method' => $pm ),
			)
		);
	}

	private function signed( array $event, ?int $time = null ): array {
		$body = (string) wp_json_encode( $event );
		$time = $time ?? Clock::timestamp();
		return array( $body, array( 'stripe-signature' => 't=' . $time . ',v1=' . str_repeat( '0', 64 ) . ',v1=' . hash_hmac( 'sha256', $time . '.' . $body, self::WHSEC ) ) );
	}

	public function test_ready_only_with_keys(): void {
		$this->assertTrue( $this->gateway()->is_ready() );
		Settings::update( 'gateway_stripe', array( 'test_secret_key' => '' ) );
		$this->assertFalse( $this->gateway()->is_ready() );
	}

	public function test_recurring_checkout_saves_method_and_renews_off_session(): void {
		$level = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '20',
				'recurring_price' => '20',
			)
		);
		$user  = self::factory()->user->create();
		$this->route(
			'POST',
			'#/v1/payment_intents$#',
			static fn( $r ) => array(
				200,
				array(
					'id'             => 'pi_first',
					'status'         => 'succeeded',
					'customer'       => 'cus_1',
					'payment_method' => self::pm(),
					'metadata'       => $r['body']['metadata'],
				),
			)
		);
		$res = $this->checkout( $user, $level );
		$this->assertSame( CheckoutResult::COMPLETED, $res->status, wp_json_encode( $res->errors ) );

		$create = $this->sent( 'POST', '#/v1/payment_intents$#' )[0];
		$this->assertSame( '2000', $create['body']['amount'] );
		$this->assertSame( 'usd', $create['body']['currency'] );
		$this->assertSame( 'true', $create['body']['confirm'] );
		$this->assertSame( 'off_session', $create['body']['setup_future_usage'] );
		$this->assertSame( (string) $res->order->id, $create['body']['metadata']['memberkin_order_id'] );
		$this->assertSame( 'Bearer sk_test_abc123456789', $create['headers']['Authorization'] );
		$this->assertSame( StripeClient::API_VERSION, $create['headers']['Stripe-Version'] );
		$this->assertStringStartsWith( 'memberkin-order-' . $res->order->id, $create['headers']['Idempotency-Key'] );
		$this->assertSame( 'cus_1', get_user_option( 'memberkin_stripe_customer_test', $user ) );

		$order = memberkin()->get( OrderRepository::class )->find( $res->order->id );
		$this->assertSame( Order::PAID, $order->status );
		$this->assertSame( 'pi_first', $order->gateway_transaction_id );
		$sub    = memberkin()->get( SubscriptionRepository::class )->find( $order->subscription_id );
		$method = memberkin()->get( PaymentMethodRepository::class )->find( (int) $sub->payment_method_id );
		$this->assertSame( 'site', $sub->billing_model );
		$this->assertSame( 'cus_1', $sub->gateway_customer_id );
		$this->assertSame( 'pm_card', $method->token );
		$this->assertSame( '4242', $method->last4 );

		// Renewal: off-session PaymentIntent with the saved method.
		$this->route(
			'POST',
			'#/v1/payment_intents$#',
			static fn( $r ) => array(
				200,
				array(
					'id'             => 'pi_renew',
					'status'         => 'succeeded',
					'customer'       => 'cus_1',
					'payment_method' => 'pm_card',
					'metadata'       => $r['body']['metadata'],
				),
			)
		);
		Clock::freeze( $sub->next_payment_at->modify( '+1 hour' ) );
		$this->assertSame( 'charged', memberkin()->get( \Memberkin\Service\RenewalService::class )->charge( $sub->id ) );
		$renew = $this->sent( 'POST', '#/v1/payment_intents$#' )[1];
		$this->assertSame( 'true', $renew['body']['off_session'] );
		$this->assertSame( 'pm_card', $renew['body']['payment_method'] );
		$this->assertSame( 'never', $renew['body']['automatic_payment_methods']['allow_redirects'] );
		$this->assertArrayHasKey( 'Idempotency-Key', $renew['headers'] );
		$this->assertSame( 1, memberkin()->get( SubscriptionRepository::class )->find( $sub->id )->cycles_completed );

		// Next renewal needs authentication: recorded as a failure with the action hook.
		$this->route(
			'POST',
			'#/v1/payment_intents$#',
			array(
				402,
				array(
					'error' => array(
						'type'           => 'card_error',
						'code'           => 'authentication_required',
						'message'        => 'This payment requires authentication.',
						'payment_intent' => array( 'id' => 'pi_auth' ),
					),
				),
			)
		);
		$fired = 0;
		add_action(
			'memberkin_renewal_action_required',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		$sub = memberkin()->get( SubscriptionRepository::class )->find( $sub->id );
		Clock::freeze( $sub->next_payment_at->modify( '+1 hour' ) );
		$this->assertSame( 'failed', memberkin()->get( \Memberkin\Service\RenewalService::class )->charge( $sub->id ) );
		$this->assertSame( 1, $fired );
		$this->assertSame( Subscription::PAST_DUE, memberkin()->get( SubscriptionRepository::class )->find( $sub->id )->status );
	}

	public function test_decline_shows_card_message_and_bad_method_is_rejected(): void {
		$level = $this->level( array( 'initial_price' => '10' ) );
		$this->route( 'POST', '#/v1/payment_intents$#', array( 402, array( 'error' => array( 'type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card was declined.' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$res = $this->checkout( self::factory()->user->create(), $level );
		$this->assertSame( CheckoutResult::FAILED, $res->status );
		$this->assertSame( 'Your card was declined.', $res->errors['payment'] );

		$this->route( 'POST', '#/v1/payment_intents$#', array( 500, array( 'error' => array( 'type' => 'api_error', 'message' => 'Internal secret detail' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$res = $this->checkout( self::factory()->user->create(), $level );
		$this->assertStringNotContainsString( 'secret detail', $res->errors['payment'] );

		$count = count( $this->requests );
		$res   = $this->checkout( self::factory()->user->create(), $level, 'tok_visa' );
		$this->assertSame( CheckoutResult::FAILED, $res->status );
		$this->assertCount( $count, $this->requests, 'nothing sent for an invalid PaymentMethod id' );
	}

	public function test_three_d_secure_then_return(): void {
		$level = $this->level( array( 'initial_price' => '15' ) );
		$user  = self::factory()->user->create();
		$meta  = array();
		$this->route(
			'POST',
			'#/v1/payment_intents$#',
			static function ( $r ) use ( &$meta ) {
				$meta = $r['body']['metadata'];
				return array(
					200,
					array(
						'id'            => 'pi_3ds',
						'status'        => 'requires_action',
						'client_secret' => 'pi_3ds_secret_xyz',
						'metadata'      => $meta,
					),
				);
			}
		);
		$res = $this->checkout( $user, $level );
		$this->assertSame( CheckoutResult::REQUIRES_ACTION, $res->status );
		$this->assertSame( 'pi_3ds_secret_xyz', $res->client_secret );
		$this->assertSame( 'pi_3ds', memberkin()->get( OrderRepository::class )->find( $res->order->id )->gateway_transaction_id );

		// A tampered return parameter is ignored: the intent stored on the order is fetched.
		$this->route(
			'GET',
			'#/v1/payment_intents/pi_3ds#',
			static fn() => array(
				200,
				array(
					'id'             => 'pi_3ds',
					'status'         => 'succeeded',
					'customer'       => 'cus_1',
					'payment_method' => self::pm(),
					'metadata'       => $meta,
				),
			)
		);
		$done = memberkin()->get( CheckoutService::class )->confirm( $res->token, array( 'payment_intent' => 'pi_other' ) );
		$this->assertSame( CheckoutResult::COMPLETED, $done->status );
		$this->assertTrue( memberkin_has_level( $level->id, $user ) );
		$this->assertCount( 0, $this->sent( 'GET', '#pi_other#' ) );
	}

	public function test_intent_of_another_order_is_rejected_on_return(): void {
		$level = $this->level( array( 'initial_price' => '15' ) );
		$user  = self::factory()->user->create();
		$this->route(
			'POST',
			'#/v1/payment_intents$#',
			array(
				200,
				array(
					'id'            => 'pi_x',
					'status'        => 'requires_action',
					'client_secret' => 'pi_x_secret_1',
					'metadata'      => array(),
				),
			)
		);
		$res = $this->checkout( $user, $level );
		$this->route(
			'GET',
			'#/v1/payment_intents/pi_x#',
			array(
				200,
				array(
					'id'       => 'pi_x',
					'status'   => 'succeeded',
					'metadata' => array( 'memberkin_order_id' => '999999' ),
				),
			)
		);
		$done = memberkin()->get( CheckoutService::class )->confirm( $res->token, array() );
		$this->assertSame( CheckoutResult::FAILED, $done->status );
		$this->assertFalse( memberkin_has_level( $level->id, $user ) );
	}

	public function test_free_trial_uses_setup_intent(): void {
		$level = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '0',
				'recurring_price' => '12',
			)
		);
		$user  = self::factory()->user->create();
		$this->route(
			'POST',
			'#/v1/setup_intents$#',
			array(
				200,
				array(
					'id'             => 'seti_1',
					'status'         => 'succeeded',
					'customer'       => 'cus_1',
					'payment_method' => self::pm( 'pm_trial' ),
				),
			)
		);
		$res = $this->checkout( $user, $level );
		$this->assertSame( CheckoutResult::COMPLETED, $res->status, wp_json_encode( $res->errors ) );
		$this->assertCount( 0, $this->sent( 'POST', '#/v1/payment_intents#' ) );
		$this->assertSame( 'off_session', $this->sent( 'POST', '#/v1/setup_intents$#' )[0]['body']['usage'] );
		$sub = memberkin()->get( SubscriptionRepository::class )->find( $res->order->subscription_id );
		$this->assertSame( 'pm_trial', memberkin()->get( PaymentMethodRepository::class )->find( (int) $sub->payment_method_id )->token );
	}

	public function test_refund_and_refund_webhook_are_not_double_counted(): void {
		$level = $this->level( array( 'initial_price' => '30' ) );
		$this->route(
			'POST',
			'#/v1/payment_intents$#',
			static fn( $r ) => array(
				200,
				array(
					'id'             => 'pi_r',
					'status'         => 'succeeded',
					'customer'       => 'cus_1',
					'payment_method' => self::pm(),
					'metadata'       => $r['body']['metadata'],
				),
			)
		);
		$order = $this->checkout( self::factory()->user->create(), $level )->order;
		$this->route(
			'POST',
			'#/v1/refunds$#',
			array(
				200,
				array(
					'id'     => 're_1',
					'status' => 'succeeded',
				),
			)
		);

		$order = memberkin()->get( RefundService::class )->refund( $order->id, Money::of( 1000, 'USD' ), 'goodwill' );
		$this->assertSame( Order::PARTIALLY_REFUNDED, $order->status );
		$refund = $this->sent( 'POST', '#/v1/refunds$#' )[0];
		$this->assertSame( 'pi_r', $refund['body']['payment_intent'] );
		$this->assertSame( '1000', $refund['body']['amount'] );

		[ $body, $headers ] = $this->signed(
			array(
				'id'       => 'evt_refund',
				'object'   => 'event',
				'type'     => 'charge.refunded',
				'livemode' => false,
				'data'     => array(
					'object' => array(
						'id'             => 'ch_1',
						'payment_intent' => 'pi_r',
						'currency'       => 'usd',
						'refunds'        => array(
							'data' => array(
								array(
									'id'     => 're_1',
									'amount' => 1000,
									'status' => 'succeeded',
								),
								array(
									'id'     => 're_dash',
									'amount' => 500,
									'status' => 'succeeded',
								),
							),
						),
					),
				),
			)
		);
		$this->assertSame( 200, $this->webhook( 'stripe', $body, $headers )->get_status() );
		$this->assertSame( 1500, memberkin()->get( OrderRepository::class )->find( $order->id )->amount_refunded, 're_1 once, plus the dashboard refund' );
	}

	public function test_webhook_signature_rules_and_async_payment(): void {
		$level = $this->level( array( 'initial_price' => '25' ) );
		$user  = self::factory()->user->create();
		$meta  = array();
		$this->route(
			'POST',
			'#/v1/payment_intents$#',
			static function ( $r ) use ( &$meta ) {
				$meta = $r['body']['metadata'];
				return array(
					200,
					array(
						'id'       => 'pi_sepa',
						'status'   => 'processing',
						'customer' => 'cus_1',
						'metadata' => $meta,
					),
				);
			}
		);
		$res = $this->checkout( $user, $level );
		$this->assertSame( CheckoutResult::PENDING, $res->status );
		$this->assertFalse( memberkin_has_level( $level->id, $user ) );

		$event              = array(
			'id'       => 'evt_ok',
			'object'   => 'event',
			'type'     => 'payment_intent.succeeded',
			'livemode' => false,
			'data'     => array(
				'object' => array(
					'id'              => 'pi_sepa',
					'amount_received' => 2500,
					'currency'        => 'usd',
					'customer'        => 'cus_1',
					'metadata'        => $meta,
				),
			),
		);
		[ $body, $headers ] = $this->signed( $event, Clock::timestamp() - 600 );
		$this->assertSame( 400, $this->webhook( 'stripe', $body, $headers )->get_status(), 'outside tolerance' );
		[ $body ] = $this->signed( $event );
		$this->assertSame( 400, $this->webhook( 'stripe', $body, array( 'stripe-signature' => 't=' . Clock::timestamp() . ',v1=' . hash_hmac( 'sha256', Clock::timestamp() . '.' . $body, 'whsec_wrong' ) ) )->get_status() );
		$this->assertFalse( memberkin_has_level( $level->id, $user ) );

		[ $body, $headers ] = $this->signed( $event );
		$this->assertSame( 200, $this->webhook( 'stripe', $body, $headers )->get_status() );
		$this->assertTrue( memberkin_has_level( $level->id, $user ) );
		$this->assertSame( Order::PAID, memberkin()->get( OrderRepository::class )->find( $res->order->id )->status );
	}

	public function test_stripe_billing_subscription_is_synced(): void {
		$level = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '10',
				'recurring_price' => '10',
			)
		);
		$user  = self::factory()->user->create();
		memberkin()->get( \Memberkin\Service\MembershipService::class )->grant( $user, $level->id );
		$m   = memberkin_get_memberships( $user )[0];
		$sub = memberkin()->get( SubscriptionService::class )->create( new Subscription( 0, $user, $level->id, 'stripe', Subscription::PENDING, 'USD', 1000, 1, 'month', $m->id, 'test', 'sub_imported', 'cus_1', null, 'gateway' ) );
		memberkin()->get( SubscriptionService::class )->activate( $sub->id, Clock::now(), Clock::now()->modify( '+1 month' ), false );

		$invoice            = array(
			'id'       => 'evt_inv',
			'object'   => 'event',
			'type'     => 'invoice.paid',
			'livemode' => false,
			'data'     => array(
				'object' => array(
					'id'             => 'in_1',
					'subscription'   => 'sub_imported',
					'payment_intent' => 'pi_inv',
					'amount_paid'    => 1000,
					'currency'       => 'usd',
					'billing_reason' => 'subscription_cycle',
					'lines'          => array(
						'data' => array(
							array(
								'period' => array(
									'start' => time(),
									'end'   => time() + 30 * DAY_IN_SECONDS,
								),
							),
						),
					),
				),
			),
		);
		[ $body, $headers ] = $this->signed( $invoice );
		$this->assertSame( 200, $this->webhook( 'stripe', $body, $headers )->get_status() );
		$renewals = array_values( array_filter( memberkin()->get( OrderRepository::class )->for_subscription( $sub->id ), static fn( Order $o ) => 'renewal' === $o->type ) );
		$this->assertCount( 1, $renewals );
		$this->assertSame( 'pi_inv', $renewals[0]->gateway_transaction_id );

		$this->route( 'POST', '#/v1/subscriptions/sub_imported$#', array( 200, array( 'id' => 'sub_imported' ) ) );
		memberkin()->get( SubscriptionService::class )->cancel( $sub->id, true, 'member' );
		$this->assertSame( 'true', $this->sent( 'POST', '#/v1/subscriptions/sub_imported$#' )[0]['body']['cancel_at_period_end'] );

		[ $body, $headers ] = $this->signed(
			array(
				'id'       => 'evt_del',
				'object'   => 'event',
				'type'     => 'customer.subscription.deleted',
				'livemode' => false,
				'data'     => array( 'object' => array( 'id' => 'sub_imported' ) ),
			)
		);
		$this->webhook( 'stripe', $body, $headers );
		$this->assertSame( Subscription::CANCELLED, memberkin()->get( SubscriptionRepository::class )->find( $sub->id )->status );
	}

	public function test_payment_method_update_via_hosted_page_webhook(): void {
		$level = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '20',
				'recurring_price' => '20',
			)
		);
		$this->route(
			'POST',
			'#/v1/payment_intents$#',
			static fn( $r ) => array(
				200,
				array(
					'id'             => 'pi_a',
					'status'         => 'succeeded',
					'customer'       => 'cus_1',
					'payment_method' => self::pm(),
					'metadata'       => $r['body']['metadata'],
				),
			)
		);
		$order = $this->checkout( self::factory()->user->create(), $level )->order;
		$sub   = memberkin()->get( SubscriptionRepository::class )->find( $order->subscription_id );

		$this->route(
			'POST',
			'#/v1/checkout/sessions$#',
			array(
				200,
				array(
					'id'  => 'cs_1',
					'url' => 'https://checkout.stripe.com/c/pay/cs_1',
				),
			)
		);
		$update = $this->gateway()->payment_method_update( $sub, 'https://example.org/account/?memberkin_notice=card' );
		$this->assertSame( 'redirect', $update->type );
		$session = $this->sent( 'POST', '#/v1/checkout/sessions$#' )[0]['body'];
		$this->assertSame( 'setup', $session['mode'] );
		$this->assertStringEndsWith( 'memberkin_stripe_setup={CHECKOUT_SESSION_ID}', $session['success_url'] );
		$this->assertStringNotContainsString( 'memberkin_notice', $session['cancel_url'] );

		$this->route(
			'GET',
			'#/v1/setup_intents/seti_9#',
			array(
				200,
				array(
					'id'             => 'seti_9',
					'status'         => 'succeeded',
					'payment_method' => self::pm( 'pm_new' ),
				),
			)
		);
		[ $body, $headers ] = $this->signed(
			array(
				'id'       => 'evt_cs',
				'object'   => 'event',
				'type'     => 'checkout.session.completed',
				'livemode' => false,
				'data'     => array(
					'object' => array(
						'id'           => 'cs_1',
						'mode'         => 'setup',
						'setup_intent' => 'seti_9',
						'metadata'     => array( 'memberkin_subscription_id' => (string) $sub->id ),
					),
				),
			)
		);
		$this->assertSame( 200, $this->webhook( 'stripe', $body, $headers )->get_status() );
		$sub = memberkin()->get( SubscriptionRepository::class )->find( $sub->id );
		$this->assertSame( 'pm_new', memberkin()->get( PaymentMethodRepository::class )->find( (int) $sub->payment_method_id )->token );
	}

	public function test_logs_never_contain_client_secrets_or_keys(): void {
		$text = Logger::redact_string( '{"id":"pi_1","client_secret":"pi_1_secret_abcdef"} sk_test_abc123456789 Bearer sk_live_xyz' );
		$this->assertStringNotContainsString( 'abcdef', $text );
		$this->assertStringNotContainsString( 'abc123456789', $text );
		$this->assertStringNotContainsString( 'xyz', $text );
	}
}
