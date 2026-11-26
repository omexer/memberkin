<?php
/**
 * Foundation integration tests.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Capabilities;
use Memberkin\Core\Crypto;
use Memberkin\Core\Db;
use Memberkin\Core\Events;
use Memberkin\Core\Lock;
use Memberkin\Core\Logger;
use Memberkin\Core\Migrations\CoreTables;
use Memberkin\Core\Migrations\Migrator;
use Memberkin\Core\SafeMode;
use Memberkin\Core\Settings;
use Memberkin\Tests\TestCase;

/**
 * @covers \Memberkin\Core\Installer
 */
final class FoundationTest extends TestCase {

	public function test_all_core_tables_exist(): void {
		foreach ( CoreTables::table_names() as $name ) {
			$this->assertTrue( Db::table_exists( $name ), "Table {$name} missing" );
		}
		$this->assertContains( ( new CoreTables() )->id(), Migrator::applied() );
	}

	public function test_migrations_are_idempotent(): void {
		( new CoreTables() )->up();
		$this->assertTrue( Db::table_exists( 'levels' ) );
		$this->assertSame( array(), Migrator::run( array( CoreTables::class ) ) );
	}

	public function test_admin_has_caps_and_manager_role_exists(): void {
		$admin = get_role( 'administrator' );
		foreach ( array_keys( Capabilities::all() ) as $cap ) {
			$this->assertTrue( $admin->has_cap( $cap ), $cap );
		}
		$manager = get_role( Capabilities::MANAGER_ROLE );
		$this->assertNotNull( $manager );
		$this->assertTrue( $manager->has_cap( Capabilities::MANAGE_MEMBERS ) );
		$this->assertFalse( $manager->has_cap( Capabilities::MANAGE_SETTINGS ) );
		$this->assertFalse( $manager->has_cap( Capabilities::REFUND_ORDERS ) );
	}

	public function test_settings_defaults_and_update(): void {
		$this->assertSame( 'USD', Settings::get( 'general', 'currency' ) );
		Settings::update( 'general', array( 'currency' => 'EUR' ) );
		$this->assertSame( 'EUR', Settings::get( 'general', 'currency' ) );
		$this->assertSame( 3, Settings::get( 'billing', 'grace_days' ) );
	}

	public function test_crypto_round_trip(): void {
		$cipher = Crypto::encrypt( 'sk_test_secret' );
		$this->assertStringStartsWith( 'em1:', $cipher );
		$this->assertStringNotContainsString( 'sk_test_secret', $cipher );
		$this->assertSame( 'sk_test_secret', Crypto::decrypt( $cipher ) );
		$this->assertSame( '', Crypto::decrypt( 'em1:garbage' ) );
		$this->assertSame( 43, strlen( Crypto::token() ) );
	}

	public function test_logger_redacts(): void {
		global $wpdb;
		Logger::error(
			'test',
			'Card 4242 4242 4242 4242 failed with sk_test_abcdefghijkl',
			array(
				'secret_key' => 'x',
				'nested'     => array( 'token' => 'y' ),
			)
		);
		$row = $wpdb->get_row( 'SELECT * FROM ' . Db::table_name( 'logs' ) . ' ORDER BY id DESC LIMIT 1' );
		$this->assertStringNotContainsString( '4242 4242', $row->message );
		$this->assertStringNotContainsString( 'sk_test_abcdefghijkl', $row->message );
		$this->assertStringContainsString( '[redacted]', $row->context );
		$this->assertStringNotContainsString( '"y"', $row->context );
	}

	public function test_events_record_and_dedupe(): void {
		$user = self::factory()->user->create();
		Events::record( 'membership.activated', $user, 'membership', 7, array( 'a' => 1 ) );
		$this->assertTrue( Events::exists( 'membership.activated', 'membership', 7 ) );
		$this->assertFalse( Events::exists( 'membership.ended', 'membership', 7 ) );
		$rows = Events::for_user( $user );
		$this->assertSame( 'membership.activated', $rows[0]->event );
		$this->assertSame( array( 'a' => 1 ), $rows[0]->data );
	}

	public function test_lock_is_reentrant_and_exclusive(): void {
		$this->assertTrue( Lock::acquire( 'unit' ) );
		$this->assertTrue( Lock::acquire( 'unit' ) );
		Lock::release( 'unit' );
		Lock::release( 'unit' );
		$this->assertSame( 5, Lock::with( 'unit2', static fn() => 5 ) );
	}

	public function test_safe_mode_detects_clone(): void {
		SafeMode::confirm_live();
		$this->assertFalse( SafeMode::active() );
		update_option( 'home', 'http://staging.example.org' );
		$this->assertTrue( SafeMode::active() );
		$this->assertTrue( SafeMode::fingerprint_mismatch() );
		SafeMode::keep_paused();
		$this->assertTrue( SafeMode::active() );
		$this->assertFalse( SafeMode::fingerprint_mismatch() );
		SafeMode::confirm_live();
		$this->assertFalse( SafeMode::active() );
	}
}
