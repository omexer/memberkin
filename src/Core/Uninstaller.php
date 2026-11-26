<?php
/**
 * Data removal on uninstall (only when the admin opted in).
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

use Memberkin\Core\Migrations\CoreTables;

defined( 'ABSPATH' ) || exit;

/**
 * Uninstaller. Runs from uninstall.php without the plugin being booted, so it must stay self-contained.
 */
final class Uninstaller {

	/**
	 * Whether the current site opted in to removing all data on uninstall.
	 */
	public static function site_opted_in(): bool {
		$advanced = get_option( 'memberkin_settings_advanced', array() );
		return is_array( $advanced ) && ! empty( $advanced['delete_data_on_uninstall'] );
	}

	/**
	 * Every table the plugin may have created on the current site (core + module tables), short names.
	 *
	 * @return string[]
	 */
	public static function all_table_names(): array {
		global $wpdb;
		$like   = $wpdb->esc_like( $wpdb->prefix . 'memberkin_' ) . '%';
		$found  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$names  = CoreTables::table_names();
		$offset = strlen( $wpdb->prefix . 'memberkin_' );
		foreach ( $found as $table ) {
			$names[] = substr( (string) $table, $offset );
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Drops all plugin tables on the current site.
	 */
	public static function drop_tables(): void {
		global $wpdb;
		foreach ( self::all_table_names() as $name ) {
			$table = Db::table_name( $name );
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from our own prefix.
		}
	}

	/**
	 * Removes all data on the current site if opted in.
	 */
	public static function uninstall_site(): void {
		if ( ! self::site_opted_in() ) {
			return;
		}
		global $wpdb;

		self::drop_tables();

		// Options and transients.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'memberkin_' ) . '%', $wpdb->esc_like( '_transient_memberkin_' ) . '%', $wpdb->esc_like( '_transient_timeout_memberkin_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Per-site user meta (keys are prefixed with the blog prefix on multisite sub-sites only when we add them that way; we use plain keys).
		if ( ! is_multisite() || is_main_site() ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'memberkin_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// Post meta and content-type posts created by modules.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_memberkin_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		Capabilities::uninstall();

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), Jobs::GROUP );
		}
		wp_cache_flush_group( 'memberkin' );
	}
}
