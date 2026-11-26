<?php
/**
 * PayPal gateway (REST: Orders v2 and Subscriptions v1).
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\PayPal;

use Memberkin\Core\Logger;
use Memberkin\Core\Money;
use Memberkin\Domain\Order;
use Memberkin\Domain\Pricing\Quote;
use Memberkin\Domain\Pricing\RecurringTerms;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\AbstractGateway;
use Memberkin\Gateway\Contracts\Capability;
use Memberkin\Gateway\Contracts\InvalidSignature;
use Memberkin\Gateway\Contracts\OperationResult;
use Memberkin\Gateway\Contracts\PaymentContext;
use Memberkin\Gateway\Contracts\PaymentResult;
use Memberkin\Gateway\Contracts\VerifiedWebhook;
use Memberkin\Gateway\Contracts\WebhookEvent;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\OrderRepository;
use Memberkin\Repository\SubscriptionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Offsite approval at PayPal.
 *
 * - One-time purchases: an Order (intent CAPTURE) is created, the member approves it at PayPal, and it is captured on the
 *   return URL (or completed by the PAYMENT.CAPTURE.COMPLETED webhook when the capture is pending).
 * - Recurring purchases: a PayPal Subscription (billing model "gateway"). Today's amount is the subscription's setup fee;
 *   the first regular cycle starts at the first renewal date. Plans are created on demand per price and cached.
 * - Webhooks are verified by PayPal's verify-webhook-signature API with the configured webhook id.
 *
 * Amounts sent to PayPal include tax (PayPal plans hold gross prices).
 */
final class PayPalGateway extends AbstractGateway {

	/**
	 * Capabilities.
	 *
	 * @var string[]
	 */
	protected array $capabilities = array(
		Capability::ONE_TIME,
		Capability::RECURRING_GATEWAY,
		Capability::TRIALS,
		Capability::REFUND_FULL,
		Capability::REFUND_PARTIAL,
		Capability::CANCEL,
		Capability::PAUSE,
		Capability::OFFSITE_REDIRECT,
		Capability::WEBHOOKS,
	);

	/**
	 * Currencies PayPal accepts.
	 *
	 * @var string[]
	 */
	protected array $currencies = array( 'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD' );

	/**
	 * Currencies PayPal only accepts as whole numbers.
	 */
	private const NO_DECIMALS = array( 'HUF', 'JPY', 'TWD' );

	/**
	 * Longest billing interval PayPal allows per unit.
	 */
	private const MAX_INTERVAL = array(
		'day'   => 365,
		'week'  => 52,
		'month' => 12,
		'year'  => 1,
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'paypal';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'PayPal', 'memberkin' );
	}

	/**
	 * API client.
	 */
	public function client(): PayPalClient {
		return new PayPalClient( $this->public_credential( 'client_id' ), $this->credential( 'client_secret' ), $this->mode() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_fields(): array {
		$fields   = array_merge(
			$this->common_fields(),
			$this->public_fields( 'client_id', __( 'client ID', 'memberkin' ) ),
			$this->secret_fields( 'client_secret', __( 'client secret', 'memberkin' ) ),
			$this->public_fields( 'webhook_id', __( 'webhook ID', 'memberkin' ) )
		);
		$fields[] = array(
			'tab'         => 'payments',
			'section'     => $this->section(),
			'key'         => 'webhook_info',
			'label'       => __( 'Webhook endpoint', 'memberkin' ),
			'type'        => 'info',
			'description' => sprintf(
				/* translators: 1: URL, 2: event list. */
				__( 'In your PayPal app add a webhook for %1$s with the events %2$s, then copy its ID above.', 'memberkin' ),
				'<code>' . esc_html( $this->webhook_url() ) . '</code>',
				'<code>' . esc_html( implode( ', ', self::webhook_events() ) ) . '</code>'
			),
		);
		return $fields;
	}

	/**
	 * Events the webhook should send.
	 *
	 * @return string[]
	 */
	public static function webhook_events(): array {
		return array( 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.SALE.COMPLETED', 'PAYMENT.SALE.REFUNDED', 'PAYMENT.SALE.REVERSED', 'BILLING.SUBSCRIPTION.ACTIVATED', 'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.EXPIRED', 'BILLING.SUBSCRIPTION.SUSPENDED', 'BILLING.SUBSCRIPTION.PAYMENT.FAILED', 'CUSTOMER.DISPUTE.CREATED', 'CUSTOMER.DISPUTE.RESOLVED' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_ready(): bool {
		return parent::is_ready() && '' !== $this->public_credential( 'client_id' ) && '' !== $this->credential( 'client_secret' );
	}

	/**
	 * {@inheritDoc} One recurring level per checkout, within PayPal's interval limits.
	 *
	 * @param Quote $quote Quote.
	 */
	public function is_available_for( Quote $quote ): bool {
		if ( ! parent::is_available_for( $quote ) || count( $quote->recurring ) > 1 ) {
			return false;
		}
		foreach ( $quote->recurring as $terms ) {
			$max = self::MAX_INTERVAL[ $terms->billing_period ] ?? 0;
			if ( $terms->billing_interval < 1 || $terms->billing_interval > $max ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * PayPal amount string.
	 *
	 * @param Money $money Money.
	 */
	public static function value( Money $money ): string {
		if ( in_array( $money->currency, self::NO_DECIMALS, true ) ) {
			return (string) (int) round( (float) $money->to_decimal() );
		}
		return $money->to_decimal();
	}

	/**
	 * PayPal amount → minor units.
	 *
	 * @param string $value    Decimal string.
	 * @param string $currency Currency.
	 */
	public static function minor( string $value, string $currency ): int {
		return abs( Money::from_decimal( $value, strtoupper( $currency ) )->amount );
	}

	/**
	 * Link of a given relation.
	 *
	 * @param array  $item    Resource.
	 * @param string ...$rels Relations, first match wins.
	 */
	private static function link( array $item, string ...$rels ): string {
		foreach ( $rels as $rel ) {
			foreach ( (array) ( $item['links'] ?? array() ) as $link ) {
				if ( ( $link['rel'] ?? '' ) === $rel ) {
					return (string) $link['href'];
				}
			}
		}
		return '';
	}

	/**
	 * Shared experience settings.
	 *
	 * @param PaymentContext $context Context.
	 * @param string         $action  PAY_NOW|SUBSCRIBE_NOW.
	 */
	private static function experience( PaymentContext $context, string $action ): array {
		return array(
			'brand_name'          => mb_substr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 0, 127 ),
			'shipping_preference' => 'NO_SHIPPING',
			'user_action'         => $action,
			'return_url'          => $context->return_url,
			'cancel_url'          => $context->cancel_url,
		);
	}

	/**
	 * Order description.
	 *
	 * @param Order $order Order.
	 */
	private static function description( Order $order ): string {
		return mb_substr( implode( ', ', array_map( static fn( $item ) => $item->description, $order->items ) ), 0, 127 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param PaymentContext $context Context.
	 */
	public function start_payment( PaymentContext $context ): PaymentResult {
		try {
			return $context->subscription && $context->quote->recurring ? $this->start_subscription( $context ) : $this->start_order( $context );
		} catch ( PayPalException $e ) {
			Logger::error(
				'paypal',
				'Checkout could not start: ' . $e->getMessage(),
				array(
					'order' => $context->order->id,
					'issue' => $e->issue(),
				)
			);
			return PaymentResult::failed( __( 'PayPal is not available right now. Please try again or use another payment method.', 'memberkin' ) );
		}
	}

	/**
	 * Creates a PayPal Order and redirects for approval.
	 *
	 * @param PaymentContext $context Context.
	 */
	private function start_order( PaymentContext $context ): PaymentResult {
		$order    = $context->order;
		$response = $this->client()->request(
			'POST',
			'/v2/checkout/orders',
			array(
				'intent'         => 'CAPTURE',
				'purchase_units' => array(
					array(
						'reference_id' => $order->number,
						'custom_id'    => (string) $order->id,
						'description'  => self::description( $order ),
						'amount'       => array(
							'currency_code' => $order->currency,
							'value'         => self::value( $order->total() ),
						),
					),
				),
				'payment_source' => array(
					'paypal' => array( 'experience_context' => self::experience( $context, 'PAY_NOW' ) ),
				),
			),
			'memberkin-order-' . get_current_blog_id() . '-' . $order->id
		);
		$url      = self::link( $response, 'payer-action', 'approve' );
		if ( '' === $url || empty( $response['id'] ) ) {
			return PaymentResult::failed( __( 'PayPal did not return an approval link.', 'memberkin' ) );
		}
		update_metadata( 'memberkin_order', $order->id, 'memberkin_paypal_order', (string) $response['id'] );
		return new PaymentResult( PaymentResult::REDIRECT, '', null, null, null, null, $url );
	}

	/**
	 * Creates a PayPal Subscription and redirects for approval.
	 *
	 * @param PaymentContext $context Context.
	 */
	private function start_subscription( PaymentContext $context ): PaymentResult {
		$order    = $context->order;
		$level_id = (int) array_key_first( $context->quote->recurring );
		$terms    = $context->quote->recurring[ $level_id ];
		$user     = $context->user;
		$body     = array(
			'plan_id'             => $this->plan_id( $level_id, $terms, $context->quote->currency ),
			'custom_id'           => (string) $order->id,
			'start_time'          => gmdate( 'Y-m-d\TH:i:s\Z', max( $terms->first_renewal_at->getTimestamp(), time() + 600 ) ),
			'subscriber'          => array_filter(
				array(
					'email_address' => $user->user_email,
					'name'          => array_filter(
						array(
							'given_name' => mb_substr( (string) $user->first_name, 0, 140 ),
							'surname'    => mb_substr( (string) $user->last_name, 0, 140 ),
						)
					),
				)
			),
			'application_context' => self::experience( $context, 'SUBSCRIBE_NOW' ),
		);
		if ( $order->total > 0 ) {
			$body['plan'] = array(
				'payment_preferences' => array(
					'setup_fee' => array(
						'currency_code' => $order->currency,
						'value'         => self::value( $order->total() ),
					),
				),
			);
		}
		$response = $this->client()->request( 'POST', '/v1/billing/subscriptions', $body, 'memberkin-sub-' . get_current_blog_id() . '-' . $order->id );
		$url      = self::link( $response, 'approve' );
		if ( '' === $url || empty( $response['id'] ) ) {
			return PaymentResult::failed( __( 'PayPal did not return an approval link.', 'memberkin' ) );
		}
		return new PaymentResult( PaymentResult::REDIRECT, '', null, null, (string) $response['id'], null, $url );
	}

	/**
	 * PayPal billing cycles for recurring terms (gross amounts).
	 *
	 * @param RecurringTerms $terms    Terms.
	 * @param string         $currency Currency.
	 * @return array<int, array<string, mixed>>
	 */
	public static function billing_cycles( RecurringTerms $terms, string $currency ): array {
		$first_net   = $terms->first_amount->amount;
		$regular_net = $terms->regular_amount->amount;
		$first_tax   = $terms->tax_amount;
		$regular_tax = 0 === $first_tax ? 0 : ( $regular_net === $first_net ? $first_tax : $terms->regular_amount->percentage( $terms->tax_rate )->amount );
		$first       = Money::of( $first_net + $first_tax, $currency );
		$regular     = Money::of( $regular_net + $regular_tax, $currency );
		$limit       = max( 0, $terms->renewals_limit );
		$frequency   = array(
			'interval_unit'  => strtoupper( $terms->billing_period ),
			'interval_count' => $terms->billing_interval,
		);
		$price       = static fn( Money $m ) => array(
			'fixed_price' => array(
				'currency_code' => $currency,
				'value'         => self::value( $m ),
			),
		);

		$intro = 0;
		if ( $first_net !== $regular_net && null !== $terms->discount_cycles ) {
			$intro = max( (int) $terms->discount_cycles, $terms->trial_cycles_left );
		} elseif ( $first_net !== $regular_net && $terms->trial_cycles_left > 0 ) {
			$intro = $terms->trial_cycles_left;
		}
		if ( $limit > 0 ) {
			$intro = min( $intro, $limit );
		}
		$all_first = $first_net !== $regular_net && 0 === $intro; // Discount applies to every renewal.

		$cycles = array();
		if ( $intro > 0 ) {
			$cycle = array(
				'frequency'    => $frequency,
				'tenure_type'  => 'TRIAL',
				'sequence'     => 1,
				'total_cycles' => $intro,
			);
			if ( $first->is_positive() ) {
				$cycle['pricing_scheme'] = $price( $first );
			}
			$cycles[] = $cycle;
			if ( $limit > 0 && $intro >= $limit ) {
				return $cycles;
			}
		}
		$cycles[] = array(
			'frequency'      => $frequency,
			'tenure_type'    => 'REGULAR',
			'sequence'       => count( $cycles ) + 1,
			'total_cycles'   => $limit > 0 ? $limit - $intro : 0,
			'pricing_scheme' => $price( $all_first ? $first : $regular ),
		);
		return $cycles;
	}

	/**
	 * PayPal product of a level (created once per mode).
	 *
	 * @param int $level_id Level.
	 * @throws PayPalException On API errors.
	 */
	private function product_id( int $level_id ): string {
		$levels = memberkin()->get( LevelRepository::class );
		$key    = 'paypal_product_' . $this->mode();
		$stored = (string) $levels->meta( $level_id, $key, '' );
		if ( '' !== $stored ) {
			return $stored;
		}
		$level   = $levels->find( $level_id );
		$product = $this->client()->request(
			'POST',
			'/v1/catalogs/products',
			array(
				'name' => mb_substr( $level ? $level->name : 'Membership', 0, 127 ),
				'type' => 'SERVICE',
			),
			'memberkin-product-' . get_current_blog_id() . '-' . $level_id . '-' . $this->mode()
		);
		$levels->set_meta( $level_id, $key, (string) $product['id'] );
		return (string) $product['id'];
	}

	/**
	 * PayPal plan for these terms (cached per product and price structure).
	 *
	 * @param int            $level_id Level.
	 * @param RecurringTerms $terms    Terms.
	 * @param string         $currency Currency.
	 * @throws PayPalException On API errors.
	 */
	public function plan_id( int $level_id, RecurringTerms $terms, string $currency ): string {
		$product = $this->product_id( $level_id );
		$cycles  = self::billing_cycles( $terms, $currency );
		$hash    = md5( (string) wp_json_encode( array( $product, $cycles ) ) );
		$plans   = (array) get_option( 'memberkin_paypal_plans', array() );
		if ( ! empty( $plans[ $this->mode() ][ $hash ] ) ) {
			return (string) $plans[ $this->mode() ][ $hash ];
		}
		$level                           = memberkin()->get( LevelRepository::class )->find( $level_id );
		$plan                            = $this->client()->request(
			'POST',
			'/v1/billing/plans',
			array(
				'product_id'          => $product,
				'name'                => mb_substr( ( $level ? $level->name : 'Membership' ) . ' (' . strtolower( $terms->billing_interval . ' ' . $terms->billing_period ) . ')', 0, 127 ),
				'status'              => 'ACTIVE',
				'billing_cycles'      => $cycles,
				'payment_preferences' => array(
					'auto_bill_outstanding'     => true,
					'setup_fee_failure_action'  => 'CANCEL',
					'payment_failure_threshold' => 3,
				),
			),
			'memberkin-plan-' . $hash
		);
		$plans[ $this->mode() ][ $hash ] = (string) $plan['id'];
		update_option( 'memberkin_paypal_plans', $plans, false );
		return (string) $plan['id'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param PaymentContext $context Context.
	 * @param array          $params  Return parameters.
	 */
	public function complete_payment( PaymentContext $context, array $params ): PaymentResult {
		try {
			if ( $context->subscription && str_starts_with( (string) $context->subscription->gateway_subscription_id, 'I-' ) ) {
				return $this->complete_subscription( $context, $params );
			}
			return $this->capture( $context, $params );
		} catch ( PayPalException $e ) {
			Logger::error(
				'paypal',
				'Completion failed: ' . $e->getMessage(),
				array(
					'order' => $context->order->id,
					'issue' => $e->issue(),
				)
			);
			if ( 'INSTRUMENT_DECLINED' === $e->issue() ) {
				return PaymentResult::failed( __( 'PayPal declined that payment method. Please try again and choose another way to pay.', 'memberkin' ) );
			}
			return PaymentResult::failed( __( 'The PayPal payment could not be completed.', 'memberkin' ) );
		}
	}

	/**
	 * Captures an approved Order.
	 *
	 * @param PaymentContext $context Context.
	 * @param array          $params  Params (token = PayPal order id).
	 * @throws PayPalException On API errors other than "already captured".
	 */
	private function capture( PaymentContext $context, array $params ): PaymentResult {
		$order  = $context->order;
		$stored = (string) get_metadata( 'memberkin_order', $order->id, 'memberkin_paypal_order', true );
		if ( '' === $stored || ( isset( $params['token'] ) && $params['token'] !== $stored ) ) {
			return PaymentResult::failed( __( 'The PayPal payment could not be matched to this order.', 'memberkin' ) );
		}
		try {
			$response = $this->client()->request( 'POST', '/v2/checkout/orders/' . rawurlencode( $stored ) . '/capture', array(), 'memberkin-capture-' . get_current_blog_id() . '-' . $order->id );
		} catch ( PayPalException $e ) {
			if ( 'ORDER_ALREADY_CAPTURED' !== $e->issue() ) {
				throw $e;
			}
			$response = $this->client()->request( 'GET', '/v2/checkout/orders/' . rawurlencode( $stored ) );
		}
		$unit    = (array) ( $response['purchase_units'][0] ?? array() );
		$capture = (array) ( $unit['payments']['captures'][0] ?? array() );
		if ( (string) ( $unit['custom_id'] ?? (string) $order->id ) !== (string) $order->id ) {
			return PaymentResult::failed( __( 'The PayPal payment could not be matched to this order.', 'memberkin' ) );
		}
		$payer = (array) ( $response['payer'] ?? array() );
		$label = __( 'PayPal', 'memberkin' ) . ( ! empty( $payer['email_address'] ) ? ' (' . $payer['email_address'] . ')' : '' );
		switch ( (string) ( $capture['status'] ?? '' ) ) {
			case 'COMPLETED':
				return new PaymentResult( PaymentResult::COMPLETED, '', (string) $capture['id'], $payer['payer_id'] ?? null, null, null, null, null, null, $label );
			case 'PENDING':
				return new PaymentResult( PaymentResult::PENDING, '', (string) $capture['id'], $payer['payer_id'] ?? null, null, null, null, null, null, $label );
			default:
				return PaymentResult::failed( __( 'PayPal did not complete the payment.', 'memberkin' ) );
		}
	}

	/**
	 * Checks an approved Subscription.
	 *
	 * @param PaymentContext $context Context.
	 * @param array          $params  Params (subscription_id).
	 * @throws PayPalException On API errors.
	 */
	private function complete_subscription( PaymentContext $context, array $params ): PaymentResult {
		$id = (string) $context->subscription->gateway_subscription_id;
		if ( isset( $params['subscription_id'] ) && $params['subscription_id'] !== $id ) {
			return PaymentResult::failed( __( 'The PayPal subscription could not be matched to this order.', 'memberkin' ) );
		}
		$remote = $this->client()->request( 'GET', '/v1/billing/subscriptions/' . rawurlencode( $id ) );
		if ( (string) ( $remote['custom_id'] ?? '' ) !== (string) $context->order->id ) {
			return PaymentResult::failed( __( 'The PayPal subscription could not be matched to this order.', 'memberkin' ) );
		}
		$subscriber = (array) ( $remote['subscriber'] ?? array() );
		$next       = ! empty( $remote['billing_info']['next_billing_time'] ) ? new \DateTimeImmutable( (string) $remote['billing_info']['next_billing_time'] ) : null;
		$label      = __( 'PayPal', 'memberkin' ) . ( ! empty( $subscriber['email_address'] ) ? ' (' . $subscriber['email_address'] . ')' : '' );
		switch ( (string) ( $remote['status'] ?? '' ) ) {
			case 'ACTIVE':
				return new PaymentResult( PaymentResult::COMPLETED, '', null, $subscriber['payer_id'] ?? null, $id, null, null, null, $next, $label );
			case 'APPROVED':
				// Approved, activation (and setup fee) still processing: the ACTIVATED webhook completes the order.
				return new PaymentResult( PaymentResult::PENDING, '', null, $subscriber['payer_id'] ?? null, $id, null, null, null, $next, $label );
			default:
				return PaymentResult::failed( __( 'The PayPal subscription was not approved.', 'memberkin' ) );
		}
	}

	/**
	 * Calls a subscription action (suspend|cancel|activate). "Already in that state" counts as success.
	 *
	 * @param Subscription $subscription Subscription.
	 * @param string       $action       Action.
	 * @param string       $reason       Reason.
	 */
	private function subscription_action( Subscription $subscription, string $action, string $reason ): OperationResult {
		$id = (string) $subscription->gateway_subscription_id;
		if ( 'gateway' !== $subscription->billing_model || ! str_starts_with( $id, 'I-' ) ) {
			return OperationResult::ok();
		}
		try {
			$this->client()->request( 'POST', '/v1/billing/subscriptions/' . rawurlencode( $id ) . '/' . $action, array( 'reason' => mb_substr( $reason, 0, 127 ) ) );
		} catch ( PayPalException $e ) {
			if ( 'SUBSCRIPTION_STATUS_INVALID' === $e->issue() ) {
				return OperationResult::ok();
			}
			Logger::error( 'paypal', 'Subscription ' . $action . ' failed: ' . $e->getMessage(), array( 'subscription' => $subscription->id ) );
			return OperationResult::error( $e->getMessage() );
		}
		return OperationResult::ok();
	}

	/**
	 * {@inheritDoc} PayPal cannot cancel "at period end" and a cancelled PayPal subscription cannot be restarted, so a
	 * scheduled cancellation suspends billing (reversible) and the subscription is cancelled at PayPal when it ends here.
	 *
	 * @param Subscription $subscription  Subscription.
	 * @param bool         $at_period_end At period end.
	 */
	public function cancel_subscription( Subscription $subscription, bool $at_period_end ): OperationResult {
		return $this->subscription_action( $subscription, $at_period_end ? 'suspend' : 'cancel', $at_period_end ? 'Cancellation scheduled at the end of the paid period' : 'Cancelled' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Subscription            $subscription Subscription.
	 * @param \DateTimeImmutable|null $resume_at    Resume.
	 */
	public function pause_subscription( Subscription $subscription, ?\DateTimeImmutable $resume_at ): OperationResult {
		return $this->subscription_action( $subscription, 'suspend', 'Paused' );
	}

	/**
	 * {@inheritDoc} Also undoes a scheduled cancellation.
	 *
	 * @param Subscription $subscription Subscription.
	 */
	public function resume_subscription( Subscription $subscription ): OperationResult {
		return $this->subscription_action( $subscription, 'activate', 'Resumed' );
	}

	/**
	 * Cancels at PayPal when a PayPal-billed subscription ended here (e.g. a scheduled cancellation reached its date).
	 *
	 * @param Subscription $subscription Subscription.
	 */
	public function on_local_cancel( Subscription $subscription ): void {
		if ( 'paypal' === $subscription->gateway && 'gateway' === $subscription->billing_model ) {
			$this->subscription_action( $subscription, 'cancel', 'Subscription ended' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Order  $order  Order.
	 * @param Money  $amount Amount.
	 * @param string $reason Reason.
	 * @throws PayPalException Internally (caught and returned as an error result).
	 */
	public function refund( Order $order, Money $amount, string $reason ): OperationResult {
		$tx = (string) $order->gateway_transaction_id;
		if ( '' === $tx ) {
			return OperationResult::error( __( 'This order has no PayPal payment to refund.', 'memberkin' ) );
		}
		$key = 'memberkin-refund-' . get_current_blog_id() . '-' . $order->id . '-' . $order->amount_refunded . '-' . $amount->amount;
		try {
			try {
				$body = array(
					'amount' => array(
						'currency_code' => $amount->currency,
						'value'         => self::value( $amount ),
					),
				);
				if ( '' !== trim( $reason ) ) {
					$body['note_to_payer'] = mb_substr( $reason, 0, 255 );
				}
				$refund = $this->client()->request( 'POST', '/v2/payments/captures/' . rawurlencode( $tx ) . '/refund', $body, $key );
			} catch ( PayPalException $e ) {
				if ( 404 !== $e->status ) {
					throw $e;
				}
				// Subscription payments are "sales" (v1).
				$refund = $this->client()->request(
					'POST',
					'/v1/payments/sale/' . rawurlencode( $tx ) . '/refund',
					array(
						'amount' => array(
							'total'    => self::value( $amount ),
							'currency' => $amount->currency,
						),
					),
					$key
				);
			}
		} catch ( PayPalException $e ) {
			Logger::error( 'paypal', 'Refund failed: ' . $e->getMessage(), array( 'order' => $order->id ) );
			return OperationResult::error( $e->getMessage() );
		}
		$status = strtoupper( (string) ( $refund['status'] ?? ( $refund['state'] ?? '' ) ) );
		if ( in_array( $status, array( 'FAILED', 'CANCELLED' ), true ) ) {
			return OperationResult::error( __( 'PayPal could not refund this payment.', 'memberkin' ) );
		}
		return OperationResult::ok( (string) $refund['id'], array( 'status' => $status ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @throws InvalidSignature When PayPal does not confirm the signature.
	 */
	public function verify_webhook( \WP_REST_Request $request ): VerifiedWebhook {
		$webhook_id = $this->public_credential( 'webhook_id' );
		$body       = (string) $request->get_body();
		$headers    = array();
		foreach ( array( 'auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time' ) as $name ) {
			$headers[ $name ] = (string) $request->get_header( 'paypal_' . $name );
			if ( '' === $headers[ $name ] ) {
				throw new InvalidSignature( 'Missing PayPal header ' . esc_html( $name ) . '.' );
			}
		}
		$payload = json_decode( $body, true );
		if ( '' === $webhook_id || ! is_array( $payload ) || empty( $payload['id'] ) || empty( $payload['event_type'] ) ) {
			throw new InvalidSignature( 'Malformed PayPal event or webhook id not configured.' );
		}
		// The event must be passed back exactly as received, so the JSON is assembled around the raw body.
		$verify = '{';
		foreach ( $headers as $name => $value ) {
			$verify .= wp_json_encode( $name ) . ':' . wp_json_encode( $value ) . ',';
		}
		$verify .= '"webhook_id":' . wp_json_encode( $webhook_id ) . ',"webhook_event":' . $body . '}';
		try {
			$result = $this->client()->request( 'POST', '/v1/notifications/verify-webhook-signature', $verify );
		} catch ( PayPalException $e ) {
			throw new InvalidSignature( 'PayPal signature check failed: ' . esc_html( $e->getMessage() ) );
		}
		if ( 'SUCCESS' !== ( $result['verification_status'] ?? '' ) ) {
			throw new InvalidSignature( 'PayPal did not verify the event.' );
		}
		return new VerifiedWebhook( 'paypal', $this->mode(), (string) $payload['id'], (string) $payload['event_type'], $payload );
	}

	/**
	 * Local subscription for a PayPal subscription id.
	 *
	 * @param string $remote Remote id.
	 */
	private function local( string $remote ): ?Subscription {
		return '' === $remote ? null : memberkin()->get( SubscriptionRepository::class )->find_by_gateway_id( 'paypal', $remote );
	}

	/**
	 * Capture id from a refund resource's "up" link.
	 *
	 * @param array $refund Refund.
	 */
	private static function refunded_capture( array $refund ): ?string {
		$href = self::link( $refund, 'up' );
		return preg_match( '#/captures/([^/?]+)#', $href, $m ) ? $m[1] : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param VerifiedWebhook $webhook Webhook.
	 * @return WebhookEvent[]
	 * @throws PayPalException When a lookup fails (the event is retried).
	 */
	public function parse_webhook( VerifiedWebhook $webhook ): array {
		$r = (array) ( $webhook->payload['resource'] ?? array() );
		switch ( $webhook->type ) {
			case 'PAYMENT.CAPTURE.COMPLETED':
				return array( new WebhookEvent( 'payment.succeeded', (string) $r['id'], null, isset( $r['custom_id'] ) ? (int) $r['custom_id'] : null, self::minor( (string) ( $r['amount']['value'] ?? '0' ), (string) ( $r['amount']['currency_code'] ?? 'USD' ) ), strtoupper( (string) ( $r['amount']['currency_code'] ?? '' ) ) ) );

			case 'PAYMENT.CAPTURE.DENIED':
			case 'PAYMENT.CAPTURE.DECLINED':
				return array( new WebhookEvent( 'payment.failed', (string) $r['id'], null, isset( $r['custom_id'] ) ? (int) $r['custom_id'] : null, null, null, null, __( 'PayPal declined the payment.', 'memberkin' ) ) );

			case 'PAYMENT.CAPTURE.REFUNDED':
				return array( new WebhookEvent( 'refund.succeeded', self::refunded_capture( $r ), null, null, self::minor( (string) ( $r['amount']['value'] ?? '0' ), (string) ( $r['amount']['currency_code'] ?? 'USD' ) ), null, (string) $r['id'] ) );

			case 'PAYMENT.SALE.COMPLETED':
				$remote = (string) ( $r['billing_agreement_id'] ?? '' );
				if ( '' === $remote ) {
					return array();
				}
				$currency = strtoupper( (string) ( $r['amount']['currency'] ?? 'USD' ) );
				$sub      = $this->client()->request( 'GET', '/v1/billing/subscriptions/' . rawurlencode( $remote ) );
				$done     = 0;
				foreach ( (array) ( $sub['billing_info']['cycle_executions'] ?? array() ) as $cycle ) {
					$done += (int) ( $cycle['cycles_completed'] ?? 0 );
				}
				$next  = ! empty( $sub['billing_info']['next_billing_time'] ) ? strtotime( (string) $sub['billing_info']['next_billing_time'] ) : null;
				$first = 0 === $done; // No cycle billed yet: this sale is the setup fee (today's checkout amount).
				return array(
					new WebhookEvent(
						'payment.succeeded',
						(string) $r['id'],
						$remote,
						$first && isset( $sub['custom_id'] ) ? (int) $sub['custom_id'] : null,
						self::minor( (string) ( $r['amount']['total'] ?? '0' ), $currency ),
						$currency,
						null,
						'',
						array_filter(
							array(
								'is_first_payment' => $first,
								'period_end'       => $next ? $next : null,
							),
							static fn( $v ) => null !== $v
						)
					),
				);

			case 'PAYMENT.SALE.REFUNDED':
			case 'PAYMENT.SALE.REVERSED':
				$currency = (string) ( $r['amount']['currency'] ?? 'USD' );
				return array( new WebhookEvent( 'refund.succeeded', (string) ( $r['sale_id'] ?? $r['id'] ), null, null, self::minor( (string) ( $r['amount']['total'] ?? '0' ), $currency ), strtoupper( $currency ), (string) $r['id'] ) );

			case 'BILLING.SUBSCRIPTION.ACTIVATED':
				$events = array(
					new WebhookEvent(
						'payment.succeeded',
						null,
						(string) $r['id'],
						isset( $r['custom_id'] ) ? (int) $r['custom_id'] : null,
						null,
						null,
						null,
						'',
						array_filter(
							array(
								'is_first_payment' => true,
								'customer_id'      => $r['subscriber']['payer_id'] ?? null,
								'period_end'       => ! empty( $r['billing_info']['next_billing_time'] ) ? strtotime( (string) $r['billing_info']['next_billing_time'] ) : null,
							),
							static fn( $v ) => null !== $v
						)
					),
				);
				// Re-activation after a pause.
				$events[] = new WebhookEvent( 'subscription.resumed', null, (string) $r['id'] );
				return $events;

			case 'BILLING.SUBSCRIPTION.CANCELLED':
				return array( new WebhookEvent( 'subscription.cancelled', null, (string) $r['id'], null, null, null, null, '', array( 'at_period_end' => true ) ) );

			case 'BILLING.SUBSCRIPTION.EXPIRED':
				return array( new WebhookEvent( 'subscription.expired', null, (string) $r['id'] ) );

			case 'BILLING.SUBSCRIPTION.SUSPENDED':
				$local = $this->local( (string) $r['id'] );
				// Our own scheduled cancellations, pauses and dunning also suspend at PayPal: nothing to mirror then.
				if ( ! $local || $local->cancel_at_period_end || in_array( $local->status, array( Subscription::PAUSED, Subscription::PAST_DUE, Subscription::UNPAID, Subscription::CANCELLED ), true ) ) {
					return array();
				}
				return array( new WebhookEvent( 'subscription.paused', null, (string) $r['id'] ) );

			case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
				return array( new WebhookEvent( 'payment.failed', null, (string) $r['id'], null, null, null, null, __( 'PayPal could not collect the renewal payment.', 'memberkin' ) ) );

			case 'CUSTOMER.DISPUTE.CREATED':
			case 'CUSTOMER.DISPUTE.RESOLVED':
				$tx = (string) ( $r['disputed_transactions'][0]['seller_transaction_id'] ?? '' );
				if ( '' === $tx ) {
					return array();
				}
				$won = 'RESOLVED_SELLER_FAVOUR' === ( $r['dispute_outcome']['outcome_code'] ?? '' );
				return array( new WebhookEvent( 'CUSTOMER.DISPUTE.CREATED' === $webhook->type ? 'dispute.opened' : 'dispute.closed', $tx, null, null, null, null, null, (string) ( $r['reason'] ?? '' ), array( 'outcome' => $won ? 'won' : 'lost' ) ) );
		}
		return array();
	}
}
