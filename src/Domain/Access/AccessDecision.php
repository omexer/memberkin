<?php
/**
 * Result of an access check.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain\Access;

defined( 'ABSPATH' ) || exit;

/**
 * Whether access is granted and, if not, why and what to show.
 */
final class AccessDecision implements \JsonSerializable {

	public const NO_RULES      = 'no_rules';
	public const MEMBER        = 'member';
	public const BYPASS        = 'bypass';
	public const NOT_LOGGED_IN = 'not_logged_in';
	public const NO_ACCESS     = 'no_access';
	public const DRIP_PENDING  = 'drip_pending';
	public const EXPIRED       = 'expired';

	/**
	 * Constructor.
	 *
	 * @param bool                    $granted         Granted.
	 * @param string                  $reason          Reason code.
	 * @param int[]                   $required_levels Levels that would grant access.
	 * @param \DateTimeImmutable|null $available_at    When drip content becomes available.
	 * @param Restriction|null        $rule            Extra restriction that denied (for behaviour/message).
	 */
	public function __construct(
		public readonly bool $granted,
		public readonly string $reason,
		public readonly array $required_levels = array(),
		public readonly ?\DateTimeImmutable $available_at = null,
		public readonly ?Restriction $rule = null
	) {}

	/**
	 * Whether any restriction applies to the target (regardless of the user).
	 */
	public function is_restricted(): bool {
		return self::NO_RULES !== $this->reason;
	}

	/**
	 * JSON form.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return array(
			'granted'         => $this->granted,
			'reason'          => $this->reason,
			'required_levels' => $this->required_levels,
			'available_at'    => $this->available_at?->format( DATE_ATOM ),
			'rule_id'         => $this->rule?->id(),
		);
	}
}
