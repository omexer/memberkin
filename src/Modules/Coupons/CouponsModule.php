<?php
/**
 * Coupons module.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Coupons;

use Memberkin\Core\Capabilities;
use Memberkin\Core\Container;
use Memberkin\Core\Module;
use Memberkin\Core\Plugin;
use Memberkin\Repository\CouponRepository;
use Memberkin\Repository\LevelRepository;
use Memberkin\Service\CouponService;
use Memberkin\Service\LevelService;

defined( 'ABSPATH' ) || exit;

/**
 * Discount codes.
 */
final class CouponsModule extends Module {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'coupons';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Coupons', 'memberkin' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return __( 'Discount codes with usage limits, recurring discounts, trial overrides and per-level prices.', 'memberkin' );
	}

	/**
	 * Registers services.
	 *
	 * @param Container $c Container.
	 */
	public static function services( Container $c ): void {
		$c->set( CouponRepository::class, static fn() => new CouponRepository() );
		$c->set( CouponService::class, static fn( Container $c ) => new CouponService( $c->get( CouponRepository::class ), $c->get( LevelRepository::class ), $c->get( LevelService::class ) ) );
	}

	/**
	 * Registers hooks and services.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function register( Plugin $plugin ): void {
		self::services( $plugin->container() );
		if ( is_admin() ) {
			$screen = new CouponsScreen( $plugin );
			$screen->register();
			add_filter(
				'memberkin_admin_pages',
				static function ( array $pages ) use ( $screen ): array {
					$pages['memberkin-coupons'] = array(
						'title'    => __( 'Coupons', 'memberkin' ),
						'menu'     => __( 'Coupons', 'memberkin' ),
						'cap'      => Capabilities::MANAGE_COUPONS,
						'callback' => array( $screen, 'render' ),
						'position' => 60,
					);
					return $pages;
				}
			);
		}
	}
}
