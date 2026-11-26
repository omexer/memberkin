<?php
/**
 * Frontend shortcodes, form handling and access control.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Settings;
use Memberkin\Domain\Subscription;
use Memberkin\Frontend\AccountController;
use Memberkin\Frontend\CheckoutController;
use Memberkin\Frontend\LoginController;
use Memberkin\Frontend\PageSetup;
use Memberkin\Repository\SubscriptionRepository;

/**
 * @covers \Memberkin\Frontend\CheckoutController
 * @covers \Memberkin\Frontend\AccountController
 * @covers \Memberkin\Frontend\LoginController
 * @covers \Memberkin\Frontend\PageSetup
 */
final class FrontendTest extends BillingTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		PageSetup::create_missing();
		wp_set_current_user( 0 );
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RedirectException( (string) $location );
			}
		);
	}

	public function tear_down(): void {
		$_POST = array();
		$_GET  = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tear_down();
	}

	private function post( callable $handler, array $post ): ?string {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = $post;
		try {
			$handler();
		} catch ( RedirectException $e ) {
			return $e->getMessage();
		}
		return null;
	}

	public function test_pages_created_once(): void {
		foreach ( array_keys( PageSetup::pages() ) as $key ) {
			$this->assertGreaterThan( 0, (int) Settings::get( 'pages', $key ), $key );
		}
		$this->assertSame( array(), PageSetup::create_missing() );
		$this->assertStringContainsString( '<!-- wp:memberkin/checkout /-->', get_post( Settings::get( 'pages', 'checkout' ) )->post_content );
	}

	public function test_levels_page_lists_purchasable_levels(): void {
		$open   = $this->level(
			array(
				'name'          => 'Open Level',
				'billing_type'  => 'one_time',
				'initial_price' => '12',
				'meta'          => array( 'benefits' => "Perk A\nPerk B" ),
			)
		);
		$hidden = $this->level(
			array(
				'name'         => 'Secret Level',
				'billing_type' => 'free',
				'status'       => 'hidden',
			)
		);
		$html   = do_shortcode( '[memberkin_levels]' );
		$this->assertStringContainsString( 'Open Level', $html );
		$this->assertStringContainsString( 'Perk B', $html );
		$this->assertStringContainsString( '$12.00', $html );
		$this->assertStringNotContainsString( 'Secret Level', $html );
		$this->assertStringContainsString( 'level=' . $open->slug, $html );
		$this->assertStringContainsString( 'Secret Level', do_shortcode( '[memberkin_levels levels="' . $hidden->slug . '"]' ), 'hidden levels can be shown explicitly' );
	}

	public function test_checkout_form_renders_and_submits(): void {
		$level         = $this->level(
			array(
				'name'          => 'Form Level',
				'billing_type'  => 'one_time',
				'initial_price' => '20',
			)
		);
		$_GET['level'] = $level->slug;
		$html          = do_shortcode( '[memberkin_checkout]' );
		$this->assertStringContainsString( 'name="account[email]"', $html );
		$this->assertStringContainsString( 'Pay $20.00', $html );
		$this->assertStringContainsString( 'name="gateway" value="test"', $html );

		$controller = new CheckoutController();
		$location   = $this->post(
			array( $controller, 'handle_post' ),
			array(
				'memberkin_checkout' => '1',
				'_memberkin_nonce'   => wp_create_nonce( 'memberkin_checkout' ),
				'level_ids'        => array( $level->id ),
				'gateway'          => 'test',
				'account'          => array(
					'email'    => 'formbuyer@example.org',
					'username' => 'formbuyer',
					'password' => 'long enough pass',
				),
				'memberkin_gateway'  => array( 'test_card' => 'success' ),
			)
		);
		$this->assertNotNull( $location, 'redirects to confirmation' );
		$this->assertStringContainsString( 'memberkin_order=', $location );
		$user = get_user_by( 'email', 'formbuyer@example.org' );
		$this->assertTrue( memberkin_has_level( $level->id, $user->ID ) );

		// The confirmation page shows the order to the key holder only.
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		wp_set_current_user( 0 );
		$_GET = $query;
		$this->assertStringContainsString( 'Your membership is active', do_shortcode( '[memberkin_confirmation]' ) );
		$_GET['memberkin_key'] = 'wrong-key-wrong-key-wrong-key';
		$this->assertStringNotContainsString( 'Your membership is active', do_shortcode( '[memberkin_confirmation]' ) );
		wp_set_current_user( self::factory()->user->create() );
		$_GET = array( 'memberkin_order' => $query['memberkin_order'] );
		$this->assertStringNotContainsString( 'Your membership is active', do_shortcode( '[memberkin_confirmation]' ), 'other members cannot see the order' );
	}

	public function test_bad_nonce_and_honeypot_are_rejected(): void {
		$level      = $this->level( array( 'billing_type' => 'free' ) );
		$controller = new CheckoutController();
		$this->assertNull( $this->post( array( $controller, 'handle_post' ), array( 'memberkin_checkout' => '1', '_memberkin_nonce' => 'bad', 'level_ids' => array( $level->id ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertNull( $this->post( array( $controller, 'handle_post' ), array( 'memberkin_checkout' => '1', '_memberkin_nonce' => wp_create_nonce( 'memberkin_checkout' ), 'memberkin_website' => 'spam', 'level_ids' => array( $level->id ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 0, memberkin()->get( \Memberkin\Repository\MembershipRepository::class )->query( array( 'level_ids' => array( $level->id ) ) )['total'], 'nothing was created' );
	}

	public function test_quote_endpoint(): void {
		do_action( 'rest_api_init' );
		$level = $this->level(
			array(
				'billing_type'  => 'one_time',
				'initial_price' => '40',
			)
		);
		memberkin()->get( \Memberkin\Service\CouponService::class )->save(
			array(
				'code'   => 'QUARTER',
				'amount' => '25',
			)
		);
		$request = new \WP_REST_Request( 'POST', '/memberkin/v1/quote' );
		$request->set_body_params( array( 'level_ids' => array( $level->id ), 'coupon_code' => 'quarter' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 3000, $response->get_data()['quote']->total()->amount );
		$this->assertStringContainsString( 'Coupon QUARTER', $response->get_data()['html'] );

		$request->set_body_params( array( 'level_ids' => array( $level->id ), 'coupon_code' => 'NOPE' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	public function test_account_dashboard_and_member_actions(): void {
		Settings::update( 'accounts', array( 'allow_member_pause' => true ) );
		$level  = $this->level(
			array(
				'name'            => 'Dash Level',
				'billing_type'    => 'recurring',
				'initial_price'   => '9',
				'recurring_price' => '9',
			)
		);
		$user   = self::factory()->user->create();
		$sub_id = $this->member_checkout( $user, $level )->order->subscription_id;
		wp_set_current_user( $user );

		$html = do_shortcode( '[memberkin_account]' );
		$this->assertStringContainsString( 'Dash Level', $html );
		$this->assertStringContainsString( 'Cancel subscription', $html );
		$this->assertStringContainsString( 'Visa •••• 4242', $html );

		$account = new AccountController();
		$nonce   = static fn( string $a, int $id ) => wp_create_nonce( 'memberkin_account_' . $a . '_' . $id );

		$this->post( array( $account, 'handle_post' ), array( 'memberkin_account_action' => 'pause', 'id' => $sub_id, 'days' => 14, '_memberkin_nonce' => $nonce( 'pause', $sub_id ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( Subscription::PAUSED, memberkin()->get( SubscriptionRepository::class )->find( $sub_id )->status );
		$this->post( array( $account, 'handle_post' ), array( 'memberkin_account_action' => 'resume', 'id' => $sub_id, '_memberkin_nonce' => $nonce( 'resume', $sub_id ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->post( array( $account, 'handle_post' ), array( 'memberkin_account_action' => 'cancel', 'id' => $sub_id, '_memberkin_nonce' => $nonce( 'cancel', $sub_id ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$sub = memberkin()->get( SubscriptionRepository::class )->find( $sub_id );
		$this->assertTrue( $sub->cancel_at_period_end );
		$this->assertTrue( memberkin_has_level( $level->id, $user ), 'access until period end' );

		// Another member cannot act on this subscription.
		wp_set_current_user( self::factory()->user->create() );
		$this->post( array( $account, 'handle_post' ), array( 'memberkin_account_action' => 'reactivate', 'id' => $sub_id, '_memberkin_nonce' => $nonce( 'reactivate', $sub_id ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( memberkin()->get( SubscriptionRepository::class )->find( $sub_id )->cancel_at_period_end, 'IDOR blocked' );
	}

	public function test_change_plan_link_for_upgrade_path_groups(): void {
		$service = memberkin()->get( \Memberkin\Service\LevelService::class );
		$path    = $service->save_group( array( 'name' => 'Plans', 'is_upgrade_path' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$plain   = $service->save_group( array( 'name' => 'Extras', 'allow_multiple' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$basic   = $this->level( array( 'name' => 'Basic', 'group_id' => $path->id ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$addon   = $this->level( array( 'name' => 'Add-on', 'group_id' => $plain->id ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$user    = self::factory()->user->create();
		memberkin_grant_membership( $user, $basic->id );
		wp_set_current_user( $user );
		$html = do_shortcode( '[memberkin_account]' );
		$this->assertSame( 1, substr_count( $html, 'Change plan' ) );
		$this->assertStringContainsString( 'group=' . $path->id, html_entity_decode( $html ) );

		memberkin_grant_membership( $user, $addon->id );
		$this->assertSame( 1, substr_count( do_shortcode( '[memberkin_account]' ), 'Change plan' ), 'no link for groups without an upgrade path' );
	}

	public function test_profile_change_requires_current_password(): void {
		$user = self::factory()->user->create( array( 'user_email' => 'before@example.org', 'user_pass' => 'old password 1' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		wp_set_current_user( $user );
		$account = new AccountController();
		$nonce   = wp_create_nonce( 'memberkin_account_profile_0' );
		$this->post( array( $account, 'handle_post' ), array( 'memberkin_account_action' => 'profile', 'id' => 0, '_memberkin_nonce' => $nonce, 'email' => 'after@example.org', 'first_name' => 'Ann' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'before@example.org', get_userdata( $user )->user_email );
		$this->post( array( $account, 'handle_post' ), array( 'memberkin_account_action' => 'profile', 'id' => 0, '_memberkin_nonce' => $nonce, 'email' => 'after@example.org', 'first_name' => 'Ann', 'current_password' => 'old password 1' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		clean_user_cache( $user );
		$this->assertSame( 'after@example.org', get_userdata( $user )->user_email );
		$this->assertSame( 'Ann', get_userdata( $user )->first_name );
	}

	public function test_login_page_lost_password_and_redirects(): void {
		$login = new LoginController();
		$login->register();
		$this->assertStringContainsString( (string) get_permalink( Settings::get( 'pages', 'login' ) ), wp_login_url() );
		$this->assertStringContainsString( 'action=lostpassword', wp_lostpassword_url() );
		$this->assertStringContainsString( 'loginform', do_shortcode( '[memberkin_login]' ) );

		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		reset_phpmailer_instance();
		$this->post( array( $login, 'handle_post' ), array( 'memberkin_login_action' => 'lostpassword', '_memberkin_nonce' => wp_create_nonce( 'memberkin_lostpassword' ), 'user_login' => get_userdata( $member )->user_login ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertStringContainsString( 'action=rp', $mail->body );
		$this->assertStringContainsString( (string) get_permalink( Settings::get( 'pages', 'login' ) ), str_replace( '&amp;', '&', $mail->body ) );

		$redirect = $login->login_redirect( admin_url(), '', get_userdata( $member ) );
		$this->assertSame( get_permalink( Settings::get( 'pages', 'account' ) ), $redirect, 'members land on their account page' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertSame( admin_url(), $login->login_redirect( admin_url(), '', get_userdata( $admin ) ) );
	}
}
