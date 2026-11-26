<?php
/**
 * Checkout pipeline (ARCHITECTURE.md §6).
 *
 * @package Memberkin
 */

namespace Memberkin\Service\Checkout;

use Memberkin\Core\Clock;
use Memberkin\Core\Events;
use Memberkin\Core\Lock;
use Memberkin\Core\LockException;
use Memberkin\Core\Logger;
use Memberkin\Core\Money;
use Memberkin\Core\Settings;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Coupon;
use Memberkin\Domain\CouponApplication;
use Memberkin\Domain\Membership;
use Memberkin\Domain\Order;
use Memberkin\Domain\PriceSchedule;
use Memberkin\Domain\Pricing\ProrationPlan;
use Memberkin\Domain\Pricing\Quote;
use Memberkin\Domain\Pricing\QuoteItem;
use Memberkin\Domain\Pricing\RecurringTerms;
use Memberkin\Domain\Pricing\TaxContext;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\Contracts\Capability;
use Memberkin\Gateway\Contracts\Gateway;
use Memberkin\Gateway\Contracts\PaymentContext;
use Memberkin\Gateway\Contracts\PaymentResult;
use Memberkin\Gateway\GatewayRegistry;
use Memberkin\Gateway\AbstractGateway;
use Memberkin\Repository\CheckoutSessionRepository;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\MembershipRepository;
use Memberkin\Repository\OrderRepository;
use Memberkin\Repository\PaymentMethodRepository;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\CouponService;
use Memberkin\Service\MembershipService;
use Memberkin\Service\OrderService;
use Memberkin\Service\PricingService;
use Memberkin\Service\SubscriptionService;

defined( 'ABSPATH' ) || exit;

/**
 * Validate → account → order (+ pending memberships/subscriptions) → pay → fulfil.
 *
 * Fulfilment is idempotent and can be triggered by the checkout request, an offsite return,
 * a webhook, or an admin marking an offline order paid — whichever comes first.
 */
class CheckoutService {

	public const QUOTE_META     = 'memberkin_quote';
	public const MAP_META       = 'memberkin_checkout_map';
	public const FULFILLED_META = 'memberkin_fulfilled';

	/**
	 * Orders being fulfilled in this request (prevents re-entry through the memberkin_order_paid hook).
	 *
	 * @var array<int, true>
	 */
	private array $in_progress = array();

	/**
	 * Constructor.
	 *
	 * @param PricingService            $pricing              Pricing.
	 * @param OrderService              $orders               Orders.
	 * @param OrderRepository           $order_repo           Order repository.
	 * @param MembershipService         $memberships          Memberships.
	 * @param MembershipRepository      $membership_repo      Membership repository.
	 * @param SubscriptionService       $subscriptions        Subscriptions.
	 * @param SubscriptionRepository    $subscription_repo    Subscription repository.
	 * @param PaymentMethodRepository   $methods              Payment methods.
	 * @param GatewayRegistry           $gateways             Gateways.
	 * @param CheckoutSessionRepository $sessions             Sessions.
	 * @param LevelRepository           $levels               Levels.
	 * @param CouponService|null        $coupons              Coupons.
	 */
	public function __construct(
		private PricingService $pricing,
		private OrderService $orders,
		private OrderRepository $order_repo,
		private MembershipService $memberships,
		private MembershipRepository $membership_repo,
		private SubscriptionService $subscriptions,
		private SubscriptionRepository $subscription_repo,
		private PaymentMethodRepository $methods,
		private GatewayRegistry $gateways,
		private CheckoutSessionRepository $sessions,
		private LevelRepository $levels,
		private ?CouponService $coupons = null
	) {}

	/**
	 * Classes allowed when unserialising stored quotes.
	 *
	 * @return string[]
	 */
	private static function quote_classes(): array {
		return array( Quote::class, QuoteItem::class, RecurringTerms::class, ProrationPlan::class, TaxContext::class, Money::class, Coupon::class, CouponApplication::class, PriceSchedule::class, \DateTimeImmutable::class, \DateTimeZone::class );
	}

	/**
	 * Quote stored with an order.
	 *
	 * @param int $order_id Order.
	 */
	public function stored_quote( int $order_id ): ?Quote {
		$raw = get_metadata( 'memberkin_order', $order_id, self::QUOTE_META, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$quote = unserialize( base64_decode( $raw ), array( 'allowed_classes' => self::quote_classes() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- written by us; classes restricted.
		return $quote instanceof Quote ? $quote : null;
	}

	/**
	 * Full checkout submit.
	 *
	 * @param array $input   See class doc in ARCHITECTURE.md; keys: token, level_ids, coupon_code, gateway, account, billing, tax, consent, gateway_fields, ip.
	 * @param int   $user_id Current user (0 = guest).
	 */
	public function submit( array $input, int $user_id ): CheckoutResult {
		$token   = (string) ( $input['token'] ?? '' );
		$session = '' !== $token ? $this->sessions->find( $token ) : null;
		if ( $session && 'completed' === $session->status && $session->order_id ) {
			return $this->result_for_completed( (int) $session->order_id, $session->token );
		}

		/**
		 * Return a message to refuse a checkout submission (abuse protection, maintenance…).
		 *
		 * @param string $message '' = allowed.
		 * @param array  $input   Input.
		 * @param int    $user_id User (0 = guest).
		 */
		$blocked = (string) apply_filters( 'memberkin_checkout_blocked', '', $input, $user_id );
		if ( '' !== $blocked ) {
			return CheckoutResult::failed( array( 'form' => $blocked ), $token );
		}

		/**
		 * Whether this checkout buys a gift (the buyer pays; no membership is created for them). The Gifts module
		 * turns this on when the form asks for it.
		 *
		 * @param bool  $gift    Gift.
		 * @param array $input   Input.
		 * @param int   $user_id Buyer.
		 */
		$input['_gift'] = (bool) apply_filters( 'memberkin_checkout_gift', false, $input, $user_id );

		// 1. Quote.
		$tax = (array) ( $input['tax'] ?? array() );
		if ( empty( $tax['country'] ) && ! empty( $input['billing']['country'] ) ) {
			$tax['country']  = $input['billing']['country'];
			$tax['region']   = $input['billing']['region'] ?? '';
			$tax['postcode'] = $input['billing']['postcode'] ?? '';
		}
		$tax['ip_country'] = $input['ip_country'] ?? '';
		try {
			$quote = $this->pricing->quote(
				array(
					'level_ids'          => (array) ( $input['level_ids'] ?? array() ),
					'user_id'            => $input['_gift'] ? 0 : $user_id, // A gift is priced as a new membership (no plan change, no trial history).
					'custom_prices'      => (array) ( $input['custom_prices'] ?? array() ),
					'one_time'           => (array) ( $input['one_time'] ?? array() ),
					'coupon_code'        => (string) ( $input['coupon_code'] ?? '' ),
					'tax'                => $tax,
					'merchant_of_record' => $this->gateway_is_merchant_of_record( (string) ( $input['gateway'] ?? '' ) ),
				)
			);
		} catch ( ValidationException $e ) {
			return CheckoutResult::failed( $e->errors(), $token );
		}

		// 2. Validation.
		$gateway = null;
		$errors  = $this->validate( $input, $user_id, $quote, $gateway );
		if ( $errors ) {
			return CheckoutResult::failed( $errors, $token );
		}

		if ( ! $session ) {
			$session = $this->sessions->create( $user_id, $quote->level_ids );
		}

		try {
			return Lock::with( 'checkout_' . $session->id, fn() => $this->process( $session, $input, $user_id, $quote, $gateway ), 0 );
		} catch ( LockException $e ) {
			return CheckoutResult::failed( array( 'form' => __( 'Your payment is already being processed. Please wait a moment.', 'memberkin' ) ), $session->token );
		}
	}

	/**
	 * Whether a gateway is the merchant of record (it calculates and collects tax itself).
	 *
	 * @param string $gateway_id Gateway id.
	 */
	public function gateway_is_merchant_of_record( string $gateway_id ): bool {
		$gateway = '' !== $gateway_id ? $this->gateways->get( $gateway_id ) : null;
		return $gateway && $gateway->supports( Capability::MERCHANT_OF_RECORD );
	}

	/**
	 * Validates the form. Returns errors (field => message).
	 *
	 * @param array        $input   Input.
	 * @param int          $user_id User.
	 * @param Quote        $quote   Quote.
	 * @param Gateway|null $gateway Selected gateway (out).
	 * @return array<string, string>
	 */
	private function validate( array $input, int $user_id, Quote $quote, ?Gateway &$gateway ): array {
		$errors   = array();
		$settings = Settings::section( 'checkout' );
		if ( ! empty( $input['_gift'] ) && ( 1 !== count( $quote->level_ids ) || $quote->recurring ) ) {
			$errors['gift'] = __( 'A gift can be one membership with a one-time price. Recurring memberships cannot be given as gifts.', 'memberkin' );
		}
		$account = (array) ( $input['account'] ?? array() );

		if ( ! $user_id ) {
			$email = sanitize_email( (string) ( $account['email'] ?? '' ) );
			if ( ! is_email( $email ) ) {
				$errors['email'] = __( 'Enter a valid email address.', 'memberkin' );
			} elseif ( email_exists( $email ) ) {
				$errors['email'] = __( 'An account with this email address already exists. Please log in first.', 'memberkin' );
			}
			if ( empty( $settings['username_is_email'] ) ) {
				$username = (string) ( $account['username'] ?? '' );
				if ( '' === $username || ! validate_username( $username ) ) {
					$errors['username'] = __( 'Choose a username (letters, numbers, spaces and . - _ @ only).', 'memberkin' );
				} elseif ( username_exists( $username ) ) {
					$errors['username'] = __( 'This username is taken.', 'memberkin' );
				}
			}
			$password = (string) ( $account['password'] ?? '' );
			if ( strlen( $password ) < 8 ) {
				$errors['password'] = __( 'Your password must be at least 8 characters long.', 'memberkin' );
			} else {
				/**
				 * Filters the password validation message ('' = valid). The Security module enforces strength here.
				 *
				 * @param string $message  Error message.
				 * @param string $password Password.
				 * @param array  $account  Account fields.
				 */
				$message = (string) apply_filters( 'memberkin_validate_password', '', $password, $account );
				if ( '' !== $message ) {
					$errors['password'] = $message;
				}
			}
			if ( ! empty( $settings['require_name'] ) ) {
				if ( '' === trim( (string) ( $account['first_name'] ?? '' ) ) ) {
					$errors['first_name'] = __( 'Enter your first name.', 'memberkin' );
				}
				if ( '' === trim( (string) ( $account['last_name'] ?? '' ) ) ) {
					$errors['last_name'] = __( 'Enter your last name.', 'memberkin' );
				}
			}
		}

		// Payment method.
		if ( $quote->requires_payment() ) {
			$allowed = array();
			foreach ( $quote->level_ids as $level_id ) {
				$level_allowed = (array) $this->levels->meta( $level_id, 'gateways', array() );
				$allowed       = $level_allowed ? ( $allowed ? array_intersect( $allowed, $level_allowed ) : $level_allowed ) : $allowed;
			}
			$available = $this->gateways->available_for( $quote, array_values( $allowed ) );
			$chosen    = sanitize_key( (string) ( $input['gateway'] ?? '' ) );
			if ( '' === $chosen && 1 === count( $available ) ) {
				$chosen = (string) array_key_first( $available );
			}
			if ( ! isset( $available[ $chosen ] ) ) {
				$errors['gateway'] = $available ? __( 'Choose a payment method.', 'memberkin' ) : __( 'No payment method is available for this purchase. Please contact us.', 'memberkin' );
			} else {
				$gateway = $available[ $chosen ];
			}
		}

		// Billing address.
		$billing        = (array) ( $input['billing'] ?? array() );
		$address_needed = ! empty( $settings['require_billing_address'] ) || $quote->tax->address_required;
		/**
		 * Filters whether the billing address is required.
		 *
		 * @param bool         $needed  Needed.
		 * @param Quote        $quote   Quote.
		 * @param Gateway|null $gateway Gateway.
		 */
		$address_needed = (bool) apply_filters( 'memberkin_checkout_requires_address', $address_needed && $quote->requires_payment(), $quote, $gateway );
		if ( $address_needed ) {
			$labels = array(
				'name'     => __( 'Enter the billing name.', 'memberkin' ),
				'line1'    => __( 'Enter the street address.', 'memberkin' ),
				'city'     => __( 'Enter the city.', 'memberkin' ),
				'postcode' => __( 'Enter the postcode.', 'memberkin' ),
				'country'  => __( 'Choose the country.', 'memberkin' ),
			);
			foreach ( $labels as $key => $message ) {
				if ( '' === trim( (string) ( $billing[ $key ] ?? '' ) ) ) {
					$errors[ 'billing_' . $key ] = $message;
				}
			}
		}

		// Consent.
		$consent = (array) ( $input['consent'] ?? array() );
		if ( ! empty( $settings['terms_page'] ) && empty( $consent['terms'] ) ) {
			$errors['terms'] = __( 'Please accept the terms of service.', 'memberkin' );
		}
		if ( ! empty( $settings['privacy_consent'] ) && empty( $consent['privacy'] ) ) {
			$errors['privacy'] = __( 'Please accept the privacy policy.', 'memberkin' );
		}

		/**
		 * Filters checkout validation errors (CAPTCHA, custom fields, spam checks …).
		 *
		 * @param array<string,string> $errors  Errors.
		 * @param array                $input   Raw input.
		 * @param Quote                $quote   Quote.
		 * @param int                  $user_id User.
		 */
		return (array) apply_filters( 'memberkin_checkout_validate', $errors, $input, $quote, $user_id );
	}

	/**
	 * Creates the account, order, pending memberships and subscriptions, then pays.
	 *
	 * @param object       $session Session.
	 * @param array        $input   Input.
	 * @param int          $user_id User.
	 * @param Quote        $quote   Quote.
	 * @param Gateway|null $gateway Gateway.
	 */
	private function process( object $session, array $input, int $user_id, Quote $quote, ?Gateway $gateway ): CheckoutResult {
		$session = $this->sessions->find( $session->token );
		if ( 'completed' === $session->status && $session->order_id ) {
			return $this->result_for_completed( (int) $session->order_id, $session->token );
		}
		// A previous attempt in this session failed or was abandoned: clean it up first.
		if ( $session->order_id ) {
			$previous = $this->order_repo->find( (int) $session->order_id );
			if ( $previous && in_array( $previous->status, array( Order::PENDING, Order::FAILED ), true ) ) {
				$this->abandon( $previous->id, __( 'Replaced by a new checkout attempt.', 'memberkin' ) );
			}
		}

		// Account.
		$created_user = false;
		if ( ! $user_id ) {
			$user_id = $this->create_account( (array) ( $input['account'] ?? array() ) );
			if ( is_wp_error( $user_id ) ) {
				return CheckoutResult::failed( array( 'email' => $user_id->get_error_message() ), $session->token );
			}
			$created_user = true;
			$this->sessions->update( (int) $session->id, array( 'user_id' => $user_id ) );
			// Re-check per-user coupon limits now that the account exists (new accounts have no history, so this only matters for re-quotes).
		}
		$user = get_userdata( $user_id );

		/**
		 * Fires before the order is created (custom fields can be saved here).
		 *
		 * @param int   $user_id User.
		 * @param array $input   Input.
		 * @param Quote $quote   Quote.
		 */
		do_action( 'memberkin_checkout_before_order', $user_id, $input, $quote );

		// Order.
		$billing = (array) ( $input['billing'] ?? array() );
		if ( empty( $billing['email'] ) ) {
			$billing['email'] = $user->user_email;
		}
		if ( empty( $billing['name'] ) ) {
			$billing['name'] = trim( $user->first_name . ' ' . $user->last_name );
		}
		$order = $this->orders->create_from_quote(
			$quote,
			$user_id,
			$gateway ? $gateway->id() : 'free',
			array(
				'gateway_mode'        => $gateway ? $gateway->mode() : 'live',
				'billing'             => $billing,
				'ip_address'          => isset( $input['ip'] ) ? (string) $input['ip'] : null,
				'checkout_session_id' => (int) $session->id,
			) + ( $input['_gift'] ? array( 'type' => 'gift' ) : array() )
		);
		update_metadata( 'memberkin_order', $order->id, self::QUOTE_META, base64_encode( serialize( $quote ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$consent = (array) ( $input['consent'] ?? array() );
		if ( $consent ) {
			update_metadata(
				'memberkin_order',
				$order->id,
				'memberkin_consent',
				array(
					'terms'   => ! empty( $consent['terms'] ),
					'privacy' => ! empty( $consent['privacy'] ),
					'at'      => Clock::now()->format( DATE_ATOM ),
					'ip'      => (string) ( $input['ip'] ?? '' ),
				)
			);
		}

		// Pending memberships and subscriptions (none for a gift: the recipient's membership starts when they redeem it).
		$map  = array();
		$main = null;
		if ( $input['_gift'] ) {
			/**
			 * Fires after a gift order was created (before payment).
			 *
			 * @param Order $order Order.
			 * @param array $input Checkout input.
			 */
			do_action( 'memberkin_gift_order_created', $order, $input );
		}
		foreach ( $input['_gift'] ? array() : $quote->level_ids as $level_id ) {
			$schedule = $quote->schedules[ $level_id ];
			$m        = $this->memberships->grant(
				$user_id,
				$level_id,
				array(
					'status'           => Membership::PENDING,
					'source'           => 'checkout',
					'schedule'         => $schedule,
					'initial_order_id' => $order->id,
					'coupon_id'        => isset( $quote->coupon_applications[ $level_id ] ) ? $quote->coupon?->id : null,
					'ends_at'          => null,
				)
			);
			$sub_id   = null;
			if ( isset( $quote->recurring[ $level_id ] ) && $gateway ) {
				$terms = $quote->recurring[ $level_id ];
				$sub   = $this->subscriptions->create(
					new Subscription(
						0,
						$user_id,
						$level_id,
						$gateway->id(),
						Subscription::PENDING,
						$quote->currency,
						$terms->first_amount->amount,
						$terms->billing_interval,
						$terms->billing_period,
						$m->id,
						$gateway->mode(),
						null,
						null,
						null,
						$gateway->supports( Capability::RECURRING_GATEWAY ) ? 'gateway' : 'site',
						$terms->tax_amount,
						$terms->renewals_limit,
						0,
						isset( $quote->coupon_applications[ $level_id ] ) ? $quote->coupon?->id : null,
						$terms->discount_cycles
					)
				);
				update_metadata( 'memberkin_subscription', $sub->id, 'tax_rate', $terms->tax_rate );
				update_metadata( 'memberkin_subscription', $sub->id, 'reverse_charge', $quote->tax->reverse_charge ? 1 : 0 );
				if ( $terms->trial_cycles_left > 0 ) {
					update_metadata( 'memberkin_subscription', $sub->id, 'trial_cycles_left', $terms->trial_cycles_left );
				}
				$sub_id = $sub->id;
				$this->membership_repo->update( $m, array( 'subscription_id' => $sub_id ) );
				$main = $main ?? $sub;
			}
			$map[ $level_id ] = array(
				'membership_id'   => $m->id,
				'subscription_id' => $sub_id,
			);
		}
		update_metadata( 'memberkin_order', $order->id, self::MAP_META, $map );
		if ( $main ) {
			$this->order_repo->update( $order->id, array( 'subscription_id' => $main->id ) );
		}
		$order = $this->order_repo->find( $order->id );
		$this->sessions->update(
			(int) $session->id,
			array(
				'order_id' => $order->id,
				'status'   => 'processing',
			)
		);

		// Payment.
		if ( ! $gateway ) {
			$this->fulfil( $order->id, new PaymentResult( PaymentResult::COMPLETED ) );
			return $this->result_for_completed( $order->id, $session->token, $created_user );
		}

		$context = new PaymentContext( $order, $quote, $user, $this->return_url( $session->token ), $this->cancel_url( $quote ), (array) ( $input['gateway_fields'] ?? array() ), $main ? $this->subscription_repo->find( $main->id ) : null, $session->token );
		try {
			$result = $gateway->start_payment( $context );
		} catch ( \Throwable $e ) {
			Logger::error( $gateway->id(), 'start_payment error: ' . $e->getMessage(), array( 'order' => $order->id ) );
			$result = PaymentResult::failed( __( 'The payment could not be processed. Please try again.', 'memberkin' ) );
		}
		return $this->handle_result( $order, $result, $session, $gateway, $created_user );
	}

	/**
	 * Acts on a gateway result.
	 *
	 * @param Order         $order        Order.
	 * @param PaymentResult $result      Result.
	 * @param object        $session      Session.
	 * @param Gateway       $gateway      Gateway.
	 * @param bool          $created_user Whether the account was created in this request.
	 */
	private function handle_result( Order $order, PaymentResult $result, object $session, Gateway $gateway, bool $created_user = false ): CheckoutResult {
		$this->store_gateway_ids( $order, $result );
		switch ( $result->status ) {
			case PaymentResult::COMPLETED:
				$this->fulfil( $order->id, $result );
				return $this->result_for_completed( $order->id, $session->token, $created_user );

			case PaymentResult::PENDING:
				if ( $gateway instanceof AbstractGateway && $gateway->grants_access_before_payment() ) {
					$this->fulfil( $order->id, null, 'access_only' );
				}
				$this->sessions->update( (int) $session->id, array( 'status' => 'completed' ) );
				$this->maybe_login( (int) $order->user_id, $created_user );
				Events::record( 'checkout.pending', $order->user_id, 'order', $order->id );
				/**
				 * Fires when an order was placed but payment is still pending (manual payments, bank debits).
				 *
				 * @param Order $order Order.
				 */
				do_action( 'memberkin_checkout_pending', $this->order_repo->find( $order->id ) );
				return new CheckoutResult( CheckoutResult::PENDING, array(), $this->order_repo->find( $order->id ), $this->confirmation_url( $order, $session->token ), '', $session->token );

			case PaymentResult::REDIRECT:
				$this->maybe_login( (int) $order->user_id, $created_user );
				return new CheckoutResult( CheckoutResult::REDIRECT, array(), $order, (string) $result->redirect_url, '', $session->token );

			case PaymentResult::REQUIRES_ACTION:
				$this->maybe_login( (int) $order->user_id, $created_user );
				return new CheckoutResult( CheckoutResult::REQUIRES_ACTION, array(), $order, '', (string) $result->client_secret, $session->token );

			default:
				$message = '' !== $result->message ? $result->message : __( 'The payment was declined.', 'memberkin' );
				$this->abandon( $order->id, $message );
				$this->sessions->update(
					(int) $session->id,
					array(
						'status'   => 'open',
						'order_id' => null,
					)
				);
				/**
				 * Fires when a checkout payment failed.
				 *
				 * @param Order  $order   Order.
				 * @param string $message Message.
				 */
				do_action( 'memberkin_checkout_failed', $order, $message );
				return CheckoutResult::failed( array( 'payment' => $message ), $session->token );
		}
	}

	/**
	 * Continues a checkout after an offsite return or browser authentication.
	 *
	 * @param string $token  Session token.
	 * @param array  $params Gateway parameters (query string or posted fields).
	 */
	public function confirm( string $token, array $params ): CheckoutResult {
		$session = $this->sessions->find( $token );
		if ( ! $session || ! $session->order_id ) {
			return CheckoutResult::failed( array( 'form' => __( 'This checkout has expired. Please start again.', 'memberkin' ) ) );
		}
		if ( 'completed' === $session->status ) {
			return $this->result_for_completed( (int) $session->order_id, $token );
		}
		$order = $this->order_repo->find( (int) $session->order_id );
		$quote = $order ? $this->stored_quote( $order->id ) : null;
		$gate  = $order ? $this->gateways->get( $order->gateway ) : null;
		if ( ! $order || ! $quote || ! $gate ) {
			return CheckoutResult::failed( array( 'form' => __( 'This checkout has expired. Please start again.', 'memberkin' ) ) );
		}
		if ( $order->is_paid() ) {
			$this->fulfil( $order->id, null );
			return $this->result_for_completed( $order->id, $token );
		}
		try {
			return Lock::with(
				'checkout_' . $session->id,
				function () use ( $order, $quote, $gate, $params, $session ): CheckoutResult {
					$sub     = $order->subscription_id ? $this->subscription_repo->find( $order->subscription_id ) : null;
					$context = new PaymentContext( $order, $quote, get_userdata( $order->user_id ), $this->return_url( $session->token ), $this->cancel_url( $quote ), $params, $sub, $session->token );
					try {
						$result = isset( $params['confirmed'] ) ? $gate->start_payment( $context ) : $gate->complete_payment( $context, $params );
					} catch ( \Throwable $e ) {
						Logger::error( $gate->id(), 'complete_payment error: ' . $e->getMessage(), array( 'order' => $order->id ) );
						$result = PaymentResult::failed( __( 'The payment could not be confirmed.', 'memberkin' ) );
					}
					return $this->handle_result( $order, $result, $session, $gate );
				},
				5
			);
		} catch ( LockException $e ) {
			return CheckoutResult::failed( array( 'form' => __( 'Your payment is already being processed. Please wait a moment.', 'memberkin' ) ), $token );
		}
	}

	/**
	 * Grants access, activates subscriptions, records coupon use. Idempotent.
	 *
	 * @param int                $order_id Order.
	 * @param PaymentResult|null $result   Gateway result (null when triggered by an admin or webhook without details).
	 * @param string             $mode     "full" or "access_only" (offline orders that give access before payment).
	 */
	public function fulfil( int $order_id, ?PaymentResult $result, string $mode = 'full' ): ?Order {
		return Lock::with(
			'fulfil_' . $order_id,
			function () use ( $order_id, $result, $mode ): ?Order {
				$order = $this->order_repo->find( $order_id );
				if ( ! $order || isset( $this->in_progress[ $order_id ] ) ) {
					return $order;
				}
				$this->in_progress[ $order_id ] = true;
				try {
					return $this->fulfil_locked( $order, $result, $mode );
				} finally {
					unset( $this->in_progress[ $order_id ] );
				}
			}
		);
	}

	/**
	 * Fulfilment body (lock held).
	 *
	 * @param Order              $order  Order.
	 * @param PaymentResult|null $result Result.
	 * @param string             $mode   Mode.
	 */
	private function fulfil_locked( Order $order, ?PaymentResult $result, string $mode ): Order {
				$order_id = $order->id;
				$state    = (string) get_metadata( 'memberkin_order', $order_id, self::FULFILLED_META, true );
		if ( 'full' === $state || ( 'access_only' === $state && 'access_only' === $mode ) ) {
			return $order;
		}

		if ( 'full' === $mode && ! $order->is_paid() && ( null === $result || $result->is_completed() ) ) {
			$order = $this->orders->mark_paid(
				$order_id,
				array(
					'gateway_transaction_id' => $result?->transaction_id,
					'gateway_customer_id'    => $result?->customer_id,
					'payment_method_label'   => $result?->payment_method_label,
				)
			);
		}

		if ( '' === $state ) {
			$this->grant_access( $order, $result );
		}

		if ( 'full' === $mode && $order->coupon_id && $this->coupons ) {
			try {
				$this->coupons->redeem( $order->coupon_id, $order->user_id, $order->id );
			} catch ( ValidationException $e ) {
				// Limit reached between quote and payment: the member already paid the discounted price; log it.
				Logger::warning( 'coupons', 'Coupon limit exceeded after payment.', array( 'order' => $order->id ) );
			}
		}

				update_metadata( 'memberkin_order', $order_id, self::FULFILLED_META, $mode );
				$session = $this->sessions->find_by_order( $order_id );
		if ( $session ) {
			$this->sessions->update( (int) $session->id, array( 'status' => 'completed' ) );
		}
				delete_user_meta( $order->user_id, 'memberkin_checkout_pending' );
				$order = $this->order_repo->find( $order_id );
				Events::record( 'checkout.completed', $order->user_id, 'order', $order->id, array( 'mode' => $mode ) );

				/**
				 * Fires after a checkout was fulfilled (access granted).
				 *
				 * @param Order  $order Order.
				 * @param string $mode  full|access_only.
				 */
				do_action( 'memberkin_checkout_completed', $order, $mode );
				return $order;
	}

	/**
	 * Activates the order's pending memberships and subscriptions.
	 *
	 * @param Order              $order  Order.
	 * @param PaymentResult|null $result Result.
	 */
	private function grant_access( Order $order, ?PaymentResult $result ): void {
		$quote = $this->stored_quote( $order->id );
		$map   = (array) get_metadata( 'memberkin_order', $order->id, self::MAP_META, true );
		$now   = Clock::now();

		$method_id = null;
		if ( $result && $result->payment_method ) {
			$pm          = $result->payment_method;
			$pm->user_id = $order->user_id;
			$method_id   = $this->methods->upsert( $pm );
		}

		foreach ( $map as $level_id => $ids ) {
			$m = $this->membership_repo->find( (int) $ids['membership_id'] );
			if ( ! $m || Membership::PENDING !== $m->status ) {
				continue;
			}
			$plan     = $quote->proration[ $level_id ] ?? null;
			$terms    = $quote->recurring[ $level_id ] ?? null;
			$level    = $this->levels->find( (int) $level_id );
			$schedule = $quote->schedules[ $level_id ] ?? $m->schedule;
			$context  = array(
				'order_id' => $order->id,
				'reason'   => 'checkout',
			);

			$starts = $now;
			$ends   = $level ? $schedule->access_end( $now ) : null; // The bought schedule (member choices such as "pay once" set an end).

			if ( $plan && ProrationPlan::AT_PERIOD_END === $plan->mode && $plan->anchor ) {
				// Downgrade at the end of the paid period: old membership runs until then, the new one starts then.
				$starts = $plan->anchor;
				$ends   = $level ? $schedule->access_end( $plan->anchor ) : null;
				$old    = $this->membership_repo->find( $plan->from_membership_id );
				if ( $old && $old->is_current() ) {
					$old_sub = $old->subscription_id ? $this->subscription_repo->find( $old->subscription_id ) : null;
					if ( $old_sub && $old_sub->is_live() ) {
						$this->subscriptions->cancel( $old_sub->id, true, 'changed' );
					} else {
						$this->memberships->cancel( $old->id, $plan->anchor, array( 'reason' => 'changed' ) );
					}
				}
				$context['keep_conflicts'] = true;
			} elseif ( ! $plan && $level ) {
				// Early renewal of the same fixed-term level: continue from the current end date.
				$current = $this->memberships->current_for_level( $order->user_id, (int) $level_id );
				if ( $current && $current->id !== $m->id && $current->ends_at && $current->ends_at > $now ) {
					$ends = $schedule->access_end( $current->ends_at );
				}
			}
			if ( $plan && $plan->extension_seconds > 0 && $ends ) {
				$ends = $ends->modify( '+' . $plan->extension_seconds . ' seconds' );
			}

			// Replace the old plan's subscription (immediate changes).
			if ( $plan && ProrationPlan::AT_PERIOD_END !== $plan->mode ) {
				$old = $this->membership_repo->find( $plan->from_membership_id );
				if ( $old && $old->subscription_id ) {
					$old_sub = $this->subscription_repo->find( $old->subscription_id );
					if ( $old_sub && $old_sub->is_live() ) {
						try {
							$this->subscriptions->transition(
								$old_sub->id,
								Subscription::CANCELLED,
								array(
									'cancelled_at'    => $now,
									'cancel_reason'   => 'changed',
									'next_payment_at' => null,
								),
								array( 'reason' => 'changed' )
							);
							$gateway = $this->gateways->get( $old_sub->gateway );
							if ( $gateway && 'gateway' === $old_sub->billing_model && $old_sub->gateway_subscription_id ) {
								$gateway->cancel_subscription( $old_sub, false );
							}
						} catch ( \Throwable $e ) {
							Logger::error( 'checkout', 'Could not cancel the replaced subscription: ' . $e->getMessage(), array( 'subscription' => $old_sub->id ) );
						}
					}
				}
			}

			$this->memberships->activate(
				$m->id,
				array(
					'starts_at' => $starts,
					'ends_at'   => $ends,
				),
				$context
			);

			if ( ! empty( $ids['subscription_id'] ) && $terms ) {
				$fields = array(
					'membership_id'       => $m->id,
					'payment_method_id'   => $method_id,
					'gateway_customer_id' => $result?->customer_id,
				);
				if ( $result && $result->subscription_id && (int) $ids['subscription_id'] === (int) $order->subscription_id ) {
					$fields['gateway_subscription_id'] = $result->subscription_id;
				}
				$next  = $result && $result->next_payment_at ? $result->next_payment_at : $terms->first_renewal_at;
				$trial = $schedule->has_trial() && 'cycle' !== $schedule->trial_period && ! ( $plan && ProrationPlan::AT_PERIOD_END === $plan->mode );
				$this->subscriptions->activate( (int) $ids['subscription_id'], $starts, $next, $trial, array_filter( $fields, static fn( $v ) => null !== $v ) );
			}
		}
	}

	/**
	 * Cancels a failed/abandoned checkout: order failed, pending memberships ended, pending subscriptions cancelled.
	 *
	 * @param int    $order_id Order.
	 * @param string $message  Reason.
	 */
	public function abandon( int $order_id, string $message = '' ): void {
		$order = $this->order_repo->find( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		$this->orders->mark_failed( $order_id, $message );
		foreach ( (array) get_metadata( 'memberkin_order', $order_id, self::MAP_META, true ) as $ids ) {
			$m = $this->membership_repo->find( (int) ( $ids['membership_id'] ?? 0 ) );
			if ( $m && Membership::PENDING === $m->status ) {
				$this->memberships->end(
					$m->id,
					'abandoned',
					array(
						'skip_fallback' => true,
						'notify'        => false,
					)
				);
			}
			$sub = ! empty( $ids['subscription_id'] ) ? $this->subscription_repo->find( (int) $ids['subscription_id'] ) : null;
			if ( $sub && Subscription::PENDING === $sub->status ) {
				$this->subscriptions->transition(
					$sub->id,
					Subscription::CANCELLED,
					array(
						'cancelled_at'  => Clock::now(),
						'cancel_reason' => 'abandoned',
					)
				);
			}
		}
	}

	/**
	 * Stores gateway customer/transaction ids reported before completion (offsite/pending flows).
	 *
	 * @param Order         $order  Order.
	 * @param PaymentResult $result Result.
	 */
	private function store_gateway_ids( Order $order, PaymentResult $result ): void {
		$fields = array();
		if ( $result->transaction_id && ! $order->gateway_transaction_id ) {
			$fields['gateway_transaction_id'] = $result->transaction_id;
		}
		if ( $result->customer_id && ! $order->gateway_customer_id ) {
			$fields['gateway_customer_id'] = $result->customer_id;
		}
		if ( $fields ) {
			$this->order_repo->update( $order->id, $fields );
		}
		if ( $result->subscription_id && $order->subscription_id ) {
			$this->subscription_repo->update( $order->subscription_id, array( 'gateway_subscription_id' => $result->subscription_id ) );
		}
	}

	/**
	 * Creates the member's account.
	 *
	 * @param array $account email, username, password, first_name, last_name.
	 * @return int|\WP_Error
	 */
	private function create_account( array $account ): int|\WP_Error {
		$email                    = sanitize_email( (string) ( $account['email'] ?? '' ) );
		$username                 = Settings::get( 'checkout', 'username_is_email', false ) ? $email : sanitize_user( (string) ( $account['username'] ?? '' ), true );
		$userdata                 = array(
			'user_login' => $username,
			'user_email' => $email,
			'user_pass'  => (string) ( $account['password'] ?? '' ),
			'first_name' => sanitize_text_field( (string) ( $account['first_name'] ?? '' ) ),
			'last_name'  => sanitize_text_field( (string) ( $account['last_name'] ?? '' ) ),
			'role'       => (string) get_option( 'default_role', 'subscriber' ),
		);
		$full_name                = trim( $userdata['first_name'] . ' ' . $userdata['last_name'] );
		$userdata['display_name'] = '' !== $full_name ? $full_name : $username;
		/**
		 * Filters the user data for accounts created at checkout.
		 *
		 * @param array $userdata wp_insert_user() data.
		 * @param array $account  Raw account fields.
		 */
		$user_id = wp_insert_user( apply_filters( 'memberkin_checkout_new_user', $userdata, $account ) );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		update_user_meta( $user_id, 'memberkin_checkout_pending', 1 );
		/**
		 * Fires after a member account was created at checkout.
		 *
		 * @param int   $user_id User.
		 * @param array $account Account fields (password removed).
		 */
		do_action( 'memberkin_checkout_user_created', $user_id, array_diff_key( $account, array( 'password' => 1 ) ) );
		return (int) $user_id;
	}

	/**
	 * Logs in a member whose account was just created (if enabled).
	 *
	 * @param int  $user_id      User.
	 * @param bool $created_user Created in this request.
	 */
	private function maybe_login( int $user_id, bool $created_user ): void {
		if ( ! $created_user || ! Settings::get( 'checkout', 'auto_login', true ) || is_user_logged_in() || headers_sent() ) {
			return;
		}
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, false, is_ssl() );
	}

	/**
	 * Result for a completed checkout.
	 *
	 * @param int    $order_id     Order.
	 * @param string $token        Session token.
	 * @param bool   $created_user Whether to log the new member in.
	 */
	private function result_for_completed( int $order_id, string $token, bool $created_user = false ): CheckoutResult {
		$order = $this->order_repo->find( $order_id );
		$this->maybe_login( (int) $order->user_id, $created_user );
		$status = $order->is_paid() ? CheckoutResult::COMPLETED : CheckoutResult::PENDING;
		return new CheckoutResult( $status, array(), $order, $this->confirmation_url( $order, $token ), '', $token );
	}

	/**
	 * Confirmation page URL (or the level's thank-you page).
	 *
	 * @param Order  $order Order.
	 * @param string $token Session token (lets guests whose login cookie is not set yet see their order).
	 */
	public function confirmation_url( Order $order, string $token ): string {
		$level_ids = $order->level_ids();
		$page_id   = $level_ids ? (int) $this->levels->meta( $level_ids[0], 'thank_you_page', 0 ) : 0;
		$base      = $page_id ? (string) get_permalink( $page_id ) : memberkin_page_url( 'confirmation' );
		if ( '' === $base ) {
			$account = memberkin_page_url( 'account' );
			$base    = '' !== $account ? $account : home_url( '/' );
		}
		return add_query_arg(
			array(
				'memberkin_order' => $order->number,
				'memberkin_key'   => $token,
			),
			$base
		);
	}

	/**
	 * Offsite return URL.
	 *
	 * @param string $token Session token.
	 */
	public function return_url( string $token ): string {
		return add_query_arg( array( 'memberkin_return' => $token ), home_url( '/' ) );
	}

	/**
	 * Where offsite gateways send members who cancel.
	 *
	 * @param Quote $quote Quote.
	 */
	private function cancel_url( Quote $quote ): string {
		$level = $this->levels->find( $quote->level_ids[0] );
		return $level ? memberkin_checkout_url( $level, array( 'memberkin_cancelled' => 1 ) ) : home_url( '/' );
	}
}
