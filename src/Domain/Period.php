<?php
/**
 * Billing period arithmetic.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Calendar maths for day/week/month/year periods with month-end clamping.
 */
final class Period {

	public const UNITS = array( 'day', 'week', 'month', 'year' );

	/**
	 * Whether a unit is valid.
	 *
	 * @param string $unit Unit.
	 */
	public static function is_valid( string $unit ): bool {
		return in_array( $unit, self::UNITS, true );
	}

	/**
	 * Adds N units to a date. Month/year additions keep the anchor day (default: the date's own day) and clamp to the
	 * last day of shorter months, so Jan 31 + 1 month = Feb 28/29 and + 2 months = Mar 31 when anchored on 31.
	 *
	 * @param \DateTimeImmutable $date       Start.
	 * @param int                $count      Units to add (may be negative).
	 * @param string             $unit       day|week|month|year.
	 * @param int|null           $anchor_day Day of month to aim for (1–31).
	 * @throws \InvalidArgumentException On invalid unit.
	 */
	public static function add( \DateTimeImmutable $date, int $count, string $unit, ?int $anchor_day = null ): \DateTimeImmutable {
		switch ( $unit ) {
			case 'day':
				return $date->modify( sprintf( '%+d days', $count ) );
			case 'week':
				return $date->modify( sprintf( '%+d days', 7 * $count ) );
			case 'month':
			case 'year':
				$months = 'year' === $unit ? 12 * $count : $count;
				$total  = (int) $date->format( 'Y' ) * 12 + (int) $date->format( 'n' ) - 1 + $months;
				$year   = intdiv( $total, 12 );
				$month  = $total % 12 + 1;
				$day    = $anchor_day ?? (int) $date->format( 'j' );
				$last   = (int) ( new \DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), $date->getTimezone() ) )->format( 't' );
				return $date->setDate( $year, $month, min( $day, $last ) );
		}
		throw new \InvalidArgumentException( 'Invalid period unit: ' . esc_html( $unit ) );
	}

	/**
	 * Approximate length of one unit in seconds (for proration ratios only).
	 *
	 * @param string $unit Unit.
	 */
	public static function approx_seconds( string $unit ): int {
		return match ( $unit ) {
			'day'   => DAY_IN_SECONDS,
			'week'  => WEEK_IN_SECONDS,
			'month' => (int) ( YEAR_IN_SECONDS / 12 ),
			'year'  => YEAR_IN_SECONDS,
			default => DAY_IN_SECONDS,
		};
	}

	/**
	 * Localised label, e.g. "month" / "months".
	 *
	 * @param string $unit  Unit.
	 * @param int    $count Count for pluralisation.
	 */
	public static function label( string $unit, int $count = 1 ): string {
		return match ( $unit ) {
			/* translators: unit of time. */
			'day'   => _n( 'day', 'days', $count, 'memberkin' ),
			/* translators: unit of time. */
			'week'  => _n( 'week', 'weeks', $count, 'memberkin' ),
			/* translators: unit of time. */
			'month' => _n( 'month', 'months', $count, 'memberkin' ),
			/* translators: unit of time. */
			'year'  => _n( 'year', 'years', $count, 'memberkin' ),
			/* translators: billing cycle. */
			'cycle' => _n( 'payment', 'payments', $count, 'memberkin' ),
			default => $unit,
		};
	}

	/**
	 * A length of time, e.g. "1 month", "3 months".
	 *
	 * @param int    $count Count.
	 * @param string $unit  Unit.
	 */
	public static function duration( int $count, string $unit ): string {
		/* translators: 1: number, 2: unit of time such as "months". */
		return sprintf( _x( '%1$d %2$s', 'duration', 'memberkin' ), $count, self::label( $unit, $count ) );
	}

	/**
	 * A repeat interval, e.g. "every month", "every 3 months".
	 *
	 * @param int    $count Count.
	 * @param string $unit  Unit.
	 */
	public static function every( int $count, string $unit ): string {
		return 1 === $count
			/* translators: %s: unit of time such as "month". */
			? sprintf( __( 'every %s', 'memberkin' ), self::label( $unit, 1 ) )
			/* translators: 1: number, 2: unit of time such as "months". */
			: sprintf( __( 'every %1$d %2$s', 'memberkin' ), $count, self::label( $unit, $count ) );
	}

	/**
	 * Select options for unit dropdowns.
	 *
	 * @param bool $with_cycle Include "payments" (trial measured in billing cycles).
	 * @return array<string, string>
	 */
	public static function options( bool $with_cycle = false ): array {
		$options = array(
			'day'   => __( 'Day(s)', 'memberkin' ),
			'week'  => __( 'Week(s)', 'memberkin' ),
			'month' => __( 'Month(s)', 'memberkin' ),
			'year'  => __( 'Year(s)', 'memberkin' ),
		);
		if ( $with_cycle ) {
			$options['cycle'] = __( 'Payment(s)', 'memberkin' );
		}
		return $options;
	}
}
