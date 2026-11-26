<?php
/**
 * Throttling, card-testing protection and login limits.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Clock;
use Memberkin\Core\Settings;
use Memberkin\Core\Throttle;
use Memberkin\Service\Checkout\CheckoutResult;
use Memberkin\Service\Checkout\CheckoutService;
use Memberkin\Service\Security\CheckoutGuard;

/**
 * @covers \Memberkin\Core\Throttle
 * @covers \Memberkin\Service\Security\CheckoutGuard
 * @covers \Memberkin\Service\Security\LoginGuard
 */
final class SecurityTest extends BillingTestCase {

	public function set_up(): void {
		parent::set_up();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		delete_transient( CheckoutGuard::CARD_TESTING_FLAG );
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	private function guest( $level, string $card = 'success', string $email = '' ): CheckoutResult {
		return memberkin()->get( CheckoutService::class )->submit(
			array(
				'level_ids'      => array( $level->id ),
				'gateway'        => 'test',
				'gateway_fields' => array( 'test_card' => $card ),
				'ip'             => '203.0.113.9',
				'account'        => array(
					'email'    => '' !== $email ? $email : 'guest' . wp_rand() . '@example.com',
					'username' => 'guest' . wp_rand(),
					'password' => 'correct horse battery',
				),
			),
			0
		);
	}

	public function test_throttle_window(): void {
		Clock::freeze( '2026-06-01 10:00:00' );
		$this->assertSame( 1, Throttle::hit( 'x', 'k', 60 ) );
		$this->assertSame( 2, Throttle::hit( 'x', 'K ', 60 ), 'keys are case/space-insensitive' );
		$this->assertSame( 60, Throttle::retry_after( 'x', 'k', 60 ) );
		Clock::freeze( '2026-06-01 10:01:01' );
		$this->assertSame( 0, Throttle::count( 'x', 'k', 60 ) );
		$this->assertSame( 0, Throttle::hit( 'x', '', 60 ), 'empty keys are never counted' );
	}

	public function test_submission_and_decline_limits(): void {
		Settings::update( 'security', array( 'checkout_submit_limit' => 3, 'checkout_fail_limit' => 2, 'card_testing_threshold' => 0 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$level = $this->level( array( 'initial_price' => '10' ) );

		$this->assertSame( CheckoutResult::FAILED, $this->guest( $level, 'decline' )->status );
		$this->assertSame( CheckoutResult::FAILED, $this->guest( $level, 'decline' )->status );
		$blocked = $this->guest( $level );
		$this->assertStringContainsString( 'Too many declined payments', $blocked->errors['form'] );

		Settings::update( 'security', array( 'checkout_fail_limit' => 0 ) );
		$over = $this->guest( $level );
		$this->assertStringContainsString( 'Too many attempts', $over->errors['form'], '4th submission this hour' );

		Clock::freeze( Clock::now()->modify( '+61 minutes' ) );
		$this->assertSame( CheckoutResult::COMPLETED, $this->guest( $level )->status );
	}

	public function test_card_testing_pauses_new_signups_only(): void {
		Settings::update( 'security', array( 'checkout_submit_limit' => 0, 'checkout_fail_limit' => 0, 'card_testing_threshold' => 3 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Settings::update( 'emails', array( 'admin_emails' => 'owner@example.com' ) );
		$level = $this->level( array( 'initial_price' => '10' ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->guest( $level, 'decline' );
		}
		$this->assertNotFalse( get_transient( CheckoutGuard::CARD_TESTING_FLAG ) );
		$this->assertStringContainsString( 'card testing', tests_retrieve_phpmailer_instance()->get_sent()->subject );
		$this->assertStringContainsString( 'paused', $this->guest( $level )->errors['form'] );
		$member = $this->member_checkout( self::factory()->user->create(), $level );
		$this->assertSame( CheckoutResult::COMPLETED, $member->status, 'logged-in members can still pay' );
	}

	public function test_login_lock(): void {
		Settings::update( 'security', array( 'login_limit' => 3 ) );
		$user = self::factory()->user->create( array( 'user_login' => 'alice', 'user_pass' => 'right-password' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertWPError( wp_authenticate( 'alice', 'wrong' ) );
		}
		$locked = wp_authenticate( 'alice', 'right-password' );
		$this->assertWPError( $locked );
		$this->assertSame( 'memberkin_login_locked', $locked->get_error_code() );
		$this->assertStringContainsString( '15 minutes', $locked->get_error_message() );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$this->assertSame( $user, wp_authenticate( 'alice', 'right-password' )->ID, 'another IP is not locked' );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		Clock::freeze( Clock::now()->modify( '+16 minutes' ) );
		$this->assertSame( $user, wp_authenticate( 'alice', 'right-password' )->ID );

		Settings::update( 'security', array( 'login_limit' => 0 ) );
		for ( $i = 0; $i < 5; $i++ ) {
			wp_authenticate( 'alice', 'wrong' );
		}
		$this->assertSame( $user, wp_authenticate( 'alice', 'right-password' )->ID, '0 turns it off' );
	}
}
