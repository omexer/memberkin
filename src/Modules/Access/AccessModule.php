<?php
/**
 * Access module (core).
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Access;

use Memberkin\Core\Container;
use Memberkin\Core\Module;
use Memberkin\Core\Plugin;
use Memberkin\Repository\AccessRepository;
use Memberkin\Service\AccessService;
use Memberkin\Service\MembershipService;

defined( 'ABSPATH' ) || exit;

/**
 * Content restriction: assignments, enforcement, admin.
 */
final class AccessModule extends Module {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'access';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Content access', 'memberkin' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_core(): bool {
		return true;
	}

	/**
	 * Registers services.
	 *
	 * @param Container $c Container.
	 */
	public static function services( Container $c ): void {
		$c->set( AccessRepository::class, static fn() => new AccessRepository() );
		$c->set( AccessService::class, static fn( Container $c ) => new AccessService( $c->get( AccessRepository::class ), $c->get( MembershipService::class ) ) );
		$c->set( ContentGuard::class, static fn( Container $c ) => new ContentGuard( $c->get( AccessService::class ) ) );
		$c->set( QueryFilter::class, static fn( Container $c ) => new QueryFilter( $c->get( AccessRepository::class ), $c->get( MembershipService::class ) ) );
	}

	/**
	 * Registers hooks and services.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function register( Plugin $plugin ): void {
		self::services( $plugin->container() );
		$plugin->get( ContentGuard::class )->register();
		$plugin->get( QueryFilter::class )->register();
		( new RestrictShortcode( $plugin->get( ContentGuard::class ) ) )->register();

		// Deleting a post or term removes its assignments.
		add_action( 'deleted_post', static fn( $post_id ) => $plugin->get( AccessRepository::class )->set_levels( 'post', (int) $post_id, array() ) );
		add_action( 'delete_term', static fn( $term_id ) => $plugin->get( AccessRepository::class )->set_levels( 'term', (int) $term_id, array() ) );

		if ( is_admin() ) {
			( new Admin\ContentMetaBox( $plugin ) )->register();
			( new Admin\TermFields( $plugin ) )->register();
		}
	}
}
