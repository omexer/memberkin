<?php
/**
 * REST API memberkin/v1.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Capabilities;
use Memberkin\Core\Settings;
use Memberkin\Domain\Level;

/**
 * @covers \Memberkin\Rest\RestController
 */
final class RestApiTest extends BillingTestCase {

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $this->admin );
		}
		do_action( 'rest_api_init', rest_get_server() );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function req( string $method, string $route, array $params = array(), ?int $user = null ): \WP_REST_Response {
		wp_set_current_user( $user ?? 0 );
		$request = new \WP_REST_Request( $method, '/memberkin/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_routes_registered_with_schema(): void {
		$routes = rest_get_server()->get_routes( 'memberkin/v1' );
		foreach ( array( '/levels', '/level-groups', '/members', '/memberships', '/subscriptions', '/orders', '/coupons', '/access/check', '/me', '/me/orders', '/quote' ) as $route ) {
			$this->assertArrayHasKey( '/memberkin/v1' . $route, $routes, $route );
		}
		$this->assertNotEmpty( preg_grep( '#^/memberkin/v1/webhooks/#', array_keys( $routes ) ) );
		$options = rest_get_server()->dispatch( new \WP_REST_Request( 'OPTIONS', '/memberkin/v1/levels' ) )->get_data();
		$this->assertSame( 'memberkin-level', $options['schema']['title'] );
	}

	public function test_levels_public_and_crud(): void {
		$public = $this->level( array( 'name' => 'Public' ) );
		$hidden = $this->level(
			array(
				'name'   => 'Hidden',
				'status' => Level::STATUS_HIDDEN,
			)
		);

		$res = $this->req( 'GET', '/levels' );
		$ids = wp_list_pluck( $res->get_data(), 'id' );
		$this->assertContains( $public->id, $ids );
		$this->assertNotContains( $hidden->id, $ids );
		$this->assertArrayNotHasKey( 'meta', $res->get_data()[0] );
		$this->assertSame( 404, $this->req( 'GET', '/levels/' . $hidden->id )->get_status() );
		$this->assertSame( 200, $this->req( 'GET', '/levels/' . $hidden->id, array(), $this->admin )->get_status() );

		$this->assertSame( 401, $this->req( 'POST', '/levels', array( 'name' => 'X' ) )->get_status() );
		$this->assertSame( 403, $this->req( 'POST', '/levels', array( 'name' => 'X' ), self::factory()->user->create() )->get_status() );

		$res = $this->req(
			'POST',
			'/levels',
			array(
				'name'            => 'Gold',
				'billing_type'    => 'recurring',
				'initial_price'   => '20',
				'recurring_price' => '20',
				'billing_period'  => 'month',
			),
			$this->admin
		);
		$this->assertSame( 201, $res->get_status() );
		$id = $res->get_data()['id'];
		$this->assertSame( 2000, $res->get_data()['pricing']['recurring_amount'] );
		$this->assertArrayHasKey( 'meta', $res->get_data() );

		$res = $this->req( 'PATCH', '/levels/' . $id, array( 'name' => 'Gold Plus' ), $this->admin );
		$this->assertSame( 'Gold Plus', $res->get_data()['name'] );
		$this->assertSame( 2000, $res->get_data()['pricing']['recurring_amount'] );

		$this->assertSame( 400, $this->req( 'POST', '/levels', array( 'name' => '' ), $this->admin )->get_status() );
		$this->assertSame( 404, $this->req( 'PATCH', '/levels/999999', array( 'name' => 'Nope' ), $this->admin )->get_status() );
		$this->assertSame( 'deleted', $this->req( 'DELETE', '/levels/' . $id, array(), $this->admin )->get_data()['result'] );

		$group = $this->req(
			'POST',
			'/level-groups',
			array(
				'name'           => 'Tiers',
				'allow_multiple' => true,
			),
			$this->admin
		);
		$this->assertSame( 201, $group->get_status() );
		$this->assertTrue( $group->get_data()['allow_multiple'] );
		$this->assertTrue( $this->req( 'DELETE', '/level-groups/' . $group->get_data()['id'], array(), $this->admin )->get_data()['deleted'] );
	}

	public function test_memberships_grant_actions_and_pagination(): void {
		$level = $this->level( array( 'name' => 'Free' ) );
		$users = self::factory()->user->create_many( 3 );

		$this->assertSame(
			403,
			$this->req(
				'POST',
				'/memberships',
				array(
					'user_id'  => $users[0],
					'level_id' => $level->id,
				),
				$users[1]
			)->get_status()
		);
		foreach ( $users as $u ) {
			$res = $this->req(
				'POST',
				'/memberships',
				array(
					'user_id'  => $u,
					'level_id' => $level->id,
					'notify'   => false,
				),
				$this->admin
			);
			$this->assertSame( 201, $res->get_status() );
			$this->assertSame( 'api', $res->get_data()['source'] );
		}
		$this->assertTrue( memberkin_has_level( $level->id, $users[0] ) );
		$id = $res->get_data()['id'];

		$list = $this->req(
			'GET',
			'/members',
			array(
				'level_id' => $level->id,
				'per_page' => 2,
			),
			$this->admin
		);
		$this->assertCount( 2, $list->get_data() );
		$this->assertSame( '3', $list->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $list->get_headers()['X-WP-TotalPages'] );
		$this->assertArrayHasKey( 'email', $list->get_data()[0]['user'] );

		$member = $this->req( 'GET', '/members/' . $users[2], array(), $this->admin )->get_data();
		$this->assertSame( array( $level->id ), $member['level_ids'] );

		$res = $this->req( 'PATCH', '/memberships/' . $id, array( 'ends_at' => '2030-01-01T00:00:00Z' ), $this->admin );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertStringStartsWith( '2030-01-01', $res->get_data()['ends_at'] );

		$this->assertSame( 'suspended', $this->req( 'POST', '/memberships/' . $id . '/suspend', array( 'note' => 'abuse' ), $this->admin )->get_data()['status'] );
		$this->assertFalse( memberkin_has_level( $level->id, $users[2] ) );
		$this->assertSame( 'active', $this->req( 'POST', '/memberships/' . $id . '/unsuspend', array(), $this->admin )->get_data()['status'] );
		$this->assertSame( 'ended', $this->req( 'POST', '/memberships/' . $id . '/end', array(), $this->admin )->get_data()['status'] );
		$this->assertSame( 400, $this->req( 'POST', '/memberships/' . $id . '/pause', array(), $this->admin )->get_status() );
	}

	public function test_orders_refunds_and_capabilities(): void {
		$level = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '15',
				'recurring_price' => '15',
			)
		);
		$user  = self::factory()->user->create();
		$order = $this->member_checkout( $user, $level )->order;

		$list = $this->req( 'GET', '/orders', array( 'user_id' => $user ), $this->admin );
		$this->assertSame( $order->number, $list->get_data()[0]['number'] );
		$this->assertArrayNotHasKey( 'ip_address', $list->get_data()[0] );

		$manager = self::factory()->user->create( array( 'role' => Capabilities::MANAGER_ROLE ) );
		$this->assertSame( 200, $this->req( 'GET', '/orders/' . $order->id, array(), $manager )->get_status() );
		$this->assertSame( 403, $this->req( 'POST', '/orders/' . $order->id . '/refunds', array( 'amount' => '5' ), $manager )->get_status() );

		$res = $this->req(
			'POST',
			'/orders/' . $order->id . '/refunds',
			array(
				'amount' => '5.00',
				'reason' => 'goodwill',
			),
			$this->admin
		);
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'partially_refunded', $res->get_data()['status'] );
		$this->assertSame( 500, $res->get_data()['refunds'][0]['amount'] );
		$this->assertSame( 400, $this->req( 'POST', '/orders/' . $order->id . '/refunds', array( 'amount' => '500' ), $this->admin )->get_status() );

		$res = $this->req( 'POST', '/orders/' . $order->id . '/notes', array( 'note' => 'Called the member.' ), $this->admin );
		$this->assertStringContainsString( 'Called the member.', $res->get_data()['notes'] );

		$sub = $this->req( 'GET', '/subscriptions/' . $order->subscription_id, array(), $this->admin )->get_data();
		$this->assertSame( $order->id, $sub['orders'][0]['id'] );
		$res = $this->req( 'POST', '/subscriptions/' . $order->subscription_id . '/cancel', array( 'at_period_end' => true ), $this->admin );
		$this->assertTrue( $res->get_data()['cancel_at_period_end'] );

		$offline = $this->member_checkout( self::factory()->user->create(), $level, array( 'gateway' => 'manual' ) )->order;
		$this->assertSame( 'paid', $this->req( 'POST', '/orders/' . $offline->id . '/mark-paid', array(), $this->admin )->get_data()['status'] );
	}

	public function test_coupons_crud(): void {
		$level = $this->level( array( 'initial_price' => '10' ) );
		$res   = $this->req(
			'POST',
			'/coupons',
			array(
				'code'          => 'api10',
				'discount_type' => 'percent',
				'amount'        => '10',
				'level_ids'     => array( $level->id ),
			),
			$this->admin
		);
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'API10', $res->get_data()['code'] );
		$id = $res->get_data()['id'];
		$this->assertSame( $level->id, $res->get_data()['levels'][0]['level_id'] );

		$this->assertSame(
			400,
			$this->req(
				'POST',
				'/coupons',
				array(
					'code'          => 'API10',
					'discount_type' => 'percent',
					'amount'        => '5',
				),
				$this->admin
			)->get_status()
		);
		$res = $this->req(
			'PATCH',
			'/coupons/' . $id,
			array(
				'code'          => 'API10',
				'discount_type' => 'percent',
				'amount'        => '20',
				'level_ids'     => array( $level->id ),
			),
			$this->admin
		);
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( '1', $this->req( 'GET', '/coupons', array( 'search' => 'API1' ), $this->admin )->get_headers()['X-WP-Total'] );
		$this->assertTrue( $this->req( 'DELETE', '/coupons/' . $id, array(), $this->admin )->get_data()['deleted'] );
		$this->assertSame( 404, $this->req( 'GET', '/coupons/' . $id, array(), $this->admin )->get_status() );
	}

	public function test_me_is_scoped_to_current_user(): void {
		$level = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '9',
				'recurring_price' => '9',
			)
		);
		$alice = self::factory()->user->create();
		$bob   = self::factory()->user->create();
		$order = $this->member_checkout( $alice, $level )->order;

		$this->assertSame( 401, $this->req( 'GET', '/me' )->get_status() );
		$me = $this->req( 'GET', '/me', array(), $alice )->get_data();
		$this->assertSame( array( $level->id ), $me['level_ids'] );
		$this->assertSame( array(), $this->req( 'GET', '/me/orders', array(), $bob )->get_data() );

		$orders = $this->req( 'GET', '/me/orders', array(), $alice )->get_data();
		$this->assertSame( $order->number, $orders[0]['number'] );
		$this->assertArrayNotHasKey( 'gateway_customer_id', $orders[0] );

		$subs = $this->req( 'GET', '/me/subscriptions', array(), $alice )->get_data();
		$this->assertArrayNotHasKey( 'gateway_subscription_id', $subs[0] );
		$this->assertTrue( $subs[0]['can_cancel'] );

		$this->assertSame( 404, $this->req( 'POST', '/me/subscriptions/' . $order->subscription_id . '/cancel', array(), $bob )->get_status() );
		$this->assertTrue( $this->req( 'POST', '/me/subscriptions/' . $order->subscription_id . '/cancel', array(), $alice )->get_data()['cancel_at_period_end'] );
		$this->assertSame( 400, $this->req( 'POST', '/me/subscriptions/' . $order->subscription_id . '/pause', array(), $alice )->get_status() );

		$order = memberkin()->get( \Memberkin\Repository\OrderRepository::class )->find( $order->id );
		$this->assertNotEmpty( $order->invoice_number );
		$this->assertArrayNotHasKey( '/memberkin/v1/me/invoices/(?P<number>[A-Za-z0-9_\-]+)', rest_get_server()->get_routes( 'memberkin/v1' ), 'invoice downloads are an add-on feature' );

		$this->assertSame( 403, $this->req( 'GET', '/members', array(), $alice )->get_status() );
		$this->assertSame( 403, $this->req( 'GET', '/orders', array(), $alice )->get_status() );
	}
}
