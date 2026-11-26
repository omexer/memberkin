<?php
/**
 * [memberkin_checkout] and [memberkin_confirmation].
 *
 * @package Memberkin
 */

namespace Memberkin\Frontend;

use Memberkin\Core\Settings;
use Memberkin\Core\Templates;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Level;
use Memberkin\Domain\Order;
use Memberkin\Domain\Pricing\Quote;
use Memberkin\Gateway\GatewayRegistry;
use Memberkin\Gateway\AbstractGateway;
use Memberkin\Repository\CheckoutSessionRepository;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\OrderRepository;
use Memberkin\Service\Checkout\CheckoutResult;
use Memberkin\Service\Checkout\CheckoutService;
use Memberkin\Service\PricingService;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and processes the checkout form (works without JavaScript; JS adds a live price summary).
 */
final class CheckoutController {

	/**
	 * Result of a POST processed in this request (shown by the shortcode).
	 *
	 * @var CheckoutResult|null
	 */
	private ?CheckoutResult $result = null;

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_shortcode( 'memberkin_checkout', array( $this, 'render_checkout' ) );
		add_shortcode( 'memberkin_confirmation', array( $this, 'render_confirmation' ) );
		add_action( 'template_redirect', array( $this, 'handle_post' ), 5 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Level(s) requested in the URL or form.
	 *
	 * @return Level[]
	 */
	private function requested_levels(): array {
		$repo = memberkin()->get( LevelRepository::class );
		// phpcs:disable WordPress.Security.NonceVerification -- read-only selection of what to display/buy; the POST itself is nonce-checked.
		if ( isset( $_POST['level_ids'] ) ) {
			$raw = is_array( $_POST['level_ids'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['level_ids'] ) ) : explode( ',', sanitize_text_field( wp_unslash( (string) $_POST['level_ids'] ) ) );
		} elseif ( isset( $_GET['level'] ) ) {
			$raw = explode( ',', sanitize_text_field( wp_unslash( (string) $_GET['level'] ) ) );
		} elseif ( isset( $_GET['level_id'] ) ) {
			$raw = explode( ',', sanitize_text_field( wp_unslash( (string) $_GET['level_id'] ) ) );
		} else {
			$raw = array();
		}
		$add = isset( $_GET['add'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['add'] ) ) ) : array();
		// phpcs:enable
		$levels = array();
		foreach ( array_merge( $raw, $add ) as $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			$level = is_numeric( $value ) ? $repo->find( (int) $value ) : $repo->find_by_slug( $value );
			if ( $level && $level->is_purchasable() ) {
				$levels[ $level->id ] = $level;
			}
		}
		return array_values( $levels );
	}


	/**
	 * What the member buys: the requested levels, plus add-on choices (extra levels, chosen prices,
	 * levels bought without automatic renewal) contributed by add-ons.
	 *
	 * @param Level[] $levels  Requested levels.
	 * @param array   $src     Raw form or request data (add-ons sanitise their own keys).
	 * @param int     $user_id Buyer.
	 * @return array{level_ids: int[], custom_prices: array<int, string>, one_time: int[]}
	 */
	public static function resolve_choices( array $levels, array $src, int $user_id ): array {
		$resolved = array(
			'level_ids'     => array_map( static fn( $level ) => (int) $level->id, $levels ),
			'custom_prices' => array(),
			'one_time'      => array(),
		);
		/**
		 * Filters what is bought at checkout.
		 *
		 * @param array   $resolved level_ids (int[]), custom_prices (level id => decimal string), one_time (int[]).
		 * @param Level[] $levels   Requested levels.
		 * @param array   $src      Raw form or request data.
		 * @param int     $user_id  Buyer.
		 */
		$filtered = (array) apply_filters( 'memberkin_checkout_resolve_levels', $resolved, $levels, $src, $user_id );
		return array(
			'level_ids'     => array_values( array_filter( array_map( 'intval', (array) ( $filtered['level_ids'] ?? $resolved['level_ids'] ) ) ) ),
			'custom_prices' => array_map( 'strval', (array) ( $filtered['custom_prices'] ?? array() ) ),
			'one_time'      => array_values( array_map( 'intval', (array) ( $filtered['one_time'] ?? array() ) ) ),
		);
	}

	/**
	 * Visitor IP (REMOTE_ADDR only).
	 */
	public static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the visitor IP (REMOTE_ADDR by default). Sites behind a trusted proxy or CDN can return the forwarded
		 * address here; never trust forwarding headers without knowing the proxy.
		 *
		 * @param string $ip IP.
		 */
		$ip = (string) apply_filters( 'memberkin_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Country from a trusted CDN header, if configured.
	 */
	public static function ip_country(): string {
		/**
		 * Filters the request header used as IP country evidence (e.g. CF-IPCountry when behind Cloudflare). Empty = none.
		 *
		 * @param string $header Header name in $_SERVER format.
		 */
		$header = (string) apply_filters( 'memberkin_ip_country_header', isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ? 'HTTP_CF_IPCOUNTRY' : '' );
		if ( '' === $header || empty( $_SERVER[ $header ] ) ) {
			return '';
		}
		$country = strtoupper( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
	}

	/**
	 * Processes a submitted checkout before output starts (so redirects work).
	 */
	public function handle_post(): void {
		if ( 'post' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) || empty( $_POST['memberkin_checkout'] ) ) {
			return;
		}
		if ( ! isset( $_POST['_memberkin_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_memberkin_nonce'] ) ), 'memberkin_checkout' ) ) {
			$this->result = CheckoutResult::failed( array( 'form' => __( 'Your session expired. Please submit the form again.', 'memberkin' ) ) );
			return;
		}
		// Honeypot.
		if ( ! empty( $_POST['memberkin_website'] ) ) {
			$this->result = CheckoutResult::failed( array( 'form' => __( 'The form could not be submitted.', 'memberkin' ) ) );
			return;
		}
		// "Apply coupon" without JavaScript just re-renders the form with the new quote.
		if ( ! empty( $_POST['memberkin_apply_coupon'] ) ) {
			return;
		}

		$post     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitised by CheckoutService/OrderService.
		$resolved = self::resolve_choices( $this->requested_levels(), $post, get_current_user_id() );
		$input    = array(
			'token'          => sanitize_text_field( (string) ( $post['memberkin_token'] ?? '' ) ),
			'level_ids'      => $resolved['level_ids'],
			'custom_prices'  => $resolved['custom_prices'],
			'one_time'       => $resolved['one_time'],
			'coupon_code'    => sanitize_text_field( (string) ( $post['coupon_code'] ?? '' ) ),
			'gateway'        => sanitize_key( (string) ( $post['gateway'] ?? '' ) ),
			'account'        => array(
				'email'      => sanitize_email( (string) ( $post['account']['email'] ?? '' ) ),
				'username'   => sanitize_user( (string) ( $post['account']['username'] ?? '' ), true ),
				'password'   => (string) ( $post['account']['password'] ?? '' ), // Not sanitised: any character is valid in a password.
				'first_name' => sanitize_text_field( (string) ( $post['account']['first_name'] ?? '' ) ),
				'last_name'  => sanitize_text_field( (string) ( $post['account']['last_name'] ?? '' ) ),
			),
			'billing'        => array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $post['billing'] ?? array() ) ) ),
			'tax'            => array( 'vat_number' => sanitize_text_field( (string) ( $post['vat_number'] ?? '' ) ) ),
			'consent'        => array(
				'terms'   => ! empty( $post['consent']['terms'] ),
				'privacy' => ! empty( $post['consent']['privacy'] ),
			),
			'gateway_fields' => array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $post['memberkin_gateway'] ?? array() ) ) ),
			'ip'             => self::ip(),
			'ip_country'     => self::ip_country(),
			'extra'          => isset( $post['memberkin_extra'] ) && is_array( $post['memberkin_extra'] ) ? $post['memberkin_extra'] : array(), // Modules sanitise their own keys.
		);

		/**
		 * Filters the checkout input before it is submitted. Add-ons add their own keys here and sanitise them
		 * in their own CheckoutService hooks.
		 *
		 * @param array $input Checkout input.
		 * @param array $post  Raw (unslashed) form data. The nonce was already verified.
		 */
		$input = (array) apply_filters( 'memberkin_checkout_input', $input, $post );

		$this->result = memberkin()->get( CheckoutService::class )->submit( $input, get_current_user_id() );
		if ( in_array( $this->result->status, array( CheckoutResult::COMPLETED, CheckoutResult::PENDING ), true ) && '' !== $this->result->redirect_url ) {
			wp_safe_redirect( $this->result->redirect_url );
			exit;
		}
		if ( CheckoutResult::REDIRECT === $this->result->status ) {
			wp_redirect( $this->result->redirect_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- offsite payment page URL returned by the gateway API.
			exit;
		}
	}

	/**
	 * [memberkin_checkout].
	 *
	 * @return string
	 */
	public function render_checkout(): string {
		$levels = $this->requested_levels();
		if ( ! $levels ) {
			$levels_url = memberkin_page_url( 'levels' );
			return Templates::render(
				'checkout/no-level',
				array( 'levels_url' => $levels_url )
			);
		}

		$user_id = get_current_user_id();
		$token   = $this->result ? $this->result->token : ( isset( $_GET['memberkin_token'] ) ? sanitize_text_field( wp_unslash( $_GET['memberkin_token'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$errors  = $this->result ? $this->result->errors : array();
		if ( '' !== $token && ! $this->result ) {
			$offsite_error = get_transient( 'memberkin_checkout_error_' . $token );
			if ( $offsite_error ) {
				$errors['payment'] = (string) $offsite_error;
				delete_transient( 'memberkin_checkout_error_' . $token );
			}
		}
		// phpcs:disable WordPress.Security.NonceVerification -- display only.
		$coupon = sanitize_text_field( wp_unslash( (string) ( $_POST['coupon_code'] ?? ( $_GET['coupon'] ?? '' ) ) ) );
		$values = array(
			'account' => isset( $_POST['account'] ) && is_array( $_POST['account'] ) ? array_diff_key( array_map( 'sanitize_text_field', wp_unslash( $_POST['account'] ) ), array( 'password' => 1 ) ) : array(),
			'billing' => isset( $_POST['billing'] ) && is_array( $_POST['billing'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['billing'] ) ) : array(),
			'gateway' => sanitize_key( (string) ( $_POST['gateway'] ?? '' ) ),
		);
		// phpcs:enable

		$choices  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- display only; add-ons parse and sanitise their own keys.
		$resolved = self::resolve_choices( $levels, $choices, $user_id );
		$base     = array(
			'level_ids'          => $resolved['level_ids'],
			'user_id'            => $user_id,
			'one_time'           => $resolved['one_time'],
			'merchant_of_record' => memberkin()->get( CheckoutService::class )->gateway_is_merchant_of_record( (string) ( $values['gateway'] ?? '' ) ),
		);

		$quote = null;
		try {
			$quote = memberkin()->get( PricingService::class )->quote(
				$base + array(
					'custom_prices' => $resolved['custom_prices'],
					'coupon_code'   => $coupon,
					'tax'           => array(
						'vat_number' => isset( $_POST['vat_number'] ) ? sanitize_text_field( wp_unslash( $_POST['vat_number'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- display only.
						'country'    => $values['billing']['country'] ?? '',
						'region'     => $values['billing']['region'] ?? '',
						'postcode'   => $values['billing']['postcode'] ?? '',
						'ip_country' => self::ip_country(),
					),
				)
			);
		} catch ( ValidationException $e ) {
			$errors = array_merge( $e->errors(), $errors );
			$soft   = array_filter( array_keys( $e->errors() ), static fn( $k ) => 'coupon' === $k || str_starts_with( (string) $k, 'price_' ) );
			if ( $soft ) {
				// Show the form again with the error: price without the coupon or the rejected amount.
				try {
					$quote = memberkin()->get( PricingService::class )->quote( $base + ( isset( $e->errors()['coupon'] ) ? array( 'custom_prices' => $resolved['custom_prices'] ) : array( 'coupon_code' => $coupon ) ) );
					if ( isset( $e->errors()['coupon'] ) ) {
						$coupon = '';
					}
				} catch ( ValidationException $inner ) {
					try {
						$quote  = memberkin()->get( PricingService::class )->quote( $base );
						$coupon = '';
					} catch ( ValidationException $last ) {
						$quote = null;
					}
				}
			}
		}
		if ( ! $quote ) {
			return Templates::render( 'checkout/unavailable', array( 'errors' => $errors ) );
		}

		$gateways = array();
		if ( $quote->requires_payment() ) {
			$allowed = array();
			foreach ( $levels as $level ) {
				$level_allowed = (array) memberkin()->get( LevelRepository::class )->meta( $level->id, 'gateways', array() );
				$allowed       = $level_allowed ? ( $allowed ? array_intersect( $allowed, $level_allowed ) : $level_allowed ) : $allowed;
			}
			$gateways = memberkin()->get( GatewayRegistry::class )->available_for( $quote, array_values( $allowed ) );
		}

		wp_enqueue_style( 'memberkin-frontend' );
		wp_enqueue_script( 'memberkin-checkout' );
		/**
		 * Fires when the checkout form is about to render, for add-ons to enqueue their assets.
		 */
		do_action( 'memberkin_checkout_enqueue_scripts' );
		$settings = Settings::section( 'checkout' );

		return Templates::render(
			'checkout/form',
			array(
				'levels'          => $levels,
				'quote'           => $quote,
				'options'         => (string) apply_filters( 'memberkin_checkout_options_html', '', $levels, $choices, $resolved, $errors, $user_id ),
				'errors'          => $errors,
				'gateways'        => $gateways,
				'user'            => $user_id ? wp_get_current_user() : null,
				'coupon'          => $coupon,
				'coupons_enabled' => memberkin()->modules()->is_enabled( 'coupons' ),
				'values'          => $values,
				'token'           => $token,
				'settings'        => $settings,
				'need_address'    => $quote->requires_payment() && ( ! empty( $settings['require_billing_address'] ) || $quote->tax->address_required ),
				'vat_field'       => (bool) apply_filters( 'memberkin_checkout_show_vat_field', false, $quote ),
				'vat_number'      => isset( $_POST['vat_number'] ) ? sanitize_text_field( wp_unslash( $_POST['vat_number'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- display only.
				'client_secret'   => $this->result && CheckoutResult::REQUIRES_ACTION === $this->result->status ? $this->result->client_secret : '',
				'login_url'       => wp_login_url( (string) ( isset( $_SERVER['REQUEST_URI'] ) ? home_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : '' ) ),
			)
		);
	}

	/**
	 * [memberkin_confirmation].
	 *
	 * @return string
	 */
	public function render_confirmation(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the session key authenticates guests.
		$number = isset( $_GET['memberkin_order'] ) ? sanitize_text_field( wp_unslash( $_GET['memberkin_order'] ) ) : '';
		$key    = isset( $_GET['memberkin_key'] ) ? sanitize_text_field( wp_unslash( $_GET['memberkin_key'] ) ) : '';
		// phpcs:enable
		$order = '' !== $number ? memberkin()->get( OrderRepository::class )->find_by_number( $number ) : null;
		if ( ! $order || ! self::can_view_order( $order, $key ) ) {
			return Templates::render( 'checkout/confirmation-missing', array() );
		}
		wp_enqueue_style( 'memberkin-frontend' );
		$levels  = memberkin()->get( LevelRepository::class );
		$gateway = memberkin()->get( GatewayRegistry::class )->get( $order->gateway );
		$message = '';
		foreach ( $order->level_ids() as $level_id ) {
			$message .= (string) $levels->meta( $level_id, 'confirmation_message', '' );
		}
		return Templates::render(
			'checkout/confirmation',
			array(
				'order'        => $order,
				'levels'       => array_filter( array_map( array( $levels, 'find' ), $order->level_ids() ) ),
				'memberships'  => memberkin_get_memberships( $order->user_id ),
				/**
				 * Filters the heading area of the confirmation page (HTML). Empty uses the default "Thank you" heading.
				 * Add-ons use it for orders that are not a normal purchase (gifts, applications awaiting approval).
				 *
				 * @param string $html  HTML.
				 * @param Order  $order Order.
				 */
				'header'       => (string) apply_filters( 'memberkin_confirmation_header_html', '', $order ),
				'message'      => $message,
				'instructions' => $gateway instanceof AbstractGateway && ! $order->is_paid() ? $gateway->instructions( $order ) : '',
				'account_url'  => memberkin_page_url( 'account' ),
			)
		);
	}

	/**
	 * Whether the current visitor may see an order: its owner, a manager, or a guest holding the checkout key.
	 *
	 * @param Order  $order Order.
	 * @param string $key   Checkout session token.
	 */
	public static function can_view_order( Order $order, string $key = '' ): bool {
		if ( is_user_logged_in() && ( get_current_user_id() === $order->user_id || current_user_can( \Memberkin\Core\Capabilities::MANAGE_ORDERS ) ) ) {
			return true;
		}
		if ( '' === $key ) {
			return false;
		}
		$session = memberkin()->get( CheckoutSessionRepository::class )->find( $key );
		return $session && (int) $session->order_id === $order->id && hash_equals( (string) $session->token, $key );
	}

	/**
	 * REST: POST /memberkin/v1/quote — live price summary for the checkout form.
	 */
	public function register_routes(): void {
		register_rest_route(
			'memberkin/v1',
			'/quote',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_quote' ),
				'permission_callback' => '__return_true', // Public, read-only; rate limited below.
				'args'                => array(
					'level_ids'   => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => true,
					),
					'coupon_code' => array( 'type' => 'string' ),
					'choices'     => array( 'type' => 'string' ),
					'country'     => array( 'type' => 'string' ),
					'region'      => array( 'type' => 'string' ),
					'postcode'    => array( 'type' => 'string' ),
					'vat_number'  => array( 'type' => 'string' ),
					'gateway'     => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Quote endpoint callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_quote( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$key   = 'memberkin_quote_rl_' . md5( self::ip() );
		$count = (int) get_transient( $key );
		if ( $count > 60 ) {
			return new \WP_Error( 'memberkin_rate_limited', __( 'Too many requests. Please wait a minute.', 'memberkin' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		$repo    = memberkin()->get( LevelRepository::class );
		$levels  = array_values( array_filter( array_map( static fn( $id ) => $repo->find( (int) $id ), (array) $request['level_ids'] ) ) );
		$choices = array();
		wp_parse_str( (string) $request['choices'], $choices ); // Form fields named memberkin_*; add-ons sanitise their own keys.
		$resolved = self::resolve_choices( $levels, $choices, get_current_user_id() );
		try {
			$quote = memberkin()->get( PricingService::class )->quote(
				array(
					'level_ids'          => $resolved['level_ids'] ? $resolved['level_ids'] : array_map( 'intval', (array) $request['level_ids'] ),
					'custom_prices'      => $resolved['custom_prices'],
					'one_time'           => $resolved['one_time'],
					'user_id'            => get_current_user_id(),
					'coupon_code'        => sanitize_text_field( (string) $request['coupon_code'] ),
					'merchant_of_record' => memberkin()->get( CheckoutService::class )->gateway_is_merchant_of_record( sanitize_key( (string) $request['gateway'] ) ),
					'tax'                => array(
						'country'    => sanitize_text_field( (string) $request['country'] ),
						'region'     => sanitize_text_field( (string) $request['region'] ),
						'postcode'   => sanitize_text_field( (string) $request['postcode'] ),
						'vat_number' => sanitize_text_field( (string) $request['vat_number'] ),
						'ip_country' => self::ip_country(),
					),
				)
			);
		} catch ( ValidationException $e ) {
			return $e->to_wp_error();
		}
		return new \WP_REST_Response(
			array(
				'quote' => $quote,
				'html'  => Templates::render( 'checkout/summary', array( 'quote' => $quote ) ),
			)
		);
	}

	/**
	 * Formatted recurring description for a quote (used by templates).
	 *
	 * @param Quote $quote Quote.
	 * @return string[]
	 */
	public static function recurring_lines( Quote $quote ): array {
		$lines  = array();
		$levels = memberkin()->get( LevelRepository::class );
		foreach ( $quote->recurring as $terms ) {
			$level = $levels->find( $terms->level_id );
			$gross = $terms->first_amount->amount + $terms->tax_amount;
			$every = 1 === $terms->billing_interval
				? \Memberkin\Domain\Period::label( $terms->billing_period, 1 )
				: $terms->billing_interval . ' ' . \Memberkin\Domain\Period::label( $terms->billing_period, $terms->billing_interval );
			$line  = sprintf(
				/* translators: 1: level, 2: amount, 3: frequency, 4: date. */
				__( '%1$s: %2$s every %3$s, starting %4$s', 'memberkin' ),
				$level ? $level->name : '',
				\Memberkin\Core\Money::of( $gross, $quote->currency )->format(),
				$every,
				wp_date( get_option( 'date_format' ), $terms->first_renewal_at->getTimestamp() )
			);
			if ( null !== $terms->discount_cycles && ! $terms->first_amount->equals( $terms->regular_amount ) ) {
				$line .= ' ' . sprintf(
					/* translators: 1: number of payments, 2: regular amount. */
					_n( '(discounted for %1$d payment, then %2$s)', '(discounted for %1$d payments, then %2$s)', $terms->discount_cycles, 'memberkin' ),
					$terms->discount_cycles,
					$terms->regular_amount->format()
				);
			}
			if ( $terms->renewals_limit > 0 ) {
				/* translators: %d: number of renewals. */
				$line .= ' ' . sprintf( _n( '(%d renewal)', '(%d renewals)', $terms->renewals_limit, 'memberkin' ), $terms->renewals_limit );
			}
			$lines[] = $line;
		}
		return $lines;
	}
}
