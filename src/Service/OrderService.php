<?php
/**
 * Order business logic — the only write path for orders.
 *
 * @package Memberkin
 */

namespace Memberkin\Service;

use Memberkin\Core\Clock;
use Memberkin\Core\Events;
use Memberkin\Core\Lock;
use Memberkin\Core\Money;
use Memberkin\Core\Settings;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Order;
use Memberkin\Domain\OrderItem;
use Memberkin\Domain\Pricing\Quote;
use Memberkin\Repository\OrderRepository;
use Memberkin\Repository\SequenceRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Creates orders from quotes and moves them through their statuses.
 *
 * Hooks: memberkin_order_created( Order ), memberkin_order_status_changed( Order, from, to ),
 * memberkin_order_paid( Order ), memberkin_order_failed( Order ), memberkin_order_refunded( Order, object $refund ).
 */
class OrderService {

	/**
	 * Constructor.
	 *
	 * @param OrderRepository    $orders    Orders.
	 * @param SequenceRepository $sequences Sequences.
	 */
	public function __construct(
		private OrderRepository $orders,
		private SequenceRepository $sequences
	) {}

	/**
	 * Builds and stores a pending order from a quote.
	 *
	 * @param Quote  $quote   Quote.
	 * @param int    $user_id Buyer.
	 * @param string $gateway Gateway id.
	 * @param array  $args    type, gateway_mode, billing (array), ip_address, subscription_id, checkout_session_id, notes.
	 */
	public function create_from_quote( Quote $quote, int $user_id, string $gateway, array $args = array() ): Order {
		$order                      = new Order(
			0,
			'',
			$user_id,
			(string) ( $args['type'] ?? ( $quote->proration ? 'upgrade' : 'initial' ) ),
			Order::PENDING,
			$quote->currency,
			$quote->subtotal()->amount,
			$quote->discount_total()->amount,
			$quote->tax_total()->amount,
			$quote->total()->amount,
			0,
			$gateway,
			(string) ( $args['gateway_mode'] ?? 'live' ),
			isset( $args['subscription_id'] ) ? (int) $args['subscription_id'] : null,
			null,
			$quote->coupon?->id,
			$quote->coupon?->code
		);
		$order->billing             = self::clean_billing( (array) ( $args['billing'] ?? array() ) );
		$order->tax                 = $quote->tax->to_order_tax();
		$order->ip_address          = isset( $args['ip_address'] ) ? (string) $args['ip_address'] : null;
		$order->checkout_session_id = isset( $args['checkout_session_id'] ) ? (int) $args['checkout_session_id'] : null;
		$order->notes               = (string) ( $args['notes'] ?? '' );

		foreach ( $quote->items as $item ) {
			$order->items[] = new OrderItem( $item->type, $item->description, $item->amount, 1, $item->level_id, 0, $item->tax_rate, $item->tax_amount, $item->tax_rate_id );
		}
		$this->orders->insert( $order );
		if ( $quote->tax->evidence ) {
			update_metadata( 'memberkin_order', $order->id, 'memberkin_tax_evidence', $quote->tax->evidence );
		}
		Events::record(
			'order.created',
			$user_id,
			'order',
			$order->id,
			array(
				'total'    => $order->total,
				'currency' => $order->currency,
				'gateway'  => $gateway,
			)
		);

		/**
		 * Fires after an order was created.
		 *
		 * @param Order $order Order.
		 */
		do_action( 'memberkin_order_created', $order );
		return $order;
	}

	/**
	 * Creates a renewal order for a subscription payment.
	 *
	 * @param int    $user_id         User.
	 * @param int    $subscription_id Subscription.
	 * @param int    $level_id        Level.
	 * @param string $description     Line description.
	 * @param Money  $net             Net amount.
	 * @param int    $tax_amount      Tax.
	 * @param string $tax_rate        Tax rate.
	 * @param string $gateway         Gateway.
	 * @param string $mode            Gateway mode.
	 * @param array  $args            billing, tax (order tax snapshot), status, gateway_transaction_id.
	 */
	public function create_renewal( int $user_id, int $subscription_id, int $level_id, string $description, Money $net, int $tax_amount, string $tax_rate, string $gateway, string $mode, array $args = array() ): Order {
		$order          = new Order( 0, '', $user_id, 'renewal', Order::PENDING, $net->currency, $net->amount, 0, $tax_amount, $net->amount + $tax_amount, 0, $gateway, $mode, $subscription_id );
		$order->billing = (array) ( $args['billing'] ?? array() );
		$order->tax     = (array) ( $args['tax'] ?? array() );
		$order->items[] = new OrderItem( 'level', $description, $net->amount, 1, $level_id, 0, $tax_rate, $tax_amount );
		if ( ! empty( $args['gateway_transaction_id'] ) ) {
			$order->gateway_transaction_id = (string) $args['gateway_transaction_id'];
		}
		$this->orders->insert( $order );
		/** This action is documented in OrderService::create_from_quote(). */
		do_action( 'memberkin_order_created', $order );
		return $order;
	}

	/**
	 * Marks an order paid. Idempotent: a paid order is returned unchanged.
	 *
	 * @param int   $order_id Order.
	 * @param array $fields   gateway_transaction_id, gateway_customer_id, payment_method_label, paid_at.
	 * @throws ValidationException When the order is missing or cannot become paid.
	 */
	public function mark_paid( int $order_id, array $fields = array() ): Order {
		return Lock::with(
			'order_' . $order_id,
			function () use ( $order_id, $fields ): Order {
				$order = $this->require( $order_id );
				if ( $order->is_paid() || Order::REFUNDED === $order->status ) {
					return $order;
				}
				$from = $order->status;
				if ( ! Order::can_transition( $from, Order::PAID ) ) {
					throw new ValidationException( array( esc_html__( 'This order cannot be marked as paid.', 'memberkin' ) ) );
				}
				$update = array(
					'status'  => Order::PAID,
					'paid_at' => $fields['paid_at'] ?? Clock::now(),
				);
				foreach ( array( 'gateway_transaction_id', 'gateway_customer_id', 'payment_method_label' ) as $key ) {
					if ( ! empty( $fields[ $key ] ) ) {
						$update[ $key ] = (string) $fields[ $key ];
					}
				}
				if ( $order->total > 0 && null === $order->invoice_number ) {
					$update['invoice_number'] = $this->next_number( 'invoice' );
				}
				if ( ! $this->orders->update( $order_id, $update, $from ) ) {
					return $this->require( $order_id );
				}
				$order = $this->require( $order_id );
				Events::record(
					'order.paid',
					$order->user_id,
					'order',
					$order->id,
					array(
						'total'       => $order->total,
						'transaction' => $order->gateway_transaction_id,
					)
				);
				$this->fire( $order, $from, Order::PAID );
				/**
				 * Fires when an order was paid.
				 *
				 * @param Order $order Order.
				 */
				do_action( 'memberkin_order_paid', $order );
				return $order;
			}
		);
	}

	/**
	 * Marks an order failed.
	 *
	 * @param int    $order_id Order.
	 * @param string $error    Error message (stored in notes).
	 */
	public function mark_failed( int $order_id, string $error = '' ): Order {
		$order = $this->require( $order_id );
		if ( Order::FAILED === $order->status || ! Order::can_transition( $order->status, Order::FAILED ) ) {
			return $order;
		}
		$from = $order->status;
		$this->orders->update( $order_id, array( 'status' => Order::FAILED ), $from );
		if ( '' !== $error ) {
			$this->add_note( $order_id, $error );
		}
		$order = $this->require( $order_id );
		Events::record( 'order.failed', $order->user_id, 'order', $order->id, array( 'error' => $error ) );
		$this->fire( $order, $from, Order::FAILED );
		/**
		 * Fires when an order failed.
		 *
		 * @param Order $order Order.
		 */
		do_action( 'memberkin_order_failed', $order );
		return $order;
	}

	/**
	 * Cancels an unpaid order.
	 *
	 * @param int    $order_id Order.
	 * @param string $note     Note.
	 */
	public function cancel( int $order_id, string $note = '' ): Order {
		$order = $this->require( $order_id );
		if ( ! Order::can_transition( $order->status, Order::CANCELLED ) ) {
			return $order;
		}
		$from = $order->status;
		$this->orders->update( $order_id, array( 'status' => Order::CANCELLED ), $from );
		if ( '' !== $note ) {
			$this->add_note( $order_id, $note );
		}
		$order = $this->require( $order_id );
		$this->fire( $order, $from, Order::CANCELLED );
		return $order;
	}

	/**
	 * Puts an order on hold for review (fraud screening).
	 *
	 * @param int    $order_id Order.
	 * @param string $note     Note.
	 */
	public function hold_for_review( int $order_id, string $note = '' ): Order {
		$order = $this->require( $order_id );
		if ( Order::can_transition( $order->status, Order::REVIEW ) ) {
			$this->orders->update( $order_id, array( 'status' => Order::REVIEW ), $order->status );
			$this->fire( $this->require( $order_id ), $order->status, Order::REVIEW );
		}
		if ( '' !== $note ) {
			$this->add_note( $order_id, $note );
		}
		return $this->require( $order_id );
	}

	/**
	 * Marks a dispute opened or closed.
	 *
	 * @param int  $order_id Order.
	 * @param bool $opened   Opened (true) or resolved in the merchant's favour (false).
	 */
	public function set_disputed( int $order_id, bool $opened ): Order {
		$order = $this->require( $order_id );
		$to    = $opened ? Order::DISPUTED : Order::PAID;
		if ( $order->status !== $to && Order::can_transition( $order->status, $to ) ) {
			$this->orders->update( $order_id, array( 'status' => $to ), $order->status );
			Events::record( $opened ? 'order.disputed' : 'order.dispute_won', $order->user_id, 'order', $order->id );
			$this->fire( $this->require( $order_id ), $order->status, $to );
		}
		return $this->require( $order_id );
	}

	/**
	 * Records a refund that happened at the gateway (or offline). Idempotent per gateway refund id.
	 *
	 * @param int         $order_id          Order.
	 * @param Money       $amount            Amount refunded.
	 * @param string      $reason            Reason.
	 * @param string|null $gateway_refund_id Gateway refund id.
	 * @param int         $created_by        Admin user (0 = gateway/system).
	 * @throws ValidationException When the amount exceeds what is refundable.
	 */
	public function record_refund( int $order_id, Money $amount, string $reason = '', ?string $gateway_refund_id = null, int $created_by = 0 ): Order {
		return Lock::with(
			'order_' . $order_id,
			function () use ( $order_id, $amount, $reason, $gateway_refund_id, $created_by ): Order {
				$order = $this->require( $order_id );
				if ( $gateway_refund_id && $this->orders->refund_exists( $gateway_refund_id ) ) {
					return $order;
				}
				if ( ! $order->is_paid() ) {
					throw new ValidationException( array( esc_html__( 'Only paid orders can be refunded.', 'memberkin' ) ) );
				}
				if ( $amount->currency !== $order->currency || $amount->amount <= 0 || $amount->amount > $order->refundable()->amount ) {
					throw new ValidationException( array( esc_html__( 'The refund amount is more than what can be refunded.', 'memberkin' ) ) );
				}
				$refunded  = $order->amount_refunded + $amount->amount;
				$full      = $refunded >= $order->total;
				$from      = $order->status;
				$refund    = array(
					'order_id'           => $order_id,
					'amount'             => $amount->amount,
					'reason'             => mb_substr( $reason, 0, 255 ),
					'gateway_refund_id'  => $gateway_refund_id,
					'status'             => 'succeeded',
					'credit_note_number' => $order->invoice_number ? $this->next_number( 'credit_note' ) : null,
					'created_by'         => $created_by,
				);
				$refund_id = $this->orders->insert_refund( $refund );
				$update    = array(
					'amount_refunded' => $refunded,
					'status'          => $full ? Order::REFUNDED : Order::PARTIALLY_REFUNDED,
				);
				if ( $full ) {
					$update['refunded_at'] = Clock::now();
				}
				$this->orders->update( $order_id, $update );
				$order = $this->require( $order_id );
				Events::record(
					'order.refunded',
					$order->user_id,
					'order',
					$order->id,
					array(
						'amount' => $amount->amount,
						'full'   => $full,
						'reason' => $reason,
					)
				);
				if ( $from !== $order->status ) {
					$this->fire( $order, $from, $order->status );
				}
				$refund_row = (object) array_merge( $refund, array( 'id' => $refund_id ) );
				/**
				 * Fires after a refund was recorded.
				 *
				 * @param Order  $order  Order (updated).
				 * @param object $refund Refund row.
				 */
				do_action( 'memberkin_order_refunded', $order, $refund_row );
				return $order;
			}
		);
	}

	/**
	 * Appends a timestamped admin note.
	 *
	 * @param int    $order_id Order.
	 * @param string $note     Note.
	 */
	public function add_note( int $order_id, string $note ): void {
		$order = $this->require( $order_id );
		$line  = '[' . wp_date( 'Y-m-d H:i', Clock::timestamp() ) . '] ' . sanitize_textarea_field( $note );
		$this->orders->update( $order_id, array( 'notes' => trim( $order->notes . "\n" . $line ) ) );
	}

	/**
	 * Next formatted invoice or credit note number.
	 *
	 * @param string $sequence invoice|credit_note.
	 */
	public function next_number( string $sequence ): string {
		$settings = Settings::section( 'invoices' );
		$value    = $this->sequences->next( $sequence, max( 1, (int) ( $settings[ $sequence . '_start' ] ?? 1 ) ) );
		$prefix   = (string) ( 'invoice' === $sequence ? ( $settings['invoice_prefix'] ?? 'INV-' ) : ( $settings['credit_note_prefix'] ?? 'CN-' ) );
		$prefix   = str_replace( '{year}', wp_date( 'Y', Clock::timestamp() ), $prefix );
		return $prefix . str_pad( (string) $value, max( 1, (int) ( $settings['number_padding'] ?? 6 ) ), '0', STR_PAD_LEFT );
	}

	/**
	 * Loads an order or fails.
	 *
	 * @param int $order_id Order.
	 * @throws ValidationException When missing.
	 */
	private function require( int $order_id ): Order {
		$order = $this->orders->find( $order_id );
		if ( ! $order ) {
			throw new ValidationException( array( esc_html__( 'Order not found.', 'memberkin' ) ) );
		}
		return $order;
	}

	/**
	 * Fires the status hook.
	 *
	 * @param Order  $order Order.
	 * @param string $from  From.
	 * @param string $to    To.
	 */
	private function fire( Order $order, string $from, string $to ): void {
		/**
		 * Fires when an order's status changed.
		 *
		 * @param Order  $order Order.
		 * @param string $from  Previous status.
		 * @param string $to    New status.
		 */
		do_action( 'memberkin_order_status_changed', $order, $from, $to );
	}

	/**
	 * Sanitises a billing array.
	 *
	 * @param array $billing Raw billing fields.
	 * @return array<string, string>
	 */
	public static function clean_billing( array $billing ): array {
		$out = array();
		foreach ( array( 'name', 'company', 'line1', 'line2', 'city', 'region', 'postcode', 'phone' ) as $key ) {
			if ( isset( $billing[ $key ] ) && '' !== trim( (string) $billing[ $key ] ) ) {
				$out[ $key ] = mb_substr( sanitize_text_field( (string) $billing[ $key ] ), 0, 190 );
			}
		}
		if ( ! empty( $billing['email'] ) && is_email( $billing['email'] ) ) {
			$out['email'] = sanitize_email( (string) $billing['email'] );
		}
		if ( ! empty( $billing['country'] ) ) {
			$out['country'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $billing['country'] ), 0, 2 ) );
		}
		return $out;
	}
}
