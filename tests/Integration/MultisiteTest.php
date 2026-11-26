<?php
/**
 * Network activation, new-site provisioning and per-site data.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Installer;
use Memberkin\Repository\LevelRepository;

/**
 * Runs only with WP_MULTISITE=1.
 *
 * @covers \Memberkin\Core\Installer
 */
final class MultisiteTest extends \Memberkin\Tests\TestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
	}

	private static function provisioned( int $blog_id ): bool {
		switch_to_blog( $blog_id );
		$migrations = (array) get_option( 'memberkin_migrations', array() );
		global $wpdb;
		$quiet    = $wpdb->suppress_errors( true );
		$table_ok = false !== $wpdb->query( "SELECT 1 FROM {$wpdb->prefix}memberkin_levels LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( $quiet );
		restore_current_blog();
		return $table_ok && in_array( 'core_20261123_001_tables', $migrations, true );
	}

	public function test_network_activation_provisions_every_site(): void {
		$site = self::factory()->blog->create();
		$this->assertFalse( self::provisioned( $site ) );
		Installer::activate( true );
		$this->assertTrue( self::provisioned( $site ) );
		$this->assertTrue( self::provisioned( get_main_site_id() ) );
	}

	public function test_new_site_is_provisioned_when_network_active(): void {
		$basename = plugin_basename( MEMBERKIN_FILE );
		$active   = (array) get_site_option( 'active_sitewide_plugins', array() );
		update_site_option( 'active_sitewide_plugins', array_merge( $active, array( $basename => time() ) ) );
		$site = self::factory()->blog->create();
		update_site_option( 'active_sitewide_plugins', $active );
		$this->assertTrue( self::provisioned( $site ) );
	}

	public function test_data_is_per_site(): void {
		Installer::activate( true );
		$site = self::factory()->blog->create();
		Installer::activate( true );
		switch_to_blog( $site );
		memberkin()->get( LevelRepository::class )->flush();
		$level = memberkin()->get( \Memberkin\Service\LevelService::class )->save( array( 'name' => 'Site two only' ) );
		$this->assertSame( 'Site two only', memberkin()->get( LevelRepository::class )->find( $level->id )->name );
		restore_current_blog();
		memberkin()->get( LevelRepository::class )->flush();
		$names = array_map( static fn( $l ) => $l->name, memberkin()->get( LevelRepository::class )->all() );
		$this->assertNotContains( 'Site two only', $names );
	}
}
