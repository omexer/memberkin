<?php
/**
 * Login attempt limits.
 *
 * @package Memberkin
 */

namespace Memberkin\Service\Security;

use Memberkin\Core\Settings;
use Memberkin\Core\Throttle;
use Memberkin\Frontend\CheckoutController;

defined( 'ABSPATH' ) || exit;

/**
 * Applies to every WordPress login (the plugin's login page and wp-login.php): after N failures for one username from
 * one IP within 15 minutes, or 4×N failures from one IP, further attempts are refused until the window passes (even
 * with the right password). A successful login clears the username counter. 0 turns it off.
 */
final class LoginGuard {

	private const WINDOW = 15 * MINUTE_IN_SECONDS;

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_filter( 'authenticate', array( $this, 'authenticate' ), 30, 3 );
		add_action( 'wp_login_failed', array( $this, 'failed' ), 5, 1 ); // Before the login page redirects.
		add_action( 'wp_login', array( $this, 'succeeded' ), 10, 1 );
	}

	/**
	 * Limit.
	 */
	private static function limit(): int {
		return max( 0, (int) Settings::get( 'security', 'login_limit', 0 ) );
	}

	/**
	 * Counter key for a username from the current IP.
	 *
	 * @param string $username Username or email.
	 */
	private static function pair( string $username ): string {
		return CheckoutController::ip() . '|' . strtolower( trim( $username ) );
	}

	/**
	 * Remaining lock time in seconds (0 = not locked).
	 *
	 * @param string $username Username.
	 */
	public static function locked_for( string $username ): int {
		$limit = self::limit();
		if ( ! $limit ) {
			return 0;
		}
		$ip = CheckoutController::ip();
		if ( Throttle::count( 'login_pair', self::pair( $username ), self::WINDOW ) >= $limit ) {
			return Throttle::retry_after( 'login_pair', self::pair( $username ), self::WINDOW );
		}
		if ( Throttle::count( 'login_ip', $ip, self::WINDOW ) >= 4 * $limit ) {
			return Throttle::retry_after( 'login_ip', $ip, self::WINDOW );
		}
		return 0;
	}

	/**
	 * Refuses logins while locked.
	 *
	 * @param \WP_User|\WP_Error|null $user     Result so far.
	 * @param string                  $username Username.
	 * @param string                  $password Password.
	 * @return \WP_User|\WP_Error|null
	 */
	public function authenticate( $user, $username, $password ) {
		unset( $password );
		if ( '' === (string) $username ) {
			return $user;
		}
		$seconds = self::locked_for( (string) $username );
		if ( $seconds > 0 ) {
			return new \WP_Error(
				'memberkin_login_locked',
				/* translators: %d: minutes. */
				sprintf( _n( 'Too many failed login attempts. Please try again in %d minute.', 'Too many failed login attempts. Please try again in %d minutes.', (int) ceil( $seconds / 60 ), 'memberkin' ), (int) ceil( $seconds / 60 ) )
			);
		}
		return $user;
	}

	/**
	 * Counts a failure.
	 *
	 * @param string $username Username.
	 */
	public function failed( $username ): void {
		if ( ! self::limit() ) {
			return;
		}
		Throttle::hit( 'login_pair', self::pair( (string) $username ), self::WINDOW );
		Throttle::hit( 'login_ip', CheckoutController::ip(), self::WINDOW );
	}

	/**
	 * Clears the username counter after a successful login.
	 *
	 * @param string $username Username.
	 */
	public function succeeded( $username ): void {
		Throttle::clear( 'login_pair', self::pair( (string) $username ) );
	}
}
