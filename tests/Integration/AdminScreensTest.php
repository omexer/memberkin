<?php
/**
 * Admin screens render without errors and enforce capabilities.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Modules\Foundation\SettingsFields;
use Memberkin\Modules\Foundation\SettingsScreen;
use Memberkin\Modules\Levels\LevelsScreen;
use Memberkin\Modules\Memberships\MembersScreen;
use Memberkin\Service\LevelService;
use Memberkin\Tests\TestCase;

/**
 * @coversNothing
 */
final class AdminScreensTest extends TestCase {

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		set_current_screen( 'dashboard' );
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	private function render( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	public function test_settings_tabs_render(): void {
		$screen = new SettingsScreen( memberkin() );
		foreach ( array_keys( SettingsFields::tabs() ) as $tab ) {
			$_GET['tab'] = $tab;
			$html        = $this->render( array( $screen, 'render' ) );
			$this->assertStringContainsString( 'memberkin_save_settings', $html, $tab );
		}
	}

	public function test_levels_screens_render(): void {
		$level  = memberkin()->get( LevelService::class )->save(
			array(
				'name'            => 'Fish & Chips',
				'billing_type'    => 'recurring',
				'initial_price'   => '9',
				'recurring_price' => '9',
			)
		);
		$screen = new LevelsScreen( memberkin() );
		$html   = $this->render( array( $screen, 'render' ) );
		$this->assertStringContainsString( 'Fish &amp; Chips', $html, 'Level names are escaped' );
		$_GET = array(
			'view' => 'edit',
			'id'   => $level->id,
		);
		$this->assertStringContainsString( 'name="recurring_price"', $this->render( array( $screen, 'render' ) ) );
		$_GET = array( 'view' => 'group' );
		$this->assertStringContainsString( 'memberkin_save_level_group', $this->render( array( $screen, 'render' ) ) );
	}

	public function test_members_screens_render(): void {
		$level  = memberkin()->get( LevelService::class )->save(
			array(
				'name'         => 'Club',
				'billing_type' => 'free',
			)
		);
		$member = self::factory()->user->create( array( 'display_name' => 'Jamie Member' ) );
		memberkin_grant_membership( $member, $level->id );
		set_current_screen( 'memberships_page_memberkin-members' );
		$screen = new MembersScreen( memberkin() );
		$html   = $this->render( array( $screen, 'render' ) );
		$this->assertStringContainsString( 'Jamie Member', $html );
		$_GET['user_id'] = $member;
		$html            = $this->render( array( $screen, 'render' ) );
		$this->assertStringContainsString( 'Membership “Club” created', $html );
		$this->assertStringContainsString( 'Set end date', $html );
	}

	public function test_coupon_screens_render(): void {
		$level  = memberkin()->get( LevelService::class )->save(
			array(
				'name'          => 'Couponable',
				'billing_type'  => 'one_time',
				'initial_price' => '10',
			)
		);
		$coupon = memberkin()->get( \Memberkin\Service\CouponService::class )->save(
			array(
				'code'      => 'SMOKE',
				'amount'    => '15',
				'level_ids' => array( $level->id ),
				'overrides' => array(
					$level->id => array(
						'enabled'       => 1,
						'initial_price' => '5',
					),
				),
			)
		);
		set_current_screen( 'memberships_page_memberkin-coupons' );
		$screen = new \Memberkin\Modules\Coupons\CouponsScreen( memberkin() );
		$this->assertStringContainsString( 'SMOKE', $this->render( array( $screen, 'render' ) ) );
		$_GET = array(
			'view' => 'edit',
			'id'   => $coupon->id,
		);
		$html = $this->render( array( $screen, 'render' ) );
		$this->assertStringContainsString( 'value="5.00"', $html, 'override price shown' );
		$this->assertStringContainsString( 'value="15"', $html, 'percent shown without decimals' );
	}

	public function test_subscriber_cannot_open_screens(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		add_filter(
			'wp_die_handler',
			static fn() => static function ( $message ) {
			throw new \RuntimeException( 'wp_die' );
			}
		);
		$this->expectException( \RuntimeException::class );
		( new LevelsScreen( memberkin() ) )->render();
	}

	public function test_payments_tab_lists_stripe_and_paypal_only(): void {
		$_GET['tab'] = 'payments';
		$html        = $this->render( array( new SettingsScreen( memberkin() ), 'render' ) );
		foreach ( array( 'gateway_stripe', 'gateway_paypal' ) as $section ) {
			$this->assertStringContainsString( 'memberkin[' . $section . '][enabled]', $html );
			$this->assertStringNotContainsString( 'memberkin[' . $section . '][webhook_info]', $html, 'info rows have no input' );
		}
		foreach ( array( 'gateway_offline', 'gateway_authnet', 'gateway_square', 'gateway_paddle' ) as $section ) {
			$this->assertStringNotContainsString( 'memberkin[' . $section . ']', $html, 'add-on gateways are not part of the free plugin' );
		}
		$this->assertStringContainsString( '<code>' . esc_html( rest_url( 'memberkin/v1/webhooks/stripe' ) ) . '</code>', $html );
		$this->assertStringContainsString( '<code>MEMBERKIN_PAYPAL_TEST_CLIENT_ID</code>', $html );
		$this->assertStringContainsString( 'MEMBERKIN_TEST_TEST_WEBHOOK_SECRET', $html, 'constant override is reported, secret not shown' );
		$this->assertStringNotContainsString( '&lt;code&gt;', $html );
	}
}
