<?php
/**
 * Period and PriceSchedule tests.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Unit;

use Memberkin\Domain\Period;
use Memberkin\Domain\PriceSchedule;
use Memberkin\Tests\TestCase;

/**
 * @covers \Memberkin\Domain\Period
 * @covers \Memberkin\Domain\PriceSchedule
 */
final class PeriodAndScheduleTest extends TestCase {

	private function d( string $s ): \DateTimeImmutable {
		return new \DateTimeImmutable( $s, new \DateTimeZone( 'UTC' ) );
	}

	public function test_month_end_clamping(): void {
		$this->assertSame( '2026-02-28', Period::add( $this->d( '2026-01-31' ), 1, 'month' )->format( 'Y-m-d' ) );
		$this->assertSame( '2028-02-29', Period::add( $this->d( '2028-01-31' ), 1, 'month' )->format( 'Y-m-d' ) );
		$this->assertSame( '2026-03-31', Period::add( $this->d( '2026-02-28' ), 1, 'month', 31 )->format( 'Y-m-d' ) );
		$this->assertSame( '2027-01-15', Period::add( $this->d( '2026-12-15' ), 1, 'month' )->format( 'Y-m-d' ) );
		$this->assertSame( '2025-12-15', Period::add( $this->d( '2026-01-15' ), -1, 'month' )->format( 'Y-m-d' ) );
		$this->assertSame( '2027-02-28', Period::add( $this->d( '2026-02-28' ), 1, 'year' )->format( 'Y-m-d' ) );
		$this->assertSame( '2029-02-28', Period::add( $this->d( '2028-02-29' ), 1, 'year' )->format( 'Y-m-d' ) );
		$this->assertSame( '2026-01-15', Period::add( $this->d( '2026-01-01' ), 2, 'week' )->format( 'Y-m-d' ) );
		$this->assertSame( '10:30:00', Period::add( $this->d( '2026-01-31 10:30:00' ), 1, 'month' )->format( 'H:i:s' ) );
	}

	public function test_one_time_schedule(): void {
		$s = new PriceSchedule( 'USD', 5000, 0, 0, 1, 'month', 0, 0, 0, 'day', 'duration', 1, 'year' );
		$this->assertFalse( $s->is_recurring() );
		$this->assertFalse( $s->is_free() );
		$this->assertSame( 5000, $s->first_payment()->amount );
		$this->assertNull( $s->next_payment_after_start( $this->d( '2026-01-01' ) ) );
		$this->assertSame( '2027-01-01', $s->access_end( $this->d( '2026-01-01' ) )->format( 'Y-m-d' ) );
		$this->assertSame( array(), $s->validate() );
	}

	public function test_recurring_with_signup_fee_and_trial(): void {
		$s = new PriceSchedule( 'USD', 1000, 500, 1000, 1, 'month', 12, 0, 14, 'day' );
		$this->assertTrue( $s->is_recurring() );
		$this->assertTrue( $s->has_trial() );
		$this->assertSame( 500, $s->first_payment()->amount ); // Free trial + signup fee.
		$this->assertSame( '2026-01-15', $s->next_payment_after_start( $this->d( '2026-01-01' ) )->format( 'Y-m-d' ) );
		$this->assertSame( 12, $s->renewals_limit() );
		$this->assertStringContainsString( 'Free for 14 days', $s->describe() );
	}

	public function test_cycle_trial(): void {
		$s = new PriceSchedule( 'USD', 2000, 0, 2000, 1, 'month', 0, 500, 3, 'cycle' );
		$this->assertSame( 500, $s->first_payment()->amount );
		$this->assertSame( 500, $s->recurring_payment_amount( 1 )->amount );
		$this->assertSame( 500, $s->recurring_payment_amount( 2 )->amount );
		$this->assertSame( 2000, $s->recurring_payment_amount( 3 )->amount );
		$this->assertSame( '2026-02-01', $s->next_payment_after_start( $this->d( '2026-01-01' ) )->format( 'Y-m-d' ) );
	}

	public function test_after_cycles_expiration(): void {
		$s = new PriceSchedule( 'USD', 1000, 0, 1000, 1, 'month', 6, 0, 0, 'day', 'after_cycles' );
		$this->assertSame( '2026-07-31', $s->access_end( $this->d( '2026-01-31' ) )->format( 'Y-m-d' ) );
		$this->assertSame( 5, $s->renewals_limit() );
	}

	public function test_validation(): void {
		$this->assertNotEmpty( ( new PriceSchedule( 'USD', -1 ) )->validate() );
		$this->assertNotEmpty( ( new PriceSchedule( 'USD', 0, 0, 0, 1, 'month', 0, 0, 7, 'day' ) )->validate() );
		$this->assertNotEmpty( ( new PriceSchedule( 'USD', 0, 0, 0, 1, 'month', 0, 0, 0, 'day', 'duration', 0 ) )->validate() );
		$this->assertTrue( ( new PriceSchedule( 'USD' ) )->is_free() );
	}
}
