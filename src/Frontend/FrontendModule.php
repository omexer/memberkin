<?php
/**
 * Frontend module (core): shortcodes, pages, assets.
 *
 * @package Memberkin
 */

namespace Memberkin\Frontend;

use Memberkin\Core\Module;
use Memberkin\Core\Plugin;
use Memberkin\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout, confirmation, levels, login, account and profile pages.
 */
final class FrontendModule extends Module {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'frontend';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Member pages', 'memberkin' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_core(): bool {
		return true;
	}

	/**
	 * Registers hooks.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function register( Plugin $plugin ): void {
		( new CheckoutController() )->register();
		( new LevelsController() )->register();
		( new LoginController() )->register();
		( new AccountController() )->register();
		( new PageSetup() )->register();
		( new \Memberkin\Blocks\Blocks() )->register();

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'template_redirect', array( $this, 'no_cache' ), 0 );
	}

	/**
	 * Registers (not enqueues) assets; shortcodes enqueue what they use.
	 */
	public function register_assets(): void {
		wp_register_style( 'memberkin-frontend', MEMBERKIN_URL . 'assets/css/frontend.css', array(), MEMBERKIN_VERSION );
		wp_register_script( 'memberkin-checkout', MEMBERKIN_URL . 'assets/js/checkout.js', array(), MEMBERKIN_VERSION, true );
		wp_localize_script(
			'memberkin-checkout',
			'memberkinCheckout',
			array(
				'processing' => __( 'Processing…', 'memberkin' ),
				'error'      => __( 'The price could not be updated. Please try again.', 'memberkin' ),
			)
		);
	}

	/**
	 * Member pages must never be served from a page cache.
	 */
	public function no_cache(): void {
		if ( ! is_singular( 'page' ) ) {
			return;
		}
		$pages = Settings::section( 'pages' );
		/**
		 * Filters the member pages (keys of the "pages" settings) that must never be cached.
		 *
		 * @param string[] $keys Page keys.
		 */
		$keys = (array) apply_filters( 'memberkin_no_cache_pages', array( 'checkout', 'confirmation', 'account', 'login', 'profile' ) );
		if ( in_array( get_queried_object_id(), array_map( 'intval', array_intersect_key( $pages, array_flip( $keys ) ) ), true ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- standard constant read by page-cache plugins.
			}
			nocache_headers();
		}
	}
}
