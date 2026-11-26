<?php
/**
 * /content-access and /access/check.
 *
 * @package Memberkin
 */

namespace Memberkin\Rest;

use Memberkin\Core\Capabilities;
use Memberkin\Core\ValidationException;
use Memberkin\Repository\AccessRepository;
use Memberkin\Repository\LevelRepository;
use Memberkin\Service\AccessService;

defined( 'ABSPATH' ) || exit;

/**
 * Per-object assignments need memberkin_manage_rules. Access checks need memberkin_view_members,
 * except a logged-in user checking their own access.
 */
final class AccessController extends RestController {

	/**
	 * Routes.
	 */
	public function register_routes(): void {
		$manage = $this->can( Capabilities::MANAGE_RULES );
		register_rest_route(
			$this->namespace,
			'/content-access/(?P<object_type>post|term)/(?P<object_id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_assignments' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'set_assignments' ),
					'permission_callback' => $manage,
					'args'                => array(
						'levels' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array(
								'type'       => 'object',
								'properties' => array(
									'level_id'        => array(
										'type'     => 'integer',
										'required' => true,
									),
									'drip_delay_days' => array( 'type' => array( 'integer', 'null' ) ),
								),
							),
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/access/check',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'check' ),
				'permission_callback' => array( $this, 'can_check' ),
				'args'                => array(
					'user_id' => array( 'type' => 'integer' ),
					'post_id' => array( 'type' => 'integer' ),
					'uri'     => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Assignment list for an object.
	 *
	 * @param string $type Type.
	 * @param int    $id   Id.
	 */
	private function assignments( string $type, int $id ): array {
		$out = array();
		foreach ( memberkin()->get( AccessRepository::class )->levels_for( $type, $id ) as $level_id => $delay ) {
			$out[] = array(
				'level_id'        => $level_id,
				'drip_delay_days' => $delay,
			);
		}
		return array(
			'object_type' => $type,
			'object_id'   => $id,
			'levels'      => $out,
		);
	}

	/**
	 * Whether the object exists.
	 *
	 * @param string $type post|term.
	 * @param int    $id   Id.
	 */
	private static function object_exists( string $type, int $id ): bool {
		return 'post' === $type ? (bool) get_post( $id ) : get_term( $id ) instanceof \WP_Term;
	}

	/**
	 * GET /content-access/{type}/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_assignments( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$type = (string) $request['object_type'];
		$id   = (int) $request['object_id'];
		if ( ! self::object_exists( $type, $id ) ) {
			return self::not_found( 'post' === $type ? __( 'Post', 'memberkin' ) : __( 'Term', 'memberkin' ) );
		}
		return new \WP_REST_Response( $this->assignments( $type, $id ) );
	}

	/**
	 * PUT /content-access/{type}/{id} — replaces the assignments.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function set_assignments( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$type = (string) $request['object_type'];
		$id   = (int) $request['object_id'];
		if ( ! self::object_exists( $type, $id ) ) {
			return self::not_found( 'post' === $type ? __( 'Post', 'memberkin' ) : __( 'Term', 'memberkin' ) );
		}
		return $this->run(
			function () use ( $request, $type, $id ) {
				$levels = array();
				$repo   = memberkin()->get( LevelRepository::class );
				foreach ( (array) $request['levels'] as $row ) {
					$level_id = (int) ( $row['level_id'] ?? 0 );
					if ( ! $repo->find( $level_id ) ) {
						/* translators: %d: level id. */
						throw new ValidationException( array( 'levels' => sprintf( esc_html__( 'Level %d not found.', 'memberkin' ), (int) $level_id ) ) );
					}
					$delay               = $row['drip_delay_days'] ?? null;
					$levels[ $level_id ] = null === $delay ? null : max( 0, (int) $delay );
				}
				memberkin()->get( AccessRepository::class )->set_levels( $type, $id, $levels );
				memberkin()->get( AccessService::class )->flush();
				return $this->assignments( $type, $id );
			}
		);
	}

	/**
	 * Permission for /access/check.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function can_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( current_user_can( Capabilities::VIEW_MEMBERS ) ) {
			return true;
		}
		$user_id = (int) ( $request['user_id'] ?? 0 );
		if ( is_user_logged_in() && ( 0 === $user_id || get_current_user_id() === $user_id ) ) {
			return true;
		}
		return self::denied();
	}

	/**
	 * GET /access/check.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function check( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user_id = (int) ( $request['user_id'] ? $request['user_id'] : get_current_user_id() );
		$service = memberkin()->get( AccessService::class );
		if ( $request['post_id'] ) {
			if ( ! get_post( (int) $request['post_id'] ) ) {
				return self::not_found( __( 'Post', 'memberkin' ) );
			}
			$decision = $service->decide_post( (int) $request['post_id'], $user_id );
		} elseif ( null !== $request['uri'] && '' !== $request['uri'] ) {
			$decision = $service->decide_uri( (string) wp_parse_url( (string) $request['uri'], PHP_URL_PATH ), $user_id );
		} else {
			return new \WP_Error( 'memberkin_invalid', __( 'Pass post_id or uri.', 'memberkin' ), array( 'status' => 400 ) );
		}
		return new \WP_REST_Response( array_merge( array( 'user_id' => $user_id ), $decision->jsonSerialize() ) );
	}
}
