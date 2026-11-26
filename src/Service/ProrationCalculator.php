<?php
/**
 * Proration maths.
 *
 * @package Memberkin
 */

namespace Memberkin\Service;

use Memberkin\Core\Money;
use Memberkin\Domain\Period;
use Memberkin\Domain\PriceSchedule;
use Memberkin\Domain\Pricing\ProrationPlan;

defined( 'ABSPATH' ) || exit;

/**
 * Seconds-based proration (ARCHITECTURE.md §6). Pure functions, no WordPress calls.
 */
final class ProrationCalculator {

	/**
	 * Value of the unused part of a paid period.
	 *
	 * @param Money                   $paid         Net amount paid for the period.
	 * @param \DateTimeImmutable      $period_start Period start.
	 * @param \DateTimeImmutable|null $period_end   Period end (null = lifetime: full credit).
	 * @param \DateTimeImmutable      $now          Now.
	 */
	public static function unused_credit( Money $paid, \DateTimeImmutable $period_start, ?\DateTimeImmutable $period_end, \DateTimeImmutable $now ): Money {
		if ( $paid->amount <= 0 ) {
			return Money::zero( $paid->currency );
		}
		if ( null === $period_end ) {
			return $paid;
		}
		$total     = $period_end->getTimestamp() - $period_start->getTimestamp();
		$remaining = $period_end->getTimestamp() - $now->getTimestamp();
		if ( $total <= 0 || $remaining <= 0 ) {
			return Money::zero( $paid->currency );
		}
		return $paid->multiply_ratio( min( $remaining, $total ), $total );
	}

	/**
	 * Daily rate of a schedule (minor units per day × 1,000,000 for precision), used to compare plans.
	 *
	 * @param PriceSchedule $schedule Schedule.
	 */
	public static function daily_rate_micros( PriceSchedule $schedule ): int {
		if ( $schedule->is_recurring() ) {
			$seconds = Period::approx_seconds( $schedule->billing_period ) * $schedule->billing_interval;
			return intdiv( $schedule->recurring_amount * DAY_IN_SECONDS * 1000000, max( 1, $seconds ) );
		}
		// One-time: spread over its access duration if any, else treat as a large lifetime value.
		if ( 'duration' === $schedule->expiration_type && $schedule->expiration_length > 0 ) {
			$seconds = Period::approx_seconds( $schedule->expiration_period ) * $schedule->expiration_length;
			return intdiv( $schedule->initial_amount * DAY_IN_SECONDS * 1000000, max( 1, $seconds ) );
		}
		return $schedule->initial_amount * 1000000;
	}

	/**
	 * Builds a plan.
	 *
	 * @param string                  $mode            Group proration mode (none|prorate_immediate|keep_anchor).
	 * @param string                  $downgrade_timing immediate|period_end.
	 * @param int                     $from_id         Membership being replaced.
	 * @param PriceSchedule           $old             Old schedule.
	 * @param PriceSchedule           $new_schedule             New schedule.
	 * @param Money                   $paid            Net amount paid for the current period of the old plan.
	 * @param \DateTimeImmutable      $period_start    Current period start.
	 * @param \DateTimeImmutable|null $period_end      Current period end (null = lifetime).
	 * @param \DateTimeImmutable      $now             Now.
	 * @param Money                   $new_first       What the new plan costs today (net, after coupon).
	 */
	public static function plan( string $mode, string $downgrade_timing, int $from_id, PriceSchedule $old, PriceSchedule $new_schedule, Money $paid, \DateTimeImmutable $period_start, ?\DateTimeImmutable $period_end, \DateTimeImmutable $now, Money $new_first ): ProrationPlan {
		$zero         = Money::zero( $new_first->currency );
		$is_downgrade = self::daily_rate_micros( $new_schedule ) < self::daily_rate_micros( $old );

		if ( $is_downgrade && 'period_end' === $downgrade_timing && null !== $period_end && $period_end > $now ) {
			return new ProrationPlan( ProrationPlan::AT_PERIOD_END, $from_id, $zero, 0, $period_end, true );
		}
		if ( ProrationPlan::NONE === $mode || $paid->currency !== $new_first->currency ) {
			return new ProrationPlan( ProrationPlan::NONE, $from_id, $zero, 0, null, $is_downgrade );
		}

		$credit = self::unused_credit( $paid, $period_start, $period_end, $now );

		if ( ProrationPlan::KEEP_ANCHOR === $mode && null !== $period_end && $period_end > $now && $new_schedule->is_recurring() && $old->is_recurring() ) {
			// Charge the new plan only for the remaining part of the current period, minus the unused value of the old plan.
			$new_period_seconds = max( 1, $period_end->getTimestamp() - $period_start->getTimestamp() );
			$remaining          = max( 0, $period_end->getTimestamp() - $now->getTimestamp() );
			$new_part           = $new_schedule->recurring()->multiply_ratio( $remaining, $new_period_seconds );
			$credit             = Money::of( min( $credit->amount, $new_part->amount ), $credit->currency );
			return new ProrationPlan( ProrationPlan::KEEP_ANCHOR, $from_id, $credit->add( $new_first->subtract( $new_part ) )->non_negative(), 0, $period_end, $is_downgrade );
		}

		$extension = 0;
		if ( $credit->amount > $new_first->amount ) {
			$excess = $credit->subtract( $new_first );
			if ( $new_schedule->is_recurring() && $new_schedule->recurring_amount > 0 ) {
				$period_seconds = Period::approx_seconds( $new_schedule->billing_period ) * $new_schedule->billing_interval;
				$extension      = intdiv( $excess->amount * $period_seconds, $new_schedule->recurring_amount );
			}
			$credit = $new_first;
		}
		return new ProrationPlan( ProrationPlan::IMMEDIATE, $from_id, $credit, $extension, null, $is_downgrade );
	}
}
