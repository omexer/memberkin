<?php
/**
 * Registers the memberkin/v1 controllers.
 *
 * @package Memberkin
 */

namespace Memberkin\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Route registration (the public /quote and /webhooks routes live with checkout and billing).
 */
final class RestApi {

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers every controller.
	 */
	public function register_routes(): void {
		$controllers = array(
			new LevelsController(),
			new MembershipsController(),
			new BillingController(),
			new CouponsController(),
			new AccessController(),
			new MeController(),
		);
		/**
		 * Filters REST controllers (add your own WP_REST_Controller instances).
		 *
		 * @param \WP_REST_Controller[] $controllers Controllers.
		 */
		foreach ( (array) apply_filters( 'memberkin_rest_controllers', $controllers ) as $controller ) {
			$controller->register_routes();
		}
	}
}
