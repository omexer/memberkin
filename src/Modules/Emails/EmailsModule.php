<?php
/**
 * Emails module (core).
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Emails;

use Memberkin\Core\Capabilities;
use Memberkin\Core\Module;
use Memberkin\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Templates, queue, log and notifications.
 */
final class EmailsModule extends Module {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'emails';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Emails', 'memberkin' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_core(): bool {
		return true;
	}

	/**
	 * Registers hooks and services.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function register( Plugin $plugin ): void {
		$plugin->container()->set( EmailService::class, static fn() => new EmailService() );
		( new Notifications( $plugin->get( EmailService::class ) ) )->register();
		add_action( 'memberkin_send_email', static fn( $log_id ) => $plugin->get( EmailService::class )->deliver( (int) $log_id ) );

		if ( is_admin() ) {
			$screen = new Admin\EmailsScreen( $plugin );
			$screen->register();
			add_filter(
				'memberkin_admin_pages',
				static function ( array $pages ) use ( $screen ): array {
					$pages[ Admin\EmailsScreen::PAGE ] = array(
						'title'    => __( 'Emails', 'memberkin' ),
						'menu'     => __( 'Emails', 'memberkin' ),
						'cap'      => Capabilities::MANAGE_EMAILS,
						'callback' => array( $screen, 'render' ),
						'position' => 70,
					);
					return $pages;
				}
			);
		}
	}
}
