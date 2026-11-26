<?php
/**
 * Top-level "Memberships" admin menu.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Foundation;

use Memberkin\Core\Capabilities;
use Memberkin\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Collects pages from modules (via the "memberkin_admin_pages" filter) and registers them in position order.
 */
final class AdminMenu {

	public const SLUG = 'memberkin';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Settings screen.
	 *
	 * @var SettingsScreen
	 */
	private SettingsScreen $settings;

	/**
	 * Constructor.
	 *
	 * @param Plugin         $plugin   Plugin.
	 * @param SettingsScreen $settings Settings screen.
	 */
	public function __construct( Plugin $plugin, SettingsScreen $settings ) {
		$this->plugin   = $plugin;
		$this->settings = $settings;
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Admin pages: slug => [ title, menu title, capability, callable, position ].
	 *
	 * @return array<string, array{title:string,menu:string,cap:string,callback:callable,position:int}>
	 */
	public function pages(): array {
		$pages = array(
			'memberkin-settings' => array(
				'title'    => __( 'Membership Settings', 'memberkin' ),
				'menu'     => __( 'Settings', 'memberkin' ),
				'cap'      => Capabilities::MANAGE_SETTINGS,
				'callback' => array( $this->settings, 'render' ),
				'position' => 90,
			),
		);
		/**
		 * Filters admin pages under the Memberships menu.
		 *
		 * @param array $pages slug => [ title, menu, cap, callback, position ].
		 */
		$pages = (array) apply_filters( 'memberkin_admin_pages', $pages );
		uasort( $pages, static fn( $a, $b ) => ( $a['position'] ?? 50 ) <=> ( $b['position'] ?? 50 ) );
		return $pages;
	}

	/**
	 * Registers the menu.
	 */
	public function admin_menu(): void {
		$pages = $this->pages();
		$first = array_key_first( $pages );

		// The top-level entry points at the first page the user can access.
		$parent_cap = Capabilities::VIEW_MEMBERS;
		foreach ( $pages as $page ) {
			if ( current_user_can( $page['cap'] ) ) {
				$parent_cap = $page['cap'];
				break;
			}
		}
		// The top-level slug is the first page's slug so every page keeps a stable URL (admin.php?page=<slug>).
		add_menu_page(
			__( 'Memberships', 'memberkin' ),
			__( 'Memberships', 'memberkin' ),
			$parent_cap,
			$first,
			$pages[ $first ]['callback'],
			'dashicons-groups',
			58
		);
		foreach ( $pages as $slug => $page ) {
			add_submenu_page( $first, $page['title'], $page['menu'], $page['cap'], $slug, $page['callback'] );
		}
	}

	/**
	 * Admin CSS/JS on our screens only.
	 *
	 * @param string $hook_suffix Screen hook.
	 */
	public function enqueue( string $hook_suffix ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! str_starts_with( $page, 'memberkin' ) && ! in_array( $hook_suffix, array( 'index.php', 'post.php', 'post-new.php', 'term.php', 'edit-tags.php', 'user-edit.php', 'profile.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'memberkin-admin', MEMBERKIN_URL . 'assets/css/admin.css', array(), MEMBERKIN_VERSION );
		wp_enqueue_script( 'memberkin-admin', MEMBERKIN_URL . 'assets/js/admin.js', array(), MEMBERKIN_VERSION, true );
	}
}
