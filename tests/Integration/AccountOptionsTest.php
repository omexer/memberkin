<?php
/**
 * Checkout account options.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Settings;
use Memberkin\Service\Checkout\CheckoutResult;
use Memberkin\Service\Checkout\CheckoutService;

/**
 * @covers \Memberkin\Service\Checkout\CheckoutService
 */
final class AccountOptionsTest extends BillingTestCase {

	private function submit( array $account ): CheckoutResult {
		return memberkin()->get( CheckoutService::class )->submit(
			array(
				'level_ids' => array( $this->level( array( 'name' => 'Free' ) )->id ),
				'account'   => array_merge( array( 'password' => 'correct horse battery' ), $account ),
			),
			0
		);
	}

	public function test_username_is_email(): void {
		Settings::update( 'checkout', array( 'username_is_email' => true ) );
		$res = $this->submit( array( 'email' => 'Mail.Login@example.com' ) );
		$this->assertSame( CheckoutResult::COMPLETED, $res->status, wp_json_encode( $res->errors ) );
		$this->assertSame( 'mail.login@example.com', strtolower( get_userdata( $res->order->user_id )->user_login ) );

		Settings::update( 'checkout', array( 'username_is_email' => false ) );
		$this->assertArrayHasKey( 'username', $this->submit( array( 'email' => 'other@example.com' ) )->errors );
	}

	public function test_names_required(): void {
		Settings::update( 'checkout', array( 'username_is_email' => true, 'require_name' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$res = $this->submit( array( 'email' => 'noname@example.com' ) );
		$this->assertSame( array( 'first_name', 'last_name' ), array_values( array_intersect( array_keys( $res->errors ), array( 'first_name', 'last_name' ) ) ) );
		$ok = $this->submit( array( 'email' => 'named@example.com', 'first_name' => 'Ada', 'last_name' => 'Lovelace' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( CheckoutResult::COMPLETED, $ok->status );
		$this->assertSame( 'Ada Lovelace', get_userdata( $ok->order->user_id )->display_name );
	}
}
