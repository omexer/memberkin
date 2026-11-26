<?php
/**
 * Proration result for a plan change.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain\Pricing;

use Memberkin\Core\Money;

defined( 'ABSPATH' ) || exit;

/**
 * How a plan change is billed.
 */
final class ProrationPlan implements \JsonSerializable {

	public const NONE          = 'none';
	public const IMMEDIATE     = 'prorate_immediate';
	public const KEEP_ANCHOR   = 'keep_anchor';
	public const AT_PERIOD_END = 'period_end';

	/**
	 * Constructor.
	 *
	 * @param string                  $mode              none|prorate_immediate|keep_anchor|period_end.
	 * @param int                     $from_membership_id Membership being replaced.
	 * @param Money                   $credit            Value of unused time (applied as a negative line).
	 * @param int                     $extension_seconds Free time added to the new plan when the credit exceeds today's price.
	 * @param \DateTimeImmutable|null $anchor            Keep this renewal date (keep_anchor) or change date (period_end).
	 * @param bool                    $is_downgrade      New plan is cheaper per day.
	 */
	public function __construct(
		public readonly string $mode,
		public readonly int $from_membership_id,
		public readonly Money $credit,
		public readonly int $extension_seconds = 0,
		public readonly ?\DateTimeImmutable $anchor = null,
		public readonly bool $is_downgrade = false
	) {}

	/**
	 * JSON form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'mode'               => $this->mode,
			'from_membership_id' => $this->from_membership_id,
			'credit'             => $this->credit,
			'extension_seconds'  => $this->extension_seconds,
			'anchor'             => $this->anchor?->format( DATE_ATOM ),
			'is_downgrade'       => $this->is_downgrade,
		);
	}
}
