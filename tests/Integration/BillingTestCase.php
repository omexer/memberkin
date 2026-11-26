<?php
/**
 * Helpers for billing tests.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Settings;
use Memberkin\Domain\Level;
use Memberkin\Service\Checkout\CheckoutResult;
use Memberkin\Service\Checkout\CheckoutService;
use Memberkin\Service\LevelService;
use Memberkin\Tests\TestCase;

/**
 * Base class with level/checkout helpers.
 */
abstract class BillingTestCase extends TestCase {

	public function set_up(): void {
		parent::set_up();
		Settings::update( 'gateway_test', array( 'enabled' => true ) );
		Settings::update( 'gateway_manual', array( 'enabled' => true ) );
		Settings::update( 'checkout', array( 'require_name' => false ) );
	}

	protected function level( array $input ): Level {
		return memberkin()->get( LevelService::class )->save( array_merge( array( 'name' => 'Level ' . wp_rand() ), $input ) );
	}

	protected function guest_checkout( Level $level, array $extra = array(), string $card = 'success' ): CheckoutResult {
		$email = 'member' . wp_rand() . '@example.org';
		return memberkin()->get( CheckoutService::class )->submit(
			array_merge(
				array(
					'level_ids'      => array( $level->id ),
					'gateway'        => 'test',
					'account'        => array(
						'email'    => $email,
						'username' => 'user' . wp_rand(),
						'password' => 'correct horse battery',
					),
					'gateway_fields' => array( 'test_card' => $card ),
				),
				$extra
			),
			0
		);
	}

	protected function member_checkout( int $user_id, Level $level, array $extra = array(), string $card = 'success' ): CheckoutResult {
		return memberkin()->get( CheckoutService::class )->submit(
			array_merge(
				array(
					'level_ids'      => array( $level->id ),
					'gateway'        => 'test',
					'gateway_fields' => array( 'test_card' => $card ),
				),
				$extra
			),
			$user_id
		);
	}
}
