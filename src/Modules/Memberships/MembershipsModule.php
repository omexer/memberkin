<?php
/**
 * Memberships module (core).
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Memberships;

use Memberkin\Core\Capabilities;
use Memberkin\Core\Container;
use Memberkin\Core\Module;
use Memberkin\Core\Plugin;
use Memberkin\Domain\Membership;
use Memberkin\Repository\LevelGroupRepository;
use Memberkin\Repository\LevelRepository;
use Memberkin\Repository\MembershipRepository;
use Memberkin\Service\MembershipService;

defined( 'ABSPATH' ) || exit;

/**
 * Membership records, expiry and the Members admin.
 */
final class MembershipsModule extends Module {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'memberships';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Memberships', 'memberkin' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_core(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function recurring_jobs(): array {
		return array(
			'memberkin_expire_memberships' => 15 * MINUTE_IN_SECONDS,
			'memberkin_scheduled_changes'  => 15 * MINUTE_IN_SECONDS,
		);
	}

	/**
	 * Registers services.
	 *
	 * @param Container $c Container.
	 */
	public static function services( Container $c ): void {
		$c->set( MembershipRepository::class, static fn() => new MembershipRepository() );
		$c->set(
			MembershipService::class,
			static fn( Container $c ) => new MembershipService(
				$c->get( MembershipRepository::class ),
				$c->get( LevelRepository::class ),
				$c->get( LevelGroupRepository::class )
			)
		);
	}

	/**
	 * Registers hooks and services.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function register( Plugin $plugin ): void {
		self::services( $plugin->container() );

		add_action( 'memberkin_expire_memberships', static fn() => $plugin->get( MembershipService::class )->expire_due() );
		add_action( 'memberkin_scheduled_changes', static fn() => $plugin->get( MembershipService::class )->resume_due() );
		add_action( 'delete_user', array( $this, 'on_delete_user' ) );
		( new \Memberkin\Service\SignupCleanup() )->register();
		add_action( 'remove_user_from_blog', array( $this, 'on_delete_user' ) );

		if ( is_admin() ) {
			$screen = new MembersScreen( $plugin );
			$screen->register();
			add_filter(
				'memberkin_admin_pages',
				static function ( array $pages ) use ( $screen ): array {
					$pages['memberkin-members'] = array(
						'title'    => __( 'Members', 'memberkin' ),
						'menu'     => __( 'Members', 'memberkin' ),
						'cap'      => Capabilities::VIEW_MEMBERS,
						'callback' => array( $screen, 'render' ),
						'position' => 10,
					);
					return $pages;
				}
			);
			add_filter( 'manage_users_columns', array( $this, 'users_column' ) );
			add_filter( 'manage_users_custom_column', array( $this, 'users_column_value' ), 10, 3 );
		}
	}

	/**
	 * Ends a user's current memberships when the account is deleted (subscriptions are cancelled by the billing module).
	 *
	 * @param int $user_id User id.
	 */
	public function on_delete_user( int $user_id ): void {
		$service = memberkin()->get( MembershipService::class );
		foreach ( memberkin()->get( MembershipRepository::class )->current_for_user( $user_id ) as $m ) {
			try {
				$service->end(
					$m->id,
					'admin',
					array(
						'user_deleted'  => true,
						'skip_fallback' => true,
						'notify'        => false,
					)
				);
			} catch ( \Throwable $e ) {
				// Never block the deletion of a WordPress account (for example when the record is already gone).
				\Memberkin\Core\Logger::error( 'memberships', sprintf( 'Could not end membership #%d of deleted user #%d: %s', $m->id, $user_id, $e->getMessage() ) );
			}
		}
	}

	/**
	 * Adds a "Membership" column to the Users list.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function users_column( array $columns ): array {
		if ( current_user_can( Capabilities::VIEW_MEMBERS ) ) {
			$columns['memberkin_levels'] = __( 'Membership', 'memberkin' );
		}
		return $columns;
	}

	/**
	 * Users list column value.
	 *
	 * @param string $output  Output.
	 * @param string $column  Column.
	 * @param int    $user_id User id.
	 */
	public function users_column_value( string $output, string $column, int $user_id ): string {
		if ( 'memberkin_levels' !== $column ) {
			return $output;
		}
		$levels = memberkin()->get( LevelRepository::class );
		$names  = array();
		foreach ( memberkin()->get( MembershipRepository::class )->current_for_user( $user_id ) as $m ) {
			$level   = $levels->find( $m->level_id );
			$label   = ( $level ? $level->name : '#' . $m->level_id ) . ( Membership::ACTIVE !== $m->status ? ' (' . ( Membership::statuses()[ $m->status ] ?? $m->status ) . ')' : '' );
			$names[] = '<a href="' . esc_url( MembersScreen::member_url( $user_id ) ) . '">' . esc_html( $label ) . '</a>';
		}
		return $names ? implode( '<br />', $names ) : '—';
	}
}
