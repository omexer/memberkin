<?php
/**
 * Coupon business logic.
 *
 * @package Memberkin
 */

namespace Memberkin\Service;

use Memberkin\Core\Clock;
use Memberkin\Core\Currency;
use Memberkin\Core\Events;
use Memberkin\Core\Money;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Coupon;
use Memberkin\Domain\CouponApplication;
use Memberkin\Domain\Level;
use Memberkin\Domain\Period;
use Memberkin\Domain\PriceSchedule;
use Memberkin\Repository\CouponRepository;
use Memberkin\Repository\LevelRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Validates, applies and redeems coupons; admin create/update.
 */
class CouponService {

	/**
	 * Constructor.
	 *
	 * @param CouponRepository $coupons Coupons.
	 * @param LevelRepository  $levels  Levels.
	 * @param LevelService     $level_service Level service (schedule parsing).
	 */
	public function __construct(
		private CouponRepository $coupons,
		private LevelRepository $levels,
		private LevelService $level_service
	) {}

	/**
	 * Finds a coupon by code and checks that the user may use it for the level.
	 *
	 * @param string $code      Code.
	 * @param int    $level_id  Level.
	 * @param int    $user_id   User (0 = guest; per-user limits are re-checked once the account exists).
	 * @param bool   $is_change Whether the checkout changes an existing plan.
	 * @throws ValidationException With a member-facing message.
	 */
	public function validate( string $code, int $level_id, int $user_id = 0, bool $is_change = false ): Coupon {
		$coupon = $this->coupons->find_by_code( $code );
		if ( ! $coupon || 'active' !== $coupon->status ) {
			throw new ValidationException( array( 'coupon' => esc_html__( 'This coupon code is not valid.', 'memberkin' ) ) );
		}
		$this->check( $coupon, $level_id, $user_id, $is_change );
		return $coupon;
	}

	/**
	 * Eligibility checks for a loaded coupon.
	 *
	 * @param Coupon $coupon    Coupon.
	 * @param int    $level_id  Level.
	 * @param int    $user_id   User.
	 * @param bool   $is_change Plan change.
	 * @throws ValidationException With a member-facing message.
	 */
	public function check( Coupon $coupon, int $level_id, int $user_id = 0, bool $is_change = false ): void {
		$now = Clock::now();
		if ( $coupon->starts_at && $coupon->starts_at > $now ) {
			throw new ValidationException( array( 'coupon' => esc_html__( 'This coupon is not valid yet.', 'memberkin' ) ) );
		}
		if ( $coupon->expires_at && $coupon->expires_at <= $now ) {
			throw new ValidationException( array( 'coupon' => esc_html__( 'This coupon has expired.', 'memberkin' ) ) );
		}
		if ( $coupon->max_uses > 0 && $coupon->uses_count >= $coupon->max_uses ) {
			throw new ValidationException( array( 'coupon' => esc_html__( 'This coupon has been used up.', 'memberkin' ) ) );
		}
		if ( ! $coupon->applies_to_level( $level_id ) ) {
			throw new ValidationException( array( 'coupon' => esc_html__( 'This coupon is not valid for the selected membership.', 'memberkin' ) ) );
		}
		if ( $is_change && ! $coupon->allow_on_change ) {
			throw new ValidationException( array( 'coupon' => esc_html__( 'This coupon cannot be used when changing plans.', 'memberkin' ) ) );
		}
		if ( $user_id && $coupon->max_uses_per_user > 0 ) {
			$since = $coupon->per_user_window_days > 0 ? $now->modify( '-' . $coupon->per_user_window_days . ' days' ) : null;
			if ( $this->coupons->user_uses( $coupon->id, $user_id, $since ) >= $coupon->max_uses_per_user ) {
				throw new ValidationException( array( 'coupon' => esc_html__( 'You have already used this coupon.', 'memberkin' ) ) );
			}
		}
		/**
		 * Extra coupon checks. Throw a ValidationException to reject.
		 *
		 * @param Coupon $coupon    Coupon.
		 * @param int    $level_id  Level.
		 * @param int    $user_id   User.
		 * @param bool   $is_change Plan change.
		 */
		do_action( 'memberkin_coupon_check', $coupon, $level_id, $user_id, $is_change );
	}

	/**
	 * Best automatically applied coupon for a level, if any (silently skips ineligible ones).
	 *
	 * @param Level $level   Level.
	 * @param int   $user_id User.
	 */
	public function auto_coupon( Level $level, int $user_id = 0 ): ?Coupon {
		foreach ( $this->coupons->auto_apply_for_group( $level->group_id ) as $coupon ) {
			try {
				$this->check( $coupon, $level->id, $user_id );
				return $coupon;
			} catch ( ValidationException $e ) {
				continue;
			}
		}
		return null;
	}

	/**
	 * Applies a coupon to a level's schedule.
	 *
	 * @param Coupon        $coupon   Coupon.
	 * @param Level         $level    Level.
	 * @param PriceSchedule $schedule Base schedule (defaults to the level's).
	 */
	public function apply( Coupon $coupon, Level $level, ?PriceSchedule $schedule = null ): CouponApplication {
		$schedule = $schedule ?? $level->schedule;
		$settings = $coupon->levels[ $level->id ] ?? null;

		// A per-level override replaces the whole price schedule; discounts are then not applied on top.
		if ( $settings && $settings['override'] && $settings['schedule'] ) {
			$override = $settings['schedule']->with(
				array(
					'currency'        => $schedule->currency,
					'expiration_type' => $settings['schedule']->expiration_type,
					'expiration_date' => $schedule->expiration_date,
				)
			);
			return new CouponApplication( $coupon, $override, Money::zero( $schedule->currency ), Money::zero( $schedule->currency ), 0 );
		}

		if ( null !== $coupon->trial_override_length && $schedule->is_recurring() ) {
			$schedule = $schedule->with(
				array(
					'trial_length' => max( 0, $coupon->trial_override_length ),
					'trial_period' => $coupon->trial_override_period && ( Period::is_valid( $coupon->trial_override_period ) || 'cycle' === $coupon->trial_override_period ) ? $coupon->trial_override_period : 'day',
					'trial_amount' => max( 0, (int) $coupon->trial_override_amount ),
				)
			);
		}

		$zero  = Money::zero( $schedule->currency );
		$first = $zero;
		if ( in_array( $coupon->applies_to, array( 'initial', 'both' ), true ) ) {
			$type   = null !== $coupon->first_payment_type && '' !== $coupon->first_payment_type ? $coupon->first_payment_type : $coupon->discount_type;
			$amount = null !== $coupon->first_payment_amount && $coupon->first_payment_type ? $coupon->first_payment_amount : $coupon->amount;
			$price  = $schedule->first_payment();
			$first  = $price->subtract( Coupon::discount( $price, $type, $amount ) );
		}

		$recurring = $zero;
		$cycles    = 0;
		if ( $schedule->is_recurring() && in_array( $coupon->applies_to, array( 'recurring', 'both' ), true ) ) {
			$price     = $schedule->recurring();
			$recurring = $price->subtract( Coupon::discount( $price, $coupon->discount_type, $coupon->amount ) );
			$cycles    = $coupon->recurring_cycles;
		}

		/**
		 * Filters a coupon application.
		 *
		 * @param CouponApplication $application Application.
		 * @param Level             $level       Level.
		 */
		return apply_filters( 'memberkin_coupon_application', new CouponApplication( $coupon, $schedule, $first, $recurring, $cycles ), $level );
	}

	/**
	 * Consumes a use and records it. Called once the order is paid (or a free checkout completes).
	 *
	 * @param int $coupon_id Coupon.
	 * @param int $user_id   User.
	 * @param int $order_id  Order.
	 * @throws ValidationException When the global limit was reached in the meantime.
	 */
	public function redeem( int $coupon_id, int $user_id, int $order_id ): void {
		if ( ! $this->coupons->consume( $coupon_id ) ) {
			throw new ValidationException( array( 'coupon' => esc_html__( 'This coupon has been used up.', 'memberkin' ) ) );
		}
		$this->coupons->record_use( $coupon_id, $user_id, $order_id );
		Events::record( 'coupon.redeemed', $user_id, 'coupon', $coupon_id, array( 'order_id' => $order_id ) );
	}

	/**
	 * Creates or updates a coupon from admin/API input (already unslashed).
	 *
	 * Keys: id, code, description, status, discount_type, amount (decimal string: percent or money),
	 * applies_to, recurring_cycles ('' = all), first_payment_type, first_payment_amount (decimal),
	 * trial_override (bool), trial_length, trial_period, trial_price (decimal), starts_at / expires_at
	 * ('Y-m-d' or 'Y-m-d H:i', site timezone), max_uses, max_uses_per_user, per_user_window_days,
	 * allow_on_change, auto_apply_group_id, level_ids (int[]), overrides (level_id => pricing input).
	 *
	 * @param array $input Input.
	 * @throws ValidationException On invalid input.
	 */
	public function save( array $input ): Coupon {
		$existing = ! empty( $input['id'] ) ? $this->coupons->find( (int) $input['id'] ) : null;
		$errors   = array();
		$currency = Currency::default();
		$sep      = LevelService::decimal_separator();

		$code = strtoupper( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $input['code'] ?? ( $existing->code ?? '' ) ) ) );
		if ( '' === $code ) {
			$errors['code'] = __( 'Enter a code using letters, numbers, dashes or underscores.', 'memberkin' );
		} elseif ( strlen( $code ) > 64 ) {
			$errors['code'] = __( 'The code can be at most 64 characters.', 'memberkin' );
		} elseif ( $this->coupons->code_exists( $code, $existing->id ?? 0 ) ) {
			$errors['code'] = __( 'Another coupon already uses this code.', 'memberkin' );
		}

		$type = (string) ( $input['discount_type'] ?? ( $existing->discount_type ?? Coupon::PERCENT ) );
		if ( ! isset( Coupon::types()[ $type ] ) ) {
			$errors['discount_type'] = __( 'Choose a discount type.', 'memberkin' );
			$type                    = Coupon::PERCENT;
		}

		$parse = static function ( string $kind, $raw ) use ( $currency, $sep ): int {
			$raw = trim( (string) $raw );
			if ( Coupon::PERCENT === $kind ) {
				return Money::from_decimal( '' === $raw ? '0' : $raw, 'USD', $sep )->amount; // Two decimals → hundredths of a percent.
			}
			return Money::from_decimal( '' === $raw ? '0' : $raw, $currency, $sep )->amount;
		};

		try {
			$amount = array_key_exists( 'amount', $input ) ? $parse( $type, $input['amount'] ) : ( $existing->amount ?? 0 );
		} catch ( \InvalidArgumentException $e ) {
			$errors['amount'] = __( 'Enter a valid discount amount.', 'memberkin' );
			$amount           = 0;
		}
		if ( $amount < 0 || ( Coupon::PERCENT === $type && $amount > 10000 ) ) {
			$errors['amount'] = __( 'Percentages must be between 0 and 100.', 'memberkin' );
		}

		$applies_to = (string) ( $input['applies_to'] ?? ( $existing->applies_to ?? 'both' ) );
		if ( ! in_array( $applies_to, array( 'initial', 'recurring', 'both' ), true ) ) {
			$applies_to = 'both';
		}
		$cycles_raw = $input['recurring_cycles'] ?? ( $existing ? $existing->recurring_cycles : null );
		$cycles     = ( null === $cycles_raw || '' === $cycles_raw ) ? null : max( 0, (int) $cycles_raw );

		$first_type   = isset( $input['first_payment_type'] ) && isset( Coupon::types()[ $input['first_payment_type'] ] ) ? (string) $input['first_payment_type'] : null;
		$first_amount = null;
		if ( $first_type ) {
			try {
				$first_amount = $parse( $first_type, $input['first_payment_amount'] ?? '0' );
			} catch ( \InvalidArgumentException $e ) {
				$errors['first_payment_amount'] = __( 'Enter a valid first payment discount.', 'memberkin' );
			}
		}

		$trial_length = null;
		$trial_period = null;
		$trial_amount = null;
		if ( ! empty( $input['trial_override'] ) ) {
			$trial_length = max( 0, (int) ( $input['trial_length'] ?? 0 ) );
			$trial_period = (string) ( $input['trial_period'] ?? 'day' );
			if ( ! Period::is_valid( $trial_period ) && 'cycle' !== $trial_period ) {
				$trial_period = 'day';
			}
			try {
				$trial_amount = Money::from_decimal( (string) ( $input['trial_price'] ?? '0' ), $currency, $sep )->amount;
			} catch ( \InvalidArgumentException $e ) {
				$errors['trial_price'] = __( 'Enter a valid trial price.', 'memberkin' );
			}
		} elseif ( $existing && ! array_key_exists( 'trial_override', $input ) ) {
			$trial_length = $existing->trial_override_length;
			$trial_period = $existing->trial_override_period;
			$trial_amount = $existing->trial_override_amount;
		}

		$starts  = array_key_exists( 'starts_at', $input ) ? self::parse_local_datetime( (string) $input['starts_at'], false ) : ( $existing->starts_at ?? null );
		$expires = array_key_exists( 'expires_at', $input ) ? self::parse_local_datetime( (string) $input['expires_at'], true ) : ( $existing->expires_at ?? null );
		if ( $starts && $expires && $expires <= $starts ) {
			$errors['expires_at'] = __( 'The expiry date must be after the start date.', 'memberkin' );
		}

		$status = (string) ( $input['status'] ?? ( $existing->status ?? 'active' ) );
		$coupon = new Coupon(
			$existing->id ?? 0,
			$code,
			sanitize_text_field( (string) ( $input['description'] ?? ( $existing->description ?? '' ) ) ),
			in_array( $status, array( 'active', 'inactive' ), true ) ? $status : 'active',
			$type,
			$amount,
			$applies_to,
			$cycles,
			$first_type,
			$first_amount,
			$trial_length,
			$trial_period,
			$trial_amount,
			$starts,
			$expires,
			max( 0, (int) ( $input['max_uses'] ?? ( $existing->max_uses ?? 0 ) ) ),
			$existing->uses_count ?? 0,
			max( 0, (int) ( $input['max_uses_per_user'] ?? ( $existing->max_uses_per_user ?? 0 ) ) ),
			max( 0, (int) ( $input['per_user_window_days'] ?? ( $existing->per_user_window_days ?? 0 ) ) ),
			array_key_exists( 'allow_on_change', $input ) ? (bool) $input['allow_on_change'] : ( $existing->allow_on_change ?? true ),
			! empty( $input['auto_apply_group_id'] ) ? (int) $input['auto_apply_group_id'] : ( array_key_exists( 'auto_apply_group_id', $input ) ? null : ( $existing->auto_apply_group_id ?? null ) )
		);

		// Levels and per-level overrides.
		if ( array_key_exists( 'level_ids', $input ) ) {
			$coupon->levels = array();
			$overrides      = isset( $input['overrides'] ) && is_array( $input['overrides'] ) ? $input['overrides'] : array();
			foreach ( array_unique( array_map( 'intval', (array) $input['level_ids'] ) ) as $level_id ) {
				$level = $this->levels->find( $level_id );
				if ( ! $level ) {
					continue;
				}
				$settings = array(
					'override' => false,
					'schedule' => null,
				);
				if ( ! empty( $overrides[ $level_id ]['enabled'] ) ) {
					try {
						$schedule = $this->level_service->schedule_from_input( (array) $overrides[ $level_id ], $level->schedule );
						$problems = $schedule->validate();
						if ( $problems ) {
							/* translators: 1: level name, 2: problem. */
							$errors[ 'override_' . $level_id ] = sprintf( __( '%1$s: %2$s', 'memberkin' ), $level->name, implode( ' ', $problems ) );
						}
						$settings = array(
							'override' => true,
							'schedule' => $schedule,
						);
					} catch ( \InvalidArgumentException $e ) {
						/* translators: %s: level name. */
						$errors[ 'override_' . $level_id ] = sprintf( __( '%s: enter valid amounts.', 'memberkin' ), $level->name );
					}
				}
				$coupon->levels[ $level_id ] = $settings;
			}
		} elseif ( $existing ) {
			$coupon->levels = $existing->levels;
		}

		if ( $errors ) {
			throw new ValidationException( array_map( 'esc_html', $errors ) );
		}
		$this->coupons->save( $coupon );
		/**
		 * Fires after a coupon was saved.
		 *
		 * @param Coupon $coupon Coupon.
		 */
		do_action( 'memberkin_coupon_saved', $coupon );
		return $this->coupons->find( $coupon->id );
	}

	/**
	 * Parses "Y-m-d" or "Y-m-d H:i" in the site timezone to UTC. Empty → null.
	 *
	 * @param string $value      Value.
	 * @param bool   $end_of_day For date-only values, use 23:59:59.
	 */
	public static function parse_local_datetime( string $value, bool $end_of_day ): ?\DateTimeImmutable {
		$value = trim( str_replace( 'T', ' ', $value ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$value .= $end_of_day ? ' 23:59:59' : ' 00:00:00';
		} elseif ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value ) ) {
			return null;
		}
		return ( new \DateTimeImmutable( $value, wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
	}
}
