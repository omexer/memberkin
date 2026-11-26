<?php
/**
 * Refund service and billing admin screens.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Money;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Order;
use Memberkin\Modules\Billing\Admin\OrdersScreen;
use Memberkin\Modules\Billing\Admin\SubscriptionsScreen;
use Memberkin\Modules\Billing\Admin\ToolsScreen;
use Memberkin\Modules\Memberships\MembersScreen;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\RefundService;

/**
 * @covers \Memberkin\Service\RefundService
 */
final class BillingAdminTest extends BillingTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	private function render( callable $cb ): string {
		ob_start();
		$cb();
		return (string) ob_get_clean();
	}

	public function test_refund_with_revoke_ends_access_and_subscription(): void {
		$level  = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '15',
				'recurring_price' => '15',
			)
		);
		$user   = self::factory()->user->create();
		$order  = $this->member_checkout( $user, $level )->order;
		$refund = memberkin()->get( RefundService::class );

		$partial = $refund->refund( $order->id, Money::of( 500, 'USD' ), 'goodwill' );
		$this->assertSame( Order::PARTIALLY_REFUNDED, $partial->status );
		$this->assertTrue( memberkin_has_level( $level->id, $user ) );

		$full = $refund->refund( $order->id, Money::of( 1000, 'USD' ), 'cancel', true );
		$this->assertSame( Order::REFUNDED, $full->status );
		$this->assertFalse( memberkin_has_level( $level->id, $user ) );
		$this->assertSame( 'cancelled', memberkin()->get( SubscriptionRepository::class )->find( $order->subscription_id )->status );

		$this->expectException( ValidationException::class );
		$refund->refund( $order->id, Money::of( 1, 'USD' ) );
	}

	public function test_screens_render(): void {
		$level = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '15',
				'recurring_price' => '15',
			)
		);
		$user  = self::factory()->user->create( array( 'display_name' => 'Panel Person' ) );
		$order = $this->member_checkout( $user, $level )->order;

		set_current_screen( 'memberships_page_memberkin-orders' );
		$orders = new OrdersScreen( memberkin() );
		$this->assertStringContainsString( $order->number, $this->render( array( $orders, 'render' ) ) );
		$_GET = array( 'id' => $order->id );
		$html = $this->render( array( $orders, 'render' ) );
		$this->assertStringContainsString( 'name="revoke"', $html );
		$this->assertStringContainsString( $order->invoice_number, $html );

		set_current_screen( 'memberships_page_memberkin-subscriptions' );
		$_GET = array();
		$subs = new SubscriptionsScreen( memberkin() );
		$this->assertStringContainsString( '#' . $order->subscription_id, $this->render( array( $subs, 'render' ) ) );
		$_GET = array( 'id' => $order->subscription_id );
		$this->assertStringContainsString( 'Cancel at period end', $this->render( array( $subs, 'render' ) ) );

		$subs->register();
		$_GET = array( 'user_id' => $user );
		$html = $this->render( array( new MembersScreen( memberkin() ), 'render' ) );
		$this->assertStringContainsString( $order->number, $html, 'member detail lists orders' );

		$_GET = array( 'tab' => 'logs' );
		$this->assertStringContainsString( 'Channel', $this->render( array( new ToolsScreen( memberkin() ), 'render' ) ) );
		$_GET = array( 'tab' => 'webhooks' );
		$this->assertStringContainsString( 'Webhook events', $this->render( array( new ToolsScreen( memberkin() ), 'render' ) ) );
	}
}
