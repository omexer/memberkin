<?php
/**
 * Billing module (core): orders, subscriptions, gateways, checkout, renewals, webhooks.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Billing;

use Memberkin\Core\Capabilities;
use Memberkin\Core\Container;
use Memberkin\Core\Module;
use Memberkin\Core\Plugin;
use Memberkin\Domain\Membership;
use Memberkin\Domain\Order;
use Memberkin\Gateway\AbstractGateway;
use Memberkin\Gateway\GatewayRegistry;
use Memberkin\Gateway\Webhooks\WebhookController;
use Memberkin\Gateway\Webhooks\WebhookDispatcher;
use Memberkin\Gateway\Webhooks\WebhookProcessor;
use Memberkin\Repository\CheckoutSessionRepository;
use Memberkin\Repository\LevelGroupRepository;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\MembershipRepository;
use Memberkin\Repository\OrderRepository;
use Memberkin\Repository\PaymentMethodRepository;
use Memberkin\Repository\SequenceRepository;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\Checkout\CheckoutService;
use Memberkin\Service\CouponService;
use Memberkin\Service\MembershipService;
use Memberkin\Service\OrderService;
use Memberkin\Service\PricingService;
use Memberkin\Service\RenewalService;
use Memberkin\Service\SubscriptionService;
use Memberkin\Service\Tax\NoTax;
use Memberkin\Service\Tax\TaxCalculator;

defined( 'ABSPATH' ) || exit;

/**
 * Wires billing services and hooks.
 */
final class BillingModule extends Module {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'billing';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Billing', 'memberkin' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_core(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function recurring_jobs(): array {
		return array( 'memberkin_billing_tick' => 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Registers services.
	 *
	 * @param Container $c       Container.
	 * @param bool      $coupons Whether the coupons module is enabled.
	 */
	public static function services( Container $c, bool $coupons = true ): void {
		$c->set( OrderRepository::class, static fn() => new OrderRepository() );
		$c->set( SubscriptionRepository::class, static fn() => new SubscriptionRepository() );
		$c->set( PaymentMethodRepository::class, static fn() => new PaymentMethodRepository() );
		$c->set( SequenceRepository::class, static fn() => new SequenceRepository() );
		$c->set( CheckoutSessionRepository::class, static fn() => new CheckoutSessionRepository() );
		$c->set( GatewayRegistry::class, static fn() => new GatewayRegistry() );
		if ( ! $c->has( TaxCalculator::class ) ) {
			$c->set( TaxCalculator::class, static fn() => new NoTax() );
		}
		$c->set( OrderService::class, static fn( Container $c ) => new OrderService( $c->get( OrderRepository::class ), $c->get( SequenceRepository::class ) ) );
		$c->set(
			SubscriptionService::class,
			static fn( Container $c ) => new SubscriptionService(
				$c->get( SubscriptionRepository::class ),
				$c->get( MembershipService::class ),
				$c->get( MembershipRepository::class ),
				$c->get( GatewayRegistry::class ),
				$c->get( LevelRepository::class )
			)
		);
		$c->set(
			PricingService::class,
			static fn( Container $c ) => new PricingService(
				$c->get( LevelRepository::class ),
				$c->get( LevelGroupRepository::class ),
				$c->get( MembershipService::class ),
				$c->get( MembershipRepository::class ),
				$c->get( SubscriptionRepository::class ),
				$c->get( OrderRepository::class ),
				$c->get( TaxCalculator::class ),
				$coupons && $c->has( CouponService::class ) ? $c->get( CouponService::class ) : null
			)
		);
		$c->set(
			CheckoutService::class,
			static fn( Container $c ) => new CheckoutService(
				$c->get( PricingService::class ),
				$c->get( OrderService::class ),
				$c->get( OrderRepository::class ),
				$c->get( MembershipService::class ),
				$c->get( MembershipRepository::class ),
				$c->get( SubscriptionService::class ),
				$c->get( SubscriptionRepository::class ),
				$c->get( PaymentMethodRepository::class ),
				$c->get( GatewayRegistry::class ),
				$c->get( CheckoutSessionRepository::class ),
				$c->get( LevelRepository::class ),
				$coupons && $c->has( CouponService::class ) ? $c->get( CouponService::class ) : null
			)
		);
		$c->set(
			RenewalService::class,
			static fn( Container $c ) => new RenewalService(
				$c->get( SubscriptionRepository::class ),
				$c->get( SubscriptionService::class ),
				$c->get( OrderService::class ),
				$c->get( OrderRepository::class ),
				$c->get( PaymentMethodRepository::class ),
				$c->get( GatewayRegistry::class ),
				$c->get( LevelRepository::class ),
				$c->get( MembershipRepository::class )
			)
		);
		$c->set(
			WebhookDispatcher::class,
			static fn( Container $c ) => new WebhookDispatcher(
				$c->get( OrderRepository::class ),
				$c->get( OrderService::class ),
				$c->get( SubscriptionRepository::class ),
				$c->get( SubscriptionService::class ),
				$c->get( CheckoutService::class ),
				$c->get( PaymentMethodRepository::class ),
				$c->get( LevelRepository::class )
			)
		);
		$c->set(
			\Memberkin\Service\RefundService::class,
			static fn( Container $c ) => new \Memberkin\Service\RefundService(
				$c->get( OrderRepository::class ),
				$c->get( OrderService::class ),
				$c->get( GatewayRegistry::class ),
				$c->get( MembershipRepository::class ),
				$c->get( MembershipService::class ),
				$c->get( SubscriptionService::class )
			)
		);
		$c->set( WebhookProcessor::class, static fn( Container $c ) => new WebhookProcessor( $c->get( GatewayRegistry::class ), $c->get( WebhookDispatcher::class ) ) );
	}

	/**
	 * Registers hooks and services.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function register( Plugin $plugin ): void {
		self::services( $plugin->container(), $plugin->modules()->is_enabled( 'coupons' ) );

		add_action( 'memberkin_register_gateways', array( $this, 'register_gateways' ), 5 );
		add_filter( 'memberkin_settings_defaults', array( $this, 'settings_defaults' ) );
		add_filter( 'memberkin_settings_fields', array( $this, 'settings_fields' ) );
		add_action( 'memberkin_settings_tab_before', array( $this, 'payments_tab_intro' ) );

		add_action( 'rest_api_init', static fn() => ( new WebhookController( $plugin->get( GatewayRegistry::class ) ) )->register_routes() );

		// Jobs.
		add_action(
			'memberkin_billing_tick',
			static function () use ( $plugin ): void {
				$subs = $plugin->get( SubscriptionService::class );
				$subs->process_scheduled_changes();
				$subs->queue_due_renewals();
			}
		);
		add_action( 'memberkin_renewal_charge', static fn( $subscription_id ) => $plugin->get( RenewalService::class )->charge( (int) $subscription_id ), 10, 1 );
		add_action( 'memberkin_process_webhook', static fn( $id ) => $plugin->get( WebhookProcessor::class )->process( (int) $id ) );

		// Cross-aggregate rules.
		add_action( 'memberkin_order_paid', array( $this, 'on_order_paid' ) );
		add_action( 'memberkin_membership_ended', array( $this, 'on_membership_ended' ), 10, 2 );
		add_filter( 'memberkin_auto_resume_membership', array( $this, 'auto_resume_membership' ), 10, 2 );

		// Offsite returns.
		add_action( 'template_redirect', array( $this, 'handle_return' ), 1 );
		add_action(
			'template_redirect',
			static function () use ( $plugin ): void {
				$gateway = $plugin->get( GatewayRegistry::class )->get( 'stripe' );
				if ( $gateway instanceof \Memberkin\Gateway\Stripe\StripeGateway ) {
					$gateway->handle_setup_return();
				}
			},
			1
		);
		add_action(
			'memberkin_subscription_cancelled',
			static function ( $sub ) use ( $plugin ): void {
				$gateway = $plugin->get( GatewayRegistry::class )->get( 'paypal' );
				if ( $gateway instanceof \Memberkin\Gateway\PayPal\PayPalGateway && $sub instanceof \Memberkin\Domain\Subscription ) {
					$gateway->on_local_cancel( $sub );
				}
			}
		);

		if ( is_admin() ) {
			$orders = new Admin\OrdersScreen( $plugin );
			$subs   = new Admin\SubscriptionsScreen( $plugin );
			$tools  = new Admin\ToolsScreen( $plugin );
			$orders->register();
			$subs->register();
			$tools->register();
			add_filter(
				'memberkin_admin_pages',
				static function ( array $pages ) use ( $orders, $subs, $tools ): array {
					$pages[ Admin\OrdersScreen::PAGE ]        = array(
						'title'    => __( 'Orders', 'memberkin' ),
						'menu'     => __( 'Orders', 'memberkin' ),
						'cap'      => Capabilities::MANAGE_ORDERS,
						'callback' => array( $orders, 'render' ),
						'position' => 20,
					);
					$pages[ Admin\SubscriptionsScreen::PAGE ] = array(
						'title'    => __( 'Subscriptions', 'memberkin' ),
						'menu'     => __( 'Subscriptions', 'memberkin' ),
						'cap'      => Capabilities::MANAGE_ORDERS,
						'callback' => array( $subs, 'render' ),
						'position' => 30,
					);
					$pages[ Admin\ToolsScreen::PAGE ]         = array(
						'title'    => __( 'Membership Tools', 'memberkin' ),
						'menu'     => __( 'Tools', 'memberkin' ),
						'cap'      => Capabilities::MANAGE_SETTINGS,
						'callback' => array( $tools, 'render' ),
						'position' => 95,
					);
					return $pages;
				}
			);
		}
	}

	/**
	 * Built-in gateways.
	 *
	 * @param GatewayRegistry $registry Registry.
	 */
	public function register_gateways( GatewayRegistry $registry ): void {
		$registry->register( new \Memberkin\Gateway\Stripe\StripeGateway() );
		$registry->register( new \Memberkin\Gateway\PayPal\PayPalGateway() );
	}

	/**
	 * Defaults for invoices and gateway sections.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public function settings_defaults( array $defaults ): array {
		$defaults['invoices'] = array(
			'invoice_prefix'     => 'INV-',
			'credit_note_prefix' => 'CN-',
			'number_padding'     => 6,
			'invoice_start'      => 1,
			'credit_note_start'  => 1,
		);
		return $defaults;
	}

	/**
	 * Gateway settings on the Payments tab.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public function settings_fields( array $fields ): array {
		foreach ( memberkin()->get( GatewayRegistry::class )->all() as $gateway ) {
			$gateway_fields = $gateway->settings_fields();
			if ( $gateway_fields ) {
				$gateway_fields[0]['heading'] = $gateway->label();
				if ( $gateway instanceof AbstractGateway && $gateway->supports( \Memberkin\Gateway\Contracts\Capability::WEBHOOKS ) ) {
					/* translators: %s: URL. */
					$gateway_fields[0]['description'] = sprintf( __( 'Webhook URL: %s', 'memberkin' ), $gateway->webhook_url() );
				}
				$fields = array_merge( $fields, $gateway_fields );
			}
		}
		return $fields;
	}

	/**
	 * Intro text on the Payments tab.
	 *
	 * @param string $tab Tab.
	 */
	public function payments_tab_intro( string $tab ): void {
		if ( 'payments' !== $tab ) {
			return;
		}
		echo '<p>' . esc_html__( 'Enable one or more payment gateways. Credentials can also be defined as constants in wp-config.php; constants always win over values saved here.', 'memberkin' ) . '</p>';
	}

	/**
	 * A paid order completes its checkout (offline orders marked paid by an admin) and manual renewals.
	 *
	 * @param Order $order Order.
	 */
	public function on_order_paid( Order $order ): void {
		if ( in_array( $order->type, array( 'initial', 'upgrade', 'gift' ), true ) && 'full' !== get_metadata( 'memberkin_order', $order->id, CheckoutService::FULFILLED_META, true ) && metadata_exists( 'memberkin_order', $order->id, CheckoutService::MAP_META ) ) { // Gift orders have an empty map.
			memberkin()->get( CheckoutService::class )->fulfil( $order->id, null );
		}
		memberkin()->get( RenewalService::class )->on_order_paid( $order );
	}

	/**
	 * When a membership ends for a reason other than its subscription, stop the subscription too.
	 *
	 * @param Membership $membership Membership.
	 * @param array      $context    Context.
	 */
	public function on_membership_ended( Membership $membership, array $context ): void {
		if ( ! $membership->subscription_id ) {
			return;
		}
		$sub = memberkin()->get( SubscriptionRepository::class )->find( $membership->subscription_id );
		if ( ! $sub || ! $sub->is_live() ) {
			return;
		}
		try {
			memberkin()->get( SubscriptionService::class )->cancel( $sub->id, false, (string) ( $context['reason'] ?? 'membership_ended' ) );
		} catch ( \Throwable $e ) {
			\Memberkin\Core\Logger::error( 'billing', 'Could not cancel subscription of ended membership: ' . $e->getMessage(), array( 'subscription' => $sub->id ) );
		}
	}

	/**
	 * Memberships with a subscription are resumed by the subscription (which also resumes billing).
	 *
	 * @param bool       $resume     Resume.
	 * @param Membership $membership Membership.
	 */
	public function auto_resume_membership( bool $resume, Membership $membership ): bool {
		return $membership->subscription_id ? false : $resume;
	}

	/**
	 * Handles ?memberkin_return={token} after an offsite payment page.
	 */
	public function handle_return(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the session token authenticates the return; the gateway verifies the payment.
		if ( empty( $_GET['memberkin_return'] ) ) {
			return;
		}
		$token  = sanitize_text_field( wp_unslash( $_GET['memberkin_return'] ) );
		$params = array();
		foreach ( wp_unslash( $_GET ) as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$params[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
			}
		}
		// phpcs:enable
		$result = memberkin()->get( CheckoutService::class )->confirm( $token, $params );
		if ( $result->redirect_url ) {
			if ( \Memberkin\Service\Checkout\CheckoutResult::REDIRECT === $result->status ) {
				wp_redirect( $result->redirect_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- offsite payment page URL returned by the gateway API.
			} else {
				wp_safe_redirect( $result->redirect_url );
			}
			exit;
		}
		$message = $result->errors ? (string) reset( $result->errors ) : __( 'The payment was not completed.', 'memberkin' );
		set_transient( 'memberkin_checkout_error_' . $token, $message, 10 * MINUTE_IN_SECONDS );
		$checkout = memberkin_page_url( 'checkout', array( 'memberkin_token' => $token ) );
		wp_safe_redirect( $checkout ? $checkout : home_url( '/' ) );
		exit;
	}
}
