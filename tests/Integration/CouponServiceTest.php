<?php
/**
 * Coupon tests.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Clock;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Coupon;
use Memberkin\Repository\CouponRepository;
use Memberkin\Service\CouponService;
use Memberkin\Service\LevelService;
use Memberkin\Tests\TestCase;

/**
 * @covers \Memberkin\Service\CouponService
 * @covers \Memberkin\Domain\Coupon
 */
final class CouponServiceTest extends TestCase {

	private function coupons(): CouponService {
		return memberkin()->get( CouponService::class );
	}

	private function monthly() {
		return memberkin()->get( LevelService::class )->save(
			array(
				'name'             => 'Monthly ' . wp_rand(),
				'billing_type'     => 'recurring',
				'initial_price'    => '20',
				'recurring_price'  => '20',
				'signup_fee_price' => '10',
			)
		);
	}

	public function test_discount_math(): void {
		$price = \Memberkin\Core\Money::of( 2000, 'USD' );
		$this->assertSame( 1500, Coupon::discount( $price, Coupon::PERCENT, 2500 )->amount );
		$this->assertSame( 1500, Coupon::discount( $price, Coupon::FIXED, 500 )->amount );
		$this->assertSame( 0, Coupon::discount( $price, Coupon::FIXED, 5000 )->amount );
		$this->assertSame( 999, Coupon::discount( $price, Coupon::SET_PRICE, 999 )->amount );
		$this->assertSame( 2000, Coupon::discount( $price, Coupon::SET_PRICE, 9999 )->amount, 'set price never increases the price' );
	}

	public function test_save_normalises_and_validates(): void {
		$coupon = $this->coupons()->save(
			array(
				'code'             => 'save 25!',
				'discount_type'    => 'percent',
				'amount'           => '25',
				'recurring_cycles' => '3',
			)
		);
		$this->assertSame( 'SAVE25', $coupon->code );
		$this->assertSame( 2500, $coupon->amount );
		$this->assertSame( 3, $coupon->recurring_cycles );

		try {
			$this->coupons()->save(
				array(
					'code'          => 'save25',
					'discount_type' => 'percent',
					'amount'        => '150',
				)
			);
			$this->fail( 'expected errors' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'code', $e->errors() );
			$this->assertArrayHasKey( 'amount', $e->errors() );
		}
	}

	public function test_apply_percent_with_limited_recurring_cycles(): void {
		$level  = $this->monthly();
		$coupon = $this->coupons()->save(
			array(
				'code'             => 'HALF',
				'discount_type'    => 'percent',
				'amount'           => '50',
				'recurring_cycles' => '2',
			)
		);
		$app    = $this->coupons()->apply( $coupon, $level );
		$this->assertSame( 1500, $app->first_payment()->amount, '50% of 20 + 10 signup fee' );
		$this->assertSame( 1000, $app->recurring_payment( 1 )->amount );
		$this->assertSame( 1000, $app->recurring_payment( 2 )->amount );
		$this->assertSame( 2000, $app->recurring_payment( 3 )->amount );
	}

	public function test_first_payment_discount_and_trial_override(): void {
		$level  = $this->monthly();
		$coupon = $this->coupons()->save(
			array(
				'code'                 => 'TRY',
				'discount_type'        => 'fixed',
				'amount'               => '5',
				'applies_to'           => 'both',
				'first_payment_type'   => 'set_price',
				'first_payment_amount' => '1',
				'trial_override'       => 1,
				'trial_length'         => 14,
				'trial_period'         => 'day',
				'trial_price'          => '0',
			)
		);
		$app    = $this->coupons()->apply( $coupon, $level );
		$this->assertSame( 14, $app->schedule->trial_length );
		$this->assertSame( 100, $app->first_payment()->amount, 'first payment set to 1.00' );
		$this->assertSame( 1500, $app->recurring_payment( 1 )->amount );
	}

	public function test_level_override(): void {
		$level  = $this->monthly();
		$coupon = $this->coupons()->save(
			array(
				'code'      => 'VIP',
				'level_ids' => array( $level->id ),
				'overrides' => array(
					$level->id => array(
						'enabled'          => 1,
						'initial_price'    => '99',
						'recurring_price'  => '99',
						'billing_interval' => 1,
						'billing_period'   => 'year',
					),
				),
			)
		);
		$app    = $this->coupons()->apply( memberkin()->get( CouponRepository::class )->find( $coupon->id ), $level );
		$this->assertSame( 'year', $app->schedule->billing_period );
		$this->assertSame( 9900 + 1000, $app->first_payment()->amount, 'override keeps the level signup fee unless changed' );
	}

	public function test_validation_rules(): void {
		$level = $this->monthly();
		$other = $this->monthly();
		$user  = self::factory()->user->create();
		Clock::freeze( '2026-06-01 12:00:00' );
		$coupon = $this->coupons()->save(
			array(
				'code'              => 'LIMITED',
				'amount'            => '10',
				'level_ids'         => array( $level->id ),
				'max_uses'          => 1,
				'max_uses_per_user' => 1,
				'expires_at'        => '2026-06-30',
				'allow_on_change'   => 0,
			)
		);
		$this->assertSame( $coupon->id, $this->coupons()->validate( 'limited', $level->id, $user )->id, 'codes are case insensitive' );

		$this->assertRejected( fn() => $this->coupons()->validate( 'LIMITED', $other->id, $user ) );
		$this->assertRejected( fn() => $this->coupons()->validate( 'LIMITED', $level->id, $user, true ) );
		$this->assertRejected( fn() => $this->coupons()->validate( 'NOPE', $level->id, $user ) );

		$this->coupons()->redeem( $coupon->id, $user, 1 );
		$this->assertRejected( fn() => $this->coupons()->validate( 'LIMITED', $level->id, $user ) );
		$this->assertRejected( fn() => $this->coupons()->redeem( $coupon->id, $user, 2 ), 'global limit is atomic' );

		Clock::freeze( '2026-07-01 12:00:00' );
		$this->assertRejected( fn() => $this->coupons()->validate( 'LIMITED', $level->id, 0 ) );
	}

	public function test_auto_apply_coupon(): void {
		$level = $this->monthly();
		$this->coupons()->save(
			array(
				'code'                => 'SALE',
				'amount'              => '20',
				'auto_apply_group_id' => $level->group_id,
			)
		);
		$this->assertSame( 'SALE', $this->coupons()->auto_coupon( $level )->code );
	}

	private function assertRejected( callable $fn, string $message = '' ): void {
		try {
			$fn();
			$this->fail( 'Expected rejection. ' . $message );
		} catch ( ValidationException $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}
	}
}
