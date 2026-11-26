<?php
/**
 * Stripe gateway.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Stripe;

use Memberkin\Core\Clock;
use Memberkin\Core\Currency;
use Memberkin\Core\Events;
use Memberkin\Core\Logger;
use Memberkin\Core\Money;
use Memberkin\Domain\Order;
use Memberkin\Domain\PaymentMethod;
use Memberkin\Domain\Pricing\Quote;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\AbstractGateway;
use Memberkin\Gateway\Contracts\Capability;
use Memberkin\Gateway\Contracts\ChargeRequest;
use Memberkin\Gateway\Contracts\InvalidSignature;
use Memberkin\Gateway\Contracts\OperationResult;
use Memberkin\Gateway\Contracts\PaymentContext;
use Memberkin\Gateway\Contracts\PaymentMethodUpdate;
use Memberkin\Gateway\Contracts\PaymentResult;
use Memberkin\Gateway\Contracts\VerifiedWebhook;
use Memberkin\Gateway\Contracts\WebhookEvent;
use Memberkin\Repository\PaymentMethodRepository;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\SubscriptionService;

defined( 'ABSPATH' ) || exit;

/**
 * Payment Element on the checkout page, PaymentIntents/SetupIntents confirmed server-side, saved payment methods
 * charged off-session by this site for renewals (billing model "site", so prices, coupons, tax and proration stay
 * in one place). Subscriptions that already exist in Stripe Billing (e.g. imported from another plugin) are kept
 * running as billing model "gateway": their invoices, cancellations and pauses are synced through the API and webhooks.
 *
 * Card data never reaches this server (Stripe.js collects it and returns a PaymentMethod id).
 */
final class StripeGateway extends AbstractGateway {

	/**
	 * Capabilities.
	 *
	 * @var string[]
	 */
	protected array $capabilities = array(
		Capability::ONE_TIME,
		Capability::RECURRING_SITE,
		Capability::TRIALS,
		Capability::REFUND_FULL,
		Capability::REFUND_PARTIAL,
		Capability::CANCEL,
		Capability::PAUSE,
		Capability::CHANGE_PLAN,
		Capability::PAYMENT_METHOD_UPDATE,
		Capability::SAVED_METHODS,
		Capability::ONSITE_FIELDS,
		Capability::WEBHOOKS,
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'stripe';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Stripe', 'memberkin' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function checkout_label(): string {
		$title = (string) $this->setting( 'title', '' );
		return '' !== $title ? $title : __( 'Card and other payment methods', 'memberkin' );
	}

	/**
	 * API client.
	 */
	public function client(): StripeClient {
		return new StripeClient( $this->credential( 'secret_key' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_fields(): array {
		$section  = $this->section();
		$fields   = $this->common_fields();
		$fields   = array_merge( $fields, $this->public_fields( 'publishable_key', __( 'publishable key', 'memberkin' ) ) );
		$fields   = array_merge( $fields, $this->secret_fields( 'secret_key', __( 'secret key', 'memberkin' ) ), $this->secret_fields( 'webhook_secret', __( 'webhook signing secret', 'memberkin' ) ) );
		$fields[] = array(
			'tab'         => 'payments',
			'section'     => $section,
			'key'         => 'webhook_info',
			'label'       => __( 'Webhook endpoint', 'memberkin' ),
			'type'        => 'info',
			'description' => sprintf(
				/* translators: 1: URL, 2: event list. */
				__( 'In the Stripe Dashboard add an endpoint for %1$s with the events: %2$s.', 'memberkin' ),
				'<code>' . esc_html( $this->webhook_url() ) . '</code>',
				'<code>' . esc_html( implode( ', ', self::webhook_events() ) ) . '</code>'
			),
		);
		return $fields;
	}

	/**
	 * Events the webhook endpoint should send.
	 *
	 * @return string[]
	 */
	public static function webhook_events(): array {
		return array( 'payment_intent.succeeded', 'payment_intent.payment_failed', 'charge.refunded', 'refund.updated', 'charge.dispute.created', 'charge.dispute.closed', 'checkout.session.completed', 'invoice.paid', 'invoice.payment_failed', 'invoice.payment_action_required', 'customer.subscription.updated', 'customer.subscription.deleted' );
	}

	/**
	 * Publishable key (current mode).
	 */
	public function publishable_key(): string {
		return $this->public_credential( 'publishable_key' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_ready(): bool {
		return parent::is_ready() && '' !== $this->credential( 'secret_key' ) && '' !== $this->publishable_key();
	}

	/**
	 * Amount in Stripe's smallest unit (Stripe follows ISO 4217 exponents for the currencies it supports, which is what
	 * Money stores). Filterable for the few currencies where Stripe documents special rules.
	 *
	 * @param int    $amount   Minor units.
	 * @param string $currency Currency.
	 */
	public static function amount( int $amount, string $currency ): int {
		/**
		 * Filters an amount sent to Stripe.
		 *
		 * @param int    $amount   Minor units.
		 * @param string $currency Currency.
		 */
		return (int) apply_filters( 'memberkin_stripe_amount', $amount, strtoupper( $currency ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Quote $quote Quote.
	 */
	public function render_payment_fields( Quote $quote ): string {
		wp_enqueue_script( 'memberkin-stripe-js', 'https://js.stripe.com/v3/', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Stripe.js must be loaded unversioned from js.stripe.com.
		wp_enqueue_script( 'memberkin-stripe', MEMBERKIN_URL . 'assets/js/stripe.js', array( 'memberkin-stripe-js', 'memberkin-checkout' ), MEMBERKIN_VERSION, true );
		$total  = $quote->total()->amount;
		$config = array(
			'publishableKey'   => $this->publishable_key(),
			'mode'             => $total > 0 ? 'payment' : 'setup',
			'amount'           => self::amount( $total, $quote->currency ),
			'currency'         => strtolower( $quote->currency ),
			'exponent'         => Currency::exponent( $quote->currency ),
			'setupFutureUsage' => $quote->is_recurring(),
			'returnBase'       => add_query_arg( 'memberkin_return', '', home_url( '/' ) ),
			'locale'           => str_replace( '_', '-', substr( determine_locale(), 0, 5 ) ),
			'i18n'             => array(
				'error'    => __( 'Your payment details could not be processed. Please check them and try again.', 'memberkin' ),
				'authFail' => __( 'Authentication was not completed. Please try again or use another payment method.', 'memberkin' ),
			),
		);
		/**
		 * Filters the Stripe Elements configuration (e.g. to add "appearance").
		 *
		 * @param array $config Config.
		 * @param Quote $quote  Quote.
		 */
		$config = (array) apply_filters( 'memberkin_stripe_elements_config', $config, $quote );
		return parent::render_payment_fields( $quote )
			. '<div class="memberkin-stripe" data-memberkin-stripe="' . esc_attr( (string) wp_json_encode( $config ) ) . '">'
			. '<div class="memberkin-stripe__element"></div>'
			. '<input type="hidden" name="memberkin_gateway[payment_method]" value="" />'
			. '<p class="memberkin-field-error memberkin-stripe__error" role="alert" hidden></p>'
			. '<noscript><p class="memberkin-notice">' . esc_html__( 'Card payments need JavaScript.', 'memberkin' ) . '</p></noscript>'
			. '</div>';
	}

	/**
	 * Stripe customer of a user on this site and mode (created on first use).
	 *
	 * @param \WP_User $user User.
	 * @throws StripeException On API errors.
	 */
	public function customer_id( \WP_User $user ): string {
		$key      = 'memberkin_stripe_customer_' . $this->mode();
		$existing = (string) get_user_option( $key, $user->ID );
		if ( '' !== $existing ) {
			return $existing;
		}
		$name     = trim( $user->first_name . ' ' . $user->last_name );
		$customer = $this->client()->request(
			'POST',
			'customers',
			array(
				'email'    => $user->user_email,
				'name'     => '' !== $name ? $name : $user->display_name,
				'metadata' => array(
					'memberkin_user_id' => $user->ID,
					'site'              => home_url( '/' ),
				),
			),
			'memberkin-customer-' . get_current_blog_id() . '-' . $user->ID . '-' . $this->mode()
		);
		update_user_option( $user->ID, $key, (string) $customer['id'] );
		return (string) $customer['id'];
	}

	/**
	 * Metadata for intents.
	 *
	 * @param Order $order Order.
	 */
	private static function metadata( Order $order ): array {
		return array(
			'memberkin_order_id'        => $order->id,
			'memberkin_order'           => $order->number,
			'memberkin_subscription_id' => $order->subscription_id,
			'memberkin_type'            => $order->type,
			'site'                      => home_url( '/' ),
		);
	}

	/**
	 * Order description.
	 *
	 * @param Order $order Order.
	 */
	private static function description( Order $order ): string {
		$names = array_map( static fn( $item ) => $item->description, $order->items );
		/* translators: 1: order number, 2: items. */
		return mb_substr( sprintf( __( 'Order %1$s: %2$s', 'memberkin' ), $order->number, implode( ', ', $names ) ), 0, 1000 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param PaymentContext $context Context.
	 */
	public function start_payment( PaymentContext $context ): PaymentResult {
		$pm = (string) ( $context->fields['payment_method'] ?? '' );
		if ( ! preg_match( '/^pm_[A-Za-z0-9]+$/', $pm ) ) {
			return PaymentResult::failed( __( 'Please enter your payment details.', 'memberkin' ) );
		}
		$order     = $context->order;
		$recurring = null !== $context->subscription || $context->quote->is_recurring();
		try {
			$customer = $this->customer_id( $context->user );
			$common   = array(
				'customer'                  => $customer,
				'payment_method'            => $pm,
				'confirm'                   => true,
				'automatic_payment_methods' => array( 'enabled' => true ),
				'return_url'                => $context->return_url,
				'metadata'                  => self::metadata( $order ),
				'expand'                    => array( 'payment_method' ),
			);
			if ( $order->total > 0 ) {
				$intent = $this->client()->request(
					'POST',
					'payment_intents',
					array_merge(
						$common,
						array(
							'amount'             => self::amount( $order->total, $order->currency ),
							'currency'           => strtolower( $order->currency ),
							'description'        => self::description( $order ),
							'setup_future_usage' => $recurring ? 'off_session' : null,
						)
					),
					'memberkin-order-' . $order->id . '-' . $pm
				);
			} else {
				$intent = $this->client()->request(
					'POST',
					'setup_intents',
					array_merge( $common, array( 'usage' => 'off_session' ) ),
					'memberkin-setup-' . $order->id . '-' . $pm
				);
			}
		} catch ( StripeException $e ) {
			return $this->failure( $e, $order );
		}
		return $this->map_intent( $intent, $customer );
	}

	/**
	 * Failed result from an exception (card errors are shown as-is, others generically).
	 *
	 * @param StripeException $e     Exception.
	 * @param Order|null      $order Order.
	 */
	private function failure( StripeException $e, ?Order $order = null ): PaymentResult {
		if ( $e->is_card_error() ) {
			return PaymentResult::failed( $e->getMessage(), array( 'code' => $e->error_code() ) );
		}
		Logger::error(
			'stripe',
			'Stripe error: ' . $e->getMessage(),
			array(
				'order'  => $order?->id,
				'status' => $e->status,
			)
		);
		return PaymentResult::failed( __( 'The payment could not be processed. Please try again or use another payment method.', 'memberkin' ) );
	}

	/**
	 * PaymentIntent / SetupIntent → result.
	 *
	 * @param array  $intent   Intent (payment_method expanded when possible).
	 * @param string $customer Customer id.
	 */
	public function map_intent( array $intent, string $customer = '' ): PaymentResult {
		$is_payment = str_starts_with( (string) ( $intent['id'] ?? '' ), 'pi_' );
		$customer   = '' !== $customer ? $customer : (string) ( is_array( $intent['customer'] ?? null ) ? $intent['customer']['id'] : ( $intent['customer'] ?? '' ) );
		$method     = is_array( $intent['payment_method'] ?? null ) ? $this->payment_method( $intent['payment_method'] ) : null;
		$tx         = $is_payment ? (string) $intent['id'] : null;
		$cust       = '' !== $customer ? $customer : null;
		switch ( (string) ( $intent['status'] ?? '' ) ) {
			case 'succeeded':
				return new PaymentResult( PaymentResult::COMPLETED, '', $tx, $cust, null, $method, null, null, null, $method?->label() );
			case 'processing':
				return new PaymentResult( PaymentResult::PENDING, '', $tx, $cust, null, $method, null, null, null, $method?->label() );
			case 'requires_action':
				return new PaymentResult( PaymentResult::REQUIRES_ACTION, __( 'Authentication required.', 'memberkin' ), (string) $intent['id'], $cust, null, null, null, (string) ( $intent['client_secret'] ?? '' ) );
			default:
				$error = (array) ( $intent['last_payment_error'] ?? $intent['last_setup_error'] ?? array() );
				return PaymentResult::failed( (string) ( $error['message'] ?? __( 'The payment was not completed.', 'memberkin' ) ) );
		}
	}

	/**
	 * Stripe PaymentMethod object → local payment method.
	 *
	 * @param array $pm Stripe object.
	 */
	public function payment_method( array $pm ): PaymentMethod {
		$type  = (string) ( $pm['type'] ?? 'card' );
		$data  = (array) ( $pm[ $type ] ?? array() );
		$brand = $data['brand'] ?? ( $data['bank_name'] ?? ( 'card' === $type ? null : $type ) );
		return new PaymentMethod(
			0,
			0,
			'stripe',
			! empty( $pm['livemode'] ) ? 'live' : 'test',
			(string) $pm['id'],
			$type,
			null !== $brand ? (string) $brand : null,
			isset( $data['last4'] ) ? (string) $data['last4'] : null,
			isset( $data['exp_month'] ) ? (int) $data['exp_month'] : null,
			isset( $data['exp_year'] ) ? (int) $data['exp_year'] : null,
			true
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Called on the return URL after a redirect-based payment method or 3-D Secure in the browser. The intent id stored on
	 * the order wins over request parameters; the intent must belong to the order.
	 *
	 * @param PaymentContext $context Context.
	 * @param array          $params  Params.
	 */
	public function complete_payment( PaymentContext $context, array $params ): PaymentResult {
		$order = $context->order;
		$id    = (string) $order->gateway_transaction_id;
		if ( '' === $id ) {
			$id = (string) ( $params['payment_intent'] ?? ( $params['setup_intent'] ?? '' ) );
		}
		if ( ! preg_match( '/^(pi|seti)_[A-Za-z0-9]+$/', $id ) ) {
			return PaymentResult::failed( __( 'The payment was not completed.', 'memberkin' ) );
		}
		try {
			$intent = $this->client()->request( 'GET', ( str_starts_with( $id, 'pi_' ) ? 'payment_intents/' : 'setup_intents/' ) . $id, array( 'expand' => array( 'payment_method' ) ) );
		} catch ( StripeException $e ) {
			return $this->failure( $e, $order );
		}
		if ( (int) ( $intent['metadata']['memberkin_order_id'] ?? 0 ) !== $order->id ) {
			Logger::warning(
				'stripe',
				'Intent does not belong to the order.',
				array(
					'order'  => $order->id,
					'intent' => $id,
				)
			);
			return PaymentResult::failed( __( 'The payment was not completed.', 'memberkin' ) );
		}
		$result = $this->map_intent( $intent );
		if ( PaymentResult::REQUIRES_ACTION === $result->status ) {
			return PaymentResult::failed( __( 'Authentication was not completed. Please try again or use another payment method.', 'memberkin' ) );
		}
		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ChargeRequest $request Request.
	 */
	public function charge_saved_method( ChargeRequest $request ): PaymentResult {
		$sub      = $request->subscription;
		$customer = (string) $sub->gateway_customer_id;
		if ( '' === $customer ) {
			$customer = (string) get_user_option( 'memberkin_stripe_customer_' . $this->mode(), $sub->user_id );
		}
		try {
			$intent = $this->client()->request(
				'POST',
				'payment_intents',
				array(
					'amount'                    => self::amount( $request->amount->amount, $request->amount->currency ),
					'currency'                  => strtolower( $request->amount->currency ),
					'customer'                  => '' !== $customer ? $customer : null,
					'payment_method'            => $request->payment_method->token,
					'off_session'               => true,
					'confirm'                   => true,
					'description'               => mb_substr( $request->description, 0, 1000 ),
					'automatic_payment_methods' => array(
						'enabled'         => true,
						'allow_redirects' => 'never',
					),
					'metadata'                  => self::metadata( $request->order ),
				),
				$request->idempotency_key
			);
		} catch ( StripeException $e ) {
			if ( 'authentication_required' === $e->error_code() ) {
				$pi = (array) ( $e->error['payment_intent'] ?? array() );
				return new PaymentResult( PaymentResult::REQUIRES_ACTION, __( 'Your bank asked you to confirm this payment.', 'memberkin' ), isset( $pi['id'] ) ? (string) $pi['id'] : null );
			}
			return $this->failure( $e, $request->order );
		}
		$result = $this->map_intent( $intent, $customer );
		if ( PaymentResult::REQUIRES_ACTION === $result->status ) {
			return new PaymentResult( PaymentResult::REQUIRES_ACTION, __( 'Your bank asked you to confirm this payment.', 'memberkin' ), $result->transaction_id );
		}
		if ( $result->is_completed() || PaymentResult::PENDING === $result->status ) {
			return new PaymentResult( $result->status, '', $result->transaction_id, $result->customer_id, null, null, null, null, null, $request->payment_method->label() );
		}
		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Order  $order  Order.
	 * @param Money  $amount Amount.
	 * @param string $reason Reason.
	 */
	public function refund( Order $order, Money $amount, string $reason ): OperationResult {
		$tx = (string) $order->gateway_transaction_id;
		if ( ! preg_match( '/^(pi|ch)_[A-Za-z0-9]+$/', $tx ) ) {
			return OperationResult::error( __( 'This order has no Stripe payment to refund.', 'memberkin' ) );
		}
		try {
			$refund = $this->client()->request(
				'POST',
				'refunds',
				array(
					str_starts_with( $tx, 'pi_' ) ? 'payment_intent' : 'charge' => $tx,
					'amount'   => self::amount( $amount->amount, $amount->currency ),
					'reason'   => 'requested_by_customer',
					'metadata' => array(
						'memberkin_order_id' => $order->id,
						'memberkin_reason'   => mb_substr( $reason, 0, 500 ),
					),
				),
				'memberkin-refund-' . $order->id . '-' . $order->amount_refunded . '-' . $amount->amount
			);
		} catch ( StripeException $e ) {
			Logger::error( 'stripe', 'Refund failed: ' . $e->getMessage(), array( 'order' => $order->id ) );
			return OperationResult::error( $e->getMessage() );
		}
		if ( in_array( $refund['status'] ?? '', array( 'failed', 'canceled' ), true ) ) {
			return OperationResult::error( __( 'Stripe could not refund this payment.', 'memberkin' ) );
		}
		return OperationResult::ok( (string) $refund['id'], array( 'status' => (string) ( $refund['status'] ?? '' ) ) );
	}

	/**
	 * Whether a subscription is billed by Stripe Billing (imported) rather than this site.
	 *
	 * @param Subscription $subscription Subscription.
	 */
	private static function remote( Subscription $subscription ): bool {
		return 'gateway' === $subscription->billing_model && str_starts_with( (string) $subscription->gateway_subscription_id, 'sub_' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Subscription $subscription  Subscription.
	 * @param bool         $at_period_end At period end.
	 */
	public function cancel_subscription( Subscription $subscription, bool $at_period_end ): OperationResult {
		if ( ! self::remote( $subscription ) ) {
			return OperationResult::ok();
		}
		try {
			$path = 'subscriptions/' . $subscription->gateway_subscription_id;
			$at_period_end
				? $this->client()->request( 'POST', $path, array( 'cancel_at_period_end' => true ), 'memberkin-cancel-' . $subscription->id . '-end-' . Clock::timestamp() )
				: $this->client()->request( 'DELETE', $path );
		} catch ( StripeException $e ) {
			if ( 'resource_missing' === $e->error_code() ) {
				return OperationResult::ok();
			}
			return OperationResult::error( $e->getMessage() );
		}
		return OperationResult::ok();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Subscription            $subscription Subscription.
	 * @param \DateTimeImmutable|null $resume_at    Resume.
	 */
	public function pause_subscription( Subscription $subscription, ?\DateTimeImmutable $resume_at ): OperationResult {
		if ( ! self::remote( $subscription ) ) {
			return OperationResult::ok();
		}
		$pause = array( 'behavior' => 'void' );
		if ( $resume_at ) {
			$pause['resumes_at'] = $resume_at->getTimestamp();
		}
		try {
			$this->client()->request( 'POST', 'subscriptions/' . $subscription->gateway_subscription_id, array( 'pause_collection' => $pause ) );
		} catch ( StripeException $e ) {
			return OperationResult::error( $e->getMessage() );
		}
		return OperationResult::ok();
	}

	/**
	 * {@inheritDoc} Also used to undo a scheduled cancellation.
	 *
	 * @param Subscription $subscription Subscription.
	 */
	public function resume_subscription( Subscription $subscription ): OperationResult {
		if ( ! self::remote( $subscription ) ) {
			return OperationResult::ok();
		}
		try {
			$this->client()->request(
				'POST',
				'subscriptions/' . $subscription->gateway_subscription_id,
				array(
					'pause_collection'     => '',
					'cancel_at_period_end' => false,
				)
			);
		} catch ( StripeException $e ) {
			return OperationResult::error( $e->getMessage() );
		}
		return OperationResult::ok();
	}

	/**
	 * {@inheritDoc} Stripe-hosted page in setup mode; the result is applied on return and by webhook.
	 *
	 * @param Subscription $subscription Subscription.
	 * @param string       $return_url   Return URL.
	 */
	public function payment_method_update( Subscription $subscription, string $return_url ): PaymentMethodUpdate {
		$user     = get_userdata( $subscription->user_id );
		$customer = (string) $subscription->gateway_customer_id;
		if ( '' === $customer && self::remote( $subscription ) ) {
			// Imported Stripe Billing subscriptions: the card must belong to the subscription's own customer.
			$remote   = $this->client()->request( 'GET', 'subscriptions/' . $subscription->gateway_subscription_id );
			$customer = (string) ( self::ref( $remote['customer'] ?? null ) ?? '' );
			if ( '' !== $customer ) {
				memberkin()->get( SubscriptionRepository::class )->update( $subscription->id, array( 'gateway_customer_id' => $customer ) );
			}
		}
		if ( '' === $customer && $user ) {
			$customer = $this->customer_id( $user );
		}
		$session = $this->client()->request(
			'POST',
			'checkout/sessions',
			array(
				'mode'                 => 'setup',
				'customer'             => $customer,
				'currency'             => strtolower( $subscription->currency ),
				'payment_method_types' => array( 'card' ),
				'success_url'          => $return_url . ( str_contains( $return_url, '?' ) ? '&' : '?' ) . 'memberkin_stripe_setup={CHECKOUT_SESSION_ID}',
				'cancel_url'           => remove_query_arg( 'memberkin_notice', $return_url ),
				'metadata'             => array(
					'memberkin_subscription_id' => $subscription->id,
					'memberkin_user_id'         => $subscription->user_id,
				),
				'setup_intent_data'    => array(
					'metadata' => array( 'memberkin_subscription_id' => $subscription->id ),
				),
			)
		);
		return new PaymentMethodUpdate( 'redirect', (string) $session['url'] );
	}

	/**
	 * Return from the hosted payment-method page (?memberkin_stripe_setup=cs_…).
	 */
	public function handle_setup_return(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the Checkout Session is fetched from Stripe and must belong to the logged-in member.
		$session_id = isset( $_GET['memberkin_stripe_setup'] ) ? sanitize_text_field( wp_unslash( $_GET['memberkin_stripe_setup'] ) ) : '';
		if ( '' === $session_id || ! is_user_logged_in() || ! preg_match( '/^cs_[A-Za-z0-9_]+$/', $session_id ) ) {
			return;
		}
		$ok = false;
		try {
			$session = $this->client()->request( 'GET', 'checkout/sessions/' . $session_id, array( 'expand' => array( 'setup_intent.payment_method' ) ) );
			$sub     = memberkin()->get( SubscriptionRepository::class )->find( (int) ( $session['metadata']['memberkin_subscription_id'] ?? 0 ) );
			$intent  = (array) ( $session['setup_intent'] ?? array() );
			if ( $sub && get_current_user_id() === $sub->user_id && 'stripe' === $sub->gateway && 'succeeded' === ( $intent['status'] ?? '' ) && is_array( $intent['payment_method'] ?? null ) ) {
				$ok = $this->apply_payment_method( $sub, $this->payment_method( $intent['payment_method'] ) );
			}
		} catch ( StripeException $e ) {
			Logger::error( 'stripe', 'Payment method update failed: ' . $e->getMessage() );
		}
		$url = remove_query_arg( array( 'memberkin_stripe_setup', 'memberkin_notice' ) );
		wp_safe_redirect( add_query_arg( 'memberkin_notice', $ok ? 'card' : 'card_failed', $url ) );
		exit;
	}

	/**
	 * Stores a new payment method for a subscription (and makes it the default at Stripe for Stripe-billed ones).
	 *
	 * @param Subscription  $sub    Subscription.
	 * @param PaymentMethod $method Method.
	 */
	public function apply_payment_method( Subscription $sub, PaymentMethod $method ): bool {
		$method->user_id      = $sub->user_id;
		$method->gateway_mode = $sub->gateway_mode;
		$id                   = memberkin()->get( PaymentMethodRepository::class )->upsert( $method );
		memberkin()->get( SubscriptionService::class )->update( $sub->id, array( 'payment_method_id' => $id ) );
		if ( self::remote( $sub ) ) {
			try {
				$this->client()->request( 'POST', 'subscriptions/' . $sub->gateway_subscription_id, array( 'default_payment_method' => $method->token ) );
			} catch ( StripeException $e ) {
				Logger::error( 'stripe', 'Could not set the default payment method: ' . $e->getMessage(), array( 'subscription' => $sub->id ) );
				return false;
			}
		}
		Events::record( 'subscription.payment_method_updated', $sub->user_id, 'subscription', $sub->id, array( 'at' => Clock::now()->format( DATE_ATOM ) ) );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @throws InvalidSignature When the signature is missing or invalid.
	 */
	public function verify_webhook( \WP_REST_Request $request ): VerifiedWebhook {
		$body = (string) $request->get_body();
		if ( ! StripeClient::signature_valid( (string) $request->get_header( 'stripe_signature' ), $body, $this->credential( 'webhook_secret' ), Clock::timestamp() ) ) {
			throw new InvalidSignature( 'Invalid Stripe signature.' );
		}
		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) || empty( $payload['id'] ) || empty( $payload['type'] ) || 'event' !== ( $payload['object'] ?? '' ) ) {
			throw new InvalidSignature( 'Malformed Stripe event.' );
		}
		return new VerifiedWebhook( 'stripe', ! empty( $payload['livemode'] ) ? 'live' : 'test', (string) $payload['id'], (string) $payload['type'], $payload );
	}

	/**
	 * Id of a field that may be expanded.
	 *
	 * @param mixed $value Value.
	 */
	private static function ref( mixed $value ): ?string {
		if ( is_array( $value ) ) {
			return isset( $value['id'] ) ? (string) $value['id'] : null;
		}
		return null === $value || '' === $value ? null : (string) $value;
	}

	/**
	 * Subscription id of an invoice (old and new API shapes).
	 *
	 * @param array $invoice Invoice.
	 */
	private static function invoice_subscription( array $invoice ): ?string {
		return self::ref( $invoice['subscription'] ?? ( $invoice['parent']['subscription_details']['subscription'] ?? null ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param VerifiedWebhook $webhook Webhook.
	 * @return WebhookEvent[]
	 * @throws StripeException When a lookup fails (the event is retried).
	 */
	public function parse_webhook( VerifiedWebhook $webhook ): array {
		$obj      = (array) ( $webhook->payload['data']['object'] ?? array() );
		$previous = (array) ( $webhook->payload['data']['previous_attributes'] ?? array() );
		$currency = isset( $obj['currency'] ) ? strtoupper( (string) $obj['currency'] ) : null;
		$order_id = isset( $obj['metadata']['memberkin_order_id'] ) ? (int) $obj['metadata']['memberkin_order_id'] : null;

		switch ( $webhook->type ) {
			case 'payment_intent.succeeded':
				if ( ! empty( $obj['invoice'] ) ) {
					return array(); // Stripe Billing invoice: handled by invoice.paid.
				}
				return array( new WebhookEvent( 'payment.succeeded', (string) $obj['id'], null, $order_id, (int) ( $obj['amount_received'] ?? 0 ), $currency, null, '', array( 'customer_id' => self::ref( $obj['customer'] ?? null ) ) ) );

			case 'payment_intent.payment_failed':
				if ( ! empty( $obj['invoice'] ) ) {
					return array();
				}
				return array( new WebhookEvent( 'payment.failed', (string) $obj['id'], null, $order_id, null, $currency, null, (string) ( $obj['last_payment_error']['message'] ?? '' ) ) );

			case 'charge.refunded':
				$events = array();
				foreach ( (array) ( $obj['refunds']['data'] ?? array() ) as $refund ) {
					if ( 'succeeded' === ( $refund['status'] ?? '' ) ) {
						$events[] = new WebhookEvent( 'refund.succeeded', self::ref( $obj['payment_intent'] ?? null ) ?? (string) $obj['id'], null, null, (int) $refund['amount'], $currency, (string) $refund['id'] );
					}
				}
				return $events;

			case 'refund.created':
			case 'refund.updated':
				if ( 'succeeded' !== ( $obj['status'] ?? '' ) ) {
					return array();
				}
				return array( new WebhookEvent( 'refund.succeeded', self::ref( $obj['payment_intent'] ?? null ) ?? self::ref( $obj['charge'] ?? null ), null, null, (int) $obj['amount'], $currency, (string) $obj['id'] ) );

			case 'charge.dispute.created':
			case 'charge.dispute.closed':
				$opened = 'charge.dispute.created' === $webhook->type;
				return array( new WebhookEvent( $opened ? 'dispute.opened' : 'dispute.closed', self::ref( $obj['payment_intent'] ?? null ) ?? self::ref( $obj['charge'] ?? null ), null, null, isset( $obj['amount'] ) ? (int) $obj['amount'] : null, $currency, null, (string) ( $obj['reason'] ?? '' ), array( 'outcome' => (string) ( $obj['status'] ?? '' ) ) ) );

			case 'checkout.session.completed':
				$local = (int) ( $obj['metadata']['memberkin_subscription_id'] ?? 0 );
				$si    = self::ref( $obj['setup_intent'] ?? null );
				if ( 'setup' !== ( $obj['mode'] ?? '' ) || ! $local || ! $si ) {
					return array();
				}
				try {
					$intent = $this->client()->request( 'GET', 'setup_intents/' . $si, array( 'expand' => array( 'payment_method' ) ) );
				} catch ( StripeException $e ) {
					Logger::error( 'stripe', 'Could not fetch the SetupIntent: ' . $e->getMessage() );
					throw $e; // Retry the webhook later.
				}
				if ( 'succeeded' !== ( $intent['status'] ?? '' ) || ! is_array( $intent['payment_method'] ?? null ) ) {
					return array();
				}
				$pm = $this->payment_method( $intent['payment_method'] );
				return array(
					new WebhookEvent(
						'payment_method.updated',
						null,
						null,
						null,
						null,
						null,
						null,
						'',
						array(
							'local_subscription_id' => $local,
							'payment_method'        => array(
								'token'     => $pm->token,
								'type'      => $pm->type,
								'brand'     => $pm->brand,
								'last4'     => $pm->last4,
								'exp_month' => $pm->exp_month,
								'exp_year'  => $pm->exp_year,
							),
						)
					),
				);

			case 'invoice.paid':
				$sub = self::invoice_subscription( $obj );
				if ( ! $sub || (int) ( $obj['amount_paid'] ?? 0 ) <= 0 ) {
					return array();
				}
				$line = (array) ( $obj['lines']['data'][0]['period'] ?? array() );
				return array(
					new WebhookEvent(
						'payment.succeeded',
						self::ref( $obj['payment_intent'] ?? null ) ?? self::ref( $obj['charge'] ?? null ) ?? (string) $obj['id'],
						$sub,
						null,
						(int) $obj['amount_paid'],
						$currency,
						null,
						'',
						array_filter(
							array(
								'is_first_payment' => 'subscription_create' === ( $obj['billing_reason'] ?? '' ),
								'period_end'       => isset( $line['end'] ) ? (int) $line['end'] : null,
								'tax_amount'       => isset( $obj['tax'] ) ? (int) $obj['tax'] : null,
								'customer_id'      => self::ref( $obj['customer'] ?? null ),
							),
							static fn( $v ) => null !== $v
						)
					),
				);

			case 'invoice.payment_failed':
				$sub = self::invoice_subscription( $obj );
				return $sub ? array( new WebhookEvent( 'payment.failed', null, $sub, null, (int) ( $obj['amount_due'] ?? 0 ), $currency, null, __( 'The renewal payment failed.', 'memberkin' ) ) ) : array();

			case 'invoice.payment_action_required':
				$sub = self::invoice_subscription( $obj );
				return $sub ? array( new WebhookEvent( 'payment.action_required', null, $sub, null, null, null, null, '', array( 'hosted_url' => (string) ( $obj['hosted_invoice_url'] ?? '' ) ) ) ) : array();

			case 'customer.subscription.deleted':
				return array( new WebhookEvent( 'subscription.cancelled', null, (string) $obj['id'] ) );

			case 'customer.subscription.updated':
				$id     = (string) $obj['id'];
				$item   = (array) ( $obj['items']['data'][0] ?? array() );
				$start  = $obj['current_period_start'] ?? ( $item['current_period_start'] ?? null );
				$end    = $obj['current_period_end'] ?? ( $item['current_period_end'] ?? null );
				$events = array(
					new WebhookEvent(
						'subscription.updated',
						null,
						$id,
						null,
						null,
						null,
						null,
						'',
						array_filter(
							array(
								'period_start'         => null !== $start ? (int) $start : null,
								'period_end'           => null !== $end ? (int) $end : null,
								'cancel_at_period_end' => array_key_exists( 'cancel_at_period_end', $previous ) ? (bool) ( $obj['cancel_at_period_end'] ?? false ) : null,
							),
							static fn( $v ) => null !== $v
						)
					),
				);
				if ( array_key_exists( 'pause_collection', $previous ) ) {
					$paused   = ! empty( $obj['pause_collection'] );
					$events[] = new WebhookEvent( $paused ? 'subscription.paused' : 'subscription.resumed', null, $id, null, null, null, null, '', $paused && ! empty( $obj['pause_collection']['resumes_at'] ) ? array( 'resume_at' => (int) $obj['pause_collection']['resumes_at'] ) : array() );
				}
				if ( isset( $previous['status'] ) && 'past_due' === ( $obj['status'] ?? '' ) ) {
					$events[] = new WebhookEvent( 'subscription.past_due', null, $id );
				}
				return $events;
		}
		return array();
	}
}
