<?php
/**
 * /members and /memberships.
 *
 * @package Memberkin
 */

namespace Memberkin\Rest;

use Memberkin\Core\Capabilities;
use Memberkin\Domain\Membership;
use Memberkin\Repository\MembershipRepository;
use Memberkin\Repository\SubscriptionRepository;
use Memberkin\Service\MembershipService;

defined( 'ABSPATH' ) || exit;

/**
 * Members (read: memberkin_view_members) and membership management (memberkin_manage_members).
 */
final class MembershipsController extends RestController {

	/**
	 * Routes.
	 */
	public function register_routes(): void {
		$view   = $this->can( Capabilities::VIEW_MEMBERS );
		$manage = $this->can( Capabilities::MANAGE_MEMBERS );
		$filter = array(
			'status'   => array(
				'type'        => 'string',
				'description' => __( 'Membership status, "current" (default) or "all".', 'memberkin' ),
			),
			'level_id' => array( 'type' => 'integer' ),
			'user_id'  => array( 'type' => 'integer' ),
			'search'   => array( 'type' => 'string' ),
		);
		register_rest_route(
			$this->namespace,
			'/members',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_members' ),
				'permission_callback' => $view,
				'args'                => $this->list_args( $filter ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/members/(?P<user_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_member' ),
				'permission_callback' => $view,
			)
		);
		register_rest_route(
			$this->namespace,
			'/memberships',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_memberships' ),
					'permission_callback' => $manage,
					'args'                => $this->list_args( $filter ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'grant' ),
					'permission_callback' => $manage,
					'args'                => array(
						'user_id'   => array(
							'type'     => 'integer',
							'required' => true,
						),
						'level_id'  => array(
							'type'     => 'integer',
							'required' => true,
						),
						'ends_at'   => array( 'type' => 'string' ),
						'starts_at' => array( 'type' => 'string' ),
						'replace'   => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'notify'    => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/memberships/(?P<id>\d+)',
			array(
				'args'   => self::id_arg(),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_membership' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_membership' ),
					'permission_callback' => $manage,
					'args'                => array(
						'ends_at'   => array( 'type' => array( 'string', 'null' ) ),
						'starts_at' => array( 'type' => 'string' ),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/memberships/(?P<id>\d+)/(?P<action>cancel|end|pause|resume|suspend|unsuspend|reactivate)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'action' ),
				'permission_callback' => $manage,
				'args'                => array_merge(
					self::id_arg(),
					array(
						'ends_at'   => array( 'type' => 'string' ),
						'resume_at' => array( 'type' => 'string' ),
						'reason'    => array( 'type' => 'string' ),
						'note'      => array( 'type' => 'string' ),
					)
				),
			)
		);
	}

	/**
	 * Schema.
	 */
	public function get_item_schema(): array {
		return $this->add_additional_fields_schema(
			$this->make_schema(
				'memberkin-membership',
				array(
					'id'              => 'integer',
					'user_id'         => 'integer',
					'level_id'        => 'integer',
					'status'          => 'string',
					'source'          => 'string',
					'pricing'         => 'object',
					'starts_at'       => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
					'ends_at'         => array(
						'type'   => array( 'string', 'null' ),
						'format' => 'date-time',
					),
					'subscription_id' => array( 'type' => array( 'integer', 'null' ) ),
					'grants_access'   => 'boolean',
					'end_reason'      => array( 'type' => array( 'string', 'null' ) ),
				)
			)
		);
	}

	/**
	 * Basic user data.
	 *
	 * @param int $user_id User.
	 */
	public static function user_data( int $user_id ): ?array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}
		return array(
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'registered'   => mysql_to_rfc3339( $user->user_registered ),
		);
	}

	/**
	 * Query args from request filters.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private function query_args( \WP_REST_Request $request ): array {
		$status   = (string) $request['status'];
		$statuses = match ( $status ) {
			'', 'current' => array_diff( Membership::CURRENT_STATUSES, array( Membership::PENDING ) ),
			'all'         => array(),
			default       => array( sanitize_key( $status ) ),
		};
		return array(
			'statuses'  => $statuses,
			'level_ids' => $request['level_id'] ? array( (int) $request['level_id'] ) : array(),
			'user_id'   => (int) $request['user_id'],
			'search'    => (string) $request['search'],
			'per_page'  => (int) $request['per_page'],
			'page'      => (int) $request['page'],
		);
	}

	/**
	 * GET /members — one row per membership with the user embedded.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function list_members( \WP_REST_Request $request ): \WP_REST_Response {
		$result = memberkin()->get( MembershipRepository::class )->query( $this->query_args( $request ) );
		cache_users( array_map( static fn( Membership $m ) => $m->user_id, $result['items'] ) );
		$items = array_map(
			static function ( Membership $m ) {
				$data         = $m->jsonSerialize();
				$data['user'] = self::user_data( $m->user_id );
				return $data;
			},
			$result['items']
		);
		return $this->paginated( $items, (int) $result['total'], (int) $request['per_page'] );
	}

	/**
	 * GET /members/{user_id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_member( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user_id = (int) $request['user_id'];
		$user    = self::user_data( $user_id );
		if ( ! $user ) {
			return self::not_found( __( 'User', 'memberkin' ) );
		}
		$user['memberships']   = array_map( static fn( Membership $m ) => $m->jsonSerialize(), memberkin()->get( MembershipRepository::class )->all_for_user( $user_id ) );
		$subs                  = memberkin()->get( SubscriptionRepository::class )->query(
			array(
				'user_id'  => $user_id,
				'per_page' => 100,
			)
		);
		$user['subscriptions'] = array_map( static fn( $s ) => $s->jsonSerialize(), $subs['items'] );
		$user['level_ids']     = memberkin()->get( MembershipService::class )->active_level_ids( $user_id );
		return new \WP_REST_Response( $user );
	}

	/**
	 * GET /memberships.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function list_memberships( \WP_REST_Request $request ): \WP_REST_Response {
		$result = memberkin()->get( MembershipRepository::class )->query( $this->query_args( $request ) );
		return $this->paginated( array_map( static fn( Membership $m ) => $m->jsonSerialize(), $result['items'] ), (int) $result['total'], (int) $request['per_page'] );
	}

	/**
	 * GET /memberships/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_membership( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$m = memberkin()->get( MembershipRepository::class )->find( (int) $request['id'] );
		return $m ? new \WP_REST_Response( $m->jsonSerialize() ) : self::not_found( __( 'Membership', 'memberkin' ) );
	}

	/**
	 * POST /memberships — grants a level.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function grant( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->run(
			static function () use ( $request ) {
				$args = array(
					'source'  => 'api',
					'replace' => (bool) $request['replace'],
					'context' => array(
						'notify' => (bool) $request['notify'],
						'by'     => get_current_user_id(),
					),
				);
				if ( null !== $request['ends_at'] ) {
					$args['ends_at'] = self::date( $request['ends_at'], 'ends_at' );
				}
				if ( $request['starts_at'] ) {
					$args['starts_at'] = self::date( $request['starts_at'], 'starts_at' );
				}
				return memberkin()->get( MembershipService::class )->grant( (int) $request['user_id'], (int) $request['level_id'], $args )->jsonSerialize();
			},
			201
		);
	}

	/**
	 * PATCH /memberships/{id} — dates.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function update_membership( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$m = memberkin()->get( MembershipRepository::class )->find( (int) $request['id'] );
		if ( ! $m ) {
			return self::not_found( __( 'Membership', 'memberkin' ) );
		}
		$params = $request->get_params();
		return $this->run(
			static function () use ( $m, $params ) {
				$ends   = array_key_exists( 'ends_at', $params ) ? self::date( $params['ends_at'], 'ends_at' ) : $m->ends_at;
				$starts = ! empty( $params['starts_at'] ) ? self::date( $params['starts_at'], 'starts_at' ) : null;
				return memberkin()->get( MembershipService::class )->set_dates( $m->id, $ends, $starts )->jsonSerialize();
			}
		);
	}

	/**
	 * POST /memberships/{id}/{action}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function action( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$m = memberkin()->get( MembershipRepository::class )->find( (int) $request['id'] );
		if ( ! $m ) {
			return self::not_found( __( 'Membership', 'memberkin' ) );
		}
		return $this->run(
			static function () use ( $m, $request ) {
				$service = memberkin()->get( MembershipService::class );
				$context = array(
					'reason' => (string) ( $request['reason'] ?? 'admin' ),
					'by'     => get_current_user_id(),
				);
				$result  = match ( (string) $request['action'] ) {
					'cancel'     => $service->cancel( $m->id, self::date( $request['ends_at'], 'ends_at' ), $context ),
					'end'        => $service->end( $m->id, sanitize_key( (string) ( $request['reason'] ?? 'admin' ) ), $context ),
					'pause'      => $service->pause( $m->id, self::date( $request['resume_at'], 'resume_at' ), $context ),
					'resume'     => $service->resume( $m->id, $context ),
					'suspend'    => $service->suspend( $m->id, sanitize_text_field( (string) $request['note'] ) ),
					'unsuspend'  => $service->unsuspend( $m->id ),
					'reactivate' => $service->reactivate( $m->id, self::date( $request['ends_at'], 'ends_at' ), $context ),
				};
				return $result->jsonSerialize();
			}
		);
	}
}
