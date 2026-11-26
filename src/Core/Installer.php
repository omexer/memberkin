<?php
/**
 * Activation, upgrades, deactivation and multisite provisioning.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

use Memberkin\Core\Migrations\CoreTables;
use Memberkin\Core\Migrations\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
final class Installer {

	/**
	 * Number of sites provisioned synchronously on network activation; the rest install lazily on first load.
	 */
	private const NETWORK_BATCH = 50;

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether activated network-wide.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => self::NETWORK_BATCH,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_site();
				restore_current_blog();
			}
			return;
		}
		self::install_site();
	}

	/**
	 * Installs on the current site: core tables, capabilities, defaults. Idempotent.
	 */
	public static function install_site(): void {
		Migrator::run( self::core_migrations() );
		Capabilities::install();
		SafeMode::remember_fingerprint_if_missing();
		add_option( 'memberkin_installed_at', Clock::now()->format( DATE_ATOM ), '', false );
		update_option( 'memberkin_version', MEMBERKIN_VERSION, true );
		update_option( 'memberkin_flush_rewrite', 1, true );
	}

	/**
	 * Migrations that always run (core tables and core data).
	 *
	 * @return array<int, class-string<Migrations\Migration>>
	 */
	public static function core_migrations(): array {
		return array(
			CoreTables::class,
			Migrations\ReportingColumns::class,
		);
	}

	/**
	 * Runs on every boot: installs lazily on sites that have not been provisioned (multisite),
	 * applies pending core and module migrations, and refreshes capabilities after an upgrade.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function maybe_upgrade( Plugin $plugin ): void {
		$classes = self::core_migrations();
		foreach ( $plugin->modules()->enabled() as $module ) {
			foreach ( $module->migrations() as $class ) {
				$classes[] = $class;
			}
		}

		if ( Migrator::pending( $classes ) ) {
			try {
				Migrator::run( $classes );
			} catch ( \Throwable $e ) {
				// Already logged by the migrator; surface to admins without breaking the site.
				update_option( 'memberkin_migration_error', $e->getMessage(), false );
				return;
			}
			delete_option( 'memberkin_migration_error' );
		}

		if ( get_option( 'memberkin_version' ) !== MEMBERKIN_VERSION ) {
			Capabilities::install();
			SafeMode::remember_fingerprint_if_missing();
			update_option( 'memberkin_version', MEMBERKIN_VERSION, true );
			update_option( 'memberkin_flush_rewrite', 1, true );
		}
	}

	/**
	 * Deactivation hook: stops scheduled jobs. Data is kept.
	 *
	 * @param bool $network_wide Whether deactivated network-wide.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				Jobs::unschedule_all();
				restore_current_blog();
			}
		} else {
			Jobs::unschedule_all();
		}
		flush_rewrite_rules( false );
	}

	/**
	 * Provisions a newly created site when the plugin is network-active.
	 *
	 * @param \WP_Site $site New site.
	 */
	public static function on_initialize_site( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( MEMBERKIN_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		self::install_site();
		restore_current_blog();
	}

	/**
	 * Adds our tables to the list WordPress drops when a site is deleted (the site's data is going away anyway).
	 *
	 * @param string[] $tables  Tables to drop.
	 * @param int      $site_id Site being deleted.
	 * @return string[]
	 */
	public static function drop_tables_on_site_delete( array $tables, int $site_id ): array {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $site_id );
		foreach ( Uninstaller::all_table_names() as $name ) {
			$tables[ 'memberkin_' . $name ] = $prefix . 'memberkin_' . $name;
		}
		return $tables;
	}
}
