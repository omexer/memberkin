<?php
/**
 * Deletes accounts of sign-ups that never completed checkout.
 *
 * @package Memberkin
 */

namespace Memberkin\Service;

use Memberkin\Core\Clock;
use Memberkin\Core\Db;
use Memberkin\Core\Logger;
use Memberkin\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Accounts, off by default. Daily (with the cleanup job), at most 100 accounts per run.
 *
 * An account is deleted only when all of these hold:
 * - checkout created it and never completed (user meta memberkin_checkout_pending), and it is older than N days (30);
 * - it has no membership other than ones ended as "abandoned", and no paid order;
 * - it has only the site's default role and no other capabilities, no posts and no comments.
 * Its unpaid orders stay (with the user id) for the records. On multisite the account is only deleted from the
 * network when this is its only site; otherwise it is only removed from this site.
 */
final class SignupCleanup {

	public const LIMIT = 100;

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'memberkin_cleanup_modules', array( $this, 'run' ) );
		add_filter( 'memberkin_settings_defaults', array( $this, 'defaults' ) );
		add_filter( 'memberkin_settings_fields', array( $this, 'fields' ) );
	}

	/**
	 * Defaults.
	 *
	 * @param array $defaults Defaults.
	 */
	public function defaults( array $defaults ): array {
		$defaults['accounts']['delete_abandoned_signups'] = false;
		$defaults['accounts']['abandoned_signup_days']    = 30;
		return $defaults;
	}

	/**
	 * Settings (Accounts tab).
	 *
	 * @param array $fields Fields.
	 */
	public function fields( array $fields ): array {
		$fields[] = array(
			'tab'         => 'accounts',
			'section'     => 'accounts',
			'key'         => 'delete_abandoned_signups',
			'type'        => 'checkbox',
			'label'       => __( 'Delete accounts of sign-ups that never completed checkout', 'memberkin' ),
			'description' => __( 'Only accounts with no membership, no paid order, no posts or comments and only the default role. Deleted accounts cannot be restored.', 'memberkin' ),
		);
		$fields[] = array(
			'tab'     => 'accounts',
			'section' => 'accounts',
			'key'     => 'abandoned_signup_days',
			'type'    => 'number',
			'min'     => 7,
			'max'     => 365,
			'label'   => __( 'Delete them after (days)', 'memberkin' ),
		);
		return $fields;
	}

	/**
	 * Accounts that qualify now.
	 *
	 * @param int $limit Limit.
	 * @return int[]
	 */
	public function candidates( int $limit = self::LIMIT ): array {
		global $wpdb;
		$days        = max( 7, (int) Settings::get( 'accounts', 'abandoned_signup_days', 30 ) );
		$cutoff      = Db::datetime( Clock::now()->modify( '-' . $days . ' days' ) );
		$memberships = Db::table_name( 'memberships' );
		$orders      = Db::table_name( 'orders' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$ids  = (array) $wpdb->get_col( $wpdb->prepare( "SELECT u.ID FROM {$wpdb->users} u JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = 'memberkin_checkout_pending' WHERE u.user_registered < %s AND NOT EXISTS (SELECT 1 FROM {$memberships} m WHERE m.user_id = u.ID AND NOT (m.status = 'ended' AND m.end_reason = 'abandoned')) AND NOT EXISTS (SELECT 1 FROM {$orders} o WHERE o.user_id = u.ID AND o.paid_at IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.post_author = u.ID) AND NOT EXISTS (SELECT 1 FROM {$wpdb->comments} c WHERE c.user_id = u.ID) ORDER BY u.ID ASC LIMIT %d", $cutoff, $limit * 3 ) );
		$role = (string) get_option( 'default_role', 'subscriber' );
		$out  = array();
		foreach ( $ids as $id ) {
			$user = get_userdata( (int) $id );
			if ( ! $user || array( $role ) !== array_values( (array) $user->roles ) || is_super_admin( $user->ID ) || count( array_filter( (array) $user->caps ) ) > 1 ) {
				continue; // Extra roles or capabilities: someone set this account up on purpose.
			}
			$out[] = $user->ID;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		/**
		 * Filters the abandoned sign-up accounts about to be deleted.
		 *
		 * @param int[] $ids User ids.
		 */
		return array_map( 'intval', (array) apply_filters( 'memberkin_abandoned_signups', $out ) );
	}

	/**
	 * Daily job. Returns the number of accounts deleted.
	 */
	public function run(): int {
		if ( ! Settings::get( 'accounts', 'delete_abandoned_signups', false ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$deleted = 0;
		foreach ( $this->candidates() as $id ) {
			if ( is_multisite() ) {
				require_once ABSPATH . 'wp-admin/includes/ms.php';
				$blogs = get_blogs_of_user( $id );
				$ok    = count( $blogs ) <= 1 ? wpmu_delete_user( $id ) : remove_user_from_blog( $id, get_current_blog_id() );
			} else {
				$ok = wp_delete_user( $id );
			}
			$deleted += $ok && ! is_wp_error( $ok ) ? 1 : 0;
		}
		if ( $deleted ) {
			Logger::info( 'accounts', sprintf( 'Deleted %d abandoned sign-up account(s).', $deleted ) );
		}
		return $deleted;
	}
}
