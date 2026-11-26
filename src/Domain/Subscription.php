<?php
/**
 * Subscription entity.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain;

use Memberkin\Core\Money;

defined( 'ABSPATH' ) || exit;

/**
 * A recurring billing agreement. billing_model "gateway": the gateway runs the schedule;
 * "site": Memberkin charges a saved payment method at next_payment_at.
 */
final class Subscription implements \JsonSerializable {

	public const PENDING   = 'pending';
	public const TRIALING  = 'trialing';
	public const ACTIVE    = 'active';
	public const PAST_DUE  = 'past_due';
	public const UNPAID    = 'unpaid';
	public const PAUSED    = 'paused';
	public const CANCELLED = 'cancelled';
	public const COMPLETED = 'completed';
	public const EXPIRED   = 'expired';

	public const TRANSITIONS = array(
		self::PENDING   => array( self::TRIALING, self::ACTIVE, self::CANCELLED, self::EXPIRED ),
		self::TRIALING  => array( self::ACTIVE, self::PAST_DUE, self::PAUSED, self::CANCELLED, self::EXPIRED ),
		self::ACTIVE    => array( self::PAST_DUE, self::PAUSED, self::CANCELLED, self::COMPLETED, self::EXPIRED ),
		self::PAST_DUE  => array( self::ACTIVE, self::UNPAID, self::CANCELLED, self::EXPIRED ),
		self::UNPAID    => array( self::ACTIVE, self::CANCELLED, self::EXPIRED ),
		self::PAUSED    => array( self::ACTIVE, self::TRIALING, self::CANCELLED, self::EXPIRED ),
		self::CANCELLED => array(),
		self::COMPLETED => array(),
		self::EXPIRED   => array(),
	);

	/**
	 * Constructor.
	 *
	 * @param int                     $id                      Id.
	 * @param int                     $user_id                 User.
	 * @param int                     $level_id                Level.
	 * @param string                  $gateway                 Gateway id.
	 * @param string                  $status                  Status.
	 * @param string                  $currency                Currency.
	 * @param int                     $recurring_amount        Recurring amount (net of discount, before tax).
	 * @param int                     $billing_interval        Interval.
	 * @param string                  $billing_period          Period.
	 * @param int|null                $membership_id           Membership.
	 * @param string                  $gateway_mode            live|test.
	 * @param string|null             $gateway_subscription_id Gateway id.
	 * @param string|null             $gateway_customer_id     Gateway customer.
	 * @param int|null                $payment_method_id       Saved payment method.
	 * @param string                  $billing_model           gateway|site.
	 * @param int                     $tax_amount              Tax per renewal.
	 * @param int                     $billing_cycles          Total renewals allowed (0 = unlimited).
	 * @param int                     $cycles_completed        Renewals charged so far.
	 * @param int|null                $coupon_id               Coupon.
	 * @param int|null                $coupon_cycles_remaining Discounted renewals left (null = forever).
	 * @param \DateTimeImmutable|null $trial_ends_at           Trial end.
	 * @param \DateTimeImmutable|null $current_period_start    Period start.
	 * @param \DateTimeImmutable|null $current_period_end      Period end.
	 * @param \DateTimeImmutable|null $next_payment_at         Next charge.
	 * @param bool                    $cancel_at_period_end    Cancellation scheduled.
	 * @param \DateTimeImmutable|null $cancelled_at            Cancelled at.
	 * @param string|null             $cancel_reason           Reason.
	 * @param \DateTimeImmutable|null $paused_at               Paused at.
	 * @param \DateTimeImmutable|null $resume_at               Resume at.
	 * @param int                     $retry_count             Failed attempts in the current dunning cycle.
	 * @param \DateTimeImmutable|null $next_retry_at           Next retry.
	 * @param string|null             $last_payment_error      Last error.
	 * @param \DateTimeImmutable|null $created_at              Created.
	 */
	public function __construct(
		public int $id,
		public int $user_id,
		public int $level_id,
		public string $gateway,
		public string $status,
		public string $currency,
		public int $recurring_amount,
		public int $billing_interval,
		public string $billing_period,
		public ?int $membership_id = null,
		public string $gateway_mode = 'live',
		public ?string $gateway_subscription_id = null,
		public ?string $gateway_customer_id = null,
		public ?int $payment_method_id = null,
		public string $billing_model = 'gateway',
		public int $tax_amount = 0,
		public int $billing_cycles = 0,
		public int $cycles_completed = 0,
		public ?int $coupon_id = null,
		public ?int $coupon_cycles_remaining = null,
		public ?\DateTimeImmutable $trial_ends_at = null,
		public ?\DateTimeImmutable $current_period_start = null,
		public ?\DateTimeImmutable $current_period_end = null,
		public ?\DateTimeImmutable $next_payment_at = null,
		public bool $cancel_at_period_end = false,
		public ?\DateTimeImmutable $cancelled_at = null,
		public ?string $cancel_reason = null,
		public ?\DateTimeImmutable $paused_at = null,
		public ?\DateTimeImmutable $resume_at = null,
		public int $retry_count = 0,
		public ?\DateTimeImmutable $next_retry_at = null,
		public ?string $last_payment_error = null,
		public ?\DateTimeImmutable $created_at = null
	) {}

	/**
	 * Status labels.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			self::PENDING   => __( 'Pending', 'memberkin' ),
			self::TRIALING  => __( 'Trial', 'memberkin' ),
			self::ACTIVE    => __( 'Active', 'memberkin' ),
			self::PAST_DUE  => __( 'Past due', 'memberkin' ),
			self::UNPAID    => __( 'Unpaid', 'memberkin' ),
			self::PAUSED    => __( 'Paused', 'memberkin' ),
			self::CANCELLED => __( 'Cancelled', 'memberkin' ),
			self::COMPLETED => __( 'Completed', 'memberkin' ),
			self::EXPIRED   => __( 'Expired', 'memberkin' ),
		);
	}

	/**
	 * Whether a transition is allowed.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 */
	public static function can_transition( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}

	/**
	 * Whether the subscription is still billing (or able to).
	 */
	public function is_live(): bool {
		return in_array( $this->status, array( self::TRIALING, self::ACTIVE, self::PAST_DUE, self::UNPAID, self::PAUSED ), true );
	}

	/**
	 * Amount of the next renewal (before tax).
	 */
	public function renewal_amount(): Money {
		return Money::of( $this->recurring_amount, $this->currency );
	}

	/**
	 * Whether the renewal limit was reached.
	 */
	public function is_final_cycle_reached(): bool {
		return $this->billing_cycles > 0 && $this->cycles_completed >= $this->billing_cycles;
	}

	/**
	 * JSON form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		$data = get_object_vars( $this );
		foreach ( $data as $key => $value ) {
			if ( $value instanceof \DateTimeImmutable ) {
				$data[ $key ] = $value->format( DATE_ATOM );
			}
		}
		return $data;
	}
}
