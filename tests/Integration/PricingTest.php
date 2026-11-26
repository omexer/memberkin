<?php
/**
 * Quote and proration tests.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Clock;
use Memberkin\Core\Money;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Membership;
use Memberkin\Domain\PriceSchedule;
use Memberkin\Domain\Pricing\ProrationPlan;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\LevelService;
use Memberkin\Service\PricingService;
use Memberkin\Service\ProrationCalculator;

/**
 * @covers \Memberkin\Service\PricingService
 * @covers \Memberkin\Service\ProrationCalculator
 */
final class PricingTest extends BillingTestCase {

	private function pricing(): PricingService {
		return memberkin()->get( PricingService::class );
	}

	private function d( string $s ): \DateTimeImmutable {
		return new \DateTimeImmutable( $s, new \DateTimeZone( 'UTC' ) );
	}

	public function test_unused_credit_math(): void {
		$paid = Money::of( 3000, 'USD' );
		$this->assertSame( 1500, ProrationCalculator::unused_credit( $paid, $this->d( '2026-01-01' ), $this->d( '2026-01-31' ), $this->d( '2026-01-16' ) )->amount );
		$this->assertSame( 0, ProrationCalculator::unused_credit( $paid, $this->d( '2026-01-01' ), $this->d( '2026-01-31' ), $this->d( '2026-02-01' ) )->amount );
		$this->assertSame( 3000, ProrationCalculator::unused_credit( $paid, $this->d( '2026-01-01' ), null, $this->d( '2026-02-01' ) )->amount, 'lifetime: full credit' );
	}

	public function test_quote_lines_and_totals(): void {
		$level = $this->level(
			array(
				'billing_type'     => 'recurring',
				'initial_price'    => '20',
				'recurring_price'  => '20',
				'signup_fee_price' => '5',
			)
		);
		Clock::freeze( '2026-01-31 00:00:00' );
		$q = $this->pricing()->quote( array( 'level_ids' => array( $level->id ) ) );
		$this->assertSame( 2500, $q->total()->amount );
		$this->assertSame( array( 'level', 'signup_fee' ), array_map( static fn( $i ) => $i->type, $q->items ) );
		$terms = $q->recurring[ $level->id ];
		$this->assertSame( 2000, $terms->first_amount->amount );
		$this->assertSame( '2026-02-28', $terms->first_renewal_at->format( 'Y-m-d' ) );
		$this->assertTrue( $q->requires_payment() );
		$json = wp_json_encode( $q );
		$this->assertStringContainsString( '"total"', $json );
	}

	public function test_trial_only_once(): void {
		$level = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '10',
				'recurring_price' => '10',
				'trial_length'    => 7,
				'trial_period'    => 'day',
			)
		);
		$user  = self::factory()->user->create();
		$first = $this->member_checkout( $user, $level );
		$this->assertSame( 0, $first->order->total );
		memberkin_end_membership( memberkin_get_memberships( $user )[0]->id );
		$q = $this->pricing()->quote(
			array(
				'level_ids' => array( $level->id ),
				'user_id'   => $user,
			)
		);
		$this->assertSame( 1000, $q->total()->amount );
		$this->assertNotEmpty( $q->notices );
	}

	public function test_unavailable_levels_and_duplicate_purchase_rejected(): void {
		$closed = $this->level(
			array(
				'billing_type'  => 'free',
				'allow_signups' => 0,
			)
		);
		try {
			$this->pricing()->quote( array( 'level_ids' => array( $closed->id ) ) );
			$this->fail( 'expected rejection' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'level', $e->errors() );
		}
		$level = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '10',
				'recurring_price' => '10',
			)
		);
		$user  = self::factory()->user->create();
		$this->member_checkout( $user, $level );
		$this->expectException( ValidationException::class );
		$this->pricing()->quote(
			array(
				'level_ids' => array( $level->id ),
				'user_id'   => $user,
			)
		);
	}

	public function test_invalid_coupon_rejects_quote(): void {
		$level = $this->level(
			array(
				'billing_type'  => 'one_time',
				'initial_price' => '10',
			)
		);
		$this->expectException( ValidationException::class );
		$this->pricing()->quote(
			array(
				'level_ids'   => array( $level->id ),
				'coupon_code' => 'NOPE',
			)
		);
	}

	public function test_upgrade_with_immediate_proration(): void {
		Clock::freeze( '2026-01-01 00:00:00' );
		$basic = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '30',
				'recurring_price' => '30',
			)
		);
		$pro   = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '60',
				'recurring_price' => '60',
			)
		);
		$user  = self::factory()->user->create();
		$old   = $this->member_checkout( $user, $basic );

		Clock::freeze( '2026-01-17 00:00:00' ); // 15 of 31 days left.
		$q    = $this->pricing()->quote(
			array(
				'level_ids' => array( $pro->id ),
				'user_id'   => $user,
			)
		);
		$plan = $q->proration[ $pro->id ];
		$this->assertSame( ProrationPlan::IMMEDIATE, $plan->mode );
		$this->assertSame( (int) round( 3000 * 15 / 31 ), $plan->credit->amount );
		$this->assertSame( 6000 - $plan->credit->amount, $q->total()->amount );

		$res = $this->member_checkout( $user, $pro );
		$this->assertSame( 'upgrade', $res->order->type );
		$this->assertSame( array( $pro->id ), memberkin()->get( \Memberkin\Service\MembershipService::class )->active_level_ids( $user ) );
		$old_sub = memberkin()->get( SubscriptionRepository::class )->find( $old->order->subscription_id );
		$this->assertSame( 'cancelled', $old_sub->status );
		$this->assertSame( 'changed', $old_sub->cancel_reason );
	}

	public function test_downgrade_takes_effect_at_period_end(): void {
		Clock::freeze( '2026-01-01 00:00:00' );
		$pro   = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '60',
				'recurring_price' => '60',
			)
		);
		$basic = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '30',
				'recurring_price' => '30',
			)
		);
		$user  = self::factory()->user->create();
		$this->member_checkout( $user, $pro );

		Clock::freeze( '2026-01-10 00:00:00' );
		$res = $this->member_checkout( $user, $basic );
		$this->assertSame( 0, $res->order->total, 'nothing charged today' );
		$this->assertTrue( memberkin_has_level( $pro->id, $user ), 'keeps the paid plan until the period ends' );
		$this->assertFalse( memberkin_has_level( $basic->id, $user ) );

		Clock::freeze( '2026-02-01 00:00:01' );
		do_action( 'memberkin_billing_tick' );
		do_action( 'memberkin_expire_memberships' );
		$this->assertTrue( memberkin_has_level( $basic->id, $user ) );
		$this->assertFalse( memberkin_has_level( $pro->id, $user ) );
		$current = memberkin_get_memberships( $user );
		$this->assertCount( 1, $current );
		$this->assertSame( Membership::ACTIVE, $current[0]->status );
	}

	public function test_credit_larger_than_new_price_extends_time(): void {
		$old  = new PriceSchedule( 'USD', 12000, 0, 12000, 1, 'year' );
		$new  = new PriceSchedule( 'USD', 1000, 0, 1000, 1, 'month' );
		$plan = ProrationCalculator::plan( 'prorate_immediate', 'immediate', 1, $old, $new, Money::of( 12000, 'USD' ), $this->d( '2026-01-01' ), $this->d( '2027-01-01' ), $this->d( '2026-07-02' ), Money::of( 1000, 'USD' ) );
		$this->assertSame( 1000, $plan->credit->amount, 'credit capped at today\'s price' );
		$this->assertGreaterThan( 100 * DAY_IN_SECONDS, $plan->extension_seconds, 'remaining ~5k credit buys ~5 months' );
	}
}
