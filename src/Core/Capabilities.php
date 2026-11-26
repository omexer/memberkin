<?php
/**
 * Capabilities and the Membership Manager role.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Capability registry (ARCHITECTURE.md §12).
 */
final class Capabilities {

	public const MANAGE_SETTINGS     = 'memberkin_manage_settings';
	public const MANAGE_LEVELS       = 'memberkin_manage_levels';
	public const VIEW_MEMBERS        = 'memberkin_view_members';
	public const MANAGE_MEMBERS      = 'memberkin_manage_members';
	public const MANAGE_ORDERS       = 'memberkin_manage_orders';
	public const REFUND_ORDERS       = 'memberkin_refund_orders';
	public const MANAGE_COUPONS      = 'memberkin_manage_coupons';
	public const MANAGE_RULES        = 'memberkin_manage_rules';
	public const MANAGE_EMAILS       = 'memberkin_manage_emails';
	public const VIEW_REPORTS        = 'memberkin_view_reports';
	public const EXPORT              = 'memberkin_export';
	public const IMPORT              = 'memberkin_import';
	public const BYPASS_RESTRICTIONS = 'memberkin_bypass_restrictions';

	public const MANAGER_ROLE = 'memberkin_manager';

	/**
	 * All capability names (no translation needed, so safe before "init").
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array( self::MANAGE_SETTINGS, self::MANAGE_LEVELS, self::VIEW_MEMBERS, self::MANAGE_MEMBERS, self::MANAGE_ORDERS, self::REFUND_ORDERS, self::MANAGE_COUPONS, self::MANAGE_RULES, self::MANAGE_EMAILS, self::VIEW_REPORTS, self::EXPORT, self::IMPORT, self::BYPASS_RESTRICTIONS );
	}

	/**
	 * All capabilities with labels.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			self::MANAGE_SETTINGS     => __( 'Manage membership settings', 'memberkin' ),
			self::MANAGE_LEVELS       => __( 'Manage levels', 'memberkin' ),
			self::VIEW_MEMBERS        => __( 'View members', 'memberkin' ),
			self::MANAGE_MEMBERS      => __( 'Manage members', 'memberkin' ),
			self::MANAGE_ORDERS       => __( 'Manage orders and subscriptions', 'memberkin' ),
			self::REFUND_ORDERS       => __( 'Refund orders', 'memberkin' ),
			self::MANAGE_COUPONS      => __( 'Manage coupons', 'memberkin' ),
			self::MANAGE_RULES        => __( 'Manage access rules', 'memberkin' ),
			self::MANAGE_EMAILS       => __( 'Manage emails', 'memberkin' ),
			self::VIEW_REPORTS        => __( 'View reports', 'memberkin' ),
			self::EXPORT              => __( 'Export data', 'memberkin' ),
			self::IMPORT              => __( 'Import data', 'memberkin' ),
			self::BYPASS_RESTRICTIONS => __( 'View all restricted content', 'memberkin' ),
		);
	}

	/**
	 * Capabilities granted to the Membership Manager role.
	 *
	 * @return string[]
	 */
	public static function manager_caps(): array {
		/**
		 * Filters the capabilities of the Membership Manager role.
		 *
		 * @param string[] $caps Capabilities.
		 */
		return (array) apply_filters(
			'memberkin_manager_capabilities',
			array(
				self::MANAGE_LEVELS,
				self::VIEW_MEMBERS,
				self::MANAGE_MEMBERS,
				self::MANAGE_ORDERS,
				self::MANAGE_COUPONS,
				self::MANAGE_RULES,
				self::MANAGE_EMAILS,
				self::VIEW_REPORTS,
				self::EXPORT,
				self::BYPASS_RESTRICTIONS,
			)
		);
	}

	/**
	 * Grants all caps to administrators and (re)creates the manager role. Idempotent.
	 */
	public static function install(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::keys() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		$caps = array(
			'read'         => true,
			'list_users'   => true,
			'edit_posts'   => false,
			'upload_files' => false,
		);
		foreach ( self::manager_caps() as $cap ) {
			$caps[ $cap ] = true;
		}
		remove_role( self::MANAGER_ROLE );
		add_role( self::MANAGER_ROLE, 'Membership Manager', $caps ); // Stored untranslated, like core roles; this runs before "init" during upgrades.
	}

	/**
	 * Removes all plugin caps and the manager role (uninstall).
	 */
	public static function uninstall(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::keys() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		remove_role( self::MANAGER_ROLE );
	}

	/**
	 * Gives super admins every plugin capability on multisite (they are not "administrator" on every site).
	 *
	 * @param array $allcaps All caps of the user.
	 * @param array $caps    Required primitive caps.
	 * @param array $args    Arguments.
	 * @param mixed $user    WP_User.
	 * @return array
	 */
	public static function super_admin_caps( array $allcaps, array $caps, array $args, $user ): array {
		if ( is_multisite() && $user instanceof \WP_User && is_super_admin( $user->ID ) ) {
			foreach ( self::keys() as $cap ) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	}
}
