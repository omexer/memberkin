<?php
/**
 * Levels module (core).
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Levels;

use Memberkin\Core\Capabilities;
use Memberkin\Core\Container;
use Memberkin\Core\Module;
use Memberkin\Core\Plugin;
use Memberkin\Domain\Membership;
use Memberkin\Repository\LevelGroupRepository;
use Memberkin\Repository\LevelRepository;
use Memberkin\Service\LevelService;

defined( 'ABSPATH' ) || exit;

/**
 * Levels, level groups and per-level role mapping.
 */
final class LevelsModule extends Module {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'levels';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Levels', 'memberkin' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_core(): bool {
		return true;
	}

	/**
	 * Registers services (also used by tests and the REST API without the admin).
	 *
	 * @param Container $c Container.
	 */
	public static function services( Container $c ): void {
		$c->set( LevelRepository::class, static fn() => new LevelRepository() );
		$c->set( LevelGroupRepository::class, static fn() => new LevelGroupRepository() );
		$c->set( LevelService::class, static fn( Container $c ) => new LevelService( $c->get( LevelRepository::class ), $c->get( LevelGroupRepository::class ) ) );
	}

	/**
	 * Registers hooks and services.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function register( Plugin $plugin ): void {
		self::services( $plugin->container() );

		// Role mapping: add the level's roles when a membership starts granting access; remove them when it stops.
		add_action( 'memberkin_membership_status_changed', array( $this, 'sync_roles' ), 10, 3 );

		if ( is_admin() ) {
			$screen = new LevelsScreen( $plugin );
			$screen->register();
			add_filter(
				'memberkin_admin_pages',
				static function ( array $pages ) use ( $screen ): array {
					$pages['memberkin-levels'] = array(
						'title'    => __( 'Levels', 'memberkin' ),
						'menu'     => __( 'Levels', 'memberkin' ),
						'cap'      => Capabilities::MANAGE_LEVELS,
						'callback' => array( $screen, 'render' ),
						'position' => 40,
					);
					return $pages;
				}
			);
		}
	}

	/**
	 * Adds/removes WordPress roles configured on the level.
	 *
	 * @param Membership $membership Membership.
	 * @param string     $from       Previous status.
	 * @param string     $to         New status.
	 */
	public function sync_roles( Membership $membership, string $from, string $to ): void {
		$repo  = memberkin()->get( LevelRepository::class );
		$roles = (array) $repo->meta( $membership->level_id, 'roles', array() );
		if ( ! $roles ) {
			return;
		}
		$user = get_userdata( $membership->user_id );
		if ( ! $user ) {
			return;
		}
		$was = Membership::status_grants_access( $from );
		$is  = Membership::status_grants_access( $to );
		if ( ! $was && $is ) {
			foreach ( $roles as $role ) {
				if ( get_role( $role ) && ! in_array( $role, (array) $user->roles, true ) ) {
					$user->add_role( $role );
				}
			}
		} elseif ( $was && ! $is && $repo->meta( $membership->level_id, 'remove_roles_on_end', true ) ) {
			// Keep a role if another current membership still grants it.
			$still = array();
			foreach ( memberkin_get_memberships( $membership->user_id ) as $other ) {
				if ( $other->id !== $membership->id ) {
					$still = array_merge( $still, (array) $repo->meta( $other->level_id, 'roles', array() ) );
				}
			}
			foreach ( array_diff( $roles, $still ) as $role ) {
				$user->remove_role( $role );
			}
			if ( ! $user->roles ) {
				$user->set_role( (string) get_option( 'default_role', 'subscriber' ) );
			}
		}
	}
}
