<?php
/**
 * Builds price quotes.
 *
 * @package Memberkin
 */

namespace Memberkin\Service;

use Memberkin\Core\Clock;
use Memberkin\Core\Money;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Level;
use Memberkin\Domain\Membership;
use Memberkin\Domain\PriceSchedule;
use Memberkin\Domain\Pricing\ProrationPlan;
use Memberkin\Domain\Pricing\Quote;
use Memberkin\Domain\Pricing\QuoteItem;
use Memberkin\Domain\Pricing\RecurringTerms;
use Memberkin\Domain\Pricing\TaxContext;
use Memberkin\Repository\LevelGroupRepository;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\MembershipRepository;
use Memberkin\Repository\OrderRepository;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\Tax\TaxCalculator;

defined( 'ABSPATH' ) || exit;

/**
 * The single place that decides what a member pays (ARCHITECTURE.md §6).
 */
class PricingService {

	/**
	 * Constructor.
	 *
	 * @param LevelRepository        $levels        Levels.
	 * @param LevelGroupRepository   $groups        Groups.
	 * @param MembershipService      $memberships   Memberships.
	 * @param MembershipRepository   $membership_repo Membership repository.
	 * @param SubscriptionRepository $subscriptions Subscriptions.
	 * @param OrderRepository        $orders        Orders.
	 * @param TaxCalculator          $tax           Tax calculator.
	 * @param CouponService|null     $coupons       Coupons (null when the module is off).
	 */
	public function __construct(
		private LevelRepository $levels,
		private LevelGroupRepository $groups,
		private MembershipService $memberships,
		private MembershipRepository $membership_repo,
		private SubscriptionRepository $subscriptions,
		private OrderRepository $orders,
		private TaxCalculator $tax,
		private ?CouponService $coupons = null
	) {}

	/**
	 * Builds a quote.
	 *
	 * @param array $args {
	 *     Arguments.
	 *
	 *     @type int[]  $level_ids   Levels to buy (first one is the main level; others are add-ons).
	 *     @type int    $user_id     Buyer (0 = new account).
	 *     @type string $coupon_code Coupon code entered by the member.
	 *     @type array  $tax         country, region, postcode, vat_number, ip_country.
	 *     @type bool   $admin       Skip purchasability checks (admin-created orders).
	 *     @type bool   $merchant_of_record The chosen gateway is the merchant of record and adds tax itself
	 *                                      itself: no tax is calculated here.
	 *     @type array  $custom_prices level_id => amount chosen by the member (decimal string; levels with variable pricing).
	 *     @type int[]  $one_time      Recurring levels the member wants to pay once, without automatic renewal
	 *                                 (levels that allow it): one billing period of access, no subscription.
	 * }
	 * @throws ValidationException With member-facing messages.
	 */
	public function quote( array $args ): Quote {
		$user_id   = (int) ( $args['user_id'] ?? 0 );
		$level_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $args['level_ids'] ?? array() ) ) ) ) );
		if ( ! $level_ids ) {
			throw new ValidationException( array( 'level' => esc_html__( 'Choose a membership level.', 'memberkin' ) ) );
		}
		$now = Clock::now();

		// Levels keyed by id.
		$levels = array();
		foreach ( $level_ids as $id ) {
			$level = $this->levels->find( $id );
			if ( ! $level || ( empty( $args['admin'] ) && ! $level->is_purchasable() ) ) {
				throw new ValidationException( array( 'level' => esc_html__( 'This membership level is not available.', 'memberkin' ) ) );
			}
			$levels[ $id ] = $level;
		}
		$currency = reset( $levels )->schedule->currency;
		foreach ( $levels as $level ) {
			if ( $level->schedule->currency !== $currency ) {
				throw new ValidationException( array( 'level' => esc_html__( 'These levels cannot be bought together because they use different currencies.', 'memberkin' ) ) );
			}
		}

		$quote      = new Quote( $currency, $user_id, $level_ids );
		$tax        = (array) ( $args['tax'] ?? array() );
		$quote->tax = new TaxContext(
			strtoupper( substr( sanitize_text_field( (string) ( $tax['country'] ?? '' ) ), 0, 2 ) ),
			sanitize_text_field( (string) ( $tax['region'] ?? '' ) ),
			sanitize_text_field( (string) ( $tax['postcode'] ?? '' ) ),
			strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $tax['vat_number'] ?? '' ) ) ),
			strtoupper( substr( sanitize_text_field( (string) ( $tax['ip_country'] ?? '' ) ), 0, 2 ) )
		);

		$coupon_code = trim( (string) ( $args['coupon_code'] ?? '' ) );
		$coupon_used = false;
		$coupon_err  = null;

		foreach ( $levels as $level ) {
			$schedule = $this->member_choices( $level, $level->schedule, $args, $quote );

			// Trial only once per member.
			if ( $user_id && $schedule->has_trial() && $this->levels->meta( $level->id, 'trial_once', true ) && $this->membership_repo->user_had_trial( $user_id, $level->id ) ) {
				$schedule         = $schedule->with( array( 'trial_length' => 0 ) );
				$quote->notices[] = sprintf(
					/* translators: %s: level name. */
					__( 'You already had a trial of %s, so billing starts today.', 'memberkin' ),
					$level->name
				);
			}

			// Plan change detection.
			$replaced = $user_id ? $this->replaced_membership( $user_id, $level ) : null;
			if ( $replaced && $replaced->level_id === $level->id ) {
				if ( $replaced->subscription_id || ! $this->levels->meta( $level->id, 'allow_renewal', true ) || null === $replaced->ends_at ) {
					throw new ValidationException( array( 'level' => esc_html__( 'You already have this membership.', 'memberkin' ) ) );
				}
				$replaced = null; // Early renewal of a fixed-term membership: extends at fulfilment, no proration.
			}

			// Coupons.
			$application = null;
			if ( $this->coupons ) {
				$coupon = null;
				if ( '' !== $coupon_code ) {
					try {
						$coupon = $this->coupons->validate( $coupon_code, $level->id, $user_id, null !== $replaced );
					} catch ( ValidationException $e ) {
						$coupon_err = $e;
					}
				} else {
					$coupon = $this->coupons->auto_coupon( $level, $user_id );
				}
				if ( $coupon ) {
					$application                              = $this->coupons->apply( $coupon, $level, $schedule );
					$schedule                                 = $application->schedule;
					$quote->coupon                            = $coupon;
					$quote->coupon_applications[ $level->id ] = $application;
					$coupon_used                              = true;
				}
			}
			$quote->schedules[ $level->id ] = $schedule;

			$first_net = $application ? $application->first_payment() : $schedule->first_payment();

			// Proration.
			$plan = null;
			if ( $replaced ) {
				$plan = $this->proration_plan( $replaced, $schedule, $first_net, $now );
				if ( $plan ) {
					$quote->proration[ $level->id ] = $plan;
				}
			}

			// Today's lines.
			if ( $plan && ProrationPlan::AT_PERIOD_END === $plan->mode ) {
				$quote->items[] = new QuoteItem(
					'level',
					sprintf(
						/* translators: 1: level name, 2: date. */
						__( '%1$s (starts %2$s)', 'memberkin' ),
						$level->name,
						wp_date( get_option( 'date_format' ), $plan->anchor->getTimestamp() )
					),
					0,
					$level->id
				);
			} else {
				$base = $schedule->has_trial() ? $schedule->trial_amount : $schedule->initial_amount;
				$desc = $schedule->has_trial() && 'cycle' !== $schedule->trial_period
					/* translators: %s: level name. */
					? sprintf( __( '%s — trial', 'memberkin' ), $level->name )
					: $level->name;
				$quote->items[] = new QuoteItem( 'level', $desc, $base, $level->id );
				if ( $schedule->signup_fee > 0 ) {
					$quote->items[] = new QuoteItem( 'signup_fee', __( 'Signup fee', 'memberkin' ), $schedule->signup_fee, $level->id );
				}
				if ( $application && $application->first_discount->is_positive() ) {
					$quote->items[] = new QuoteItem(
						'discount',
						/* translators: %s: coupon code. */
						sprintf( __( 'Coupon %s', 'memberkin' ), $application->coupon->code ),
						-$application->first_discount->amount,
						$level->id
					);
				}
				if ( $plan && $plan->credit->is_positive() ) {
					$quote->items[] = new QuoteItem(
						'proration_credit',
						ProrationPlan::KEEP_ANCHOR === $plan->mode ? __( 'Plan change adjustment', 'memberkin' ) : __( 'Credit for unused time', 'memberkin' ),
						-$plan->credit->amount,
						$level->id
					);
				}
			}

			// Recurring terms.
			if ( $schedule->is_recurring() ) {
				$quote->recurring[ $level->id ] = $this->recurring_terms( $level, $schedule, $application, $plan, $now );
			}
		}

		if ( '' !== $coupon_code && ! $coupon_used && $coupon_err ) {
			throw $coupon_err;
		}

		if ( empty( $args['merchant_of_record'] ) ) {
			$this->tax->apply( $quote );
		}

		/**
		 * Filters a finished quote.
		 *
		 * @param Quote $quote Quote.
		 * @param array $args  Arguments.
		 */
		return apply_filters( 'memberkin_quote', $quote, $args );
	}

	/**
	 * Applies the member's choices: their own price (variable pricing) and "do not renew automatically".
	 *
	 * @param Level         $level    Level.
	 * @param PriceSchedule $schedule Level schedule.
	 * @param array         $args     Quote arguments.
	 * @param Quote         $quote    Quote (for notices).
	 * @throws ValidationException When the chosen price is outside the allowed range.
	 */
	private function member_choices( Level $level, PriceSchedule $schedule, array $args, Quote $quote ): PriceSchedule {
		/**
		 * Filters a level's price schedule for one quote, before trials, coupons and tax are applied.
		 * Add-ons use it for member-chosen prices and one-time purchases of recurring levels
		 * (quote args "custom_prices" and "one_time"). Throw a ValidationException to reject the choice.
		 *
		 * @param PriceSchedule $schedule Schedule.
		 * @param Level         $level    Level.
		 * @param array         $args     Quote arguments.
		 * @param Quote         $quote    Quote being built (add notices to $quote->notices).
		 */
		$filtered = apply_filters( 'memberkin_quote_schedule', $schedule, $level, $args, $quote );
		return $filtered instanceof PriceSchedule ? $filtered : $schedule;
	}


	/**
	 * Recurring terms for a level.
	 *
	 * @param Level                                    $level       Level.
	 * @param PriceSchedule                            $schedule    Effective schedule.
	 * @param \Memberkin\Domain\CouponApplication|null $application Coupon application.
	 * @param ProrationPlan|null                       $plan        Proration plan.
	 * @param \DateTimeImmutable                       $now         Now.
	 */
	private function recurring_terms( Level $level, PriceSchedule $schedule, $application, ?ProrationPlan $plan, \DateTimeImmutable $now ): RecurringTerms {
		$first_renewal = $schedule->next_payment_after_start( $now );
		if ( $plan && in_array( $plan->mode, array( ProrationPlan::KEEP_ANCHOR, ProrationPlan::AT_PERIOD_END ), true ) && $plan->anchor ) {
			$first_renewal = $plan->anchor;
		}
		if ( $plan && $plan->extension_seconds > 0 ) {
			$first_renewal = $first_renewal->modify( '+' . $plan->extension_seconds . ' seconds' );
		}

		if ( $plan && ProrationPlan::AT_PERIOD_END === $plan->mode ) {
			// Nothing charged today: the first charge of the new plan happens at the change date.
			$first_amount = $application ? $application->first_payment() : $schedule->first_payment();
		} else {
			$first_amount = $application ? $application->recurring_payment( 1 ) : $schedule->recurring_payment_amount( 1 );
		}

		return new RecurringTerms(
			$level->id,
			$first_amount,
			$schedule->recurring(),
			$schedule->billing_interval,
			$schedule->billing_period,
			$first_renewal,
			$schedule->renewals_limit(),
			$application ? $application->recurring_cycles : null,
			0,
			'0',
			$schedule->has_trial() && 'cycle' === $schedule->trial_period ? max( 0, $schedule->trial_length - 1 ) : 0
		);
	}

	/**
	 * Current membership that buying $level would replace (same single-selection group, or the same level).
	 *
	 * @param int   $user_id User.
	 * @param Level $level   Level.
	 */
	public function replaced_membership( int $user_id, Level $level ): ?Membership {
		foreach ( $this->memberships->conflicting( $user_id, $level->id ) as $m ) {
			if ( $m->grants_access() ) {
				return $m;
			}
		}
		return null;
	}

	/**
	 * Proration plan for replacing $old with a new schedule.
	 *
	 * @param Membership         $old       Membership being replaced.
	 * @param PriceSchedule      $new_schedule       New schedule.
	 * @param Money              $new_first New first payment (net, after coupon).
	 * @param \DateTimeImmutable $now       Now.
	 */
	private function proration_plan( Membership $old, PriceSchedule $new_schedule, Money $new_first, \DateTimeImmutable $now ): ?ProrationPlan {
		$old_level = $this->levels->find( $old->level_id );
		$group     = $old_level ? $this->groups->find( $old_level->group_id ) : null;
		$mode      = $group->proration_mode ?? ProrationPlan::IMMEDIATE;
		$timing    = $group->downgrade_timing ?? 'period_end';

		[ $paid, $start, $end ] = $this->paid_period( $old );
		/**
		 * Filters the proration mode for a change.
		 *
		 * @param string     $mode Mode.
		 * @param Membership $old  Old membership.
		 * @param PriceSchedule $new_schedule New schedule.
		 */
		$mode = (string) apply_filters( 'memberkin_proration_mode', $mode, $old, $new_schedule );
		$plan = ProrationCalculator::plan( $mode, $timing, $old->id, $old->schedule, $new_schedule, $paid, $start, $end, $now, $new_first );
		/**
		 * Filters a proration plan.
		 *
		 * @param ProrationPlan $plan Plan.
		 * @param Membership    $old  Old membership.
		 */
		return apply_filters( 'memberkin_proration_plan', $plan, $old );
	}

	/**
	 * What was paid for the membership's current period: [ net amount, period start, period end ].
	 *
	 * @param Membership $m Membership.
	 * @return array{0: Money, 1: \DateTimeImmutable, 2: ?\DateTimeImmutable}
	 */
	public function paid_period( Membership $m ): array {
		$currency = $m->schedule->currency;
		if ( $m->subscription_id ) {
			$sub = $this->subscriptions->find( $m->subscription_id );
			if ( $sub && $sub->current_period_start && $sub->current_period_end ) {
				foreach ( $this->orders->for_subscription( $sub->id ) as $order ) {
					if ( $order->is_paid() ) {
						$net = max( 0, $order->total - $order->tax_total - $order->amount_refunded );
						return array( Money::of( $net, $order->currency ), $sub->current_period_start, $sub->current_period_end );
					}
				}
				return array( Money::zero( $currency ), $sub->current_period_start, $sub->current_period_end );
			}
		}
		if ( $m->initial_order_id ) {
			$order = $this->orders->find( $m->initial_order_id );
			if ( $order && $order->is_paid() ) {
				$net = max( 0, $order->total - $order->tax_total - $order->amount_refunded );
				return array( Money::of( $net, $order->currency ), $m->starts_at, $m->ends_at );
			}
		}
		return array( Money::zero( $currency ), $m->starts_at, $m->ends_at );
	}
}
