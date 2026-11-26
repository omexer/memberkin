<?php
/**
 * Sends emails on membership, billing and checkout events.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Emails;

use Memberkin\Core\Events;
use Memberkin\Core\Money;
use Memberkin\Domain\Membership;
use Memberkin\Domain\Order;
use Memberkin\Domain\Subscription;
use Memberkin\Gateway\Contracts\Capability;
use Memberkin\Gateway\GatewayRegistry;
use Memberkin\Repository\MembershipRepository;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\Checkout\CheckoutService;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to domain hooks. Each email is sent at most once per object (recorded in memberkin_events),
 * so webhook replays and retried jobs never duplicate mail. Context "notify" => false suppresses emails.
 */
final class Notifications {

	/**
	 * Constructor.
	 *
	 * @param EmailService $emails Email service.
	 */
	public function __construct( private EmailService $emails ) {}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'memberkin_checkout_completed', array( $this, 'checkout_completed' ), 20, 2 );
		add_action( 'memberkin_checkout_pending', array( $this, 'checkout_pending' ), 20 );
		add_action( 'memberkin_membership_status_changed', array( $this, 'membership_status_changed' ), 20, 4 );
		add_action( 'memberkin_subscription_renewed', array( $this, 'renewed' ), 20, 2 );
		add_action( 'memberkin_subscription_payment_failed', array( $this, 'payment_failed' ), 20, 4 );
		add_action( 'memberkin_renewal_action_required', array( $this, 'action_required' ), 20, 2 );
		add_action( 'memberkin_manual_renewal_invoiced', array( $this, 'manual_invoice' ), 20, 2 );
		add_action( 'memberkin_subscription_cancel_scheduled', array( $this, 'cancel_scheduled' ), 20, 2 );
		add_action( 'memberkin_subscription_status_changed', array( $this, 'subscription_status_changed' ), 20, 4 );
		add_action( 'memberkin_order_refunded', array( $this, 'refunded' ), 20, 2 );
	}

	/**
	 * Sends once per (template, object).
	 *
	 * @param string $template    Template id.
	 * @param string $object_type Object type for de-duplication.
	 * @param int    $object_id   Object id.
	 * @param array  $ctx         Context.
	 */
	private function once( string $template, string $object_type, int $object_id, array $ctx ): void {
		$event = 'email.' . $template;
		if ( $object_id && Events::exists( $event, $object_type, $object_id ) ) {
			return;
		}
		$ids = $this->emails->send( $template, $ctx );
		if ( $ids && $object_id ) {
			$user = $ctx['user'] ?? null;
			Events::record( $event, $user instanceof \WP_User ? $user->ID : 0, $object_type, $object_id, array( 'log_ids' => $ids ), 'system', 0 );
		}
	}

	/**
	 * Context for an order.
	 *
	 * @param Order $order Order.
	 */
	private function order_context( Order $order ): array {
		$map        = (array) get_metadata( 'memberkin_order', $order->id, CheckoutService::MAP_META, true );
		$first      = $map ? reset( $map ) : array();
		$membership = ! empty( $first['membership_id'] ) ? memberkin()->get( MembershipRepository::class )->find( (int) $first['membership_id'] ) : null;
		$sub_id     = $order->subscription_id ?: ( $first['subscription_id'] ?? null ); // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
		return array(
			'user'         => get_userdata( $order->user_id ) ?: null, // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
			'order'        => $order,
			'membership'   => $membership,
			'subscription' => $sub_id ? memberkin()->get( SubscriptionRepository::class )->find( (int) $sub_id ) : null,
		);
	}

	/**
	 * Context for a subscription.
	 *
	 * @param Subscription $sub   Subscription.
	 * @param Order|null   $order Order.
	 */
	private function subscription_context( Subscription $sub, ?Order $order = null ): array {
		return array(
			'user'         => get_userdata( $sub->user_id ) ?: null, // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
			'subscription' => $sub,
			'membership'   => $sub->membership_id ? memberkin()->get( MembershipRepository::class )->find( $sub->membership_id ) : null,
			'order'        => $order,
		);
	}

	/**
	 * Checkout completed.
	 *
	 * @param Order  $order Order.
	 * @param string $mode  full|access_only.
	 */
	public function checkout_completed( Order $order, string $mode ): void {
		if ( 'access_only' === $mode ) {
			return; // The "awaiting payment" email was sent when the order was placed.
		}
		$ctx = $this->order_context( $order );
		if ( 'upgrade' === $order->type ) {
			$template = 'plan_changed';
		} elseif ( 0 === $order->total && ! $ctx['subscription'] ) {
			$template = 'checkout_free';
		} else {
			$template = 'checkout_paid';
		}
		$this->once( $template, 'order', $order->id, $ctx );
		$this->once( 'admin_checkout', 'order', $order->id, $ctx );
	}

	/**
	 * Order placed with an pending payment.
	 *
	 * @param Order $order Order.
	 */
	public function checkout_pending( Order $order ): void {
		$gateway = memberkin()->get( GatewayRegistry::class )->get( $order->gateway );
		if ( $gateway && $gateway->supports( Capability::MANUAL_CONFIRMATION ) ) {
			$this->once( 'checkout_pending', 'order', $order->id, $this->order_context( $order ) );
		}
	}

	/**
	 * Membership changes: admin grants, expiry, cancellation, suspension.
	 *
	 * @param Membership $m       Membership.
	 * @param string     $from    From.
	 * @param string     $to      To.
	 * @param array      $context Context.
	 */
	public function membership_status_changed( Membership $m, string $from, string $to, array $context ): void {
		if ( array_key_exists( 'notify', $context ) && ! $context['notify'] ) {
			return;
		}
		$ctx = array(
			'user'       => get_userdata( $m->user_id ) ?: null, // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
			'membership' => $m,
		);
		if ( '' === $from && Membership::ACTIVE === $to && in_array( $m->source, array( 'admin', 'import', 'api' ), true ) && ! empty( $context['notify'] ) ) {
			$this->once( 'membership_granted', 'membership', $m->id, $ctx );
			return;
		}
		if ( Membership::SUSPENDED === $to && 'payment' !== ( $context['note'] ?? '' ) ) {
			$this->emails->send( 'membership_suspended', $ctx );
			return;
		}
		if ( Membership::ENDED !== $to || ! empty( $context['user_deleted'] ) ) {
			return;
		}
		$reason = (string) ( $context['reason'] ?? $m->end_reason );
		if ( 'expired' === $reason ) {
			$this->once( 'membership_expired', 'membership', $m->id, $ctx );
		} elseif ( 'cancelled' === $reason && empty( $context['automatic'] ) && ! $m->subscription_id ) {
			$this->once( 'membership_cancelled', 'membership', $m->id, $ctx );
		}
	}

	/**
	 * Renewal receipt.
	 *
	 * @param Subscription $sub   Subscription.
	 * @param Order        $order Order.
	 */
	public function renewed( Subscription $sub, Order $order ): void {
		$this->once( 'renewal_receipt', 'order', $order->id, $this->subscription_context( $sub, $order ) );
	}

	/**
	 * Renewal failed.
	 *
	 * @param Subscription $sub   Subscription.
	 * @param Order|null   $order Order.
	 * @param string       $error Error.
	 * @param bool         $is_final No more retries.
	 */
	public function payment_failed( Subscription $sub, ?Order $order, string $error, bool $is_final ): void {
		$gateway = memberkin()->get( GatewayRegistry::class )->get( $sub->gateway );
		$manual  = $gateway && $gateway->supports( Capability::MANUAL_CONFIRMATION );
		$ctx     = $this->subscription_context( $sub, $order ) + array(
			'error'    => $error,
			'retry_at' => $sub->next_retry_at,
		);
		$key     = $order ? $order->id : $sub->id * 1000 + $sub->retry_count;
		if ( $is_final && Subscription::CANCELLED === $sub->status ) {
			$this->once( 'payment_failed_final', 'subscription', $sub->id, $ctx );
			$this->once( 'admin_payment_failed', 'order', $key, $ctx );
			return;
		}
		if ( $manual ) {
			return; // Manual-payment renewals get the invoice email instead.
		}
		$this->once( 'payment_failed', 'order', $key, $ctx );
		$this->once( 'admin_payment_failed', 'order', $key, $ctx );
	}

	/**
	 * Renewal needs 3-D Secure.
	 *
	 * @param Subscription $sub   Subscription.
	 * @param Order|null   $order Order.
	 */
	public function action_required( Subscription $sub, ?Order $order ): void {
		$this->once( 'payment_action_required', 'order', $order ? $order->id : $sub->id * 1000 + $sub->retry_count, $this->subscription_context( $sub, $order ) );
	}

	/**
	 * Manual-payment renewal invoice.
	 *
	 * @param Subscription $sub   Subscription.
	 * @param Order        $order Order.
	 */
	public function manual_invoice( Subscription $sub, Order $order ): void {
		$this->once( 'renewal_invoice_manual', 'order', $order->id, $this->subscription_context( $sub, $order ) );
	}

	/**
	 * Cancellation scheduled.
	 *
	 * @param Subscription $sub    Subscription.
	 * @param string       $reason Reason.
	 */
	public function cancel_scheduled( Subscription $sub, string $reason ): void {
		if ( ! in_array( $reason, array( 'member', 'admin', 'gateway' ), true ) ) {
			return;
		}
		$ctx = $this->subscription_context( $sub );
		$this->once( 'subscription_cancelled', 'subscription', $sub->id, $ctx );
		if ( 'member' === $reason ) {
			$this->once( 'admin_cancelled', 'subscription', $sub->id, $ctx );
		}
	}

	/**
	 * Paused / resumed.
	 *
	 * @param Subscription $sub     Subscription.
	 * @param string       $from    From.
	 * @param string       $to      To.
	 * @param array        $context Context.
	 */
	public function subscription_status_changed( Subscription $sub, string $from, string $to, array $context ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- hook signature.
		if ( Subscription::PAUSED === $to ) {
			$this->emails->send( 'subscription_paused', $this->subscription_context( $sub ) + array( 'resume_at' => $sub->resume_at ) );
		} elseif ( Subscription::PAUSED === $from && Subscription::ACTIVE === $to ) {
			$this->emails->send( 'subscription_resumed', $this->subscription_context( $sub ) );
		}
	}

	/**
	 * Refund.
	 *
	 * @param Order  $order  Order.
	 * @param object $refund Refund row.
	 */
	public function refunded( Order $order, object $refund ): void {
		$ctx = $this->order_context( $order ) + array( 'refund_amount' => Money::of( (int) $refund->amount, $order->currency ) );
		$this->once( 'refund', 'refund', (int) $refund->id, $ctx );
		$this->once( 'admin_refund', 'refund', (int) $refund->id, $ctx );
	}
}
