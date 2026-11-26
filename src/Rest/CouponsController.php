<?php
/**
 * /coupons.
 *
 * @package Memberkin
 */

namespace Memberkin\Rest;

use Memberkin\Core\Capabilities;
use Memberkin\Domain\Coupon;
use Memberkin\Repository\CouponRepository;
use Memberkin\Service\CouponService;

defined( 'ABSPATH' ) || exit;

/**
 * Coupon CRUD (memberkin_manage_coupons). Input as CouponService::save(): amounts are decimal strings.
 */
final class CouponsController extends RestController {

	/**
	 * Routes.
	 */
	public function register_routes(): void {
		$manage = $this->can( Capabilities::MANAGE_COUPONS );
		register_rest_route(
			$this->namespace,
			'/coupons',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => $manage,
					'args'                => $this->list_args(
						array(
							'search' => array( 'type' => 'string' ),
							'status' => array( 'type' => 'string' ),
						)
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => $manage,
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/coupons/(?P<id>\d+)',
			array(
				'args'   => self::id_arg(),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_one' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => $manage,
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Schema.
	 */
	public function get_item_schema(): array {
		return $this->add_additional_fields_schema(
			$this->make_schema(
				'memberkin-coupon',
				array(
					'id'                => 'integer',
					'code'              => 'string',
					'description'       => 'string',
					'status'            => 'string',
					'discount_type'     => array(
						'type' => 'string',
						'enum' => array_keys( Coupon::types() ),
					),
					'amount'            => array(
						'type'        => array( 'string', 'integer' ),
						'description' => 'Write: decimal string (percent or money). Read: stored integer.',
					),
					'applies_to'        => 'string',
					'recurring_cycles'  => array( 'type' => array( 'integer', 'null' ) ),
					'starts_at'         => array( 'type' => array( 'string', 'null' ) ),
					'expires_at'        => array( 'type' => array( 'string', 'null' ) ),
					'max_uses'          => 'integer',
					'uses_count'        => array(
						'type'     => 'integer',
						'readonly' => true,
					),
					'max_uses_per_user' => 'integer',
					'level_ids'         => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'integer' ),
						'context' => array( 'edit' ),
					),
					'levels'            => array(
						'type'     => 'array',
						'readonly' => true,
					),
				)
			)
		);
	}

	/**
	 * GET /coupons.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function list_items( $request ): \WP_REST_Response {
		$result = memberkin()->get( CouponRepository::class )->query(
			array(
				'search'   => (string) $request['search'],
				'status'   => sanitize_key( (string) $request['status'] ),
				'per_page' => (int) $request['per_page'],
				'page'     => (int) $request['page'],
			)
		);
		return $this->paginated( array_map( static fn( Coupon $c ) => $c->jsonSerialize(), $result['items'] ), (int) $result['total'], (int) $request['per_page'] );
	}

	/**
	 * GET /coupons/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_one( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$coupon = memberkin()->get( CouponRepository::class )->find( (int) $request['id'] );
		return $coupon ? new \WP_REST_Response( $coupon->jsonSerialize() ) : self::not_found( __( 'Coupon', 'memberkin' ) );
	}

	/**
	 * POST /coupons, PUT|PATCH /coupons/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function save( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = self::body( $request );
		unset( $input['id'], $input['uses_count'] );
		if ( $request['id'] ) {
			if ( ! memberkin()->get( CouponRepository::class )->find( (int) $request['id'] ) ) {
				return self::not_found( __( 'Coupon', 'memberkin' ) );
			}
			$input['id'] = (int) $request['id'];
		}
		return $this->run( static fn() => memberkin()->get( CouponService::class )->save( $input )->jsonSerialize(), $request['id'] ? 200 : 201 );
	}

	/**
	 * DELETE /coupons/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$repo = memberkin()->get( CouponRepository::class );
		if ( ! $repo->find( (int) $request['id'] ) ) {
			return self::not_found( __( 'Coupon', 'memberkin' ) );
		}
		$repo->delete( (int) $request['id'] );
		return new \WP_REST_Response(
			array(
				'id'      => (int) $request['id'],
				'deleted' => true,
			)
		);
	}
}
