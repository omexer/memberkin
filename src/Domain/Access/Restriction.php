<?php
/**
 * Restriction contract.
 *
 * @package Memberkin
 */

namespace Memberkin\Domain\Access;

use Memberkin\Domain\Membership;

defined( 'ABSPATH' ) || exit;

/**
 * An extra restriction on a post or URL, added by an add-on through the "memberkin_access_restrictions"
 * or "memberkin_access_path_restrictions" filters. Levels assigned directly to content are checked by core.
 */
interface Restriction {

	/**
	 * Identifier (0 when the restriction has none).
	 */
	public function id(): int;

	/**
	 * Levels that would satisfy this restriction (shown to visitors as "join" options).
	 *
	 * @return int[]
	 */
	public function level_ids(): array;

	/**
	 * Checks the restriction for a user.
	 *
	 * @param int          $user_id     User (0 = visitor).
	 * @param Membership[] $memberships The user's active memberships.
	 * @return array{0: bool, 1: string, 2: ?\DateTimeImmutable} Granted, AccessDecision reason, available-at date.
	 */
	public function check( int $user_id, array $memberships ): array;

	/**
	 * What to do for visitors without access: message, excerpt, login, redirect or default.
	 */
	public function unauthorized_behavior(): string;

	/**
	 * Custom message (HTML) for visitors without access ('' = default message).
	 */
	public function unauthorized_message(): string;

	/**
	 * Redirect target for the "redirect" behaviour ('' = default).
	 */
	public function redirect_url(): string;
}
