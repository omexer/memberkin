<?php
/**
 * Subscription business logic — the only write path for subscriptions.
 *
 * @package Memberkin
 */

namespace Memberkin\Service;

use Memberkin\Core\Clock;
use Memberkin\Core\Events;
use Memberkin\Core\Jobs;
use Memberkin\Core\Lock;
use Memberkin\Core\Logger;
use Memberkin\Core\Settings;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Membership;
use Memberkin\Domain\Order;
use Memberkin\Domain\Period;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\Contracts\Capability;
use Memberkin\Gateway\Contracts\UnsupportedOperation;
use Memberkin\Gateway\GatewayRegistry;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\MembershipRepository;
use Memberkin\Repository\SubscriptionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks: memberkin_subscription_status_changed( Subscription, from, to, context ), memberkin_subscription_{status}( Subscription, context ),
 * memberkin_subscription_renewed( Subscription, Order ), memberkin_subscription_payment_failed( Subscription, ?Order, string $error, bool $final ).
 */
class SubscriptionService {

	/**
	 * Constructor.
	 *
	 * @param SubscriptionRepository $subscriptions   Subscriptions.
	 * @param MembershipService      $memberships     Membership service.
	 * @param MembershipRepository   $membership_repo Membership repository.
	 * @param GatewayRegistry        $gateways        Gateways.
	 * @param LevelRepository        $levels          Levels.
	 */
	public function __construct(
		private SubscriptionRepository $subscriptions,
		private MembershipService $memberships,
		private MembershipRepository $membership_repo,
		private GatewayRegistry $gateways,
		private LevelRepository $levels
	) {}

	/**
	 * Loads a subscription or fails.
	 *
	 * @param int $id Id.
	 * @throws ValidationException When missing.
	 */
	public function require( int $id ): Subscription {
		$sub = $this->subscriptions->find( $id );
		if ( ! $sub ) {
			throw new ValidationException( array( esc_html__( 'Subscription not found.', 'memberkin' ) ) );
		}
		return $sub;
	}

	/**
	 * Stores a new (pending) subscription.
	 *
	 * @param Subscription $subscription Draft (id 0).
	 */
	public function create( Subscription $subscription ): Subscription {
		$this->subscriptions->insert( $subscription );
		Events::record(
			'subscription.created',
			$subscription->user_id,
			'subscription',
			$subscription->id,
			array(
				'gateway' => $subscription->gateway,
				'amount'  => $subscription->recurring_amount,
			)
		);
		return $subscription;
	}

	/**
	 * Updates non-status fields.
	 *
	 * @param int   $id     Id.
	 * @param array $fields Columns.
	 */
	public function update( int $id, array $fields ): Subscription {
		unset( $fields['status'] );
		$this->subscriptions->update( $id, $fields );
		return $this->require( $id );
	}

	/**
	 * Status transition with hooks.
	 *
	 * @param int    $id      Id.
	 * @param string $to      Status.
	 * @param array  $fields  Extra columns.
	 * @param array  $context Context.
	 * @throws ValidationException On invalid transition.
	 */
	public function transition( int $id, string $to, array $fields = array(), array $context = array() ): Subscription {
		$sub  = $this->require( $id );
		$from = $sub->status;
		if ( $from === $to ) {
			if ( $fields ) {
				$this->subscriptions->update( $id, $fields );
			}
			return $this->require( $id );
		}
		if ( ! Subscription::can_transition( $from, $to ) ) {
			throw new ValidationException(
				array(
					sprintf(
						/* translators: 1: status, 2: status. */
						esc_html__( 'A subscription cannot go from "%1$s" to "%2$s".', 'memberkin' ),
						esc_html( Subscription::statuses()[ $from ] ?? $from ),
						esc_html( Subscription::statuses()[ $to ] ?? $to )
					),
				)
			);
		}
		$fields['status'] = $to;
		if ( in_array( $to, array( Subscription::CANCELLED, Subscription::EXPIRED, Subscription::COMPLETED ), true ) && ! isset( $fields['ended_at'] ) ) {
			$fields['ended_at'] = Clock::now(); // When it really ended (cancelled_at is when a cancellation was requested).
		}
		if ( ! $this->subscriptions->update( $id, $fields, $from ) ) {
			$fresh = $this->require( $id );
			if ( $fresh->status === $to ) {
				return $fresh;
			}
			throw new ValidationException( array( esc_html__( 'The subscription was changed by another process. Please try again.', 'memberkin' ) ) );
		}
		$sub = $this->require( $id );
		Events::record( 'subscription.' . $to, $sub->user_id, 'subscription', $sub->id, array_merge( array( 'from' => $from ), array_intersect_key( $context, array_flip( array( 'reason', 'actor', 'error' ) ) ) ) );
		/**
		 * Fires when a subscription's status changed.
		 *
		 * @param Subscription $sub     Subscription.
		 * @param string       $from    Previous status.
		 * @param string       $to      New status.
		 * @param array        $context Context.
		 */
		do_action( 'memberkin_subscription_status_changed', $sub, $from, $to, $context );
		/**
		 * Fires when a subscription reached a status, e.g. memberkin_subscription_cancelled.
		 *
		 * @param Subscription $sub     Subscription.
		 * @param array        $context Context.
		 */
		do_action( 'memberkin_subscription_' . $to, $sub, $context );
		return $sub;
	}

	/**
	 * Activates a pending subscription after the first payment (or setup) succeeded.
	 *
	 * @param int                $id              Id.
	 * @param \DateTimeImmutable $start           Period start (checkout time).
	 * @param \DateTimeImmutable $next_payment_at First renewal.
	 * @param bool               $trial           Whether the member is in a trial until $next_payment_at.
	 * @param array              $fields          Extra (gateway ids, payment method).
	 */
	public function activate( int $id, \DateTimeImmutable $start, \DateTimeImmutable $next_payment_at, bool $trial, array $fields = array() ): Subscription {
		$fields = array_merge(
			$fields,
			array(
				'current_period_start' => $start,
				'current_period_end'   => $next_payment_at,
				'next_payment_at'      => $next_payment_at,
				'trial_ends_at'        => $trial ? $next_payment_at : null,
			)
		);
		$sub    = $this->require( $id );
		if ( Subscription::PENDING !== $sub->status ) {
			return $this->update( $id, $fields );
		}
		return $this->transition( $id, $trial ? Subscription::TRIALING : Subscription::ACTIVE, $fields, array( 'reason' => 'checkout' ) );
	}

	/**
	 * Records a successful renewal payment and advances the period. Idempotent per order.
	 *
	 * @param int                     $id         Subscription.
	 * @param Order                   $order      Paid renewal order.
	 * @param \DateTimeImmutable|null $period_end Period end reported by the gateway (computed when null).
	 */
	public function record_renewal( int $id, Order $order, ?\DateTimeImmutable $period_end = null ): Subscription {
		return Lock::with(
			'subscription_' . $id,
			function () use ( $id, $order, $period_end ): Subscription {
				$sub = $this->require( $id );
				if ( Events::exists( 'subscription.renewal_recorded', 'order', $order->id ) ) {
					return $sub;
				}
				$start = $sub->current_period_end && $sub->current_period_end <= Clock::now()->modify( '+3 days' ) ? $sub->current_period_end : Clock::now();
				$end   = $period_end ?? Period::add( $start, $sub->billing_interval, $sub->billing_period );

				$cycles    = $sub->cycles_completed + 1;
				$remaining = null === $sub->coupon_cycles_remaining ? null : max( 0, $sub->coupon_cycles_remaining - 1 );
				$fields    = array(
					'cycles_completed'        => $cycles,
					'coupon_cycles_remaining' => $remaining,
					'current_period_start'    => $start,
					'current_period_end'      => $end,
					'next_payment_at'         => $end,
					'retry_count'             => 0,
					'next_retry_at'           => null,
					'last_payment_error'      => null,
					'trial_ends_at'           => null,
				);
				/**
				 * Filters the recurring amount after a renewal (e.g. a coupon's discounted cycles ended).
				 *
				 * @param int          $amount    New recurring amount.
				 * @param Subscription $sub       Subscription before the renewal.
				 * @param int|null     $remaining Discounted cycles left.
				 */
				$fields['recurring_amount'] = (int) apply_filters( 'memberkin_subscription_next_amount', $this->next_amount( $sub, $remaining ), $sub, $remaining );

				Events::record( 'subscription.renewal_recorded', $sub->user_id, 'order', $order->id, array( 'subscription_id' => $sub->id ) );

				$final = $sub->billing_cycles > 0 && $cycles >= $sub->billing_cycles;
				if ( $final ) {
					$fields['next_payment_at'] = null;
				}
				$to  = Subscription::ACTIVE;
				$sub = $this->transition( $id, $to, $fields, array( 'reason' => 'renewal' ) );

				// Membership: clear past due; keep open-ended while billing continues.
				if ( $sub->membership_id ) {
					$m = $this->membership_repo->find( $sub->membership_id );
					if ( $m && Membership::PAST_DUE === $m->status ) {
						$this->memberships->clear_past_due( $m->id, array( 'reason' => 'payment' ) );
					} elseif ( $m && Membership::SUSPENDED === $m->status && $m->suspended_at && 'payment' === ( $this->suspension_reason( $m ) ) ) {
						$this->memberships->unsuspend( $m->id );
					}
				}
				if ( $final ) {
					$sub = $this->complete( $sub->id );
				}

				/**
				 * Fires after a renewal payment was recorded.
				 *
				 * @param Subscription $sub   Subscription.
				 * @param Order        $order Order.
				 */
				do_action( 'memberkin_subscription_renewed', $sub, $order );
				return $sub;
			}
		);
	}

	/**
	 * Recurring amount after a renewal: the level's regular price once discounted cycles end.
	 *
	 * @param Subscription $sub       Subscription.
	 * @param int|null     $remaining Discounted cycles left after this renewal.
	 */
	private function next_amount( Subscription $sub, ?int $remaining ): int {
		// "Cycle" trials: the first N payments cost the trial price.
		$trial_left = (int) get_metadata( 'memberkin_subscription', $sub->id, 'trial_cycles_left', true );
		if ( $trial_left > 0 ) {
			update_metadata( 'memberkin_subscription', $sub->id, 'trial_cycles_left', $trial_left - 1 );
			if ( $trial_left - 1 > 0 ) {
				return $sub->recurring_amount;
			}
		}
		if ( 0 !== $remaining && $trial_left <= 0 ) {
			return $sub->recurring_amount;
		}
		$m = $sub->membership_id ? $this->membership_repo->find( $sub->membership_id ) : null;
		return $m ? $m->schedule->recurring_amount : $sub->recurring_amount;
	}

	/**
	 * Why a membership was suspended (stored on the suspension event).
	 *
	 * @param Membership $m Membership.
	 */
	private function suspension_reason( Membership $m ): string {
		foreach ( Events::for_user( $m->user_id, 20 ) as $event ) {
			if ( 'membership.suspended' === $event->event && (int) $event->object_id === $m->id ) {
				return (string) ( $event->data['note'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * Billing cycle limit reached.
	 *
	 * @param int $id Id.
	 */
	public function complete( int $id ): Subscription {
		$sub = $this->transition( $id, Subscription::COMPLETED, array( 'next_payment_at' => null ), array( 'reason' => 'cycles_completed' ) );
		if ( $sub->membership_id ) {
			$m     = $this->membership_repo->find( $sub->membership_id );
			$level = $this->levels->find( $sub->level_id );
			if ( $m && $m->is_current() && $level && 'after_cycles' === $level->schedule->expiration_type ) {
				$this->memberships->cancel( $m->id, $sub->current_period_end, array( 'reason' => 'cycles_completed' ) );
			}
			// Otherwise access continues without an end date (paid in full).
		}
		return $sub;
	}

	/**
	 * Records a failed renewal and starts or continues dunning.
	 *
	 * @param int        $id    Subscription.
	 * @param string     $error Reason.
	 * @param Order|null $order Failed renewal order.
	 */
	public function record_failure( int $id, string $error, ?Order $order = null ): Subscription {
		return Lock::with(
			'subscription_' . $id,
			function () use ( $id, $error, $order ): Subscription {
				$sub      = $this->require( $id );
				$attempt  = $sub->retry_count + 1;
				$schedule = array_values( array_map( 'intval', (array) Settings::get( 'billing', 'retry_schedule', array( 1, 3, 5, 7 ) ) ) );
				$final    = 'site' === $sub->billing_model && $attempt > count( $schedule );

				$fields = array(
					'retry_count'        => $attempt,
					'last_payment_error' => mb_substr( $error, 0, 255 ),
					'next_retry_at'      => ( 'site' === $sub->billing_model && ! $final ) ? Clock::now()->modify( '+' . max( 1, $schedule[ $attempt - 1 ] ?? 1 ) . ' days' ) : null,
				);

				if ( in_array( $sub->status, array( Subscription::ACTIVE, Subscription::TRIALING ), true ) ) {
					$sub = $this->transition(
						$id,
						Subscription::PAST_DUE,
						$fields,
						array(
							'reason' => 'payment_failed',
							'error'  => $error,
						)
					);
				} else {
					$sub = $this->update( $id, $fields );
				}

				// Membership keeps access during the grace period.
				if ( $sub->membership_id ) {
					$m = $this->membership_repo->find( $sub->membership_id );
					if ( $m && in_array( $m->status, array( Membership::ACTIVE, Membership::CANCELLING ), true ) ) {
						$grace = Clock::now()->modify( '+' . max( 0, (int) Settings::get( 'billing', 'grace_days', 3 ) ) . ' days' );
						if ( $sub->next_retry_at && 'site' === $sub->billing_model ) {
							// Keep access at least until the last scheduled retry.
							$last  = Clock::now()->modify( '+' . array_sum( array_slice( $schedule, $attempt - 1 ) ) . ' days' );
							$grace = max( $grace, $last );
						}
						$this->memberships->mark_past_due( $m->id, $grace, array( 'reason' => 'payment_failed' ) );
					}
				}

				if ( $final ) {
					$sub = $this->give_up( $sub );
				}
				/**
				 * Fires after a renewal payment failed.
				 *
				 * @param Subscription $sub   Subscription.
				 * @param Order|null   $order Failed order.
				 * @param string       $error Reason.
				 * @param bool         $final Whether no more retries will happen.
				 */
				do_action( 'memberkin_subscription_payment_failed', $sub, $order, $error, $final );

				return $sub;
			}
		);
	}

	/**
	 * After the last retry: cancel (default) or mark unpaid and suspend access.
	 *
	 * @param Subscription $sub Subscription.
	 */
	public function give_up( Subscription $sub ): Subscription {
		if ( 'suspend' === Settings::get( 'billing', 'after_final_failure', 'cancel' ) ) {
			$sub = $this->transition( $sub->id, Subscription::UNPAID, array( 'next_retry_at' => null ), array( 'reason' => 'payment_failed' ) );
			if ( $sub->membership_id ) {
				$m = $this->membership_repo->find( $sub->membership_id );
				if ( $m && Membership::can_transition( $m->status, Membership::SUSPENDED ) ) {
					$this->memberships->suspend( $m->id, 'payment' );
				}
			}
			return $sub;
		}
		$sub = $this->transition(
			$sub->id,
			Subscription::CANCELLED,
			array(
				'cancelled_at'    => Clock::now(),
				'cancel_reason'   => 'payment_failed',
				'next_payment_at' => null,
				'next_retry_at'   => null,
			),
			array( 'reason' => 'payment_failed' )
		);
		if ( $sub->membership_id ) {
			$m = $this->membership_repo->find( $sub->membership_id );
			if ( $m && $m->is_current() ) {
				$this->memberships->end( $m->id, 'failed_payment' );
			}
		}
		return $sub;
	}

	/**
	 * Cancels a subscription.
	 *
	 * @param int    $id            Subscription.
	 * @param bool   $at_period_end Keep access until the paid period ends.
	 * @param string $reason        Reason (member, admin, gateway, payment_failed, refund …).
	 * @param bool   $sync_gateway  Tell the gateway (false when the gateway told us).
	 * @throws ValidationException When the gateway refuses.
	 */
	public function cancel( int $id, bool $at_period_end = true, string $reason = 'member', bool $sync_gateway = true ): Subscription {
		$sub = $this->require( $id );
		if ( ! $sub->is_live() && Subscription::PENDING !== $sub->status ) {
			return $sub;
		}
		$period_end = $sub->current_period_end && $sub->current_period_end > Clock::now() ? $sub->current_period_end : null;
		if ( Subscription::PENDING === $sub->status || in_array( $sub->status, array( Subscription::PAST_DUE, Subscription::UNPAID ), true ) ) {
			$at_period_end = false; // Nothing paid ahead.
		}
		$at_period_end = $at_period_end && null !== $period_end;

		if ( $sync_gateway && 'gateway' === $sub->billing_model && $sub->gateway_subscription_id ) {
			$gateway = $this->gateways->get( $sub->gateway );
			if ( $gateway ) {
				try {
					$result = $gateway->cancel_subscription( $sub, $at_period_end );
					if ( ! $result->ok ) {
						throw new ValidationException( array( '' !== esc_html( $result->message ) ? esc_html( $result->message ) : esc_html__( 'The payment gateway could not cancel the subscription.', 'memberkin' ) ) );
					}
				} catch ( UnsupportedOperation $e ) {
					Logger::warning( 'subscriptions', 'Gateway cannot cancel; cancelled locally only.', array( 'subscription' => $id ) );
				}
			}
		}

		if ( $at_period_end ) {
			$this->subscriptions->update(
				$id,
				array(
					'cancel_at_period_end' => 1,
					'cancel_reason'        => $reason,
					'cancelled_at'         => Clock::now(),
				)
			);
			Events::record( 'subscription.cancel_scheduled', $sub->user_id, 'subscription', $id, array( 'reason' => $reason ) );
			if ( $sub->membership_id ) {
				$m = $this->membership_repo->find( $sub->membership_id );
				if ( $m && $m->is_current() ) {
					$this->memberships->cancel( $m->id, $period_end, array( 'reason' => $reason ) );
				}
			}
			$sub = $this->require( $id );
			/**
			 * Fires when a cancellation at period end was scheduled.
			 *
			 * @param Subscription $sub    Subscription.
			 * @param string       $reason Reason.
			 */
			do_action( 'memberkin_subscription_cancel_scheduled', $sub, $reason );
			return $sub;
		}

		$sub = $this->transition(
			$id,
			Subscription::CANCELLED,
			array(
				'cancelled_at'    => Clock::now(),
				'cancel_reason'   => $reason,
				'next_payment_at' => null,
				'next_retry_at'   => null,
			),
			array( 'reason' => $reason )
		);
		if ( $sub->membership_id ) {
			$m = $this->membership_repo->find( $sub->membership_id );
			if ( $m && $m->is_current() ) {
				$this->memberships->end( $m->id, 'refund' === $reason ? 'refunded' : 'cancelled', array( 'reason' => $reason ) );
			}
		}
		return $sub;
	}

	/**
	 * Undoes a scheduled cancellation.
	 *
	 * @param int  $id           Subscription.
	 * @param bool $sync_gateway Tell the gateway.
	 * @throws ValidationException When not possible.
	 */
	public function reactivate( int $id, bool $sync_gateway = true ): Subscription {
		$sub = $this->require( $id );
		if ( ! $sub->cancel_at_period_end || ! $sub->is_live() ) {
			throw new ValidationException( array( esc_html__( 'This subscription has no scheduled cancellation.', 'memberkin' ) ) );
		}
		if ( $sync_gateway && 'gateway' === $sub->billing_model && $sub->gateway_subscription_id ) {
			$gateway = $this->gateways->get( $sub->gateway );
			$result  = $gateway ? $gateway->resume_subscription( $sub ) : null;
			if ( $result && ! $result->ok ) {
				throw new ValidationException( array( esc_html( $result->message ) ) );
			}
		}
		$this->subscriptions->update(
			$id,
			array(
				'cancel_at_period_end' => 0,
				'cancel_reason'        => null,
				'cancelled_at'         => null,
			)
		);
		if ( $sub->membership_id ) {
			$m = $this->membership_repo->find( $sub->membership_id );
			if ( $m && Membership::CANCELLING === $m->status ) {
				$this->memberships->reactivate( $m->id, null, array( 'reason' => 'reactivated' ) );
			}
		}
		Events::record( 'subscription.reactivated', $sub->user_id, 'subscription', $id );
		return $this->require( $id );
	}

	/**
	 * Pauses billing and access.
	 *
	 * @param int                     $id           Subscription.
	 * @param \DateTimeImmutable|null $resume_at    Automatic resume.
	 * @param bool                    $sync_gateway Tell the gateway.
	 * @throws ValidationException When the gateway cannot pause.
	 */
	public function pause( int $id, ?\DateTimeImmutable $resume_at = null, bool $sync_gateway = true ): Subscription {
		$sub = $this->require( $id );
		if ( $sync_gateway ) {
			$gateway = $this->gateways->get( $sub->gateway );
			if ( 'gateway' === $sub->billing_model ) {
				if ( ! $gateway || ! $gateway->supports( Capability::PAUSE ) ) {
					throw new ValidationException( array( esc_html__( 'This payment method does not support pausing.', 'memberkin' ) ) );
				}
				$result = $gateway->pause_subscription( $sub, $resume_at );
				if ( ! $result->ok ) {
					throw new ValidationException( array( esc_html( $result->message ) ) );
				}
			}
		}
		$sub = $this->transition(
			$id,
			Subscription::PAUSED,
			array(
				'paused_at' => Clock::now(),
				'resume_at' => $resume_at,
			),
			array( 'reason' => 'pause' )
		);
		if ( $sub->membership_id ) {
			$m = $this->membership_repo->find( $sub->membership_id );
			if ( $m && Membership::can_transition( $m->status, Membership::PAUSED ) ) {
				$this->memberships->pause( $m->id, $resume_at );
			}
		}
		return $sub;
	}

	/**
	 * Resumes billing and access. The next payment is pushed back by the pause length.
	 *
	 * @param int  $id           Subscription.
	 * @param bool $sync_gateway Tell the gateway.
	 * @throws ValidationException When the gateway refuses.
	 */
	public function resume( int $id, bool $sync_gateway = true ): Subscription {
		$sub = $this->require( $id );
		if ( Subscription::PAUSED !== $sub->status ) {
			return $sub;
		}
		if ( $sync_gateway && 'gateway' === $sub->billing_model ) {
			$gateway = $this->gateways->get( $sub->gateway );
			$result  = $gateway ? $gateway->resume_subscription( $sub ) : null;
			if ( $result && ! $result->ok ) {
				throw new ValidationException( array( esc_html( $result->message ) ) );
			}
		}
		$shift  = $sub->paused_at ? max( 0, Clock::timestamp() - $sub->paused_at->getTimestamp() ) : 0;
		$fields = array(
			'paused_at' => null,
			'resume_at' => null,
		);
		if ( $sub->next_payment_at ) {
			$next                         = $sub->next_payment_at->modify( '+' . $shift . ' seconds' );
			$fields['next_payment_at']    = $next < Clock::now() ? Clock::now() : $next;
			$fields['current_period_end'] = $fields['next_payment_at'];
		}
		$sub = $this->transition( $id, Subscription::ACTIVE, $fields, array( 'reason' => 'resume' ) );
		if ( $sub->membership_id ) {
			$m = $this->membership_repo->find( $sub->membership_id );
			if ( $m && Membership::PAUSED === $m->status ) {
				$this->memberships->resume( $m->id );
			}
		}
		return $sub;
	}

	/**
	 * Applies due scheduled changes: cancellations at period end and automatic resumes.
	 *
	 * @param int $limit Batch.
	 */
	public function process_scheduled_changes( int $limit = 100 ): int {
		$count = 0;
		foreach ( $this->subscriptions->due_for_scheduled_change( Clock::now(), $limit ) as $sub ) {
			try {
				if ( Subscription::PAUSED === $sub->status && $sub->resume_at && $sub->resume_at <= Clock::now() && ! $sub->cancel_at_period_end ) {
					$this->resume( $sub->id );
				} else {
					$this->transition(
						$sub->id,
						Subscription::CANCELLED,
						array(
							'next_payment_at' => null,
							'next_retry_at'   => null,
						),
						array( 'reason' => null !== $sub->cancel_reason && '' !== $sub->cancel_reason ? $sub->cancel_reason : 'scheduled' )
					);
				}
				++$count;
			} catch ( \Throwable $e ) {
				Logger::error( 'subscriptions', 'Scheduled change failed: ' . $e->getMessage(), array( 'subscription' => $sub->id ) );
			}
		}
		return $count;
	}

	/**
	 * Schedules site-managed renewal jobs for due subscriptions (called by the recurring job).
	 *
	 * @param int $limit Batch.
	 */
	public function queue_due_renewals( int $limit = 100 ): int {
		$now   = Clock::now();
		$count = 0;
		foreach ( $this->subscriptions->due_for_renewal( $now, $limit ) as $sub ) {
			Jobs::async( 'memberkin_renewal_charge', array( $sub->id, $sub->next_payment_at?->format( 'Y-m-d' ) ?? '' ), true );
			++$count;
		}
		foreach ( $this->subscriptions->due_for_retry( $now, $limit ) as $sub ) {
			Jobs::async( 'memberkin_renewal_charge', array( $sub->id, 'retry-' . $sub->retry_count ), true );
			++$count;
		}
		return $count;
	}
}
