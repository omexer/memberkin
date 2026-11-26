<?php
/**
 * Maps normalised webhook events onto orders, subscriptions and memberships.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Webhooks;

use Memberkin\Core\Clock;
use Memberkin\Core\Events;
use Memberkin\Core\Money;
use Memberkin\Domain\Order;
use Memberkin\Domain\PaymentMethod;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\Contracts\PaymentResult;
use Memberkin\Gateway\Contracts\WebhookEvent;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\OrderRepository;
use Memberkin\Repository\PaymentMethodRepository;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\Checkout\CheckoutService;
use Memberkin\Service\OrderService;
use Memberkin\Service\SubscriptionService;

defined( 'ABSPATH' ) || exit;

/**
 * One place for webhook semantics, so each gateway only translates its payloads (ARCHITECTURE.md §7).
 */
class WebhookDispatcher {

	/**
	 * Constructor.
	 *
	 * @param OrderRepository         $order_repo        Orders.
	 * @param OrderService            $orders            Order service.
	 * @param SubscriptionRepository  $subscription_repo Subscriptions.
	 * @param SubscriptionService     $subscriptions     Subscription service.
	 * @param CheckoutService         $checkout          Checkout.
	 * @param PaymentMethodRepository $methods           Payment methods.
	 * @param LevelRepository         $levels            Levels.
	 */
	public function __construct(
		private OrderRepository $order_repo,
		private OrderService $orders,
		private SubscriptionRepository $subscription_repo,
		private SubscriptionService $subscriptions,
		private CheckoutService $checkout,
		private PaymentMethodRepository $methods,
		private LevelRepository $levels
	) {}

	/**
	 * Dispatches an event. Returns whether it was relevant.
	 *
	 * @param string       $gateway Gateway id.
	 * @param WebhookEvent $event   Event.
	 */
	public function dispatch( string $gateway, WebhookEvent $event ): bool {
		/**
		 * Lets extensions handle an event first. Return true to mark it handled.
		 *
		 * @param bool         $handled Handled.
		 * @param string       $gateway Gateway.
		 * @param WebhookEvent $event   Event.
		 */
		if ( apply_filters( 'memberkin_webhook_pre_dispatch', false, $gateway, $event ) ) {
			return true;
		}
		return match ( $event->type ) {
			'payment.succeeded'       => $this->payment_succeeded( $gateway, $event ),
			'payment.failed'          => $this->payment_failed( $gateway, $event ),
			'payment.action_required' => $this->action_required( $gateway, $event ),
			'refund.succeeded'        => $this->refund( $gateway, $event ),
			'dispute.opened'          => $this->dispute( $gateway, $event, true ),
			'dispute.closed'          => $this->dispute( $gateway, $event, false ),
			'subscription.cancelled'  => $this->subscription_cancelled( $gateway, $event ),
			'subscription.expired'    => $this->subscription_expired( $gateway, $event ),
			'subscription.paused'     => $this->subscription_paused( $gateway, $event, true ),
			'subscription.resumed'    => $this->subscription_paused( $gateway, $event, false ),
			'subscription.past_due'   => $this->subscription_past_due( $gateway, $event ),
			'subscription.updated', 'subscription.activated', 'subscription.renewed' => $this->subscription_updated( $gateway, $event ),
			'payment_method.updated'  => $this->payment_method_updated( $gateway, $event ),
			default                   => false,
		};
	}

	/**
	 * Finds the local order an event refers to.
	 *
	 * @param string       $gateway Gateway.
	 * @param WebhookEvent $event   Event.
	 */
	private function find_order( string $gateway, WebhookEvent $event ): ?Order {
		if ( $event->order_id ) {
			$order = $this->order_repo->find( $event->order_id );
			if ( $order && $order->gateway === $gateway ) {
				return $order;
			}
		}
		if ( $event->transaction_id ) {
			return $this->order_repo->find_by_gateway_transaction( $gateway, $event->transaction_id );
		}
		return null;
	}

	/**
	 * Finds the local subscription.
	 *
	 * @param string       $gateway Gateway.
	 * @param WebhookEvent $event   Event.
	 */
	private function find_subscription( string $gateway, WebhookEvent $event ): ?Subscription {
		// Site-billed subscriptions have no gateway id; gateways pass our own id from their metadata instead.
		if ( ! empty( $event->data['local_subscription_id'] ) ) {
			$sub = $this->subscription_repo->find( (int) $event->data['local_subscription_id'] );
			return $sub && $sub->gateway === $gateway ? $sub : null;
		}
		return $event->subscription_id ? $this->subscription_repo->find_by_gateway_id( $gateway, $event->subscription_id ) : null;
	}

	/**
	 * Successful payment: completes a pending checkout, or records a gateway-managed renewal.
	 *
	 * @param string       $gateway Gateway.
	 * @param WebhookEvent $event   Event.
	 */
	private function payment_succeeded( string $gateway, WebhookEvent $event ): bool {
		$order = $this->find_order( $gateway, $event );
		if ( $order && $order->is_paid() && $event->transaction_id && ! $order->gateway_transaction_id ) {
			// Completed earlier without a payment id (e.g. a subscription activation); refunds need it.
			$this->order_repo->update( $order->id, array( 'gateway_transaction_id' => $event->transaction_id ) );
		}
		if ( $order && in_array( $order->type, array( 'initial', 'upgrade', 'gift' ), true ) ) {
			$this->checkout->fulfil(
				$order->id,
				new PaymentResult( PaymentResult::COMPLETED, '', $event->transaction_id, $event->data['customer_id'] ?? null, $event->subscription_id, null, null, null, isset( $event->data['period_end'] ) ? ( new \DateTimeImmutable( '@' . (int) $event->data['period_end'] ) ) : null, $event->data['payment_method_label'] ?? null )
			);
			return true;
		}
		if ( $order && $order->is_paid() ) {
			return true; // Already recorded (e.g. our own renewal charge).
		}
		if ( $order && 'renewal' === $order->type ) {
			$paid = $this->orders->mark_paid( $order->id, array( 'gateway_transaction_id' => $event->transaction_id ) );
			if ( $paid->subscription_id ) {
				$this->subscriptions->record_renewal( $paid->subscription_id, $paid, $this->period_end( $event ) );
			}
			return true;
		}

		// Gateway-managed renewal without a local order yet.
		$sub = $this->find_subscription( $gateway, $event );
		if ( ! $sub ) {
			return false;
		}
		if ( ! empty( $event->data['is_first_payment'] ) ) {
			return true; // The initial order is completed via its own order_id.
		}
		$level    = $this->levels->find( $sub->level_id );
		$currency = $event->currency ? strtoupper( $event->currency ) : $sub->currency;
		$gross    = Money::of( (int) ( $event->amount ?? ( $sub->recurring_amount + $sub->tax_amount ) ), $currency );
		$tax      = (int) ( $event->data['tax_amount'] ?? 0 );
		$order    = $this->orders->create_renewal(
			$sub->user_id,
			$sub->id,
			$sub->level_id,
			/* translators: %s: level name. */
			sprintf( __( '%s — renewal', 'memberkin' ), $level ? $level->name : '#' . $sub->level_id ),
			Money::of( $gross->amount - $tax, $currency ),
			$tax,
			(string) ( $event->data['tax_rate'] ?? '0' ),
			$gateway,
			$sub->gateway_mode,
			array( 'gateway_transaction_id' => $event->transaction_id )
		);
		$paid = $this->orders->mark_paid(
			$order->id,
			array(
				'gateway_transaction_id' => $event->transaction_id,
				'payment_method_label'   => $event->data['payment_method_label'] ?? null,
			)
		);
		$this->subscriptions->record_renewal( $sub->id, $paid, $this->period_end( $event ) );
		return true;
	}

	/**
	 * Period end from event data.
	 *
	 * @param WebhookEvent $event Event.
	 */
	private function period_end( WebhookEvent $event ): ?\DateTimeImmutable {
		return isset( $event->data['period_end'] ) ? new \DateTimeImmutable( '@' . (int) $event->data['period_end'] ) : null;
	}

	/**
	 * Failed payment.
	 *
	 * @param string       $gateway Gateway.
	 * @param WebhookEvent $event   Event.
	 */
	private function payment_failed( string $gateway, WebhookEvent $event ): bool {
		$order   = $this->find_order( $gateway, $event );
		$message = '' !== $event->message ? $event->message : __( 'The payment failed.', 'memberkin' );
		if ( $order && Order::PENDING === $order->status && in_array( $order->type, array( 'initial', 'upgrade', 'gift' ), true ) ) {
			$this->checkout->abandon( $order->id, $message );
			return true;
		}
		if ( $order && Order::FAILED === $order->status ) {
			return true; // Already recorded synchronously (e.g. our own renewal charge was declined).
		}
		$sub = $order && $order->subscription_id ? $this->subscription_repo->find( $order->subscription_id ) : $this->find_subscription( $gateway, $event );
		if ( ! $sub || ! $sub->is_live() ) {
			return (bool) $order;
		}
		if ( $order && Order::PENDING === $order->status ) {
			$order = $this->orders->mark_failed( $order->id, $message );
		}
		$this->subscriptions->record_failure( $sub->id, $message, $order );
		return true;
	}

	/**
	 * Renewal needs member authentication.
	 *
	 * @param string       $gateway Gateway.
	 * @param WebhookEvent $event   Event.
	 */
	private function action_required( string $gateway, WebhookEvent $event ): bool {
		$sub = $this->find_subscription( $gateway, $event );
		if ( ! $sub ) {
			return false;
		}
		Events::record( 'subscription.action_required', $sub->user_id, 'subscription', $sub->id, array( 'url' => $event->data['hosted_url'] ?? '' ) );
		/** This action is documented in RenewalService. */
		do_action( 'memberkin_renewal_action_required', $sub, $this->find_order( $gateway, $event ) );
		return true;
	}

	/**
	 * Refund made at the gateway (from our admin or the gateway dashboard).
	 *
	 * @param string       $gateway Gateway.
	 * @param WebhookEvent $event   Event.
	 */
	private function refund( string $gateway, WebhookEvent $event ): bool {
		$order = $this->find_order( $gateway, $event );
		if ( ! $order || ! $order->is_paid() || null === $event->amount ) {
			return (bool) $order;
		}
		$amount = min( $event->amount, $order->refundable()->amount );
		if ( $amount <= 0 ) {
			return true;
		}
		$this->orders->record_refund( $order->id, Money::of( $amount, $order->currency ), '' !== $event->message ? $event->message : __( 'Refunded at the payment gateway', 'memberkin' ), $event->refund_id );
		return true;
	}

	/**
	 * Dispute opened/closed.
	 *
	 * @param string       $gateway Gateway.
	 * @param WebhookEvent $event   Event.
	 * @param bool         $opened  Opened.
	 */
	private function dispute( string $gateway, WebhookEvent $event, bool $opened ): bool {
		$order = $this->find_order( $gateway, $event );
		if ( ! $order ) {
			return false;
		}
		if ( $opened || 'won' === ( $event->data['outcome'] ?? '' ) ) {
			$this->orders->set_disputed( $order->id, $opened );
		}
		/**
		 * Fires when a dispute opened or closed.
		 *
		 * @param Order        $order  Order.
		 * @param bool         $opened Opened.
		 * @param WebhookEvent $event  Event.
		 */
		do_action( 'memberkin_order_dispute', $this->order_repo->find( $order->id ), $opened, $event );
		return true;
	}

	/**
	 * Cancelled at the gateway.
	 *
	 * @param string       $gateway Gateway.
	 * @param WebhookEvent $event   Event.
	 */
	private function subscription_cancelled( string $gateway, WebhookEvent $event ): bool {
		$sub = $this->find_subscription( $gateway, $event );
		if ( ! $sub ) {
			return false;
		}
		if ( $sub->is_live() || Subscription::PENDING === $sub->status ) {
			$at_end = ! empty( $event->data['at_period_end'] );
			if ( ! ( $at_end && $sub->cancel_at_period_end ) ) {
				$this->subscriptions->cancel( $sub->id, $at_end, 'gateway', false );
			}
		}
		return true;
	}

	/**
	 * Expired at the gateway.
	 *
	 * @param string       $gateway Gateway.
	 * @param WebhookEvent $event   Event.
	 */
	private function subscription_expired( string $gateway, WebhookEvent $event ): bool {
		$sub = $this->find_subscription( $gateway, $event );
		if ( ! $sub ) {
			return false;
		}
		if ( $sub->is_live() ) {
			$this->subscriptions->cancel( $sub->id, false, 'expired', false );
		}
		return true;
	}

	/**
	 * Paused / resumed at the gateway.
	 *
	 * @param string       $gateway Gateway.
	 * @param WebhookEvent $event   Event.
	 * @param bool         $paused  Paused.
	 */
	private function subscription_paused( string $gateway, WebhookEvent $event, bool $paused ): bool {
		$sub = $this->find_subscription( $gateway, $event );
		if ( ! $sub ) {
			return false;
		}
		if ( $paused && Subscription::PAUSED !== $sub->status && Subscription::can_transition( $sub->status, Subscription::PAUSED ) ) {
			$resume = isset( $event->data['resume_at'] ) ? new \DateTimeImmutable( '@' . (int) $event->data['resume_at'] ) : null;
			$this->subscriptions->pause( $sub->id, $resume, false );
		} elseif ( ! $paused && Subscription::PAUSED === $sub->status ) {
			$this->subscriptions->resume( $sub->id, false );
		}
		return true;
	}

	/**
	 * Gateway reports past due.
	 *
	 * @param string       $gateway Gateway.
	 * @param WebhookEvent $event   Event.
	 */
	private function subscription_past_due( string $gateway, WebhookEvent $event ): bool {
		$sub = $this->find_subscription( $gateway, $event );
		if ( ! $sub ) {
			return false;
		}
		if ( in_array( $sub->status, array( Subscription::ACTIVE, Subscription::TRIALING ), true ) ) {
			$this->subscriptions->record_failure( $sub->id, '' !== $event->message ? $event->message : __( 'Payment overdue.', 'memberkin' ) );
		}
		return true;
	}

	/**
	 * Period/amount updates from the gateway.
	 *
	 * @param string       $gateway Gateway.
	 * @param WebhookEvent $event   Event.
	 */
	private function subscription_updated( string $gateway, WebhookEvent $event ): bool {
		$sub = $this->find_subscription( $gateway, $event );
		if ( ! $sub ) {
			return false;
		}
		$fields = array();
		if ( isset( $event->data['period_start'] ) ) {
			$fields['current_period_start'] = new \DateTimeImmutable( '@' . (int) $event->data['period_start'] );
		}
		if ( isset( $event->data['period_end'] ) ) {
			$fields['current_period_end'] = new \DateTimeImmutable( '@' . (int) $event->data['period_end'] );
			$fields['next_payment_at']    = $fields['current_period_end'];
		}
		if ( array_key_exists( 'cancel_at_period_end', $event->data ) ) {
			$fields['cancel_at_period_end'] = (bool) $event->data['cancel_at_period_end'];
		}
		if ( $fields ) {
			$this->subscriptions->update( $sub->id, $fields );
		}
		return true;
	}

	/**
	 * Payment method changed at the gateway.
	 *
	 * @param string       $gateway Gateway.
	 * @param WebhookEvent $event   Event.
	 */
	private function payment_method_updated( string $gateway, WebhookEvent $event ): bool {
		$sub = $this->find_subscription( $gateway, $event );
		$pm  = (array) ( $event->data['payment_method'] ?? array() );
		if ( ! $sub || empty( $pm['token'] ) ) {
			return false;
		}
		$id = $this->methods->upsert(
			new PaymentMethod( 0, $sub->user_id, $gateway, $sub->gateway_mode, (string) $pm['token'], (string) ( $pm['type'] ?? 'card' ), $pm['brand'] ?? null, $pm['last4'] ?? null, isset( $pm['exp_month'] ) ? (int) $pm['exp_month'] : null, isset( $pm['exp_year'] ) ? (int) $pm['exp_year'] : null, true )
		);
		$this->subscriptions->update( $sub->id, array( 'payment_method_id' => $id ) );
		Events::record( 'subscription.payment_method_updated', $sub->user_id, 'subscription', $sub->id, array( 'at' => Clock::now()->format( DATE_ATOM ) ) );
		return true;
	}
}
