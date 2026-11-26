<?php
/**
 * Webhook endpoint, storage, idempotency and dispatch.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Clock;
use Memberkin\Core\Db;
use Memberkin\Core\SafeMode;
use Memberkin\Domain\Order;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\GatewayRegistry;
use Memberkin\Repository\OrderRepository;
use Memberkin\Repository\SubscriptionRepository;

/**
 * @covers \Memberkin\Gateway\Webhooks\WebhookController
 * @covers \Memberkin\Gateway\Webhooks\WebhookProcessor
 * @covers \Memberkin\Gateway\Webhooks\WebhookDispatcher
 */
final class WebhookTest extends BillingTestCase {

	public function set_up(): void {
		parent::set_up();
		do_action( 'rest_api_init' );
		SafeMode::confirm_live();
	}

	private function send( array $payload, ?string $signature = null ): \WP_REST_Response {
		$body    = wp_json_encode( $payload );
		$request = new \WP_REST_Request( 'POST', '/memberkin/v1/webhooks/test' );
		$request->set_body( $body );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-memberkin-test-signature', $signature ?? memberkin()->get( GatewayRegistry::class )->get( 'test' )->sign( $body ) );
		return rest_do_request( $request );
	}

	private function event_row( string $event_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::table_name( 'webhook_events' ) . ' WHERE event_id = %s', $event_id ) );
	}

	public function test_rejects_bad_signatures(): void {
		$this->assertSame(
			400,
			$this->send(
				array(
					'id'   => 'evt_bad',
					'type' => 'x',
				),
				't=1,v1=' . str_repeat( 'a', 64 )
			)->get_status()
		);
		$old = memberkin()->get( GatewayRegistry::class )->get( 'test' )->sign(
			wp_json_encode(
				array(
					'id'   => 'evt_old',
					'type' => 'x',
				)
			),
			Clock::timestamp() - 3600
		);
		$this->assertSame(
			400,
			$this->send(
				array(
					'id'   => 'evt_old',
					'type' => 'x',
				),
				$old
			)->get_status()
		);
		$this->assertNull( $this->event_row( 'evt_bad' ) );
		$this->assertSame( 404, rest_do_request( new \WP_REST_Request( 'POST', '/memberkin/v1/webhooks/nope' ) )->get_status() );
	}

	public function test_refund_webhook_is_processed_once(): void {
		$level = $this->level(
			array(
				'billing_type'  => 'one_time',
				'initial_price' => '40',
			)
		);
		$order = $this->member_checkout( self::factory()->user->create(), $level )->order;
		$event = array(
			'id'   => 'evt_refund_1',
			'type' => 'refund.succeeded',
			'data' => array(
				'transaction_id' => $order->gateway_transaction_id,
				'amount'         => 4000,
				'refund_id'      => 're_abc',
			),
		);
		$this->assertSame( 200, $this->send( $event )->get_status() );
		$this->assertSame( 'processed', $this->event_row( 'evt_refund_1' )->status );
		$this->assertSame( Order::REFUNDED, memberkin()->get( OrderRepository::class )->find( $order->id )->status );

		$dup = $this->send( $event );
		$this->assertTrue( $dup->get_data()['duplicate'] );
		$this->assertCount( 1, memberkin()->get( OrderRepository::class )->refunds( $order->id ) );
	}

	public function test_gateway_cancellation_and_renewal_events(): void {
		Clock::freeze( '2026-01-01 00:00:00' );
		$level  = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '10',
				'recurring_price' => '10',
			)
		);
		$user   = self::factory()->user->create();
		$sub_id = $this->member_checkout( $user, $level )->order->subscription_id;
		memberkin()->get( SubscriptionRepository::class )->update(
			$sub_id,
			array(
				'gateway_subscription_id' => 'sub_remote_1',
				'billing_model'           => 'gateway',
			)
		);

		Clock::freeze( '2026-02-01 00:05:00' );
		$this->send(
			array(
				'id'   => 'evt_renew',
				'type' => 'payment.succeeded',
				'data' => array(
					'subscription_id' => 'sub_remote_1',
					'transaction_id'  => 'ch_remote_2',
					'amount'          => 1000,
					'currency'        => 'usd',
					'period_end'      => strtotime( '2026-03-01 00:00:00 UTC' ),
				),
			)
		);
		$sub = memberkin()->get( SubscriptionRepository::class )->find( $sub_id );
		$this->assertSame( 1, $sub->cycles_completed );
		$this->assertSame( '2026-03-01', $sub->current_period_end->format( 'Y-m-d' ) );

		$this->send(
			array(
				'id'   => 'evt_cancel',
				'type' => 'subscription.cancelled',
				'data' => array( 'subscription_id' => 'sub_remote_1' ),
			)
		);
		$this->assertSame( Subscription::CANCELLED, memberkin()->get( SubscriptionRepository::class )->find( $sub_id )->status );
		$this->assertFalse( memberkin_has_level( $level->id, $user ) );
	}

	public function test_unknown_events_are_ignored_and_safe_mode_defers(): void {
		$this->send(
			array(
				'id'   => 'evt_unknown',
				'type' => 'something.else',
			)
		);
		$this->assertSame( 'ignored', $this->event_row( 'evt_unknown' )->status );

		update_option( 'home', 'http://copy.example.org' );
		$this->send(
			array(
				'id'   => 'evt_deferred',
				'type' => 'something.else',
			)
		);
		$this->assertSame( 'received', $this->event_row( 'evt_deferred' )->status );
	}
}
