<?php
/**
 * /levels and /level-groups.
 *
 * @package Memberkin
 */

namespace Memberkin\Rest;

use Memberkin\Core\Capabilities;
use Memberkin\Domain\Level;
use Memberkin\Domain\LevelGroup;
use Memberkin\Repository\LevelGroupRepository;
use Memberkin\Repository\LevelRepository;
use Memberkin\Service\LevelService;

defined( 'ABSPATH' ) || exit;

/**
 * Levels are readable publicly (listed, active levels only); everything else needs memberkin_manage_levels.
 */
final class LevelsController extends RestController {

	/**
	 * Routes.
	 */
	public function register_routes(): void {
		$manage = $this->can( Capabilities::MANAGE_LEVELS );
		register_rest_route(
			$this->namespace,
			'/levels',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_levels' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'status'   => array( 'type' => 'string' ),
						'group_id' => array( 'type' => 'integer' ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_level' ),
					'permission_callback' => $manage,
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/levels/(?P<id>\d+)',
			array(
				'args'   => self::id_arg(),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_level' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_level' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_level' ),
					'permission_callback' => $manage,
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/level-groups',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_groups' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_group' ),
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/level-groups/(?P<id>\d+)',
			array(
				'args' => self::id_arg(),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_group' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_group' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_group' ),
					'permission_callback' => $manage,
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
				'memberkin-level',
				array(
					'id'            => 'integer',
					'name'          => 'string',
					'slug'          => 'string',
					'description'   => 'string',
					'group_id'      => 'integer',
					'status'        => array(
						'type' => 'string',
						'enum' => array_keys( Level::statuses() ),
					),
					'allow_signups' => 'boolean',
					'display_order' => 'integer',
					'tax_class'     => 'string',
					'pricing'       => 'object',
					'price_text'    => array(
						'type'     => 'string',
						'readonly' => true,
					),
					'meta'          => array(
						'type'    => 'object',
						'context' => array( 'edit' ),
					),
				)
			)
		);
	}

	/**
	 * Level JSON (managers also get meta).
	 *
	 * @param Level $level Level.
	 */
	private function level_data( Level $level ): array {
		$data = $level->jsonSerialize();
		if ( current_user_can( Capabilities::MANAGE_LEVELS ) ) {
			$repo         = memberkin()->get( LevelRepository::class );
			$data['meta'] = array();
			foreach ( array_keys( LevelService::meta_fields() ) as $key ) {
				$data['meta'][ $key ] = $repo->meta( $level->id, $key, null );
			}
			$data['member_count'] = memberkin()->get( LevelService::class )->membership_count( $level->id );
		}
		return $data;
	}

	/**
	 * GET /levels.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function list_levels( \WP_REST_Request $request ): \WP_REST_Response {
		$levels = memberkin()->get( LevelRepository::class )->all();
		if ( ! current_user_can( Capabilities::MANAGE_LEVELS ) ) {
			$levels = array_filter( $levels, static fn( Level $l ) => $l->is_listed() );
		} elseif ( $request['status'] ) {
			$levels = array_filter( $levels, static fn( Level $l ) => $l->status === $request['status'] );
		}
		if ( $request['group_id'] ) {
			$levels = array_filter( $levels, static fn( Level $l ) => $l->group_id === (int) $request['group_id'] );
		}
		return new \WP_REST_Response( array_values( array_map( array( $this, 'level_data' ), $levels ) ) );
	}

	/**
	 * GET /levels/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_level( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$level = memberkin()->get( LevelRepository::class )->find( (int) $request['id'] );
		if ( ! $level || ( ! current_user_can( Capabilities::MANAGE_LEVELS ) && ! $level->is_listed() ) ) {
			return self::not_found( __( 'Level', 'memberkin' ) );
		}
		return new \WP_REST_Response( $this->level_data( $level ) );
	}

	/**
	 * POST /levels, PUT|PATCH /levels/{id}. Body as LevelService::save() (decimal prices or minor units).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function save_level( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = self::body( $request );
		unset( $input['id'] );
		if ( $request['id'] ) {
			if ( ! memberkin()->get( LevelRepository::class )->find( (int) $request['id'] ) ) {
				return self::not_found( __( 'Level', 'memberkin' ) );
			}
			$input['id'] = (int) $request['id'];
		}
		return $this->run( fn() => $this->level_data( memberkin()->get( LevelService::class )->save( $input ) ), $request['id'] ? 200 : 201 );
	}

	/**
	 * DELETE /levels/{id} (used levels are archived).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function delete_level( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! memberkin()->get( LevelRepository::class )->find( (int) $request['id'] ) ) {
			return self::not_found( __( 'Level', 'memberkin' ) );
		}
		return $this->run(
			static fn() => array(
				'id'     => (int) $request['id'],
				'result' => memberkin()->get( LevelService::class )->delete( (int) $request['id'] ),
			)
		);
	}

	/**
	 * GET /level-groups.
	 */
	public function list_groups(): \WP_REST_Response {
		return new \WP_REST_Response( array_values( array_map( static fn( LevelGroup $g ) => $g->jsonSerialize(), memberkin()->get( LevelGroupRepository::class )->all() ) ) );
	}

	/**
	 * GET /level-groups/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_group( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$group = memberkin()->get( LevelGroupRepository::class )->find( (int) $request['id'] );
		return $group ? new \WP_REST_Response( $group->jsonSerialize() ) : self::not_found( __( 'Level group', 'memberkin' ) );
	}

	/**
	 * POST /level-groups, PUT|PATCH /level-groups/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function save_group( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$input = self::body( $request );
		unset( $input['id'] );
		if ( $request['id'] ) {
			if ( ! memberkin()->get( LevelGroupRepository::class )->find( (int) $request['id'] ) ) {
				return self::not_found( __( 'Level group', 'memberkin' ) );
			}
			$input['id'] = (int) $request['id'];
		}
		return $this->run( static fn() => memberkin()->get( LevelService::class )->save_group( $input )->jsonSerialize(), $request['id'] ? 200 : 201 );
	}

	/**
	 * DELETE /level-groups/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function delete_group( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! memberkin()->get( LevelGroupRepository::class )->find( (int) $request['id'] ) ) {
			return self::not_found( __( 'Level group', 'memberkin' ) );
		}
		return $this->run(
			static function () use ( $request ) {
				memberkin()->get( LevelService::class )->delete_group( (int) $request['id'] );
				return array(
					'id'      => (int) $request['id'],
					'deleted' => true,
				);
			}
		);
	}
}
