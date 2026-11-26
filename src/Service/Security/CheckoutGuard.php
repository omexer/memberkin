<?php
/**
 * Checkout abuse protection.
 *
 * @package Memberkin
 */

namespace Memberkin\Service\Security;

use Memberkin\Core\Events;
use Memberkin\Core\Logger;
use Memberkin\Core\Settings;
use Memberkin\Core\Throttle;
use Memberkin\Domain\Order;
use Memberkin\Modules\Emails\EmailService;

defined( 'ABSPATH' ) || exit;

/**
 * - Submissions per IP per hour (scripted checkouts).
 * - Declined payments per IP and per email per hour.
 * - Card testing: when declines across the whole site pass a threshold within 10 minutes, checkouts that would create a
 *   new account are paused for 30 minutes and the admins are emailed (logged-in members can still pay).
 * All limits are on Settings → Advanced; 0 turns a limit off.
 */
final class CheckoutGuard {

	public const CARD_TESTING_FLAG = 'memberkin_card_testing';

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_filter( 'memberkin_checkout_blocked', array( $this, 'check' ), 10, 3 );
		add_action( 'memberkin_checkout_failed', array( $this, 'failed' ), 10, 2 );
	}

	/**
	 * Limit from settings.
	 *
	 * @param string $key Key.
	 */
	private static function limit( string $key ): int {
		return max( 0, (int) Settings::get( 'security', $key, 0 ) );
	}

	/**
	 * Blocks a checkout submission when a limit is reached ('' = allowed).
	 *
	 * @param string $message Earlier result.
	 * @param array  $input   Checkout input (ip, account.email).
	 * @param int    $user_id Logged-in user (0 = guest).
	 */
	public function check( string $message, array $input, int $user_id ): string {
		if ( '' !== $message ) {
			return $message;
		}
		$ip    = (string) ( $input['ip'] ?? '' );
		$email = $user_id ? (string) get_userdata( $user_id )->user_email : sanitize_email( (string) ( $input['account']['email'] ?? '' ) );
		$wait  = __( 'Too many attempts. Please try again later or contact us.', 'memberkin' );

		$submits = self::limit( 'checkout_submit_limit' );
		if ( $submits && Throttle::hit( 'co_submit', $ip, HOUR_IN_SECONDS ) > $submits ) {
			return $wait;
		}
		$fails = self::limit( 'checkout_fail_limit' );
		if ( $fails && ( Throttle::count( 'co_fail_ip', $ip, HOUR_IN_SECONDS ) >= $fails || Throttle::count( 'co_fail_email', $email, HOUR_IN_SECONDS ) >= $fails ) ) {
			return __( 'Too many declined payments. Please try again in an hour or contact us.', 'memberkin' );
		}
		if ( ! $user_id && get_transient( self::CARD_TESTING_FLAG ) ) {
			return __( 'New sign-ups are paused for a short time for security reasons. Please try again in 30 minutes.', 'memberkin' );
		}
		return '';
	}

	/**
	 * Counts a declined checkout payment.
	 *
	 * @param Order  $order   Order.
	 * @param string $message Decline message.
	 */
	public function failed( Order $order, string $message ): void {
		if ( '' === $order->gateway ) {
			return;
		}
		$gateway = memberkin()->get( \Memberkin\Gateway\GatewayRegistry::class )->get( $order->gateway );
		if ( $gateway && $gateway->supports( \Memberkin\Gateway\Contracts\Capability::MANUAL_CONFIRMATION ) ) {
			return; // Manual payments are not card attempts.
		}
		$user = get_userdata( $order->user_id );
		Throttle::hit( 'co_fail_ip', (string) $order->ip_address, HOUR_IN_SECONDS );
		Throttle::hit( 'co_fail_email', $user ? $user->user_email : (string) ( $order->billing['email'] ?? '' ), HOUR_IN_SECONDS );

		$threshold = self::limit( 'card_testing_threshold' );
		if ( ! $threshold ) {
			return;
		}
		$declines = Throttle::hit( 'co_declines', 'site', 10 * MINUTE_IN_SECONDS );
		if ( $declines >= $threshold && ! get_transient( self::CARD_TESTING_FLAG ) ) {
			set_transient( self::CARD_TESTING_FLAG, 1, 30 * MINUTE_IN_SECONDS );
			/* translators: %d: number of declines. */
			$text = sprintf( __( '%d card payments were declined in the last 10 minutes. This often means someone is testing stolen cards. New sign-ups are paused for 30 minutes; logged-in members can still pay.', 'memberkin' ), $declines );
			Logger::warning( 'security', $text );
			Events::record( 'security.card_testing', 0, 'site', 0, array( 'declines' => $declines ) );
			$recipients = EmailService::admin_recipients();
			if ( $recipients ) {
				wp_mail( $recipients, sprintf( '[%s] %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), __( 'Possible card testing on your checkout', 'memberkin' ) ), $text );
			}
		}
		unset( $message );
	}
}
