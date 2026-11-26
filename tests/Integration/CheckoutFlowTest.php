<?php
/**
 * End-to-end checkout, renewal, dunning, cancellation and refund flows with the Test gateway.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Clock;
use Memberkin\Core\Money;
use Memberkin\Domain\Membership;
use Memberkin\Domain\Order;
use Memberkin\Domain\Subscription;
use Memberkin\Repository\CouponRepository;
use Memberkin\Repository\MembershipRepository;
use Memberkin\Repository\OrderRepository;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\Checkout\CheckoutResult;
use Memberkin\Service\Checkout\CheckoutService;
use Memberkin\Service\CouponService;
use Memberkin\Service\OrderService;
use Memberkin\Service\RenewalService;
use Memberkin\Service\SubscriptionService;

/**
 * @covers \Memberkin\Service\Checkout\CheckoutService
 * @covers \Memberkin\Service\RenewalService
 * @covers \Memberkin\Service\SubscriptionService
 * @covers \Memberkin\Service\OrderService
 */
final class CheckoutFlowTest extends BillingTestCase {

	private function set_card_token( int $subscription_id, string $token ): void {
		global $wpdb;
		$sub = memberkin()->get( SubscriptionRepository::class )->find( $subscription_id );
		$wpdb->update( \Memberkin\Core\Db::table_name( 'payment_methods' ), array( 'token' => $token ), array( 'id' => $sub->payment_method_id ) );
	}

	private function orders(): OrderRepository {
		return memberkin()->get( OrderRepository::class );
	}

	public function test_free_signup_creates_account_and_membership(): void {
		$level  = $this->level( array( 'billing_type' => 'free' ) );
		$result = $this->guest_checkout( $level, array( 'gateway' => '' ) );
		$this->assertSame( CheckoutResult::COMPLETED, $result->status, implode( ' ', $result->errors ) );
		$order = $result->order;
		$this->assertSame( Order::PAID, $order->status );
		$this->assertSame( 0, $order->total );
		$this->assertNull( $order->invoice_number, 'free orders get no invoice number' );
		$this->assertTrue( memberkin_has_level( $level->id, $order->user_id ) );
		$this->assertSame( '', (string) get_user_meta( $order->user_id, 'memberkin_checkout_pending', true ) );
	}

	public function test_validation_errors(): void {
		$level  = $this->level(
			array(
				'billing_type'  => 'one_time',
				'initial_price' => '10',
			)
		);
		$taken  = self::factory()->user->create( array( 'user_email' => 'taken@example.org' ) );
		$result = memberkin()->get( CheckoutService::class )->submit(
			array(
				'level_ids' => array( $level->id ),
				'gateway'   => 'nope',
				'account'   => array(
					'email'    => 'taken@example.org',
					'username' => '',
					'password' => 'short',
				),
			),
			0
		);
		$this->assertSame( CheckoutResult::FAILED, $result->status );
		$this->assertArrayHasKey( 'email', $result->errors );
		$this->assertArrayHasKey( 'username', $result->errors );
		$this->assertArrayHasKey( 'password', $result->errors );
		$this->assertArrayHasKey( 'gateway', $result->errors );
		$this->assertGreaterThan( 0, $taken );
	}

	public function test_one_time_paid_checkout_with_invoice_number(): void {
		$level  = $this->level(
			array(
				'billing_type'      => 'one_time',
				'initial_price'     => '49.00',
				'expiration_type'   => 'duration',
				'expiration_length' => 1,
				'expiration_period' => 'year',
			)
		);
		$result = $this->guest_checkout( $level );
		$this->assertSame( CheckoutResult::COMPLETED, $result->status, implode( ' ', $result->errors ) );
		$order = $this->orders()->find( $result->order->id );
		$this->assertSame( Order::PAID, $order->status );
		$this->assertSame( 4900, $order->total );
		$this->assertMatchesRegularExpression( '/^INV-\d{6}$/', $order->invoice_number );
		$this->assertStringStartsWith( 'test_ch_', $order->gateway_transaction_id );
		$m = memberkin_get_memberships( $order->user_id )[0];
		$this->assertNotNull( $m->ends_at );
		$this->assertSame( $order->id, $m->initial_order_id );

		// Second paid order gets the next number.
		$second = $this->orders()->find( $this->guest_checkout( $level )->order->id );
		$this->assertSame( (int) substr( $order->invoice_number, 4 ) + 1, (int) substr( $second->invoice_number, 4 ) );
	}

	public function test_double_submit_is_idempotent(): void {
		$level = $this->level(
			array(
				'billing_type'  => 'one_time',
				'initial_price' => '10',
			)
		);
		$first = $this->guest_checkout( $level );
		$again = memberkin()->get( CheckoutService::class )->submit(
			array(
				'token'     => $first->token,
				'level_ids' => array( $level->id ),
			),
			0
		);
		$this->assertSame( $first->order->id, $again->order->id );
		$this->assertCount( 1, memberkin_get_memberships( $first->order->user_id ) );
	}

	public function test_decline_then_retry_in_same_session(): void {
		$level  = $this->level(
			array(
				'billing_type'  => 'one_time',
				'initial_price' => '10',
			)
		);
		$user   = self::factory()->user->create();
		$failed = $this->member_checkout( $user, $level, array(), 'decline' );
		$this->assertSame( CheckoutResult::FAILED, $failed->status );
		$this->assertArrayHasKey( 'payment', $failed->errors );
		$this->assertFalse( memberkin_has_level( $level->id, $user ) );

		$retry = $this->member_checkout( $user, $level, array( 'token' => $failed->token ) );
		$this->assertSame( CheckoutResult::COMPLETED, $retry->status );
		$this->assertTrue( memberkin_has_level( $level->id, $user ) );
		$statuses = array_map( static fn( Membership $m ) => $m->status . '/' . $m->end_reason, memberkin()->get( MembershipRepository::class )->all_for_user( $user ) );
		$this->assertContains( 'ended/abandoned', $statuses );
	}

	public function test_three_d_secure_and_offsite_redirect(): void {
		$level = $this->level(
			array(
				'billing_type'  => 'one_time',
				'initial_price' => '10',
			)
		);
		$user  = self::factory()->user->create();
		$res   = $this->member_checkout( $user, $level, array(), 'action' );
		$this->assertSame( CheckoutResult::REQUIRES_ACTION, $res->status );
		$this->assertNotEmpty( $res->client_secret );
		$done = memberkin()->get( CheckoutService::class )->confirm(
			$res->token,
			array(
				'test_card' => 'action',
				'confirmed' => 1,
			)
		);
		$this->assertSame( CheckoutResult::COMPLETED, $done->status );

		$user2 = self::factory()->user->create();
		$res2  = $this->member_checkout( $user2, $level, array(), 'redirect' );
		$this->assertSame( CheckoutResult::REDIRECT, $res2->status );
		$this->assertStringContainsString( 'memberkin_return=', $res2->redirect_url );
		$this->assertFalse( memberkin_has_level( $level->id, $user2 ) );
		$done2 = memberkin()->get( CheckoutService::class )->confirm( $res2->token, array( 'test_result' => 'success' ) );
		$this->assertSame( CheckoutResult::COMPLETED, $done2->status );
		$this->assertTrue( memberkin_has_level( $level->id, $user2 ) );
	}

	public function test_coupon_is_redeemed_and_discount_charged(): void {
		$level = $this->level(
			array(
				'billing_type'  => 'one_time',
				'initial_price' => '100',
			)
		);
		memberkin()->get( CouponService::class )->save(
			array(
				'code'          => 'TEN',
				'discount_type' => 'percent',
				'amount'        => '10',
				'max_uses'      => 5,
			)
		);
		$res = $this->member_checkout( self::factory()->user->create(), $level, array( 'coupon_code' => 'ten' ) );
		$this->assertSame( 9000, $res->order->total );
		$this->assertSame( 1000, $res->order->discount_total );
		$this->assertSame( 1, memberkin()->get( CouponRepository::class )->find_by_code( 'TEN' )->uses_count );
	}

	public function test_recurring_trial_renewal_and_cancellation(): void {
		Clock::freeze( '2026-01-01 10:00:00' );
		$level = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '20',
				'recurring_price' => '20',
				'trial_length'    => 14,
				'trial_period'    => 'day',
			)
		);
		$user  = self::factory()->user->create();
		$res   = $this->member_checkout( $user, $level );
		$this->assertSame( CheckoutResult::COMPLETED, $res->status );
		$this->assertSame( 0, $res->order->total, 'free trial' );

		$sub = memberkin()->get( SubscriptionRepository::class )->find( $res->order->subscription_id );
		$this->assertSame( Subscription::TRIALING, $sub->status );
		$this->assertSame( 'site', $sub->billing_model );
		$this->assertSame( '2026-01-15', $sub->next_payment_at->format( 'Y-m-d' ) );
		$this->assertNotNull( $sub->payment_method_id );
		$this->assertNull( memberkin_get_memberships( $user )[0]->ends_at );

		// Not due yet.
		$this->assertSame( 'skipped', memberkin()->get( RenewalService::class )->charge( $sub->id ) );

		Clock::freeze( '2026-01-15 11:00:00' );
		$this->assertSame( 1, memberkin()->get( SubscriptionService::class )->queue_due_renewals(), 'renewal queued (runs synchronously in tests)' );
		$sub = memberkin()->get( SubscriptionRepository::class )->find( $sub->id );
		$this->assertSame( Subscription::ACTIVE, $sub->status );
		$this->assertSame( 1, $sub->cycles_completed );
		$this->assertSame( '2026-02-15', $sub->next_payment_at->format( 'Y-m-d' ) );
		$renewals = array_values( array_filter( memberkin()->get( OrderRepository::class )->for_subscription( $sub->id ), static fn( Order $o ) => 'renewal' === $o->type ) );
		$this->assertCount( 1, $renewals );
		$this->assertSame( 2000, $renewals[0]->total );
		$this->assertSame( Order::PAID, $renewals[0]->status );

		// Cancel at period end.
		memberkin()->get( SubscriptionService::class )->cancel( $sub->id, true, 'member' );
		$m = memberkin_get_memberships( $user )[0];
		$this->assertSame( Membership::CANCELLING, $m->status );
		$this->assertSame( '2026-02-15', $m->ends_at->format( 'Y-m-d' ) );

		Clock::freeze( '2026-02-15 12:00:00' );
		do_action( 'memberkin_billing_tick' );
		do_action( 'memberkin_expire_memberships' );
		$this->assertSame( Subscription::CANCELLED, memberkin()->get( SubscriptionRepository::class )->find( $sub->id )->status );
		$this->assertFalse( memberkin_has_level( $level->id, $user ) );
		$renewals = array_filter( memberkin()->get( OrderRepository::class )->for_subscription( $sub->id ), static fn( Order $o ) => 'renewal' === $o->type );
		$this->assertCount( 1, $renewals, 'no renewal after cancellation' );
	}

	public function test_failed_renewals_dunning_then_cancel(): void {
		Clock::freeze( '2026-03-01 00:00:00' );
		$level = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '10',
				'recurring_price' => '10',
			)
		);
		$user  = self::factory()->user->create();
		$res   = $this->member_checkout( $user, $level );
		$sub   = memberkin()->get( SubscriptionRepository::class )->find( $res->order->subscription_id );
		$this->set_card_token( $sub->id, 'tok_decline' );

		Clock::freeze( '2026-04-01 01:00:00' );
		$this->assertSame( 'failed', memberkin()->get( RenewalService::class )->charge( $sub->id ) );
		$sub = memberkin()->get( SubscriptionRepository::class )->find( $sub->id );
		$this->assertSame( Subscription::PAST_DUE, $sub->status );
		$this->assertSame( 1, $sub->retry_count );
		$this->assertSame( '2026-04-02', $sub->next_retry_at->format( 'Y-m-d' ) );
		$m = memberkin_get_memberships( $user )[0];
		$this->assertSame( Membership::PAST_DUE, $m->status );
		$this->assertTrue( memberkin_has_level( $level->id, $user ), 'grace period keeps access' );

		// Retries at +1, +3, +5, +7 days, then give up.
		foreach ( array( '2026-04-02', '2026-04-05', '2026-04-10', '2026-04-17' ) as $day ) {
			Clock::freeze( $day . ' 02:00:00' );
			memberkin()->get( RenewalService::class )->charge( $sub->id );
		}
		$sub = memberkin()->get( SubscriptionRepository::class )->find( $sub->id );
		$this->assertSame( Subscription::CANCELLED, $sub->status );
		$this->assertSame( 'payment_failed', $sub->cancel_reason );
		$this->assertFalse( memberkin_has_level( $level->id, $user ) );
		$this->assertSame( 'failed_payment', memberkin()->get( MembershipRepository::class )->find( $m->id )->end_reason );
	}

	public function test_failed_renewal_recovers_on_retry(): void {
		Clock::freeze( '2026-05-01 00:00:00' );
		$level  = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '10',
				'recurring_price' => '10',
			)
		);
		$user   = self::factory()->user->create();
		$sub_id = $this->member_checkout( $user, $level )->order->subscription_id;
		$this->set_card_token( $sub_id, 'tok_decline' );

		Clock::freeze( '2026-06-01 01:00:00' );
		memberkin()->get( RenewalService::class )->charge( $sub_id );
		$this->set_card_token( $sub_id, 'tok_works' );
		Clock::freeze( '2026-06-02 02:00:00' );
		$this->assertSame( 'charged', memberkin()->get( RenewalService::class )->charge( $sub_id ) );
		$sub = memberkin()->get( SubscriptionRepository::class )->find( $sub_id );
		$this->assertSame( Subscription::ACTIVE, $sub->status );
		$this->assertSame( 0, $sub->retry_count );
		$this->assertSame( Membership::ACTIVE, memberkin_get_memberships( $user )[0]->status );
	}

	public function test_billing_cycle_limit_completes_subscription(): void {
		Clock::freeze( '2026-01-10 00:00:00' );
		$level  = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '5',
				'recurring_price' => '5',
				'billing_cycles'  => 2,
				'expiration_type' => 'after_cycles',
			)
		);
		$user   = self::factory()->user->create();
		$sub_id = $this->member_checkout( $user, $level )->order->subscription_id;
		Clock::freeze( '2026-02-10 01:00:00' );
		$this->assertSame( 'charged', memberkin()->get( RenewalService::class )->charge( $sub_id ) );
		$sub = memberkin()->get( SubscriptionRepository::class )->find( $sub_id );
		$this->assertSame( Subscription::COMPLETED, $sub->status );
		$m = memberkin_get_memberships( $user )[0];
		$this->assertSame( '2026-03-10', $m->ends_at->format( 'Y-m-d' ) );
	}

	public function test_pause_and_resume_shift_next_payment(): void {
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
		Clock::freeze( '2026-01-11 00:00:00' );
		memberkin()->get( SubscriptionService::class )->pause( $sub_id );
		$this->assertFalse( memberkin_has_level( $level->id, $user ) );
		Clock::freeze( '2026-01-21 00:00:00' );
		$sub = memberkin()->get( SubscriptionService::class )->resume( $sub_id );
		$this->assertSame( '2026-02-11', $sub->next_payment_at->format( 'Y-m-d' ) );
		$this->assertTrue( memberkin_has_level( $level->id, $user ) );
	}

	public function test_refunds_with_credit_notes(): void {
		$level = $this->level(
			array(
				'billing_type'  => 'one_time',
				'initial_price' => '30',
			)
		);
		$order = $this->member_checkout( self::factory()->user->create(), $level )->order;
		$svc   = memberkin()->get( OrderService::class );
		$part  = $svc->record_refund( $order->id, Money::of( 1000, 'USD' ), 'partial', 're_1' );
		$this->assertSame( Order::PARTIALLY_REFUNDED, $part->status );
		$this->assertSame( $part, $part );
		$again = $svc->record_refund( $order->id, Money::of( 1000, 'USD' ), 'dup', 're_1' );
		$this->assertSame( 1000, $again->amount_refunded, 'same gateway refund id is ignored' );
		$full = $svc->record_refund( $order->id, Money::of( 2000, 'USD' ), 'rest', 're_2' );
		$this->assertSame( Order::REFUNDED, $full->status );
		$refunds = memberkin()->get( OrderRepository::class )->refunds( $order->id );
		$this->assertCount( 2, $refunds );
		$this->assertMatchesRegularExpression( '/^CN-\d{6}$/', $refunds[0]->credit_note_number );
		$this->expectException( \Memberkin\Core\ValidationException::class );
		$svc->record_refund( $order->id, Money::of( 1, 'USD' ), 'too much' );
	}

	public function test_manual_payment_order_activates_when_marked_paid(): void {
		$level = $this->level(
			array(
				'billing_type'  => 'one_time',
				'initial_price' => '25',
			)
		);
		$user  = self::factory()->user->create();
		$res   = $this->member_checkout( $user, $level, array( 'gateway' => 'manual' ) );
		$this->assertSame( CheckoutResult::PENDING, $res->status );
		$this->assertFalse( memberkin_has_level( $level->id, $user ) );
		memberkin()->get( OrderService::class )->mark_paid( $res->order->id );
		$this->assertTrue( memberkin_has_level( $level->id, $user ) );
	}
}
