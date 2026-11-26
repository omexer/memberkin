<?php
/**
 * Public PHP API (stable functions for themes and other plugins).
 *
 * @package Memberkin
 */

defined( 'ABSPATH' ) || exit;

use Memberkin\Core\Money;
use Memberkin\Core\Plugin;

/**
 * The plugin instance (service container access).
 */
function memberkin(): Plugin {
	return Plugin::instance();
}

/**
 * Formats an amount in minor units.
 *
 * @param int         $amount   Minor units.
 * @param string|null $currency Currency (site default when null).
 */
function memberkin_format_price( int $amount, ?string $currency = null ): string {
	return Money::of( $amount, $currency ?? \Memberkin\Core\Currency::default() )->format();
}

/**
 * Whether a user currently has access through one of the given levels.
 *
 * @param int|int[]|string|string[]|null $levels  Level id(s) or slug(s); null = any level.
 * @param int|null                       $user_id User id (current user when null).
 */
function memberkin_has_level( int|array|string|null $levels = null, ?int $user_id = null ): bool {
	$user_id = $user_id ?? get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}
	$service = memberkin()->get( \Memberkin\Service\MembershipService::class );
	return $service->user_has_level( $user_id, $levels );
}

/**
 * A level by id or slug.
 *
 * @param int|string $id_or_slug Id or slug.
 */
function memberkin_get_level( int|string $id_or_slug ): ?\Memberkin\Domain\Level {
	$repo = memberkin()->get( \Memberkin\Repository\LevelRepository::class );
	return is_numeric( $id_or_slug ) ? $repo->find( (int) $id_or_slug ) : $repo->find_by_slug( (string) $id_or_slug );
}

/**
 * Current memberships of a user that grant access.
 *
 * @param int|null $user_id User id (current user when null).
 * @return \Memberkin\Domain\Membership[]
 */
function memberkin_get_memberships( ?int $user_id = null ): array {
	$user_id = $user_id ?? get_current_user_id();
	if ( ! $user_id ) {
		return array();
	}
	return memberkin()->get( \Memberkin\Service\MembershipService::class )->active_for_user( $user_id );
}

/**
 * Grants a membership (admin/API use).
 *
 * @param int   $user_id  User id.
 * @param int   $level_id Level id.
 * @param array $args     See MembershipService::grant().
 */
function memberkin_grant_membership( int $user_id, int $level_id, array $args = array() ): \Memberkin\Domain\Membership {
	return memberkin()->get( \Memberkin\Service\MembershipService::class )->grant( $user_id, $level_id, $args );
}

/**
 * Ends a membership.
 *
 * @param int    $membership_id Membership id.
 * @param string $reason        End reason (admin, cancelled, expired …).
 */
function memberkin_end_membership( int $membership_id, string $reason = 'admin' ): void {
	memberkin()->get( \Memberkin\Service\MembershipService::class )->end( $membership_id, $reason );
}

/**
 * URL of a plugin page (levels, checkout, confirmation, account, login, profile, invoice), or '' if not set up.
 *
 * @param string $page Page key.
 * @param array  $args Query args.
 */
function memberkin_page_url( string $page, array $args = array() ): string {
	$page_id = (int) \Memberkin\Core\Settings::get( 'pages', $page, 0 );
	$url     = $page_id ? get_permalink( $page_id ) : '';
	if ( ! $url ) {
		return '';
	}
	return $args ? add_query_arg( $args, $url ) : $url;
}

/**
 * Checkout URL for a level.
 *
 * @param \Memberkin\Domain\Level $level Level.
 * @param array                   $args  Extra query args (e.g. coupon).
 */
function memberkin_checkout_url( \Memberkin\Domain\Level $level, array $args = array() ): string {
	$base = memberkin_page_url( 'checkout' );
	if ( '' === $base ) {
		$base = home_url( '/' );
	}
	return add_query_arg( array_merge( array( 'level' => $level->slug ), $args ), $base );
}
