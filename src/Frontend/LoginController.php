<?php
/**
 * [memberkin_login]: login, lost password, reset password; login/logout redirects; wp-admin rules.
 *
 * @package Memberkin
 */

namespace Memberkin\Frontend;

use Memberkin\Core\Settings;
use Memberkin\Core\Templates;
use Memberkin\Repository\LevelRepository;
use Memberkin\Service\MembershipService;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend authentication pages built on WordPress core functions (authentication itself stays in core).
 */
final class LoginController {

	/**
	 * Message for the current request.
	 *
	 * @var array{type:string,text:string}|null
	 */
	private ?array $notice = null;

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_shortcode( 'memberkin_login', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_post' ), 5 );

		add_filter( 'login_url', array( $this, 'login_url' ), 10, 2 );
		add_filter( 'lostpassword_url', array( $this, 'lostpassword_url' ), 10, 2 );
		add_filter( 'retrieve_password_notification_email', array( $this, 'reset_email' ), 10, 4 );
		add_action( 'wp_login_failed', array( $this, 'login_failed' ), 10, 2 );
		add_filter( 'login_redirect', array( $this, 'login_redirect' ), 20, 3 );
		add_filter( 'logout_redirect', array( $this, 'logout_redirect' ), 20, 3 );
		add_filter( 'show_admin_bar', array( $this, 'show_admin_bar' ) );
		add_action( 'admin_init', array( $this, 'block_wp_admin' ), 1 );
		add_action( 'login_form_register', array( $this, 'disable_wp_registration' ) );
		add_filter( 'register_url', array( $this, 'register_url' ) );
	}

	/**
	 * Login page URL ('' when not configured or disabled).
	 */
	private function page(): string {
		return Settings::get( 'accounts', 'frontend_login', true ) ? memberkin_page_url( 'login' ) : '';
	}

	/**
	 * Whether a user is a "member" for redirect purposes (cannot edit content).
	 *
	 * @param \WP_User $user User.
	 */
	private static function is_member_only( \WP_User $user ): bool {
		return ! user_can( $user, 'edit_posts' ) && ! user_can( $user, \Memberkin\Core\Capabilities::VIEW_MEMBERS );
	}

	/**
	 * Points login links to the Login page.
	 *
	 * @param string $url      Default URL.
	 * @param string $redirect Redirect target.
	 */
	public function login_url( string $url, string $redirect ): string {
		$page = $this->page();
		if ( '' === $page || is_admin() || ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) ) {
			return $url;
		}
		return '' !== $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $page ) : $page;
	}

	/**
	 * Points "lost password" links to the Login page.
	 *
	 * @param string $url      Default.
	 * @param string $redirect Redirect.
	 */
	public function lostpassword_url( string $url, string $redirect ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- hook signature.
		$page = $this->page();
		return '' === $page ? $url : add_query_arg( 'action', 'lostpassword', $page );
	}

	/**
	 * Uses the Login page for the reset link in the password reset email.
	 *
	 * @param array    $email      Email (to, subject, message, headers).
	 * @param string   $key        Reset key.
	 * @param string   $user_login Login.
	 * @param \WP_User $user       User.
	 * @return array
	 */
	public function reset_email( array $email, string $key, string $user_login, $user ): array {
		$page = $this->page();
		if ( '' === $page || ( $user instanceof \WP_User && ! self::is_member_only( $user ) ) ) {
			return $email;
		}
		$link             = add_query_arg(
			array(
				'action' => 'rp',
				'key'    => $key,
				'login'  => rawurlencode( $user_login ),
			),
			$page
		);
		$email['message'] = sprintf(
			/* translators: 1: site name, 2: username, 3: reset link. */
			__( "Someone requested a password reset for this account on %1\$s.\n\nUsername: %2\$s\n\nIf this was a mistake, ignore this email and nothing will happen.\n\nTo set a new password, visit:\n%3\$s\n", 'memberkin' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			$user_login,
			$link
		);
		return $email;
	}

	/**
	 * Sends failed logins from the Login page back to it (instead of wp-login.php).
	 *
	 * @param string         $username Username.
	 * @param \WP_Error|null $error    Why it failed (a lockout shows its own message).
	 */
	public function login_failed( string $username, $error = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- hook signature.
		$page    = $this->page();
		$referer = wp_get_referer();
		if ( '' !== $page && $referer && str_starts_with( strtok( $referer, '?' ), strtok( $page, '?' ) ) ) {
			wp_safe_redirect( add_query_arg( 'login', is_wp_error( $error ) && 'memberkin_login_locked' === $error->get_error_code() ? 'locked' : 'failed', $page ) );
			exit;
		}
	}

	/**
	 * Where members land after logging in.
	 *
	 * @param string             $redirect_to Requested redirect.
	 * @param string             $requested   Requested (raw).
	 * @param \WP_User|\WP_Error $user        User.
	 */
	public function login_redirect( string $redirect_to, string $requested, $user ): string {
		if ( ! $user instanceof \WP_User || ! self::is_member_only( $user ) ) {
			return $redirect_to;
		}
		if ( '' !== $requested && ! str_contains( $requested, 'wp-admin' ) ) {
			return $redirect_to; // Honour explicit targets such as the page they came from.
		}
		$levels = memberkin()->get( LevelRepository::class );
		foreach ( memberkin()->get( MembershipService::class )->active_level_ids( $user->ID ) as $level_id ) {
			$url = (string) $levels->meta( $level_id, 'login_redirect', '' );
			if ( '' !== $url ) {
				return $url;
			}
		}
		$global = (string) Settings::get( 'accounts', 'login_redirect', '' );
		if ( '' !== $global ) {
			return $global;
		}
		$account = memberkin_page_url( 'account' );
		return '' !== $account ? $account : home_url( '/' );
	}

	/**
	 * Logout redirect.
	 *
	 * @param string $redirect_to Redirect.
	 * @param string $requested   Requested.
	 * @param mixed  $user        User.
	 */
	public function logout_redirect( string $redirect_to, string $requested, $user ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- hook signature.
		$url = (string) Settings::get( 'accounts', 'logout_redirect', '' );
		return '' === $requested && '' !== $url ? $url : $redirect_to;
	}

	/**
	 * Hides the admin bar for members.
	 *
	 * @param bool $show Show.
	 */
	public function show_admin_bar( bool $show ): bool {
		if ( $show && is_user_logged_in() && Settings::get( 'accounts', 'hide_admin_bar', true ) && self::is_member_only( wp_get_current_user() ) ) {
			return false;
		}
		return $show;
	}

	/**
	 * Keeps members out of wp-admin (AJAX, admin-post and profile.php for password changes are allowed).
	 */
	public function block_wp_admin(): void {
		if ( wp_doing_ajax() || ! is_user_logged_in() || ! Settings::get( 'accounts', 'block_wp_admin', true ) ) {
			return;
		}
		global $pagenow;
		if ( in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php', 'async-upload.php' ), true ) ) {
			return;
		}
		if ( self::is_member_only( wp_get_current_user() ) ) {
			$account = memberkin_page_url( 'account' );
			wp_safe_redirect( '' !== $account ? $account : home_url( '/' ) );
			exit;
		}
	}

	/**
	 * Sends wp-login.php?action=register to the levels page when WP registration is disabled.
	 */
	public function disable_wp_registration(): void {
		if ( Settings::get( 'accounts', 'disable_wp_registration', false ) ) {
			$levels = memberkin_page_url( 'levels' );
			wp_safe_redirect( '' !== $levels ? $levels : home_url( '/' ) );
			exit;
		}
	}

	/**
	 * Registration link goes to the levels page.
	 *
	 * @param string $url URL.
	 */
	public function register_url( string $url ): string {
		$levels = memberkin_page_url( 'levels' );
		return Settings::get( 'accounts', 'disable_wp_registration', false ) && '' !== $levels ? $levels : $url;
	}

	/**
	 * Handles lost/reset password POSTs.
	 */
	public function handle_post(): void {
		if ( 'post' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) || empty( $_POST['memberkin_login_action'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['memberkin_login_action'] ) );
		if ( ! isset( $_POST['_memberkin_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_memberkin_nonce'] ) ), 'memberkin_' . $action ) ) {
			$this->notice = array(
				'type' => 'error',
				'text' => __( 'Your session expired. Please try again.', 'memberkin' ),
			);
			return;
		}
		if ( 'lostpassword' === $action ) {
			/**
			 * Return a message to refuse a submission of one of the plugin's forms (CAPTCHA, spam checks).
			 *
			 * @param string $message '' = allowed.
			 * @param string $form    lostpassword|signup.
			 * @param array  $post    Submitted data (unslashed).
			 */
			$blocked = (string) apply_filters( 'memberkin_form_blocked', '', 'lostpassword', wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each consumer sanitises what it reads.
			if ( '' !== $blocked ) {
				$this->notice = array(
					'type' => 'error',
					'text' => $blocked,
				);
				return;
			}
			$login = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';
			if ( '' !== $login ) {
				retrieve_password( $login ); // Result intentionally ignored: never reveal whether an account exists.
			}
			$this->notice = array(
				'type' => 'success',
				'text' => __( 'If an account matches, we have sent an email with a link to reset your password.', 'memberkin' ),
			);
			return;
		}
		if ( 'resetpass' === $action ) {
			$key   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
			$login = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ) ) : '';
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$pass1 = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords are not sanitised.
			$pass2 = isset( $_POST['pass2'] ) ? (string) wp_unslash( $_POST['pass2'] ) : '';
			// phpcs:enable
			$user = check_password_reset_key( $key, $login );
			if ( is_wp_error( $user ) ) {
				$this->notice = array(
					'type' => 'error',
					'text' => __( 'This password reset link is invalid or has expired. Please request a new one.', 'memberkin' ),
				);
				return;
			}
			$message = strlen( $pass1 ) < 8 ? __( 'Your password must be at least 8 characters long.', 'memberkin' ) : ( $pass1 !== $pass2 ? __( 'The passwords do not match.', 'memberkin' ) : (string) apply_filters(
				'memberkin_validate_password',
				'',
				$pass1,
				array(
					'email' => $user->user_email,
					'user'  => $user,
				)
			) );
			if ( '' !== $message ) {
				$this->notice = array(
					'type' => 'error',
					'text' => $message,
				);
				return;
			}
			reset_password( $user, $pass1 );
			$target = '' !== $this->page() ? $this->page() : home_url( '/' );
			wp_safe_redirect( add_query_arg( 'password', 'changed', $target ) );
			exit;
		}
	}

	/**
	 * Renders the shortcode.
	 *
	 * @return string
	 */
	public function render(): string {
		wp_enqueue_style( 'memberkin-frontend' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only query args.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$notice = $this->notice;
		if ( ! $notice && isset( $_GET['login'] ) && 'failed' === sanitize_key( wp_unslash( $_GET['login'] ) ) ) {
			$notice = array(
				'type' => 'error',
				'text' => __( 'The username or password is incorrect.', 'memberkin' ),
			);
		}
		if ( ! $notice && isset( $_GET['login'] ) && 'locked' === sanitize_key( wp_unslash( $_GET['login'] ) ) ) {
			$notice = array(
				'type' => 'error',
				'text' => __( 'Too many failed login attempts. Please wait 15 minutes and try again.', 'memberkin' ),
			);
		}
		if ( ! $notice && isset( $_GET['password'] ) && 'changed' === $_GET['password'] ) {
			$notice = array(
				'type' => 'success',
				'text' => __( 'Your password was changed. You can log in now.', 'memberkin' ),
			);
		}
		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$login    = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : '';
		$login    = in_array( $login, array( 'failed', 'locked' ), true ) ? '' : sanitize_user( rawurldecode( $login ) );
		// phpcs:enable

		if ( is_user_logged_in() ) {
			return Templates::render(
				'login/logged-in',
				array(
					'user'        => wp_get_current_user(),
					'account_url' => memberkin_page_url( 'account' ),
				)
			);
		}
		if ( 'lostpassword' === $action ) {
			return Templates::render( 'login/lost-password', array( 'notice' => $notice ) );
		}
		if ( 'rp' === $action && '' !== $key && '' !== $login ) {
			$valid = ! is_wp_error( check_password_reset_key( $key, $login ) );
			/**
		 * Fires when a form with a new-password field renders, for add-ons to enqueue their assets (strength meter).
		 */
			do_action( 'memberkin_password_form_enqueue_scripts' );
			return Templates::render(
				'login/reset-password',
				array(
					'notice' => $notice,
					'key'    => $key,
					'login'  => $login,
					'valid'  => $valid,
				)
			);
		}
		$wp_redirect = wp_validate_redirect( $redirect, '' );
		return Templates::render(
			'login/form',
			array(
				'notice'   => $notice,
				'redirect' => '' !== $wp_redirect ? $wp_redirect : '',
			)
		);
	}
}
