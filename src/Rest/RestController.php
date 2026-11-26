<?php
/**
 * Base REST controller.
 *
 * @package Memberkin
 */

namespace Memberkin\Rest;

use Memberkin\Core\LockException;
use Memberkin\Core\ValidationException;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers: capability permission callbacks, exception → WP_Error mapping, pagination headers and schemas.
 * Authentication is WordPress's own (cookie + X-WP-Nonce, or Application Passwords).
 */
abstract class RestController extends \WP_REST_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'memberkin/v1';

	/**
	 * Permission callback requiring a capability.
	 *
	 * @param string $cap Capability.
	 * @return callable(\WP_REST_Request): (true|\WP_Error)
	 */
	protected function can( string $cap ): callable {
		return static function () use ( $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
			return self::denied();
		};
	}

	/**
	 * 401 for guests, 403 otherwise.
	 */
	public static function denied(): \WP_Error {
		return is_user_logged_in()
			? new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'memberkin' ), array( 'status' => 403 ) )
			: new \WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'memberkin' ), array( 'status' => 401 ) );
	}

	/**
	 * 404 error.
	 *
	 * @param string $what Resource label.
	 */
	protected static function not_found( string $what ): \WP_Error {
		/* translators: %s: resource name. */
		return new \WP_Error( 'memberkin_not_found', sprintf( __( '%s not found.', 'memberkin' ), $what ), array( 'status' => 404 ) );
	}

	/**
	 * Runs a callback, mapping domain exceptions to REST errors.
	 *
	 * @param callable $callback Callback returning response data.
	 * @param int      $status   Success status.
	 */
	protected function run( callable $callback, int $status = 200 ): \WP_REST_Response|\WP_Error {
		try {
			$result = $callback();
		} catch ( ValidationException $e ) {
			return $e->to_wp_error();
		} catch ( LockException $e ) {
			return new \WP_Error( 'memberkin_busy', __( 'This record is being changed by another request. Try again.', 'memberkin' ), array( 'status' => 409 ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'memberkin_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}
		if ( $result instanceof \WP_REST_Response || $result instanceof \WP_Error ) {
			return $result;
		}
		return new \WP_REST_Response( $result, $status );
	}

	/**
	 * Paginated list response with X-WP-Total / X-WP-TotalPages.
	 *
	 * @param array $items    Items.
	 * @param int   $total    Total rows.
	 * @param int   $per_page Page size.
	 */
	protected function paginated( array $items, int $total, int $per_page ): \WP_REST_Response {
		$response = new \WP_REST_Response( array_values( $items ) );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / max( 1, $per_page ) ) ) );
		return $response;
	}

	/**
	 * Collection params (page, per_page, search) plus extras.
	 *
	 * @param array $extra Extra args.
	 */
	protected function list_args( array $extra = array() ): array {
		return array_merge(
			array(
				'page'     => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 1,
					'maximum' => 100,
				),
			),
			$extra
		);
	}

	/**
	 * Route id argument.
	 */
	protected static function id_arg(): array {
		return array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);
	}

	/**
	 * Builds a JSON schema.
	 *
	 * @param string $title      Title.
	 * @param array  $properties name => type (string or array definition).
	 */
	protected function make_schema( string $title, array $properties ): array {
		$props = array();
		foreach ( $properties as $name => $def ) {
			$props[ $name ] = is_array( $def ) ? $def : array( 'type' => $def );
			if ( 'id' === $name ) {
				$props[ $name ]['readonly'] = true;
			}
		}
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => $title,
			'type'       => 'object',
			'properties' => $props,
		);
	}

	/**
	 * Request body without route/query control keys.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	protected static function body( \WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || ! $params ) {
			$params = $request->get_body_params();
		}
		unset( $params['_wpnonce'], $params['_method'] );
		return (array) $params;
	}

	/**
	 * Parses an optional ISO 8601 / MySQL date-time.
	 *
	 * @param mixed  $value Value.
	 * @param string $field Field (for errors).
	 * @throws ValidationException When invalid.
	 */
	protected static function date( mixed $value, string $field ): ?\DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$ts = is_string( $value ) ? strtotime( $value ) : false;
		if ( false === $ts ) {
			throw new ValidationException( array( esc_html( $field ) => esc_html__( 'Enter a valid date.', 'memberkin' ) ) );
		}
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
	}
}
