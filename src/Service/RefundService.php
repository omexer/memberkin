<?php
/**
 * Refunds initiated from the admin or API.
 *
 * @package Memberkin
 */

namespace Memberkin\Service;

use Memberkin\Core\Events;
use Memberkin\Core\Logger;
use Memberkin\Core\Money;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Order;
use Memberkin\Gateway\Contracts\Capability;
use Memberkin\Gateway\Contracts\UnsupportedOperation;
use Memberkin\Gateway\GatewayRegistry;
use Memberkin\Repository\MembershipRepository;
use Memberkin\Repository\OrderRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Refunds at the gateway, records the refund, and optionally ends access and the subscription.
 */
class RefundService {

	/**
	 * Whether the last refund() call only requested the refund (the gateway approves it later).
	 *
	 * @var bool
	 */
	public bool $last_pending = false;

	/**
	 * Constructor.
	 *
	 * @param OrderRepository      $orders          Orders.
	 * @param OrderService         $order_service   Order service.
	 * @param GatewayRegistry      $gateways        Gateways.
	 * @param MembershipRepository $memberships     Memberships.
	 * @param MembershipService    $membership_service Membership service.
	 * @param SubscriptionService  $subscriptions   Subscriptions.
	 */
	public function __construct(
		private OrderRepository $orders,
		private OrderService $order_service,
		private GatewayRegistry $gateways,
		private MembershipRepository $memberships,
		private MembershipService $membership_service,
		private SubscriptionService $subscriptions
	) {}

	/**
	 * Refunds (part of) an order.
	 *
	 * @param int    $order_id      Order.
	 * @param Money  $amount        Amount.
	 * @param string $reason        Reason.
	 * @param bool   $revoke_access End the memberships bought with this order and cancel its subscription.
	 * @param int    $admin_id      Acting admin.
	 * @throws ValidationException When the refund is not possible.
	 */
	public function refund( int $order_id, Money $amount, string $reason = '', bool $revoke_access = false, int $admin_id = 0 ): Order {
		$this->last_pending = false;
		$order              = $this->orders->find( $order_id );
		if ( ! $order || ! $order->is_paid() ) {
			throw new ValidationException( array( esc_html__( 'Only paid orders can be refunded.', 'memberkin' ) ) );
		}
		if ( $amount->currency !== $order->currency || $amount->amount <= 0 || $amount->amount > $order->refundable()->amount ) {
			throw new ValidationException( array( esc_html__( 'Enter an amount up to the refundable balance.', 'memberkin' ) ) );
		}
		$partial = $amount->amount < $order->refundable()->amount;
		$gateway = $this->gateways->get( $order->gateway );
		$remote  = null;

		if ( $order->total > 0 && 'free' !== $order->gateway ) {
			if ( ! $gateway ) {
				throw new ValidationException( array( esc_html__( 'The payment gateway of this order is not available.', 'memberkin' ) ) );
			}
			if ( ! $gateway->supports( $partial ? Capability::REFUND_PARTIAL : Capability::REFUND_FULL ) ) {
				throw new ValidationException( array( $partial ? esc_html__( 'This gateway does not support partial refunds.', 'memberkin' ) : esc_html__( 'This gateway does not support refunds.', 'memberkin' ) ) );
			}
			try {
				$result = $gateway->refund( $order, $amount, $reason );
			} catch ( UnsupportedOperation $e ) {
				throw new ValidationException( array( esc_html( $e->getMessage() ) ) );
			}
			if ( ! $result->ok ) {
				Logger::warning( $order->gateway, 'Refund failed: ' . $result->message, array( 'order' => $order->id ) );
				throw new ValidationException( array( '' !== esc_html( $result->message ) ? esc_html( $result->message ) : esc_html__( 'The gateway refused the refund.', 'memberkin' ) ) );
			}
			$remote = $result->remote_id;
			if ( ! empty( $result->data['pending'] ) ) {
				// The gateway reviews refunds first: the approval webhook records it with this id.
				$this->order_service->add_note(
					$order_id,
					/* translators: 1: amount, 2: gateway refund id. */
					sprintf( __( 'Refund of %1$s requested (%2$s); waiting for the gateway to approve it.', 'memberkin' ), $amount->format(), (string) $remote )
				);
				Events::record(
					'order.refund_requested',
					$order->user_id,
					'order',
					$order->id,
					array(
						'amount'    => $amount->amount,
						'remote_id' => $remote,
					)
				);
				$this->last_pending = true;
				$order              = $this->orders->find( $order_id );
				if ( $revoke_access ) {
					$this->revoke( $order );
				}
				return $order;
			}
		}

		$order = $this->order_service->record_refund( $order_id, $amount, $reason, $remote, $admin_id );
		if ( $revoke_access ) {
			$this->revoke( $order );
		}
		return $order;
	}

	/**
	 * Ends access bought with an order and cancels its subscription immediately.
	 *
	 * @param Order $order Order.
	 */
	public function revoke( Order $order ): void {
		if ( $order->subscription_id ) {
			$sub = memberkin()->get( \Memberkin\Repository\SubscriptionRepository::class )->find( $order->subscription_id );
			if ( $sub && $sub->is_live() ) {
				$this->subscriptions->cancel( $sub->id, false, 'refund' );
			}
		}
		$query = $this->memberships->query(
			array(
				'user_id'  => $order->user_id,
				'statuses' => array( 'active', 'cancelling', 'past_due', 'paused', 'suspended', 'pending', 'pending_approval' ),
				'per_page' => 100,
			)
		);
		foreach ( $query['items'] as $m ) {
			if ( $m->initial_order_id === $order->id || ( $order->subscription_id && $m->subscription_id === $order->subscription_id ) ) {
				$this->membership_service->end( $m->id, 'refunded', array( 'order_id' => $order->id ) );
			}
		}
	}
}
