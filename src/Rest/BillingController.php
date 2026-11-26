<?php
/**
 * /subscriptions and /orders.
 *
 * @package Memberkin
 */

namespace Memberkin\Rest;

use Memberkin\Core\Capabilities;
use Memberkin\Core\Money;
use Memberkin\Domain\Order;
use Memberkin\Domain\Subscription;
use Memberkin\Repository\OrderRepository;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\Checkout\CheckoutService;
use Memberkin\Service\OrderService;
use Memberkin\Service\RefundService;
use Memberkin\Service\SubscriptionService;

defined( 'ABSPATH' ) || exit;

/**
 * Orders and subscriptions need memberkin_manage_orders; refunds need memberkin_refund_orders.
 */
final class BillingController extends RestController {

	/**
	 * Routes.
	 */
	public function register_routes(): void {
		$manage = $this->can( Capabilities::MANAGE_ORDERS );
		$refund = $this->can( Capabilities::REFUND_ORDERS );
		register_rest_route(
			$this->namespace,
			'/subscriptions',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_subscriptions' ),
				'permission_callback' => $manage,
				'args'                => $this->list_args(
					array(
						'status'   => array( 'type' => 'string' ),
						'user_id'  => array( 'type' => 'integer' ),
						'level_id' => array( 'type' => 'integer' ),
						'gateway'  => array( 'type' => 'string' ),
					)
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/subscriptions/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_subscription' ),
				'permission_callback' => $manage,
				'args'                => self::id_arg(),
			)
		);
		register_rest_route(
			$this->namespace,
			'/subscriptions/(?P<id>\d+)/(?P<action>cancel|reactivate|pause|resume)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'subscription_action' ),
				'permission_callback' => $manage,
				'args'                => array_merge(
					self::id_arg(),
					array(
						'at_period_end' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'resume_at'     => array( 'type' => 'string' ),
						'reason'        => array( 'type' => 'string' ),
					)
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/orders',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_orders' ),
				'permission_callback' => $manage,
				'args'                => $this->list_args(
					array(
						'status'          => array( 'type' => 'string' ),
						'user_id'         => array( 'type' => 'integer' ),
						'subscription_id' => array( 'type' => 'integer' ),
						'gateway'         => array( 'type' => 'string' ),
						'search'          => array( 'type' => 'string' ),
						'after'           => array( 'type' => 'string' ),
						'before'          => array( 'type' => 'string' ),
					)
				),
				'schema'              => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order' ),
				'permission_callback' => $manage,
				'args'                => self::id_arg(),
			)
		);
		register_rest_route(
			$this->namespace,
			'/orders/(?P<id>\d+)/(?P<action>mark-paid|cancel|notes)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'order_action' ),
				'permission_callback' => $manage,
				'args'                => array_merge( self::id_arg(), array( 'note' => array( 'type' => 'string' ) ) ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/orders/(?P<id>\d+)/refunds',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_refunds' ),
					'permission_callback' => $manage,
					'args'                => self::id_arg(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_refund' ),
					'permission_callback' => $refund,
					'args'                => array_merge(
						self::id_arg(),
						array(
							'amount'        => array(
								'type'        => 'string',
								'description' => __( 'Decimal amount, e.g. "12.50". Omit for the remaining balance.', 'memberkin' ),
							),
							'reason'        => array( 'type' => 'string' ),
							'revoke_access' => array(
								'type'    => 'boolean',
								'default' => false,
							),
						)
					),
				),
			)
		);
	}

	/**
	 * Order schema.
	 */
	public function get_item_schema(): array {
		return $this->add_additional_fields_schema(
			$this->make_schema(
				'memberkin-order',
				array(
					'id'              => 'integer',
					'number'          => 'string',
					'invoice_number'  => array( 'type' => array( 'string', 'null' ) ),
					'user_id'         => 'integer',
					'type'            => 'string',
					'status'          => array(
						'type' => 'string',
						'enum' => array( Order::PENDING, Order::PAID, Order::FAILED, Order::CANCELLED, Order::REFUNDED, Order::PARTIALLY_REFUNDED, Order::DISPUTED, Order::REVIEW ),
					),
					'currency'        => 'string',
					'subtotal'        => array(
						'type'        => 'integer',
						'description' => 'Minor units.',
					),
					'discount_total'  => 'integer',
					'tax_total'       => 'integer',
					'total'           => 'integer',
					'amount_refunded' => 'integer',
					'gateway'         => 'string',
					'subscription_id' => array( 'type' => array( 'integer', 'null' ) ),
					'billing'         => 'object',
					'tax'             => 'object',
					'items'           => 'array',
					'paid_at'         => array(
						'type'   => array( 'string', 'null' ),
						'format' => 'date-time',
					),
					'created_at'      => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
				)
			)
		);
	}

	/**
	 * GET /subscriptions.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function list_subscriptions( \WP_REST_Request $request ): \WP_REST_Response {
		$result = memberkin()->get( SubscriptionRepository::class )->query(
			array(
				'statuses' => $request['status'] ? array( sanitize_key( (string) $request['status'] ) ) : array(),
				'user_id'  => (int) $request['user_id'],
				'level_id' => (int) $request['level_id'],
				'gateway'  => sanitize_key( (string) $request['gateway'] ),
				'per_page' => (int) $request['per_page'],
				'page'     => (int) $request['page'],
			)
		);
		return $this->paginated( array_map( static fn( Subscription $s ) => $s->jsonSerialize(), $result['items'] ), (int) $result['total'], (int) $request['per_page'] );
	}

	/**
	 * GET /subscriptions/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_subscription( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$sub = memberkin()->get( SubscriptionRepository::class )->find( (int) $request['id'] );
		if ( ! $sub ) {
			return self::not_found( __( 'Subscription', 'memberkin' ) );
		}
		$data           = $sub->jsonSerialize();
		$data['orders'] = array_map( static fn( Order $o ) => $o->jsonSerialize(), memberkin()->get( OrderRepository::class )->for_subscription( $sub->id ) );
		return new \WP_REST_Response( $data );
	}

	/**
	 * POST /subscriptions/{id}/{action}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function subscription_action( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$sub = memberkin()->get( SubscriptionRepository::class )->find( (int) $request['id'] );
		if ( ! $sub ) {
			return self::not_found( __( 'Subscription', 'memberkin' ) );
		}
		return $this->run(
			static function () use ( $sub, $request ) {
				$service = memberkin()->get( SubscriptionService::class );
				$result  = match ( (string) $request['action'] ) {
					'cancel'     => $service->cancel( $sub->id, (bool) $request['at_period_end'], sanitize_key( (string) ( $request['reason'] ?? 'admin' ) ) ),
					'reactivate' => $service->reactivate( $sub->id ),
					'pause'      => $service->pause( $sub->id, self::date( $request['resume_at'], 'resume_at' ) ),
					'resume'     => $service->resume( $sub->id ),
				};
				return $result->jsonSerialize();
			}
		);
	}

	/**
	 * GET /orders.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function list_orders( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			function () use ( $request ) {
				$after  = self::date( $request['after'], 'after' );
				$before = self::date( $request['before'], 'before' );
				$result = memberkin()->get( OrderRepository::class )->query(
					array(
						'statuses'        => $request['status'] ? array( sanitize_key( (string) $request['status'] ) ) : array(),
						'user_id'         => (int) $request['user_id'],
						'subscription_id' => (int) $request['subscription_id'],
						'gateway'         => sanitize_key( (string) $request['gateway'] ),
						'search'          => (string) $request['search'],
						'from'            => $after ? $after->format( 'Y-m-d H:i:s' ) : '',
						'to'              => $before ? $before->format( 'Y-m-d H:i:s' ) : '',
						'per_page'        => (int) $request['per_page'],
						'page'            => (int) $request['page'],
					)
				);
				return $this->paginated( array_map( static fn( Order $o ) => $o->jsonSerialize(), $result['items'] ), (int) $result['total'], (int) $request['per_page'] );
			}
		);
	}

	/**
	 * Full order (admin): with notes and refunds.
	 *
	 * @param Order $order Order.
	 */
	private function order_data( Order $order ): array {
		$data            = $order->jsonSerialize();
		$data['notes']   = $order->notes;
		$data['refunds'] = $this->refund_rows( $order );
		return $data;
	}

	/**
	 * Refund rows.
	 *
	 * @param Order $order Order.
	 */
	private function refund_rows( Order $order ): array {
		return array_map(
			static fn( object $r ) => array(
				'id'                 => (int) $r->id,
				'amount'             => (int) $r->amount,
				'reason'             => (string) $r->reason,
				'status'             => (string) ( $r->status ?? '' ),
				'gateway_refund_id'  => $r->gateway_refund_id,
				'credit_note_number' => $r->credit_note_number ?? null,
				'created_by'         => (int) ( $r->created_by ?? 0 ),
				'created_at'         => mysql_to_rfc3339( (string) $r->created_at ),
			),
			memberkin()->get( OrderRepository::class )->refunds( $order->id )
		);
	}

	/**
	 * GET /orders/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_order( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$order = memberkin()->get( OrderRepository::class )->find( (int) $request['id'] );
		return $order ? new \WP_REST_Response( $this->order_data( $order ) ) : self::not_found( __( 'Order', 'memberkin' ) );
	}

	/**
	 * POST /orders/{id}/{mark-paid|cancel|notes}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function order_action( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$order = memberkin()->get( OrderRepository::class )->find( (int) $request['id'] );
		if ( ! $order ) {
			return self::not_found( __( 'Order', 'memberkin' ) );
		}
		return $this->run(
			function () use ( $order, $request ) {
				$service = memberkin()->get( OrderService::class );
				$who     = wp_get_current_user()->display_name;
				switch ( (string) $request['action'] ) {
					case 'mark-paid':
						$service->mark_paid( $order->id );
						/* translators: %s: user name. */
						$service->add_note( $order->id, sprintf( __( 'Marked as paid by %s (REST API).', 'memberkin' ), $who ) );
						break;
					case 'cancel':
						/* translators: %s: user name. */
						$service->cancel( $order->id, sprintf( __( 'Cancelled by %s (REST API).', 'memberkin' ), $who ) );
						memberkin()->get( CheckoutService::class )->abandon( $order->id, '' );
						break;
					case 'notes':
						$note = sanitize_textarea_field( (string) $request['note'] );
						if ( '' === $note ) {
							throw new \Memberkin\Core\ValidationException( array( 'note' => esc_html__( 'Enter a note.', 'memberkin' ) ) );
						}
						$service->add_note( $order->id, $note );
						break;
				}
				return $this->order_data( memberkin()->get( OrderRepository::class )->find( $order->id ) );
			}
		);
	}

	/**
	 * GET /orders/{id}/refunds.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function list_refunds( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$order = memberkin()->get( OrderRepository::class )->find( (int) $request['id'] );
		return $order ? new \WP_REST_Response( $this->refund_rows( $order ) ) : self::not_found( __( 'Order', 'memberkin' ) );
	}

	/**
	 * POST /orders/{id}/refunds — refunds through the gateway (or records a manual refund for offline orders).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function create_refund( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$order = memberkin()->get( OrderRepository::class )->find( (int) $request['id'] );
		if ( ! $order ) {
			return self::not_found( __( 'Order', 'memberkin' ) );
		}
		return $this->run(
			function () use ( $order, $request ) {
				$amount  = null !== $request['amount'] && '' !== $request['amount']
					? Money::from_decimal( (string) $request['amount'], $order->currency )
					: Money::of( $order->total - $order->amount_refunded, $order->currency );
				$refunds = memberkin()->get( RefundService::class );
				$order   = $refunds->refund( $order->id, $amount, sanitize_text_field( (string) $request['reason'] ), (bool) $request['revoke_access'], get_current_user_id() );
				return $this->order_data( $order ) + array( 'refund_pending' => $refunds->last_pending );
			},
			201
		);
	}
}
