<?php
/**
 * /me/* — the current member's own data.
 *
 * @package Memberkin
 */

namespace Memberkin\Rest;

use Memberkin\Core\Clock;
use Memberkin\Core\Settings;
use Memberkin\Core\ValidationException;
use Memberkin\Domain\Membership;
use Memberkin\Domain\Order;
use Memberkin\Domain\Subscription;
use Memberkin\Frontend\AccountController;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\MembershipRepository;
use Memberkin\Repository\OrderRepository;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\MembershipService;
use Memberkin\Service\SubscriptionService;

defined( 'ABSPATH' ) || exit;

/**
 * Every route is scoped to get_current_user_id(); ids of other users' records return 404.
 * Member actions follow the same account settings as the account page.
 */
final class MeController extends RestController {

	/**
	 * Routes.
	 */
	public function register_routes(): void {
		$logged_in = static fn() => is_user_logged_in() ? true : self::denied();
		foreach ( array(
			''               => 'me',
			'/memberships'   => 'memberships',
			'/subscriptions' => 'subscriptions',
			'/orders'        => 'orders',
		) as $path => $method ) {
			register_rest_route(
				$this->namespace,
				'/me' . $path,
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, $method ),
					'permission_callback' => $logged_in,
				)
			);
		}
		register_rest_route(
			$this->namespace,
			'/me/subscriptions/(?P<id>\d+)/(?P<action>cancel|reactivate|pause|resume)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'subscription_action' ),
				'permission_callback' => $logged_in,
				'args'                => array_merge(
					self::id_arg(),
					array(
						'days' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 30,
						),
					)
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/me/memberships/(?P<id>\d+)/cancel',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel_membership' ),
				'permission_callback' => $logged_in,
				'args'                => self::id_arg(),
			)
		);
	}

	/**
	 * Membership as seen by its member.
	 *
	 * @param Membership $m Membership.
	 */
	private static function membership( Membership $m ): array {
		$level = memberkin()->get( LevelRepository::class )->find( $m->level_id );
		$data  = $m->jsonSerialize();
		unset( $data['source'], $data['parent_membership_id'], $data['coupon_id'] );
		$data['level_name'] = $level ? $level->name : '';
		return $data;
	}

	/**
	 * Subscription without gateway internals.
	 *
	 * @param Subscription $s Subscription.
	 */
	private static function subscription( Subscription $s ): array {
		$data = $s->jsonSerialize();
		unset( $data['gateway_customer_id'], $data['gateway_subscription_id'], $data['payment_method_id'], $data['coupon_id'], $data['retry_count'], $data['next_retry_at'] );
		$data['can_cancel'] = (bool) Settings::get( 'accounts', 'allow_member_cancel', true ) && ! $s->cancel_at_period_end && in_array( $s->status, array( Subscription::ACTIVE, Subscription::TRIALING, Subscription::PAST_DUE, Subscription::PAUSED ), true );
		$data['can_pause']  = AccountController::can_pause( $s );
		return $data;
	}

	/**
	 * Order without internals.
	 *
	 * @param Order $o Order.
	 */
	private static function order( Order $o ): array {
		$data = $o->jsonSerialize();
		unset( $data['gateway_customer_id'], $data['checkout_session_id'], $data['gateway_mode'], $data['coupon_id'] );
		/**
		 * Filters an order as returned to its member by /me/orders (add-ons add links such as an invoice URL).
		 *
		 * @param array $data  Order data.
		 * @param Order $order Order.
		 */
		return (array) apply_filters( 'memberkin_rest_me_order', $data, $o );
	}

	/**
	 * GET /me.
	 */
	public function me(): \WP_REST_Response {
		$user_id             = get_current_user_id();
		$data                = MembershipsController::user_data( $user_id );
		$data['level_ids']   = memberkin()->get( MembershipService::class )->active_level_ids( $user_id );
		$data['memberships'] = array_map( array( self::class, 'membership' ), memberkin()->get( MembershipRepository::class )->current_for_user( $user_id ) );
		return new \WP_REST_Response( $data );
	}

	/**
	 * GET /me/memberships (current and past).
	 */
	public function memberships(): \WP_REST_Response {
		return new \WP_REST_Response( array_map( array( self::class, 'membership' ), memberkin()->get( MembershipRepository::class )->all_for_user( get_current_user_id() ) ) );
	}

	/**
	 * GET /me/subscriptions.
	 */
	public function subscriptions(): \WP_REST_Response {
		$result = memberkin()->get( SubscriptionRepository::class )->query(
			array(
				'user_id'  => get_current_user_id(),
				'per_page' => 100,
			)
		);
		return new \WP_REST_Response( array_map( array( self::class, 'subscription' ), $result['items'] ) );
	}

	/**
	 * GET /me/orders (payment history; failed and cancelled checkouts hidden, as on the account page).
	 */
	public function orders(): \WP_REST_Response {
		$orders = array_filter( memberkin()->get( OrderRepository::class )->for_user( get_current_user_id(), 100 ), static fn( Order $o ) => ! in_array( $o->status, array( Order::FAILED, Order::CANCELLED ), true ) );
		return new \WP_REST_Response( array_values( array_map( array( self::class, 'order' ), $orders ) ) );
	}

	/**
	 * POST /me/subscriptions/{id}/{action}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function subscription_action( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$sub = memberkin()->get( SubscriptionRepository::class )->find( (int) $request['id'] );
		if ( ! $sub || get_current_user_id() !== $sub->user_id ) {
			return self::not_found( __( 'Subscription', 'memberkin' ) );
		}
		return $this->run(
			static function () use ( $sub, $request ) {
				$service = memberkin()->get( SubscriptionService::class );
				switch ( (string) $request['action'] ) {
					case 'cancel':
						if ( ! Settings::get( 'accounts', 'allow_member_cancel', true ) ) {
							throw new ValidationException( array( esc_html__( 'Please contact us to cancel.', 'memberkin' ) ) );
						}
						$sub = $service->cancel( $sub->id, true, 'member' );
						break;
					case 'reactivate':
						$sub = $service->reactivate( $sub->id );
						break;
					case 'pause':
						if ( ! AccountController::can_pause( $sub ) ) {
							throw new ValidationException( array( esc_html__( 'This subscription cannot be paused.', 'memberkin' ) ) );
						}
						$days = max( 1, min( (int) Settings::get( 'accounts', 'max_pause_days', 90 ), (int) $request['days'] ) );
						$sub  = $service->pause( $sub->id, Clock::now()->modify( '+' . $days . ' days' ) );
						break;
					case 'resume':
						if ( Subscription::PAUSED !== $sub->status ) {
							throw new ValidationException( array( esc_html__( 'This subscription is not paused.', 'memberkin' ) ) );
						}
						$sub = $service->resume( $sub->id );
						break;
				}
				return self::subscription( $sub );
			}
		);
	}

	/**
	 * POST /me/memberships/{id}/cancel — memberships without a subscription (subscriptions are cancelled via /me/subscriptions).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function cancel_membership( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$m = memberkin()->get( MembershipRepository::class )->find( (int) $request['id'] );
		if ( ! $m || get_current_user_id() !== $m->user_id ) {
			return self::not_found( __( 'Membership', 'memberkin' ) );
		}
		return $this->run(
			static function () use ( $m ) {
				if ( $m->subscription_id || ! Settings::get( 'accounts', 'allow_member_cancel', true ) ) {
					throw new ValidationException( array( esc_html__( 'This membership cannot be cancelled here.', 'memberkin' ) ) );
				}
				return self::membership( memberkin()->get( MembershipService::class )->cancel( $m->id, null, array( 'reason' => 'member' ) ) );
			}
		);
	}
}
