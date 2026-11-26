<?php
/**
 * Emails: templates, rendering, delivery, log and notifications.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Clock;
use Memberkin\Core\Db;
use Memberkin\Core\Jobs;
use Memberkin\Core\Money;
use Memberkin\Core\SafeMode;
use Memberkin\Core\Settings;
use Memberkin\Modules\Emails\EmailRenderer;
use Memberkin\Modules\Emails\EmailService;
use Memberkin\Modules\Emails\EmailTemplates;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\OrderService;
use Memberkin\Service\RenewalService;
use Memberkin\Service\SubscriptionService;

/**
 * @covers \Memberkin\Modules\Emails\EmailService
 * @covers \Memberkin\Modules\Emails\EmailRenderer
 * @covers \Memberkin\Modules\Emails\Notifications
 */
final class EmailsTest extends BillingTestCase {

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		SafeMode::confirm_live();
		Settings::update(
			'emails',
			array(
				'from_name'    => 'Club "HQ"',
				'from_email'   => 'club@example.org',
				'admin_emails' => 'boss@example.org, ops@example.org',
			)
		);
	}

	/**
	 * Templates sent so far (from the log).
	 *
	 * @return string[]
	 */
	private function sent_templates(): array {
		global $wpdb;
		return $wpdb->get_col( 'SELECT template FROM ' . Db::table_name( 'email_log' ) . " WHERE status = 'sent' ORDER BY id ASC" );
	}

	private function mails(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	public function test_render_escapes_values_and_keeps_markup(): void {
		$user = self::factory()->user->create_and_get( array( 'display_name' => 'Ann & Bob' ) );
		$out  = EmailRenderer::render( array( 'subject' => 'Hi {member_name} {error}', 'body' => '<p><strong>{member_name}</strong> {error} <a href="{account_url}">go</a></p>' ), array( 'user' => $user, 'error' => '<script>alert(1)</script>' ), 'x' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringNotContainsString( '<script>', $out['body'] );
		$this->assertStringContainsString( '&lt;script&gt;', $out['body'] );
		$this->assertStringContainsString( '<strong>Ann &amp; Bob</strong>', $out['body'] );
		$this->assertStringNotContainsString( '<script>', $out['subject'], 'subject has tags stripped' );
	}

	public function test_template_save_disable_and_reset(): void {
		EmailTemplates::save( 'checkout_free', false, 'Custom {site_name}', '<p>Custom</p>' );
		$this->assertFalse( EmailTemplates::get( 'checkout_free' )['enabled'] );
		$this->assertSame( array(), memberkin()->get( EmailService::class )->send( 'checkout_free', array( 'user' => wp_get_current_user() ) ) );
		EmailTemplates::reset( 'checkout_free' );
		$this->assertTrue( EmailTemplates::get( 'checkout_free' )['enabled'] );
	}

	public function test_delivery_log_and_body_retention(): void {
		$user = self::factory()->user->create_and_get( array( 'user_email' => 'm@example.org' ) );
		$ids  = memberkin()->get( EmailService::class )->send( 'checkout_free', array( 'user' => $user ) );
		$this->assertCount( 1, $ids );
		$mail = $this->mails()[0];
		$this->assertSame( 'm@example.org', $mail['to'][0][0] );
		$this->assertStringContainsString( 'club@example.org', $mail['header'] );
		$this->assertStringContainsString( 'text/html', $mail['header'] );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::table_name( 'email_log' ) . ' WHERE id = %d', $ids[0] ) );
		$this->assertSame( 'sent', $row->status );
		$this->assertNull( $row->body, 'bodies are dropped after sending by default' );

		$admin = memberkin()->get( EmailService::class )->send( 'admin_checkout', array( 'user' => $user ) );
		$this->assertCount( 2, $admin, 'one per admin recipient' );
	}

	public function test_safe_mode_pauses_member_mail_only(): void {
		update_option( 'home', 'http://clone.example.org' );
		$user = self::factory()->user->create_and_get();
		memberkin()->get( EmailService::class )->send( 'checkout_free', array( 'user' => $user ) );
		memberkin()->get( EmailService::class )->send( 'admin_checkout', array( 'user' => $user ) );
		global $wpdb;
		$this->assertSame( array( 'skipped' ), $wpdb->get_col( 'SELECT DISTINCT status FROM ' . Db::table_name( 'email_log' ) . " WHERE template = 'checkout_free'" ) );
		$this->assertCount( 2, $this->mails(), 'admin emails still go out' );
	}

	public function test_failed_delivery_is_retried(): void {
		add_filter( 'pre_wp_mail', static fn() => false );
		$user = self::factory()->user->create_and_get();
		$ids  = memberkin()->get( EmailService::class )->send( 'checkout_free', array( 'user' => $user ) );
		global $wpdb;
		$this->assertSame( 'failed', $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Db::table_name( 'email_log' ) . ' WHERE id = %d', $ids[0] ) ) );
		$this->assertTrue( Jobs::is_scheduled( 'memberkin_send_email', array( $ids[0] ) ) );
		$this->assertTrue( memberkin()->get( EmailService::class )->resend( $ids[0] ), 'body kept while unsent' );
	}

	public function test_checkout_emails(): void {
		$paid = $this->level(
			array(
				'name'          => 'Paid',
				'billing_type'  => 'one_time',
				'initial_price' => '10',
			)
		);
		$this->member_checkout( self::factory()->user->create(), $paid );
		$this->assertSame( array( 'checkout_paid', 'admin_checkout', 'admin_checkout' ), $this->sent_templates() );
		$this->assertStringContainsString( 'Paid', $this->mails()[0]['body'] );

		$free = $this->level(
			array(
				'name'         => 'Free',
				'billing_type' => 'free',
			)
		);
		$this->member_checkout( self::factory()->user->create(), $free, array( 'gateway' => '' ) );
		$this->assertContains( 'checkout_free', $this->sent_templates() );

		$offline = $this->member_checkout( self::factory()->user->create(), $paid, array( 'gateway' => 'manual' ) );
		$this->assertContains( 'checkout_pending', $this->sent_templates() );
		memberkin()->get( OrderService::class )->mark_paid( $offline->order->id );
		$this->assertSame( 2, count( array_keys( $this->sent_templates(), 'checkout_paid', true ) ), 'payment received email after marking paid' );
	}

	public function test_refund_email_sent_once(): void {
		$level = $this->level(
			array(
				'billing_type'  => 'one_time',
				'initial_price' => '10',
			)
		);
		$order = $this->member_checkout( self::factory()->user->create(), $level )->order;
		memberkin()->get( OrderService::class )->record_refund( $order->id, Money::of( 500, 'USD' ), 'x', 're_once' );
		memberkin()->get( OrderService::class )->record_refund( $order->id, Money::of( 500, 'USD' ), 'x', 're_once' );
		$this->assertSame( 1, count( array_keys( $this->sent_templates(), 'refund', true ) ) );
		$bodies = implode( ' ', array_column( $this->mails(), 'body' ) );
		$this->assertStringContainsString( 'We refunded <strong>$5.00</strong>', $bodies );
	}

	public function test_renewal_failure_and_final_emails(): void {
		Clock::freeze( '2026-01-01 00:00:00' );
		$level  = $this->level(
			array(
				'name'            => 'Monthly',
				'billing_type'    => 'recurring',
				'initial_price'   => '10',
				'recurring_price' => '10',
			)
		);
		$sub_id = $this->member_checkout( self::factory()->user->create(), $level )->order->subscription_id;
		Clock::freeze( '2026-02-01 01:00:00' );
		memberkin()->get( RenewalService::class )->charge( $sub_id );
		$this->assertContains( 'renewal_receipt', $this->sent_templates() );

		global $wpdb;
		$sub = memberkin()->get( SubscriptionRepository::class )->find( $sub_id );
		$wpdb->update( Db::table_name( 'payment_methods' ), array( 'token' => 'tok_decline' ), array( 'id' => $sub->payment_method_id ) );
		Clock::freeze( '2026-03-01 01:00:00' );
		memberkin()->get( RenewalService::class )->charge( $sub_id );
		$this->assertContains( 'payment_failed', $this->sent_templates() );
		$this->assertContains( 'admin_payment_failed', $this->sent_templates() );
		$failed = array_values( array_filter( $this->mails(), static fn( $m ) => str_contains( $m['subject'], 'payment failed' ) ) );
		$this->assertStringContainsString( 'We will try again on', $failed[0]['body'] );

		foreach ( array( '2026-03-02', '2026-03-05', '2026-03-10', '2026-03-17' ) as $day ) {
			Clock::freeze( $day . ' 02:00:00' );
			memberkin()->get( RenewalService::class )->charge( $sub_id );
		}
		$this->assertContains( 'payment_failed_final', $this->sent_templates() );
		$this->assertSame( 1, count( array_keys( $this->sent_templates(), 'payment_failed_final', true ) ) );
	}

	public function test_cancel_pause_grant_and_expiry_emails(): void {
		Clock::freeze( '2026-01-01 00:00:00' );
		$level  = $this->level(
			array(
				'billing_type'    => 'recurring',
				'initial_price'   => '10',
				'recurring_price' => '10',
			)
		);
		$sub_id = $this->member_checkout( self::factory()->user->create(), $level )->order->subscription_id;
		memberkin()->get( SubscriptionService::class )->pause( $sub_id );
		memberkin()->get( SubscriptionService::class )->resume( $sub_id );
		memberkin()->get( SubscriptionService::class )->cancel( $sub_id, true, 'member' );
		$sent = $this->sent_templates();
		foreach ( array( 'subscription_paused', 'subscription_resumed', 'subscription_cancelled', 'admin_cancelled' ) as $template ) {
			$this->assertContains( $template, $sent );
		}

		$fixed = $this->level(
			array(
				'billing_type'      => 'one_time',
				'initial_price'     => '5',
				'expiration_type'   => 'duration',
				'expiration_length' => 1,
				'expiration_period' => 'month',
			)
		);
		$user  = self::factory()->user->create();
		memberkin_grant_membership(
			$user,
			$fixed->id,
			array(
				'source'  => 'admin',
				'context' => array( 'notify' => true ),
			)
		);
		$this->assertContains( 'membership_granted', $this->sent_templates() );
		$silent = self::factory()->user->create();
		memberkin_grant_membership(
			$silent,
			$fixed->id,
			array(
				'source'  => 'admin',
				'context' => array( 'notify' => false ),
			)
		);
		$this->assertSame( 1, count( array_keys( $this->sent_templates(), 'membership_granted', true ) ) );

		Clock::freeze( '2026-02-02 00:00:00' );
		do_action( 'memberkin_expire_memberships' );
		$this->assertContains( 'membership_expired', $this->sent_templates() );
	}

	public function test_admin_screen_lists_and_toggles_emails(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$screen = new \Memberkin\Modules\Emails\Admin\EmailsScreen( memberkin() );
		ob_start();
		$screen->render();
		$list = (string) ob_get_clean();
		$this->assertStringContainsString( 'Payment failed', $list );
		$this->assertStringContainsString( 'memberkin_toggle_email', $list );
		$this->assertStringNotContainsString( 'memberkin_save_email', $list, 'no template editor without an add-on' );

		$_GET = array( 'email' => 'payment_failed' );
		ob_start();
		$screen->render();
		$edit = (string) ob_get_clean();
		$_GET = array();
		$this->assertStringNotContainsString( 'memberkin_save_email', $edit, 'falls back to the list' );

		EmailTemplates::set_enabled( 'payment_failed', false );
		$this->assertFalse( EmailTemplates::get( 'payment_failed' )['enabled'] );
		EmailTemplates::set_enabled( 'payment_failed', true );
		$this->assertTrue( EmailTemplates::get( 'payment_failed' )['enabled'] );
	}
}
