<?php
/**
 * Price schedule value object.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain;

use Memberkin\Core\Money;

defined( 'ABSPATH' ) || exit;

/**
 * What a member pays and when.
 *
 * Semantics:
 * - One-time: recurring_amount = 0; the member pays initial_amount (+ signup_fee) once.
 * - Recurring: the first payment is initial_amount (+ signup_fee); then recurring_amount every billing_interval billing_period,
 *   billing_cycles times in total (0 = until cancelled). initial_amount may differ from recurring_amount (intro price).
 * - Trial (trial_length > 0): replaces the first payment. The member pays trial_amount (+ signup_fee) today.
 *   trial_period day/week/month/year: regular billing starts after that duration.
 *   trial_period "cycle": the first trial_length billing cycles cost trial_amount each.
 * - Expiration: never | duration (expiration_length expiration_period after start) | fixed_date | after_cycles
 *   (access ends when the last billing cycle ends).
 */
final class PriceSchedule implements \JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param string                  $currency          Currency.
	 * @param int                     $initial_amount    First payment (minor units).
	 * @param int                     $signup_fee        One-off fee added to the first payment.
	 * @param int                     $recurring_amount  Recurring amount (0 = not recurring).
	 * @param int                     $billing_interval  Bill every N periods.
	 * @param string                  $billing_period    Period unit: day, week, month or year.
	 * @param int                     $billing_cycles    Total recurring payments (0 = unlimited).
	 * @param int                     $trial_amount      Trial price.
	 * @param int                     $trial_length      Trial length (0 = none).
	 * @param string                  $trial_period      day|week|month|year|cycle.
	 * @param string                  $expiration_type   never|duration|fixed_date|after_cycles.
	 * @param int                     $expiration_length Expiration length.
	 * @param string                  $expiration_period Expiration unit.
	 * @param \DateTimeImmutable|null $expiration_date   Fixed expiration date.
	 */
	public function __construct(
		public readonly string $currency,
		public readonly int $initial_amount = 0,
		public readonly int $signup_fee = 0,
		public readonly int $recurring_amount = 0,
		public readonly int $billing_interval = 1,
		public readonly string $billing_period = 'month',
		public readonly int $billing_cycles = 0,
		public readonly int $trial_amount = 0,
		public readonly int $trial_length = 0,
		public readonly string $trial_period = 'day',
		public readonly string $expiration_type = 'never',
		public readonly int $expiration_length = 0,
		public readonly string $expiration_period = 'month',
		public readonly ?\DateTimeImmutable $expiration_date = null
	) {}

	/**
	 * Builds from a DB row / array with the standard column names.
	 *
	 * @param array|object $row Row.
	 */
	public static function from_row( array|object $row ): self {
		$r = (array) $row;
		return new self(
			(string) ( $r['currency'] ?? 'USD' ),
			(int) ( $r['initial_amount'] ?? 0 ),
			(int) ( $r['signup_fee'] ?? 0 ),
			(int) ( $r['recurring_amount'] ?? 0 ),
			max( 1, (int) ( $r['billing_interval'] ?? 1 ) ),
			(string) ( $r['billing_period'] ?? 'month' ),
			(int) ( $r['billing_cycles'] ?? 0 ),
			(int) ( $r['trial_amount'] ?? 0 ),
			(int) ( $r['trial_length'] ?? 0 ),
			(string) ( $r['trial_period'] ?? 'day' ),
			(string) ( $r['expiration_type'] ?? 'never' ),
			(int) ( $r['expiration_length'] ?? 0 ),
			(string) ( $r['expiration_period'] ?? 'month' ),
			! empty( $r['expiration_date'] ) ? ( $r['expiration_date'] instanceof \DateTimeImmutable ? $r['expiration_date'] : new \DateTimeImmutable( (string) $r['expiration_date'], new \DateTimeZone( 'UTC' ) ) ) : null
		);
	}

	/**
	 * Column => value array for persistence.
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		return array(
			'currency'          => $this->currency,
			'initial_amount'    => $this->initial_amount,
			'signup_fee'        => $this->signup_fee,
			'recurring_amount'  => $this->recurring_amount,
			'billing_interval'  => $this->billing_interval,
			'billing_period'    => $this->billing_period,
			'billing_cycles'    => $this->billing_cycles,
			'trial_amount'      => $this->trial_amount,
			'trial_length'      => $this->trial_length,
			'trial_period'      => $this->trial_period,
			'expiration_type'   => $this->expiration_type,
			'expiration_length' => $this->expiration_length,
			'expiration_period' => $this->expiration_period,
			'expiration_date'   => $this->expiration_date?->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Copy with changes.
	 *
	 * @param array<string, mixed> $changes Property => value.
	 */
	public function with( array $changes ): self {
		$row = array_merge( $this->to_row(), $changes );
		return self::from_row( $row );
	}

	/**
	 * Whether there are recurring payments.
	 */
	public function is_recurring(): bool {
		return $this->recurring_amount > 0;
	}

	/**
	 * Whether a trial applies.
	 */
	public function has_trial(): bool {
		return $this->trial_length > 0 && $this->recurring_amount > 0;
	}

	/**
	 * Whether nothing is ever charged.
	 */
	public function is_free(): bool {
		return 0 === $this->first_payment()->amount && ! $this->is_recurring();
	}

	/**
	 * Amount due at checkout (before coupons, tax and proration).
	 */
	public function first_payment(): Money {
		$base = $this->has_trial() ? $this->trial_amount : $this->initial_amount;
		return Money::of( $base + $this->signup_fee, $this->currency );
	}

	/**
	 * Recurring amount.
	 */
	public function recurring(): Money {
		return Money::of( $this->recurring_amount, $this->currency );
	}

	/**
	 * When the second payment is due, given the start (checkout) time. Null when nothing recurs.
	 *
	 * @param \DateTimeImmutable $start Start.
	 */
	public function next_payment_after_start( \DateTimeImmutable $start ): ?\DateTimeImmutable {
		if ( ! $this->is_recurring() ) {
			return null;
		}
		if ( $this->has_trial() && 'cycle' !== $this->trial_period ) {
			return Period::add( $start, $this->trial_length, $this->trial_period );
		}
		return Period::add( $start, $this->billing_interval, $this->billing_period );
	}

	/**
	 * Amount of the Nth recurring payment (1 = the first payment after checkout).
	 *
	 * @param int $n Payment number after the initial one.
	 */
	public function recurring_payment_amount( int $n ): Money {
		if ( $this->has_trial() && 'cycle' === $this->trial_period && $n < $this->trial_length ) {
			return Money::of( $this->trial_amount, $this->currency );
		}
		return $this->recurring();
	}

	/**
	 * Total number of recurring charges after the first payment (0 = unlimited).
	 * With billing_cycles = 12 the member pays 12 times in total, i.e. 11 renewals after the first.
	 * A day/week/month/year trial does not count as a billing cycle.
	 */
	public function renewals_limit(): int {
		if ( 0 === $this->billing_cycles ) {
			return 0;
		}
		if ( $this->has_trial() && 'cycle' !== $this->trial_period ) {
			return $this->billing_cycles; // Trial payment + N regular payments.
		}
		return max( 0, $this->billing_cycles - 1 );
	}

	/**
	 * When access ends for a member starting at $start (null = no end date from the schedule itself).
	 *
	 * @param \DateTimeImmutable $start Start.
	 */
	public function access_end( \DateTimeImmutable $start ): ?\DateTimeImmutable {
		switch ( $this->expiration_type ) {
			case 'duration':
				return $this->expiration_length > 0 ? Period::add( $start, $this->expiration_length, $this->expiration_period ) : null;
			case 'fixed_date':
				return $this->expiration_date;
			case 'after_cycles':
				if ( ! $this->is_recurring() || 0 === $this->billing_cycles ) {
					return null;
				}
				$end = $start;
				if ( $this->has_trial() && 'cycle' !== $this->trial_period ) {
					$end = Period::add( $end, $this->trial_length, $this->trial_period );
					return Period::add( $end, $this->billing_interval * $this->billing_cycles, $this->billing_period, (int) $start->format( 'j' ) );
				}
				return Period::add( $end, $this->billing_interval * $this->billing_cycles, $this->billing_period );
			default:
				return null;
		}
	}

	/**
	 * Validation errors (empty when valid).
	 *
	 * @return string[]
	 */
	public function validate(): array {
		$errors = array();
		foreach ( array( 'initial_amount', 'signup_fee', 'recurring_amount', 'trial_amount' ) as $field ) {
			if ( $this->{$field} < 0 ) {
				$errors[] = __( 'Amounts cannot be negative.', 'memberkin' );
				break;
			}
		}
		if ( $this->recurring_amount > 0 && ! Period::is_valid( $this->billing_period ) ) {
			$errors[] = __( 'Choose a valid billing period.', 'memberkin' );
		}
		if ( $this->billing_interval < 1 || $this->billing_interval > 365 ) {
			$errors[] = __( 'The billing interval must be between 1 and 365.', 'memberkin' );
		}
		if ( $this->trial_length > 0 && ! Period::is_valid( $this->trial_period ) && 'cycle' !== $this->trial_period ) {
			$errors[] = __( 'Choose a valid trial period.', 'memberkin' );
		}
		if ( $this->trial_length > 0 && 0 === $this->recurring_amount ) {
			$errors[] = __( 'A trial needs a recurring price.', 'memberkin' );
		}
		if ( 'duration' === $this->expiration_type && ( $this->expiration_length < 1 || ! Period::is_valid( $this->expiration_period ) ) ) {
			$errors[] = __( 'Enter how long membership lasts.', 'memberkin' );
		}
		if ( 'fixed_date' === $this->expiration_type && null === $this->expiration_date ) {
			$errors[] = __( 'Enter the expiration date.', 'memberkin' );
		}
		if ( 'after_cycles' === $this->expiration_type && ( 0 === $this->recurring_amount || 0 === $this->billing_cycles ) ) {
			$errors[] = __( '"Ends after the last payment" needs a recurring price and a number of payments.', 'memberkin' );
		}
		return $errors;
	}

	/**
	 * Human readable price description, e.g. "$10.00 now and then $10.00 per month."
	 */
	public function describe(): string {
		$first = $this->first_payment();
		if ( $this->is_free() ) {
			$text = __( 'Free', 'memberkin' );
		} elseif ( ! $this->is_recurring() ) {
			$text = $first->format();
		} else {
			$every = 1 === $this->billing_interval
				/* translators: %s: period unit, e.g. "month". */
				? sprintf( __( 'per %s', 'memberkin' ), Period::label( $this->billing_period, 1 ) )
				/* translators: 1: number, 2: period unit plural. */
				: sprintf( __( 'every %1$d %2$s', 'memberkin' ), $this->billing_interval, Period::label( $this->billing_period, $this->billing_interval ) );

			$intro = Money::of( $this->has_trial() ? $this->trial_amount : $this->initial_amount, $this->currency );
			if ( $this->has_trial() && 'cycle' !== $this->trial_period ) {
				$trial = sprintf( '%d %s', $this->trial_length, Period::label( $this->trial_period, $this->trial_length ) );
				$text  = $intro->is_zero()
					/* translators: 1: trial length, 2: recurring price, 3: billing frequency. */
					? sprintf( __( 'Free for %1$s, then %2$s %3$s', 'memberkin' ), $trial, $this->recurring()->format(), $every )
					/* translators: 1: trial price, 2: trial length, 3: recurring price, 4: billing frequency. */
					: sprintf( __( '%1$s for %2$s, then %3$s %4$s', 'memberkin' ), $intro->format(), $trial, $this->recurring()->format(), $every );
			} elseif ( $this->has_trial() ) {
				/* translators: 1: trial price, 2: number of payments, 3: recurring price, 4: billing frequency. */
				$text = sprintf( __( '%1$s for the first %2$d payments, then %3$s %4$s', 'memberkin' ), $intro->format(), $this->trial_length, $this->recurring()->format(), $every );
			} elseif ( $intro->amount === $this->recurring_amount ) {
				$text = $this->recurring()->format() . ' ' . $every;
			} else {
				/* translators: 1: first payment, 2: recurring price, 3: billing frequency. */
				$text = sprintf( __( '%1$s now, then %2$s %3$s', 'memberkin' ), $intro->format(), $this->recurring()->format(), $every );
			}
			if ( $this->billing_cycles > 0 ) {
				/* translators: %d: number of payments. */
				$text .= ' ' . sprintf( _n( 'for %d payment', 'for %d payments', $this->billing_cycles, 'memberkin' ), $this->billing_cycles );
			}
			if ( $this->signup_fee > 0 ) {
				/* translators: %s: signup fee. */
				$text .= ' ' . sprintf( __( '(plus a %s signup fee)', 'memberkin' ), Money::of( $this->signup_fee, $this->currency )->format() );
			}
		}

		if ( 'duration' === $this->expiration_type && $this->expiration_length > 0 ) {
			/* translators: 1: number, 2: period unit. */
			$text .= '. ' . sprintf( __( 'Membership lasts %1$d %2$s.', 'memberkin' ), $this->expiration_length, Period::label( $this->expiration_period, $this->expiration_length ) );
		} elseif ( 'fixed_date' === $this->expiration_type && $this->expiration_date ) {
			/* translators: %s: date. */
			$text .= '. ' . sprintf( __( 'Membership ends on %s.', 'memberkin' ), wp_date( get_option( 'date_format' ), $this->expiration_date->getTimestamp() ) );
		} else {
			$text .= '.';
		}
		return $text;
	}

	/**
	 * JSON form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->to_row();
	}
}
