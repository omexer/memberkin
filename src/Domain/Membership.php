<?php
/**
 * Membership entity and state machine.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain;

use Memberkin\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * A user's membership of one level. The single source of truth for access (ARCHITECTURE.md §4.2, §5.1).
 */
final class Membership implements \JsonSerializable {

	public const PENDING          = 'pending';
	public const PENDING_APPROVAL = 'pending_approval';
	public const ACTIVE           = 'active';
	public const CANCELLING       = 'cancelling';
	public const PAST_DUE         = 'past_due';
	public const PAUSED           = 'paused';
	public const SUSPENDED        = 'suspended';
	public const ENDED            = 'ended';

	/**
	 * Allowed transitions: from => [to…].
	 */
	public const TRANSITIONS = array(
		self::PENDING          => array( self::ACTIVE, self::PENDING_APPROVAL, self::ENDED ),
		self::PENDING_APPROVAL => array( self::ACTIVE, self::ENDED ),
		self::ACTIVE           => array( self::CANCELLING, self::PAST_DUE, self::PAUSED, self::SUSPENDED, self::ENDED ),
		self::CANCELLING       => array( self::ACTIVE, self::PAST_DUE, self::SUSPENDED, self::ENDED ),
		self::PAST_DUE         => array( self::ACTIVE, self::CANCELLING, self::SUSPENDED, self::ENDED ),
		self::PAUSED           => array( self::ACTIVE, self::SUSPENDED, self::ENDED ),
		self::SUSPENDED        => array( self::ACTIVE, self::ENDED ),
		self::ENDED            => array(),
	);

	/**
	 * Statuses that are "current" (the membership has not ended).
	 */
	public const CURRENT_STATUSES = array( self::PENDING, self::PENDING_APPROVAL, self::ACTIVE, self::CANCELLING, self::PAST_DUE, self::PAUSED, self::SUSPENDED );

	/**
	 * Constructor.
	 *
	 * @param int                     $id                   Id.
	 * @param int                     $user_id              User.
	 * @param int                     $level_id             Level.
	 * @param string                  $status               Status.
	 * @param string                  $source               checkout|admin|import|gift|group_seat|api|integration.
	 * @param PriceSchedule           $schedule             Price snapshot.
	 * @param \DateTimeImmutable      $starts_at            Start.
	 * @param \DateTimeImmutable|null $ends_at              End (null = open ended).
	 * @param int|null                $subscription_id      Subscription.
	 * @param int|null                $initial_order_id     First order.
	 * @param int|null                $parent_membership_id Parent (group seats).
	 * @param int|null                $coupon_id            Coupon used.
	 * @param \DateTimeImmutable|null $grace_until          Grace end while past due.
	 * @param \DateTimeImmutable|null $paused_at            Paused at.
	 * @param \DateTimeImmutable|null $resume_at            Scheduled resume.
	 * @param \DateTimeImmutable|null $suspended_at         Suspended at.
	 * @param \DateTimeImmutable|null $ended_at             Ended at.
	 * @param string|null             $end_reason           End reason.
	 * @param \DateTimeImmutable|null $created_at           Created.
	 * @param \DateTimeImmutable|null $updated_at           Updated.
	 */
	public function __construct(
		public int $id,
		public int $user_id,
		public int $level_id,
		public string $status,
		public string $source,
		public PriceSchedule $schedule,
		public \DateTimeImmutable $starts_at,
		public ?\DateTimeImmutable $ends_at = null,
		public ?int $subscription_id = null,
		public ?int $initial_order_id = null,
		public ?int $parent_membership_id = null,
		public ?int $coupon_id = null,
		public ?\DateTimeImmutable $grace_until = null,
		public ?\DateTimeImmutable $paused_at = null,
		public ?\DateTimeImmutable $resume_at = null,
		public ?\DateTimeImmutable $suspended_at = null,
		public ?\DateTimeImmutable $ended_at = null,
		public ?string $end_reason = null,
		public ?\DateTimeImmutable $created_at = null,
		public ?\DateTimeImmutable $updated_at = null
	) {}

	/**
	 * Labels for statuses.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			self::PENDING          => __( 'Pending payment', 'memberkin' ),
			self::PENDING_APPROVAL => __( 'Awaiting approval', 'memberkin' ),
			self::ACTIVE           => __( 'Active', 'memberkin' ),
			self::CANCELLING       => __( 'Cancelled (active until end date)', 'memberkin' ),
			self::PAST_DUE         => __( 'Payment overdue', 'memberkin' ),
			self::PAUSED           => __( 'Paused', 'memberkin' ),
			self::SUSPENDED        => __( 'Suspended', 'memberkin' ),
			self::ENDED            => __( 'Ended', 'memberkin' ),
		);
	}

	/**
	 * End reasons with labels.
	 *
	 * @return array<string, string>
	 */
	public static function end_reasons(): array {
		return array(
			'expired'        => __( 'Expired', 'memberkin' ),
			'cancelled'      => __( 'Cancelled', 'memberkin' ),
			'changed'        => __( 'Changed to another level', 'memberkin' ),
			'refunded'       => __( 'Refunded', 'memberkin' ),
			'admin'          => __( 'Removed by an administrator', 'memberkin' ),
			'failed_payment' => __( 'Payment failed', 'memberkin' ),
			'denied'         => __( 'Application denied', 'memberkin' ),
			'group_removed'  => __( 'Removed from group account', 'memberkin' ),
			'abandoned'      => __( 'Checkout not completed', 'memberkin' ),
		);
	}

	/**
	 * Whether a status grants access at all (past_due only within grace; see grants_access()).
	 *
	 * @param string $status Status.
	 */
	public static function status_grants_access( string $status ): bool {
		return in_array( $status, array( self::ACTIVE, self::CANCELLING, self::PAST_DUE ), true );
	}

	/**
	 * Whether a transition is allowed.
	 *
	 * @param string $from From status.
	 * @param string $to   To status.
	 */
	public static function can_transition( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}

	/**
	 * Whether this membership grants access right now.
	 *
	 * @param \DateTimeImmutable|null $now Time (default: now).
	 */
	public function grants_access( ?\DateTimeImmutable $now = null ): bool {
		$now = $now ?? Clock::now();
		if ( ! self::status_grants_access( $this->status ) ) {
			return false;
		}
		if ( $this->starts_at > $now ) {
			return false;
		}
		if ( null !== $this->ends_at && $this->ends_at <= $now ) {
			return false;
		}
		if ( self::PAST_DUE === $this->status && ( null === $this->grace_until || $this->grace_until <= $now ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether the membership has not ended.
	 */
	public function is_current(): bool {
		return self::ENDED !== $this->status;
	}

	/**
	 * JSON form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		$date = static fn( ?\DateTimeImmutable $d ): ?string => $d?->format( DATE_ATOM );
		return array(
			'id'                   => $this->id,
			'user_id'              => $this->user_id,
			'level_id'             => $this->level_id,
			'status'               => $this->status,
			'source'               => $this->source,
			'pricing'              => $this->schedule->jsonSerialize(),
			'starts_at'            => $date( $this->starts_at ),
			'ends_at'              => $date( $this->ends_at ),
			'subscription_id'      => $this->subscription_id,
			'initial_order_id'     => $this->initial_order_id,
			'parent_membership_id' => $this->parent_membership_id,
			'coupon_id'            => $this->coupon_id,
			'grace_until'          => $date( $this->grace_until ),
			'paused_at'            => $date( $this->paused_at ),
			'resume_at'            => $date( $this->resume_at ),
			'suspended_at'         => $date( $this->suspended_at ),
			'ended_at'             => $date( $this->ended_at ),
			'end_reason'           => $this->end_reason,
			'grants_access'        => $this->grants_access(),
			'created_at'           => $date( $this->created_at ),
		);
	}
}
