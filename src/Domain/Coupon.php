<?php
/**
 * Coupon entity.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain;

use Memberkin\Core\Clock;
use Memberkin\Core\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Discount code.
 *
 * Amount semantics by discount_type:
 * - percent:   hundredths of a percent (2500 = 25%).
 * - fixed:     minor units taken off each discounted payment.
 * - set_price: minor units the discounted payment costs.
 */
final class Coupon implements \JsonSerializable {

	public const PERCENT   = 'percent';
	public const FIXED     = 'fixed';
	public const SET_PRICE = 'set_price';

	/**
	 * Per-level settings: level_id => [ 'override' => bool, 'schedule' => ?PriceSchedule ].
	 *
	 * @var array<int, array{override: bool, schedule: ?PriceSchedule}>
	 */
	public array $levels = array();

	/**
	 * Constructor.
	 *
	 * @param int                     $id                    Id.
	 * @param string                  $code                  Code.
	 * @param string                  $description           Admin description.
	 * @param string                  $status                active|inactive.
	 * @param string                  $discount_type         percent|fixed|set_price.
	 * @param int                     $amount                See class doc.
	 * @param string                  $applies_to            initial|recurring|both.
	 * @param int|null                $recurring_cycles      Discounted recurring payments (null = all).
	 * @param string|null             $first_payment_type    Different discount type for the first payment.
	 * @param int|null                $first_payment_amount  Amount for $first_payment_type.
	 * @param int|null                $trial_override_length Trial length granted by the coupon.
	 * @param string|null             $trial_override_period Trial unit.
	 * @param int|null                $trial_override_amount Trial price.
	 * @param \DateTimeImmutable|null $starts_at             Valid from.
	 * @param \DateTimeImmutable|null $expires_at            Valid until.
	 * @param int                     $max_uses              0 = unlimited.
	 * @param int                     $uses_count            Times used.
	 * @param int                     $max_uses_per_user     0 = unlimited.
	 * @param int                     $per_user_window_days  Window for the per-user limit (0 = lifetime).
	 * @param bool                    $allow_on_change       Usable when changing plans.
	 * @param int|null                $auto_apply_group_id   Automatically applied to checkouts of this level group.
	 */
	public function __construct(
		public int $id,
		public string $code,
		public string $description = '',
		public string $status = 'active',
		public string $discount_type = self::PERCENT,
		public int $amount = 0,
		public string $applies_to = 'both',
		public ?int $recurring_cycles = null,
		public ?string $first_payment_type = null,
		public ?int $first_payment_amount = null,
		public ?int $trial_override_length = null,
		public ?string $trial_override_period = null,
		public ?int $trial_override_amount = null,
		public ?\DateTimeImmutable $starts_at = null,
		public ?\DateTimeImmutable $expires_at = null,
		public int $max_uses = 0,
		public int $uses_count = 0,
		public int $max_uses_per_user = 0,
		public int $per_user_window_days = 0,
		public bool $allow_on_change = true,
		public ?int $auto_apply_group_id = null
	) {}

	/**
	 * Discount types with labels.
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			self::PERCENT   => __( 'Percentage off', 'memberkin' ),
			self::FIXED     => __( 'Fixed amount off', 'memberkin' ),
			self::SET_PRICE => __( 'Set a new price', 'memberkin' ),
		);
	}

	/**
	 * Whether the coupon is within its active window.
	 *
	 * @param \DateTimeImmutable|null $now Time.
	 */
	public function is_within_dates( ?\DateTimeImmutable $now = null ): bool {
		$now = $now ?? Clock::now();
		return ( null === $this->starts_at || $this->starts_at <= $now ) && ( null === $this->expires_at || $this->expires_at > $now );
	}

	/**
	 * Whether usable for a level (no level rows = all levels).
	 *
	 * @param int $level_id Level id.
	 */
	public function applies_to_level( int $level_id ): bool {
		return ! $this->levels || isset( $this->levels[ $level_id ] );
	}

	/**
	 * Applies a discount of the given type to an amount (never below zero).
	 *
	 * @param Money  $price  Price.
	 * @param string $type   Discount type.
	 * @param int    $amount Discount amount (see class doc).
	 */
	public static function discount( Money $price, string $type, int $amount ): Money {
		$discounted = match ( $type ) {
			self::PERCENT   => $price->subtract( $price->multiply_ratio( min( 10000, max( 0, $amount ) ), 10000 ) ),
			self::FIXED     => $price->subtract( Money::of( max( 0, $amount ), $price->currency ) ),
			self::SET_PRICE => Money::of( min( $price->amount, max( 0, $amount ) ), $price->currency ),
			default         => $price,
		};
		return $discounted->non_negative();
	}

	/**
	 * Short description, e.g. "25% off the first payment".
	 *
	 * @param string $currency Currency for fixed amounts.
	 */
	public function summary( string $currency ): string {
		$value = match ( $this->discount_type ) {
			/* translators: %s: percentage. */
			self::PERCENT   => sprintf( __( '%s%% off', 'memberkin' ), rtrim( rtrim( number_format_i18n( $this->amount / 100, 2 ), '0' ), '.,' ) ),
			/* translators: %s: amount. */
			self::FIXED     => sprintf( __( '%s off', 'memberkin' ), Money::of( $this->amount, $currency )->format() ),
			/* translators: %s: amount. */
			default         => sprintf( __( 'price %s', 'memberkin' ), Money::of( $this->amount, $currency )->format() ),
		};
		$scope = match ( $this->applies_to ) {
			'initial'   => __( 'the first payment', 'memberkin' ),
			'recurring' => null === $this->recurring_cycles
				? __( 'every renewal', 'memberkin' )
				/* translators: %d: number of renewals. */
				: sprintf( _n( 'the next %d renewal', 'the next %d renewals', $this->recurring_cycles, 'memberkin' ), $this->recurring_cycles ),
			default     => null === $this->recurring_cycles
				? __( 'every payment', 'memberkin' )
				/* translators: %d: number of renewals. */
				: sprintf( _n( 'the first payment and %d renewal', 'the first payment and %d renewals', $this->recurring_cycles, 'memberkin' ), $this->recurring_cycles ),
		};
		/* translators: 1: discount, 2: which payments. */
		return sprintf( __( '%1$s %2$s', 'memberkin' ), $value, $scope );
	}

	/**
	 * JSON form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		$data = get_object_vars( $this );
		foreach ( array( 'starts_at', 'expires_at' ) as $key ) {
			$data[ $key ] = $data[ $key ]?->format( DATE_ATOM );
		}
		$levels = array();
		foreach ( $this->levels as $level_id => $settings ) {
			$levels[] = array(
				'level_id' => $level_id,
				'override' => $settings['override'],
				'pricing'  => $settings['schedule']?->jsonSerialize(),
			);
		}
		$data['levels'] = $levels;
		return $data;
	}
}
