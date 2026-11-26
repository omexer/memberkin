<?php
/**
 * Membership business logic — the only write path for memberships.
 *
 * @package Memberkin
 */

namespace Memberkin\Service;

use Memberkin\Core\Clock;
use Memberkin\Core\Events;
use Memberkin\Core\Lock;
use Memberkin\Core\Logger;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Membership;
use Memberkin\Domain\PriceSchedule;
use Memberkin\Repository\LevelGroupRepository;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\MembershipRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Grants, changes and ends memberships, enforcing the state machine and level-group rules.
 *
 * Hooks fired:
 * - memberkin_membership_created( Membership $m )
 * - memberkin_membership_status_changed( Membership $m, string $from, string $to, array $context )
 * - memberkin_membership_{status}( Membership $m, array $context )   e.g. memberkin_membership_active, memberkin_membership_ended
 */
class MembershipService {

	/**
	 * Constructor.
	 *
	 * @param MembershipRepository $memberships Memberships.
	 * @param LevelRepository      $levels      Levels.
	 * @param LevelGroupRepository $groups      Groups.
	 */
	public function __construct(
		private MembershipRepository $memberships,
		private LevelRepository $levels,
		private LevelGroupRepository $groups
	) {}

	/**
	 * Memberships that grant access right now.
	 *
	 * @param int $user_id User id.
	 * @return Membership[]
	 */
	public function active_for_user( int $user_id ): array {
		$now = Clock::now();
		return array_values(
			array_filter(
				$this->memberships->current_for_user( $user_id ),
				function ( Membership $m ) use ( $now ): bool {
					if ( ! $m->grants_access( $now ) ) {
						return false;
					}
					if ( $m->parent_membership_id ) {
						// Group seats follow the owner's membership (paused, suspended, past grace → no access).
						$parent = $this->memberships->find( $m->parent_membership_id );
						return $parent && $parent->grants_access( $now );
					}
					return true;
				}
			)
		);
	}

	/**
	 * Level ids the user currently has access through.
	 *
	 * @param int $user_id User id.
	 * @return int[]
	 */
	public function active_level_ids( int $user_id ): array {
		return array_values( array_unique( array_map( static fn( Membership $m ) => $m->level_id, $this->active_for_user( $user_id ) ) ) );
	}

	/**
	 * Whether the user has access through any of the given levels (ids or slugs); null = any level.
	 *
	 * @param int                               $user_id User id.
	 * @param int|string|array<int|string>|null $levels  Levels.
	 */
	public function user_has_level( int $user_id, int|string|array|null $levels = null ): bool {
		$active = $this->active_level_ids( $user_id );
		if ( null === $levels ) {
			return (bool) $active;
		}
		foreach ( (array) $levels as $level ) {
			$id = is_numeric( $level ) ? (int) $level : ( $this->levels->find_by_slug( (string) $level )->id ?? 0 );
			if ( $id && in_array( $id, $active, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The user's current (not ended) membership of a level, if any.
	 *
	 * @param int $user_id  User id.
	 * @param int $level_id Level id.
	 */
	public function current_for_level( int $user_id, int $level_id ): ?Membership {
		foreach ( $this->memberships->current_for_user( $user_id ) as $m ) {
			if ( $m->level_id === $level_id ) {
				return $m;
			}
		}
		return null;
	}

	/**
	 * Current memberships that conflict with joining a level (same single-selection group, or the same level).
	 *
	 * @param int $user_id  User id.
	 * @param int $level_id Level id.
	 * @return Membership[]
	 */
	public function conflicting( int $user_id, int $level_id ): array {
		$level = $this->levels->find( $level_id );
		if ( ! $level ) {
			return array();
		}
		$group     = $this->groups->find( $level->group_id );
		$conflicts = array();
		foreach ( $this->memberships->current_for_user( $user_id ) as $m ) {
			if ( Membership::PENDING === $m->status ) {
				continue; // Unpaid checkouts never block.
			}
			if ( $m->level_id === $level_id ) {
				$conflicts[] = $m;
				continue;
			}
			$other = $this->levels->find( $m->level_id );
			if ( $group && ! $group->allow_multiple && $other && $other->group_id === $level->group_id ) {
				$conflicts[] = $m;
			}
		}
		return $conflicts;
	}

	/**
	 * Grants a membership.
	 *
	 * @param int   $user_id  User id.
	 * @param int   $level_id Level id.
	 * @param array $args {
	 *     Arguments.
	 *
	 *     @type string                  $status               Initial status (default active).
	 *     @type string                  $source               checkout|admin|import|gift|group_seat|api|integration|fallback.
	 *     @type \DateTimeImmutable      $starts_at            Start (default now).
	 *     @type \DateTimeImmutable|null $ends_at              End; omitted = computed from the level's expiration settings.
	 *     @type PriceSchedule           $schedule             Price snapshot (default: level pricing).
	 *     @type int                     $subscription_id      Subscription.
	 *     @type int                     $initial_order_id     Order.
	 *     @type int                     $parent_membership_id Parent (seat).
	 *     @type int                     $coupon_id            Coupon.
	 *     @type bool                    $replace              End conflicting memberships ("changed") instead of failing (default true).
	 *     @type array                   $context              Passed to hooks (e.g. "notify" => false).
	 * }
	 * @throws ValidationException When the user/level is invalid or a conflict exists and replace is false.
	 */
	public function grant( int $user_id, int $level_id, array $args = array() ): Membership {
		if ( ! get_userdata( $user_id ) ) {
			throw new ValidationException( array( 'user_id' => esc_html__( 'User not found.', 'memberkin' ) ) );
		}
		$level = $this->levels->find( $level_id );
		if ( ! $level ) {
			throw new ValidationException( array( 'level_id' => esc_html__( 'Level not found.', 'memberkin' ) ) );
		}

		return Lock::with(
			'user_' . $user_id,
			function () use ( $user_id, $level, $args ): Membership {
				$status  = (string) ( $args['status'] ?? Membership::ACTIVE );
				$context = (array) ( $args['context'] ?? array() );
				if ( ! in_array( $status, array( Membership::ACTIVE, Membership::PENDING, Membership::PENDING_APPROVAL ), true ) ) {
					throw new ValidationException( array( 'status' => esc_html__( 'A new membership must start as active or pending.', 'memberkin' ) ) );
				}

				/**
				 * Filters the initial status of a granted membership (e.g. the Approvals module switches to pending_approval).
				 *
				 * @param string $status   Status.
				 * @param int    $user_id  User id.
				 * @param int    $level_id Level id.
				 * @param array  $args     Grant arguments.
				 */
				$status = (string) apply_filters( 'memberkin_grant_initial_status', $status, $user_id, $level->id, $args );

				$conflicts = Membership::ACTIVE === $status || Membership::PENDING_APPROVAL === $status ? $this->conflicting( $user_id, $level->id ) : array();
				if ( $conflicts && false === ( $args['replace'] ?? true ) ) {
					throw new ValidationException( array( 'level_id' => esc_html__( 'The member already has this level or another level of the same group.', 'memberkin' ) ) );
				}

				$starts   = $args['starts_at'] ?? Clock::now();
				$schedule = $args['schedule'] ?? $level->schedule;
				$ends     = array_key_exists( 'ends_at', $args ) ? $args['ends_at'] : $level->schedule->access_end( $starts );

				$m = new Membership(
					0,
					$user_id,
					$level->id,
					$status,
					(string) ( $args['source'] ?? 'admin' ),
					$schedule instanceof PriceSchedule ? $schedule : $level->schedule,
					$starts,
					$ends,
					isset( $args['subscription_id'] ) ? (int) $args['subscription_id'] : null,
					isset( $args['initial_order_id'] ) ? (int) $args['initial_order_id'] : null,
					isset( $args['parent_membership_id'] ) ? (int) $args['parent_membership_id'] : null,
					isset( $args['coupon_id'] ) ? (int) $args['coupon_id'] : null
				);

				// End conflicts first so group rules hold at every moment.
				foreach ( $conflicts as $old ) {
					$this->end(
						$old->id,
						'changed',
						array_merge(
							$context,
							array(
								'replaced_by_level' => $level->id,
								'skip_fallback'     => true,
							)
						)
					);
				}

				$this->memberships->insert( $m );
				Events::record(
					'membership.created',
					$user_id,
					'membership',
					$m->id,
					array(
						'level_id' => $level->id,
						'status'   => $status,
						'source'   => $m->source,
					)
				);

				/**
				 * Fires after a membership was created.
				 *
				 * @param Membership $m       Membership.
				 * @param array      $context Context.
				 */
				do_action( 'memberkin_membership_created', $m, $context );
				$this->fire_status_hooks( $m, '', $status, $context );
				return $m;
			}
		);
	}

	/**
	 * Moves a membership to a new status.
	 *
	 * @param int    $id      Membership id.
	 * @param string $to      Target status.
	 * @param array  $fields  Extra columns to set (DateTimeImmutable allowed).
	 * @param array  $context Hook context.
	 * @throws ValidationException On invalid transition or unknown membership.
	 */
	public function transition( int $id, string $to, array $fields = array(), array $context = array() ): Membership {
		$m = $this->memberships->find( $id );
		if ( ! $m ) {
			throw new ValidationException( array( esc_html__( 'Membership not found.', 'memberkin' ) ) );
		}
		$from = $m->status;
		if ( $from === $to && ! $fields ) {
			return $m;
		}
		if ( $from !== $to && ! Membership::can_transition( $from, $to ) ) {
			throw new ValidationException(
				array(
					sprintf(
						/* translators: 1: current status, 2: target status. */
						esc_html__( 'A membership cannot go from "%1$s" to "%2$s".', 'memberkin' ),
						esc_html( Membership::statuses()[ $from ] ?? $from ),
						esc_html( Membership::statuses()[ $to ] ?? $to )
					),
				)
			);
		}
		$fields['status'] = $to;
		if ( ! $this->memberships->update( $m, $fields, $from ) ) {
			// Someone else changed it in the meantime; reload and report.
			$fresh = $this->memberships->find( $id );
			if ( $fresh && $fresh->status === $to ) {
				return $fresh;
			}
			throw new ValidationException( array( esc_html__( 'The membership was changed by another process. Please try again.', 'memberkin' ) ) );
		}
		$m = $this->memberships->find( $id );
		if ( $from !== $to ) {
			Events::record( 'membership.' . $to, $m->user_id, 'membership', $m->id, array_merge( array( 'from' => $from ), array_intersect_key( $context, array_flip( array( 'reason', 'note', 'order_id', 'subscription_id' ) ) ) ) );
			$this->fire_status_hooks( $m, $from, $to, $context );
		}
		return $m;
	}

	/**
	 * Fires the status hooks.
	 *
	 * @param Membership $m       Membership.
	 * @param string     $from    From status ('' when created).
	 * @param string     $to      To status.
	 * @param array      $context Context.
	 */
	private function fire_status_hooks( Membership $m, string $from, string $to, array $context ): void {
		/**
		 * Fires when a membership's status changed.
		 *
		 * @param Membership $m       Membership.
		 * @param string     $from    Previous status ('' for a new membership).
		 * @param string     $to      New status.
		 * @param array      $context Context (reason, order_id, notify …).
		 */
		do_action( 'memberkin_membership_status_changed', $m, $from, $to, $context );
		/**
		 * Fires when a membership reached a status, e.g. memberkin_membership_active.
		 *
		 * @param Membership $m       Membership.
		 * @param array      $context Context.
		 */
		do_action( 'memberkin_membership_' . $to, $m, $context );
	}

	/**
	 * Activates a pending membership (or one awaiting approval).
	 *
	 * @param int   $id      Membership id.
	 * @param array $fields  Extra columns (e.g. starts_at, ends_at, subscription_id).
	 * @param array $context Context.
	 */
	public function activate( int $id, array $fields = array(), array $context = array() ): Membership {
		$m  = $this->memberships->find( $id );
		$to = Membership::ACTIVE;
		if ( $m && Membership::PENDING === $m->status ) {
			/**
			 * Filters the status a pending membership moves to when its checkout completes (the Approvals module
			 * returns pending_approval for levels that need a review).
			 *
			 * @param string     $to      active|pending_approval.
			 * @param Membership $m       Membership.
			 * @param array      $fields  Columns being set (starts_at, ends_at …).
			 * @param array      $context Context.
			 */
			$to = (string) apply_filters( 'memberkin_activation_status', Membership::ACTIVE, $m, $fields, $context );
			$to = Membership::PENDING_APPROVAL === $to ? $to : Membership::ACTIVE;
		}
		if ( $m && in_array( $m->status, array( Membership::PENDING, Membership::PENDING_APPROVAL ), true ) && empty( $context['keep_conflicts'] ) ) {
			// Enforce group rules at the moment of activation (the user may have joined something else meanwhile).
			// "keep_conflicts" is used for plan changes that start in the future (the old membership ends at that date).
			foreach ( $this->conflicting( $m->user_id, $m->level_id ) as $old ) {
				if ( $old->id !== $m->id ) {
					$this->end(
						$old->id,
						'changed',
						array_merge(
							$context,
							array(
								'replaced_by_level' => $m->level_id,
								'skip_fallback'     => true,
							)
						)
					);
				}
			}
		}
		return $this->transition( $id, $to, $fields, $context );
	}

	/**
	 * Ends a membership now.
	 *
	 * @param int    $id      Membership id.
	 * @param string $reason  One of Membership::end_reasons().
	 * @param array  $context Context; "skip_fallback" => true prevents granting the group's fallback level.
	 * @throws ValidationException When the membership does not exist or cannot change.
	 */
	public function end( int $id, string $reason = 'admin', array $context = array() ): Membership {
		$m = $this->memberships->find( $id );
		if ( ! $m ) {
			throw new ValidationException( array( esc_html__( 'Membership not found.', 'memberkin' ) ) );
		}
		if ( Membership::ENDED === $m->status ) {
			return $m;
		}
		$now   = Clock::now();
		$ended = $this->transition(
			$id,
			Membership::ENDED,
			array(
				'ended_at'   => $now,
				'end_reason' => $reason,
				'ends_at'    => null === $m->ends_at || $m->ends_at > $now ? $now : $m->ends_at,
			),
			array_merge( $context, array( 'reason' => $reason ) )
		);

		// Seats held through this membership end too.
		foreach ( $this->memberships->children_of( $id ) as $child ) {
			try {
				$this->end( $child->id, 'group_removed', array( 'parent_id' => $id ) );
			} catch ( ValidationException $e ) {
				Logger::warning( 'memberships', 'Could not end child membership: ' . $e->getMessage(), array( 'child' => $child->id ) );
			}
		}

		if ( empty( $context['skip_fallback'] ) && in_array( $reason, array( 'expired', 'cancelled', 'failed_payment', 'refunded' ), true ) ) {
			$this->maybe_grant_fallback( $ended );
		}
		return $ended;
	}

	/**
	 * Grants the group's fallback level when the member has nothing else in that group.
	 *
	 * @param Membership $ended Ended membership.
	 */
	private function maybe_grant_fallback( Membership $ended ): void {
		$level = $this->levels->find( $ended->level_id );
		$group = $level ? $this->groups->find( $level->group_id ) : null;
		if ( ! $group || ! $group->fallback_level_id || $group->fallback_level_id === $ended->level_id ) {
			return;
		}
		foreach ( $this->memberships->current_for_user( $ended->user_id ) as $m ) {
			$other = $this->levels->find( $m->level_id );
			if ( $other && $other->group_id === $group->id ) {
				return;
			}
		}
		try {
			$this->grant(
				$ended->user_id,
				$group->fallback_level_id,
				array(
					'source'   => 'fallback',
					'schedule' => new PriceSchedule( $ended->schedule->currency ),
					'context'  => array( 'fallback_from' => $ended->id ),
				)
			);
		} catch ( ValidationException $e ) {
			Logger::warning( 'memberships', 'Fallback level could not be granted: ' . $e->getMessage(), array( 'membership' => $ended->id ) );
		}
	}

	/**
	 * Cancels: keeps access until $ends_at (if given and in the future), otherwise ends now.
	 *
	 * @param int                     $id      Membership id.
	 * @param \DateTimeImmutable|null $ends_at End of the paid period.
	 * @param array                   $context Context.
	 * @throws ValidationException When the membership does not exist or cannot change.
	 */
	public function cancel( int $id, ?\DateTimeImmutable $ends_at = null, array $context = array() ): Membership {
		$m = $this->memberships->find( $id );
		if ( ! $m ) {
			throw new ValidationException( array( esc_html__( 'Membership not found.', 'memberkin' ) ) );
		}
		$ends_at = $ends_at ?? $m->ends_at;
		if ( null === $ends_at || $ends_at <= Clock::now() || ! Membership::status_grants_access( $m->status ) ) {
			return $this->end( $id, 'cancelled', $context );
		}
		return $this->transition( $id, Membership::CANCELLING, array( 'ends_at' => $ends_at ), array_merge( $context, array( 'reason' => 'cancelled' ) ) );
	}

	/**
	 * Undoes a scheduled cancellation.
	 *
	 * @param int                     $id      Membership id.
	 * @param \DateTimeImmutable|null $ends_at New end (null = open ended, e.g. subscription resumed).
	 * @param array                   $context Context.
	 */
	public function reactivate( int $id, ?\DateTimeImmutable $ends_at = null, array $context = array() ): Membership {
		return $this->transition( $id, Membership::ACTIVE, array( 'ends_at' => $ends_at ), $context );
	}

	/**
	 * Admin suspension (revokes access; record kept).
	 *
	 * @param int    $id   Membership id.
	 * @param string $note Reason note.
	 */
	public function suspend( int $id, string $note = '' ): Membership {
		return $this->transition( $id, Membership::SUSPENDED, array( 'suspended_at' => Clock::now() ), array( 'note' => $note ) );
	}

	/**
	 * Lifts a suspension.
	 *
	 * @param int $id Membership id.
	 */
	public function unsuspend( int $id ): Membership {
		return $this->transition( $id, Membership::ACTIVE, array( 'suspended_at' => null ) );
	}

	/**
	 * Pauses (no access while paused). A fixed end date is pushed back by the pause length on resume.
	 *
	 * @param int                     $id        Membership id.
	 * @param \DateTimeImmutable|null $resume_at Automatic resume time.
	 * @param array                   $context   Context.
	 */
	public function pause( int $id, ?\DateTimeImmutable $resume_at = null, array $context = array() ): Membership {
		return $this->transition(
			$id,
			Membership::PAUSED,
			array(
				'paused_at' => Clock::now(),
				'resume_at' => $resume_at,
			),
			$context
		);
	}

	/**
	 * Resumes a paused membership.
	 *
	 * @param int   $id      Membership id.
	 * @param array $context Context.
	 * @throws ValidationException When the membership does not exist or cannot change.
	 */
	public function resume( int $id, array $context = array() ): Membership {
		$m = $this->memberships->find( $id );
		if ( ! $m ) {
			throw new ValidationException( array( esc_html__( 'Membership not found.', 'memberkin' ) ) );
		}
		$fields = array(
			'paused_at' => null,
			'resume_at' => null,
		);
		if ( $m->ends_at && $m->paused_at ) {
			$paused_seconds    = max( 0, Clock::timestamp() - $m->paused_at->getTimestamp() );
			$fields['ends_at'] = $m->ends_at->modify( '+' . $paused_seconds . ' seconds' );
		}
		return $this->transition( $id, Membership::ACTIVE, $fields, $context );
	}

	/**
	 * Marks a membership past due with a grace period.
	 *
	 * @param int                $id          Membership id.
	 * @param \DateTimeImmutable $grace_until Access kept until.
	 * @param array              $context     Context.
	 */
	public function mark_past_due( int $id, \DateTimeImmutable $grace_until, array $context = array() ): Membership {
		return $this->transition( $id, Membership::PAST_DUE, array( 'grace_until' => $grace_until ), $context );
	}

	/**
	 * Back to active after a successful payment.
	 *
	 * @param int   $id      Membership id.
	 * @param array $context Context.
	 */
	public function clear_past_due( int $id, array $context = array() ): Membership {
		return $this->transition( $id, Membership::ACTIVE, array( 'grace_until' => null ), $context );
	}

	/**
	 * Admin override of start/end dates.
	 *
	 * @param int                     $id        Membership id.
	 * @param \DateTimeImmutable|null $ends_at   New end (null = no end).
	 * @param \DateTimeImmutable|null $starts_at New start (null = unchanged).
	 * @throws ValidationException When the membership has ended or dates are inconsistent.
	 */
	public function set_dates( int $id, ?\DateTimeImmutable $ends_at, ?\DateTimeImmutable $starts_at = null ): Membership {
		$m = $this->memberships->find( $id );
		if ( ! $m || ! $m->is_current() ) {
			throw new ValidationException( array( esc_html__( 'Only current memberships can be changed.', 'memberkin' ) ) );
		}
		$start = $starts_at ?? $m->starts_at;
		if ( $ends_at && $ends_at <= $start ) {
			throw new ValidationException( array( esc_html__( 'The end date must be after the start date.', 'memberkin' ) ) );
		}
		$fields = array( 'ends_at' => $ends_at );
		if ( $starts_at ) {
			$fields['starts_at'] = $starts_at;
		}
		$this->memberships->update( $m, $fields );
		Events::record(
			'membership.dates_changed',
			$m->user_id,
			'membership',
			$m->id,
			array(
				'ends_at'   => $ends_at?->format( DATE_ATOM ),
				'starts_at' => $starts_at?->format( DATE_ATOM ),
			)
		);
		$fresh = $this->memberships->find( $id );
		/**
		 * Fires after an admin changed membership dates.
		 *
		 * @param Membership $fresh Membership.
		 */
		do_action( 'memberkin_membership_dates_changed', $fresh );
		return $fresh;
	}

	/**
	 * Ends memberships whose end date passed or whose grace period ran out. Returns the number processed.
	 *
	 * @param int $limit Batch size.
	 */
	public function expire_due( int $limit = 200 ): int {
		$count = 0;
		foreach ( $this->memberships->due_for_expiry( Clock::now(), $limit ) as $m ) {
			try {
				$reason = Membership::PAST_DUE === $m->status ? 'failed_payment' : ( Membership::CANCELLING === $m->status ? 'cancelled' : 'expired' );
				$this->end( $m->id, $reason, array( 'automatic' => true ) );
				++$count;
			} catch ( \Throwable $e ) {
				Logger::error( 'memberships', 'Expiry failed: ' . $e->getMessage(), array( 'membership' => $m->id ) );
			}
		}
		return $count;
	}

	/**
	 * Resumes paused memberships whose resume date passed.
	 *
	 * @param int $limit Batch size.
	 */
	public function resume_due( int $limit = 200 ): int {
		$count = 0;
		foreach ( $this->memberships->due_for_resume( Clock::now(), $limit ) as $m ) {
			try {
				/**
				 * Lets billing resume the subscription first. Return false to skip resuming here.
				 *
				 * @param bool       $resume Whether to resume.
				 * @param Membership $m      Membership.
				 */
				if ( apply_filters( 'memberkin_auto_resume_membership', true, $m ) ) {
					$this->resume( $m->id, array( 'automatic' => true ) );
					++$count;
				}
			} catch ( \Throwable $e ) {
				Logger::error( 'memberships', 'Auto-resume failed: ' . $e->getMessage(), array( 'membership' => $m->id ) );
			}
		}
		return $count;
	}
}
