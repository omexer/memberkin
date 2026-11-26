<?php
/**
 * [memberkin_account] dashboard and [memberkin_profile].
 *
 * @package Memberkin
 */

namespace Memberkin\Frontend;

use Memberkin\Core\Clock;
use Memberkin\Core\Settings;
use Memberkin\Core\Templates;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Membership;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\Contracts\Capability;
use Memberkin\Gateway\GatewayRegistry;
use Memberkin\Repository\LevelGroupRepository;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\MembershipRepository;
use Memberkin\Repository\OrderRepository;
use Memberkin\Repository\PaymentMethodRepository;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\MembershipService;
use Memberkin\Service\SubscriptionService;

defined( 'ABSPATH' ) || exit;

/**
 * Member self-service. Every action checks the nonce and that the object belongs to the current user.
 */
final class AccountController {

	/**
	 * Notice for this request.
	 *
	 * @var array{type:string,text:string}|null
	 */
	private ?array $notice = null;

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_shortcode( 'memberkin_account', array( $this, 'render_account' ) );
		add_shortcode( 'memberkin_profile', array( $this, 'render_profile' ) );
		add_action( 'template_redirect', array( $this, 'handle_post' ), 5 );
	}

	/**
	 * Flash-style notice via query arg after redirects.
	 */
	private function notice_from_url(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code     = isset( $_GET['memberkin_notice'] ) ? sanitize_key( wp_unslash( $_GET['memberkin_notice'] ) ) : '';
		$messages = array(
			'cancelled'   => __( 'Your subscription was cancelled. You keep access until the end of the paid period.', 'memberkin' ),
			'ended'       => __( 'Your membership was cancelled.', 'memberkin' ),
			'reactivated' => __( 'Your subscription will continue.', 'memberkin' ),
			'paused'      => __( 'Your subscription is paused.', 'memberkin' ),
			'resumed'     => __( 'Your subscription was resumed.', 'memberkin' ),
			'profile'     => __( 'Your profile was updated.', 'memberkin' ),
			'card'        => __( 'Your payment method was updated.', 'memberkin' ),
		);
		if ( 'card_failed' === $code ) {
			return array(
				'type' => 'error',
				'text' => __( 'Your payment method could not be updated. Please try again.', 'memberkin' ),
			);
		}
		return isset( $messages[ $code ] ) ? array(
			'type' => 'success',
			'text' => $messages[ $code ],
		) : null;
	}

	/**
	 * Handles account POSTs.
	 *
	 * @throws ValidationException Internally for disallowed actions (caught and shown as notices).
	 */
	public function handle_post(): void {
		if ( 'post' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) || empty( $_POST['memberkin_account_action'] ) || ! is_user_logged_in() ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['memberkin_account_action'] ) );
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! isset( $_POST['_memberkin_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_memberkin_nonce'] ) ), 'memberkin_account_' . $action . '_' . $id ) ) {
			$this->notice = array(
				'type' => 'error',
				'text' => __( 'Your session expired. Please try again.', 'memberkin' ),
			);
			return;
		}
		$user_id = get_current_user_id();
		$code    = '';
		try {
			if ( 'profile' === $action ) {
				$this->save_profile( $user_id, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in save_profile().
				$code = 'profile';
			} elseif ( 'cancel_membership' === $action ) {
				$m = memberkin()->get( MembershipRepository::class )->find( $id );
				if ( ! $m || $m->user_id !== $user_id || $m->subscription_id || ! Settings::get( 'accounts', 'allow_member_cancel', true ) ) {
					throw new ValidationException( array( esc_html__( 'This membership cannot be cancelled here.', 'memberkin' ) ) );
				}
				memberkin()->get( MembershipService::class )->cancel( $m->id, null, array( 'reason' => 'member' ) );
				$code = 'ended';
			} else {
				$sub = memberkin()->get( SubscriptionRepository::class )->find( $id );
				if ( ! $sub || $sub->user_id !== $user_id ) {
					throw new ValidationException( array( esc_html__( 'Subscription not found.', 'memberkin' ) ) );
				}
				$code = $this->subscription_action( $action, $sub );
			}
		} catch ( ValidationException $e ) {
			$this->notice = array(
				'type' => 'error',
				'text' => $e->getMessage(),
			);
			return;
		}
		if ( '' !== $code ) {
			$referer = wp_get_referer();
			$url     = remove_query_arg( array( 'memberkin_notice', 'memberkin_update' ), $referer ? $referer : memberkin_page_url( 'account' ) );
			wp_safe_redirect( add_query_arg( 'memberkin_notice', $code, $url ) );
			exit;
		}
	}

	/**
	 * Member actions on a subscription.
	 *
	 * @param string       $action Action.
	 * @param Subscription $sub    Subscription (owned by the current user).
	 * @return string Notice code.
	 * @throws ValidationException When not allowed.
	 */
	private function subscription_action( string $action, Subscription $sub ): string {
		$service = memberkin()->get( SubscriptionService::class );
		$gateway = memberkin()->get( GatewayRegistry::class )->get( $sub->gateway );
		switch ( $action ) {
			case 'cancel':
				if ( ! Settings::get( 'accounts', 'allow_member_cancel', true ) ) {
					throw new ValidationException( array( esc_html__( 'Please contact us to cancel.', 'memberkin' ) ) );
				}
				$service->cancel( $sub->id, true, 'member' );
				return 'cancelled';
			case 'reactivate':
				$service->reactivate( $sub->id );
				return 'reactivated';
			case 'pause':
				if ( ! self::can_pause( $sub ) ) {
					throw new ValidationException( array( esc_html__( 'This subscription cannot be paused.', 'memberkin' ) ) );
				}
				$days = max( 1, min( (int) Settings::get( 'accounts', 'max_pause_days', 90 ), isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_post().
				$service->pause( $sub->id, Clock::now()->modify( '+' . $days . ' days' ) );
				return 'paused';
			case 'resume':
				$service->resume( $sub->id );
				return 'resumed';
			case 'update_card':
				if ( ! $gateway || ! $gateway->supports( Capability::PAYMENT_METHOD_UPDATE ) ) {
					throw new ValidationException( array( esc_html__( 'The payment method cannot be changed online. Please contact us.', 'memberkin' ) ) );
				}
				$update = $gateway->payment_method_update( $sub, add_query_arg( 'memberkin_notice', 'card', memberkin_page_url( 'account' ) ) );
				if ( 'redirect' === $update->type && '' !== $update->url ) {
					wp_redirect( $update->url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- gateway-hosted page returned by the gateway API.
					exit;
				}
				// On-site forms are rendered by the gateway on the account page (?memberkin_update={id}).
				wp_safe_redirect( add_query_arg( 'memberkin_update', $sub->id, memberkin_page_url( 'account' ) ) );
				exit;
		}
		throw new ValidationException( array( esc_html__( 'Unknown action.', 'memberkin' ) ) );
	}

	/**
	 * Whether a member may pause a subscription.
	 *
	 * @param Subscription $sub Subscription.
	 */
	public static function can_pause( Subscription $sub ): bool {
		if ( ! Settings::get( 'accounts', 'allow_member_pause', false ) || ! in_array( $sub->status, array( Subscription::ACTIVE, Subscription::TRIALING ), true ) || $sub->cancel_at_period_end ) {
			return false;
		}
		if ( 'site' === $sub->billing_model ) {
			return true;
		}
		$gateway = memberkin()->get( GatewayRegistry::class )->get( $sub->gateway );
		return $gateway && $gateway->supports( Capability::PAUSE );
	}

	/**
	 * Saves the profile form.
	 *
	 * @param int   $user_id User.
	 * @param array $post    Unslashed POST.
	 * @throws ValidationException On invalid input.
	 */
	private function save_profile( int $user_id, array $post ): void {
		$user          = get_userdata( $user_id );
		$errors        = array();
		$data          = array(
			'ID'           => $user_id,
			'first_name'   => sanitize_text_field( (string) ( $post['first_name'] ?? '' ) ),
			'last_name'    => sanitize_text_field( (string) ( $post['last_name'] ?? '' ) ),
			'display_name' => sanitize_text_field( (string) ( $post['display_name'] ?? $user->display_name ) ),
		);
		$email         = sanitize_email( (string) ( $post['email'] ?? $user->user_email ) );
		$pass1         = (string) ( $post['pass1'] ?? '' );
		$pass2         = (string) ( $post['pass2'] ?? '' );
		$needs_current = $email !== $user->user_email || '' !== $pass1;
		if ( $needs_current && ! wp_check_password( (string) ( $post['current_password'] ?? '' ), $user->user_pass, $user_id ) ) {
			$errors[] = __( 'Enter your current password to change your email address or password.', 'memberkin' );
		}
		if ( $email !== $user->user_email ) {
			if ( ! is_email( $email ) ) {
				$errors[] = __( 'Enter a valid email address.', 'memberkin' );
			} elseif ( email_exists( $email ) ) {
				$errors[] = __( 'This email address is already used by another account.', 'memberkin' );
			} else {
				$data['user_email'] = $email;
			}
		}
		if ( '' !== $pass1 ) {
			if ( strlen( $pass1 ) < 8 ) {
				$errors[] = __( 'Your password must be at least 8 characters long.', 'memberkin' );
			} elseif ( $pass1 !== $pass2 ) {
				$errors[] = __( 'The passwords do not match.', 'memberkin' );
			} else {
				/** This filter is documented in src/Service/Checkout/CheckoutService.php */
				$weak = (string) apply_filters( 'memberkin_validate_password', '', $pass1, array( 'user' => $user ) );
				if ( '' !== $weak ) {
					$errors[] = $weak;
				} else {
					$data['user_pass'] = $pass1;
				}
			}
		}
		if ( '' === $data['display_name'] ) {
			$data['display_name'] = $user->user_login;
		}
		/**
		 * Filters profile form errors before anything is saved (custom fields validate here).
		 *
		 * @param array $errors  Errors.
		 * @param int   $user_id User.
		 * @param array $post    Submitted data (unslashed).
		 */
		$errors = (array) apply_filters( 'memberkin_profile_validate', $errors, $user_id, $post );
		if ( $errors ) {
			throw new ValidationException( array_map( 'esc_html', $errors ) );
		}
		$result = wp_update_user( $data );
		if ( is_wp_error( $result ) ) {
			throw new ValidationException( array( esc_html( $result->get_error_message() ) ) );
		}
		if ( isset( $data['user_pass'] ) ) {
			// Keep the member logged in after a password change.
			wp_set_auth_cookie( $user_id, false, is_ssl() );
		}
		/**
		 * Fires after a member updated their profile on the frontend.
		 *
		 * @param int   $user_id User.
		 * @param array $post    Submitted data (unslashed, unsanitised).
		 */
		do_action( 'memberkin_profile_updated', $user_id, array_diff_key( $post, array_flip( array( 'pass1', 'pass2', 'current_password' ) ) ) );
	}

	/**
	 * [memberkin_account].
	 *
	 * @return string
	 */
	public function render_account(): string {
		wp_enqueue_style( 'memberkin-frontend' );
		if ( ! is_user_logged_in() ) {
			return Templates::render( 'account/logged-out', array( 'login_url' => wp_login_url( (string) get_permalink() ) ) );
		}
		$user_id     = get_current_user_id();
		$levels      = memberkin()->get( LevelRepository::class );
		$groups      = memberkin()->get( LevelGroupRepository::class );
		$memberships = array_values( array_filter( memberkin()->get( MembershipRepository::class )->all_for_user( $user_id, 50 ), static fn( Membership $m ) => Membership::PENDING !== $m->status && ( $m->is_current() || ( $m->ended_at && $m->ended_at > Clock::now()->modify( '-1 year' ) ) ) ) );
		$subs        = memberkin()->get( SubscriptionRepository::class )->query(
			array(
				'user_id'  => $user_id,
				'statuses' => array( Subscription::TRIALING, Subscription::ACTIVE, Subscription::PAST_DUE, Subscription::UNPAID, Subscription::PAUSED ),
				'per_page' => 50,
			)
		)['items'];
		$orders      = array_values( array_filter( memberkin()->get( OrderRepository::class )->for_user( $user_id, 50 ), static fn( $o ) => 'failed' !== $o->status && 'cancelled' !== $o->status ) );
		$methods     = memberkin()->get( PaymentMethodRepository::class );
		$gateways    = memberkin()->get( GatewayRegistry::class );

		$change_plan = array();
		foreach ( $memberships as $m ) {
			$level = $levels->find( $m->level_id );
			$group = $level ? $groups->find( $level->group_id ) : null;
			if ( $m->grants_access() && $group && $group->is_upgrade_path ) {
				$change_plan[ $m->id ] = add_query_arg( 'group', $group->id, memberkin_page_url( 'levels' ) );
			}
		}

		// On-site payment method update form, if requested.
		$update_form = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$update_id = isset( $_GET['memberkin_update'] ) ? absint( $_GET['memberkin_update'] ) : 0;
		if ( $update_id ) {
			$sub = memberkin()->get( SubscriptionRepository::class )->find( $update_id );
			$gw  = $sub && $sub->user_id === $user_id ? $gateways->get( $sub->gateway ) : null;
			if ( $gw && $gw->supports( Capability::PAYMENT_METHOD_UPDATE ) && $gw->supports( Capability::ONSITE_FIELDS ) ) {
				try {
					$update = $gw->payment_method_update( $sub, add_query_arg( 'memberkin_notice', 'card', memberkin_page_url( 'account' ) ) );
				} catch ( \Throwable $e ) {
					$update = null; // The page still renders; the error is in the gateway log.
				}
				if ( $update && 'form' === $update->type ) {
					/**
					 * Filters the on-site payment method update form of a gateway.
					 *
					 * @param string                                             $html   HTML.
					 * @param Subscription                                       $sub    Subscription.
					 * @param \Memberkin\Gateway\Contracts\PaymentMethodUpdate $update Update data.
					 */
					$update_form = (string) apply_filters( 'memberkin_payment_method_update_form', (string) ( $update->data['fields'] ?? '' ), $sub, $update );
				}
			}
		}

		return Templates::render(
			'account/dashboard',
			array(
				'user'         => wp_get_current_user(),
				'notice'       => $this->notice ?? $this->notice_from_url(),
				'memberships'  => $memberships,
				'subs'         => $subs,
				'orders'       => $orders,
				'levels'       => $levels,
				'methods'      => $methods,
				'gateways'     => $gateways,
				'change_plan'  => $change_plan,
				'allow_cancel' => (bool) Settings::get( 'accounts', 'allow_member_cancel', true ),
				'max_pause'    => (int) Settings::get( 'accounts', 'max_pause_days', 90 ),
				'profile_url'  => memberkin_page_url( 'profile' ),
				'levels_url'   => memberkin_page_url( 'levels' ),
				'update_form'  => $update_form,
				'update_id'    => $update_id,
			)
		);
	}

	/**
	 * [memberkin_profile].
	 *
	 * @return string
	 */
	public function render_profile(): string {
		wp_enqueue_style( 'memberkin-frontend' );
		/**
		 * Fires when a form with a new-password field renders, for add-ons to enqueue their assets (strength meter).
		 */
		do_action( 'memberkin_password_form_enqueue_scripts' );
		if ( ! is_user_logged_in() ) {
			return Templates::render( 'account/logged-out', array( 'login_url' => wp_login_url( (string) get_permalink() ) ) );
		}
		return Templates::render(
			'account/profile',
			array(
				'user'        => wp_get_current_user(),
				'notice'      => $this->notice ?? $this->notice_from_url(),
				'account_url' => memberkin_page_url( 'account' ),
			)
		);
	}

	/**
	 * Nonce field for an account action.
	 *
	 * @param string $action Action.
	 * @param int    $id     Object id.
	 */
	public static function nonce_field( string $action, int $id ): string {
		return '<input type="hidden" name="memberkin_account_action" value="' . esc_attr( $action ) . '" /><input type="hidden" name="id" value="' . (int) $id . '" />' . wp_nonce_field( 'memberkin_account_' . $action . '_' . $id, '_memberkin_nonce', true, false );
	}
}
