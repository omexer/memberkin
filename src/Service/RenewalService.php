<?php
/**
 * Site-managed renewals and retries.
 *
 * @package Memberkin
 */

namespace Memberkin\Service;

use Memberkin\Core\Clock;
use Memberkin\Core\Events;
use Memberkin\Core\Lock;
use Memberkin\Core\LockException;
use Memberkin\Core\Logger;
use Memberkin\Core\Money;
use Memberkin\Core\SafeMode;
use Memberkin\Domain\Membership;
use Memberkin\Domain\Order;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\Contracts\Capability;
use Memberkin\Gateway\Contracts\ChargeRequest;
use Memberkin\Gateway\Contracts\PaymentResult;
use Memberkin\Gateway\GatewayRegistry;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\MembershipRepository;
use Memberkin\Repository\OrderRepository;
use Memberkin\Repository\PaymentMethodRepository;
use Memberkin\Repository\SubscriptionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Charges saved payment methods when site-managed subscriptions are due (ARCHITECTURE.md §8).
 */
class RenewalService {

	/**
	 * Constructor.
	 *
	 * @param SubscriptionRepository  $subscriptions   Subscriptions.
	 * @param SubscriptionService     $subscription_service Subscription service.
	 * @param OrderService            $orders          Order service.
	 * @param OrderRepository         $order_repo      Order repository.
	 * @param PaymentMethodRepository $methods         Payment methods.
	 * @param GatewayRegistry         $gateways        Gateways.
	 * @param LevelRepository         $levels          Levels.
	 * @param MembershipRepository    $membership_repo Memberships.
	 */
	public function __construct(
		private SubscriptionRepository $subscriptions,
		private SubscriptionService $subscription_service,
		private OrderService $orders,
		private OrderRepository $order_repo,
		private PaymentMethodRepository $methods,
		private GatewayRegistry $gateways,
		private LevelRepository $levels,
		private MembershipRepository $membership_repo
	) {}

	/**
	 * Job handler for "memberkin_renewal_charge". Returns the outcome for logging/tests.
	 *
	 * @param int $subscription_id Subscription.
	 * @return string charged|failed|pending|awaiting_manual|skipped
	 */
	public function charge( int $subscription_id ): string {
		if ( SafeMode::active() ) {
			Logger::info( 'renewals', 'Safe mode: renewal skipped.', array( 'subscription' => $subscription_id ) );
			return 'skipped';
		}
		try {
			return Lock::with( 'subscription_' . $subscription_id, fn() => $this->charge_locked( $subscription_id ), 0 );
		} catch ( LockException $e ) {
			return 'skipped'; // Another worker is on it.
		}
	}

	/**
	 * Charge logic (lock held).
	 *
	 * @param int $subscription_id Subscription.
	 */
	private function charge_locked( int $subscription_id ): string {
		$sub = $this->subscriptions->find( $subscription_id );
		$now = Clock::now();
		if ( ! $sub || 'site' !== $sub->billing_model || $sub->cancel_at_period_end ) {
			return 'skipped';
		}
		$due = ( in_array( $sub->status, array( Subscription::ACTIVE, Subscription::TRIALING ), true ) && $sub->next_payment_at && $sub->next_payment_at <= $now )
			|| ( Subscription::PAST_DUE === $sub->status && $sub->next_retry_at && $sub->next_retry_at <= $now );
		if ( ! $due ) {
			return 'skipped';
		}
		if ( $sub->is_final_cycle_reached() ) {
			$this->subscription_service->complete( $sub->id );
			return 'skipped';
		}

		$gateway = $this->gateways->get( $sub->gateway );
		if ( ! $gateway ) {
			$this->subscription_service->record_failure( $sub->id, __( 'The payment gateway is not available.', 'memberkin' ) );
			return 'failed';
		}

		$level       = $this->levels->find( $sub->level_id );
		$description = sprintf(
			/* translators: %s: level name. */
			__( '%s — renewal', 'memberkin' ),
			$level ? $level->name : '#' . $sub->level_id
		);
		$net      = Money::of( $sub->recurring_amount, $sub->currency );
		$tax_rate = (string) get_metadata( 'memberkin_subscription', $sub->id, 'tax_rate', true );
		$tax_rate = '' === $tax_rate ? '0' : $tax_rate;
		$tax      = $this->tax_for( $net, $tax_rate, (bool) get_metadata( 'memberkin_subscription', $sub->id, 'reverse_charge', true ) );
		$last     = $this->last_order( $sub );

		// Manual-payment gateways (offline): issue an invoice and wait.
		if ( $gateway->supports( Capability::MANUAL_CONFIRMATION ) ) {
			if ( $last && Order::PENDING === $last->status && 'renewal' === $last->type ) {
				// A retry slot passed and the invoice is still unpaid: continue dunning (may end the subscription).
				$this->subscription_service->record_failure( $sub->id, __( 'Invoice still unpaid.', 'memberkin' ), $last );
				return 'awaiting_manual';
			}
			$order = $this->orders->create_renewal( $sub->user_id, $sub->id, $sub->level_id, $description, $net, $tax, $tax_rate, $gateway->id(), $gateway->mode(), $this->order_context( $last ) );
			$this->subscription_service->record_failure( $sub->id, __( 'Awaiting manual payment.', 'memberkin' ), $order );
			/**
			 * Fires when a manual-payment renewal invoice was issued.
			 *
			 * @param Subscription $sub   Subscription.
			 * @param Order        $order Order.
			 */
			do_action( 'memberkin_manual_renewal_invoiced', $sub, $order );
			return 'awaiting_manual';
		}

		$method = $sub->payment_method_id ? $this->methods->find( $sub->payment_method_id ) : null;
		$order  = $this->orders->create_renewal( $sub->user_id, $sub->id, $sub->level_id, $description, $net, $tax, $tax_rate, $gateway->id(), $gateway->mode(), $this->order_context( $last ) );
		if ( ! $method ) {
			$this->orders->mark_failed( $order->id, 'No saved payment method.' );
			$this->subscription_service->record_failure( $sub->id, __( 'No saved payment method.', 'memberkin' ), $this->order_repo->find( $order->id ) );
			return 'failed';
		}

		$key     = 'memberkin-renewal-' . $sub->id . '-' . $sub->cycles_completed . '-' . $sub->retry_count;
		$request = new ChargeRequest( $sub, $order, $method, $order->total(), $description, $key );
		try {
			$result = $gateway->charge_saved_method( $request );
		} catch ( \Throwable $e ) {
			Logger::error( 'renewals', 'Gateway error: ' . $e->getMessage(), array( 'subscription' => $sub->id ) );
			$result = PaymentResult::failed( __( 'The payment could not be processed.', 'memberkin' ) );
		}

		switch ( $result->status ) {
			case PaymentResult::COMPLETED:
				$paid = $this->orders->mark_paid(
					$order->id,
					array(
						'gateway_transaction_id' => $result->transaction_id,
						'payment_method_label'   => $result->payment_method_label ?? $method->label(),
					)
				);
				$this->subscription_service->record_renewal( $sub->id, $paid );
				return 'charged';
			case PaymentResult::PENDING:
				if ( $result->transaction_id ) {
					$this->order_repo->update( $order->id, array( 'gateway_transaction_id' => $result->transaction_id ) );
				}
				// Bank debits settle later; the webhook completes it. Do not charge again for this period.
				$this->subscriptions->update( $sub->id, array( 'next_payment_at' => $now->modify( '+5 days' ) ) );
				return 'pending';
			default:
				$message = '' !== $result->message ? $result->message : __( 'The payment was declined.', 'memberkin' );
				$failed  = $this->orders->mark_failed( $order->id, $message );
				$this->subscription_service->record_failure( $sub->id, $message, $failed );
				if ( PaymentResult::REQUIRES_ACTION === $result->status ) {
					Events::record( 'subscription.action_required', $sub->user_id, 'subscription', $sub->id );
					/**
					 * Fires when a renewal needs the member to authenticate (3-D Secure).
					 *
					 * @param Subscription $sub   Subscription.
					 * @param Order        $order Failed order.
					 */
					do_action( 'memberkin_renewal_action_required', $sub, $failed );
				}
				return 'failed';
		}
	}

	/**
	 * Tax for a renewal using the rate captured at checkout.
	 *
	 * @param Money  $net            Net.
	 * @param string $rate           Percent.
	 * @param bool   $reverse_charge Reverse charge.
	 */
	private function tax_for( Money $net, string $rate, bool $reverse_charge ): int {
		if ( $reverse_charge || '0' === $rate || '' === $rate ) {
			return 0;
		}
		return $net->percentage( $rate )->amount;
	}

	/**
	 * Most recent order of the subscription.
	 *
	 * @param Subscription $sub Subscription.
	 */
	private function last_order( Subscription $sub ): ?Order {
		$orders = $this->order_repo->for_subscription( $sub->id );
		return $orders[0] ?? null;
	}

	/**
	 * Billing and tax snapshot copied from the previous order.
	 *
	 * @param Order|null $last Previous order.
	 * @return array{billing: array, tax: array}
	 */
	private function order_context( ?Order $last ): array {
		return array(
			'billing' => $last ? $last->billing : array(),
			'tax'     => $last ? $last->tax : array(),
		);
	}

	/**
	 * Admin marked a manual renewal invoice as paid: record the renewal.
	 *
	 * @param Order $order Paid order.
	 */
	public function on_order_paid( Order $order ): void {
		if ( 'renewal' !== $order->type || ! $order->subscription_id ) {
			return;
		}
		$sub = $this->subscriptions->find( $order->subscription_id );
		if ( $sub && in_array( $sub->status, array( Subscription::PAST_DUE, Subscription::UNPAID ), true ) ) {
			$gateway = $this->gateways->get( $sub->gateway );
			if ( $gateway && $gateway->supports( Capability::MANUAL_CONFIRMATION ) ) {
				$this->subscription_service->record_renewal( $sub->id, $order );
				$m = $sub->membership_id ? $this->membership_repo->find( $sub->membership_id ) : null;
				if ( $m && Membership::SUSPENDED === $m->status ) {
					memberkin()->get( MembershipService::class )->unsuspend( $m->id );
				}
			}
		}
	}
}
