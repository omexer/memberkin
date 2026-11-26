<?php
/**
 * Creates the member pages.
 *
 * @package Memberkin
 */

namespace Memberkin\Frontend;

use Memberkin\Admin\Flash;
use Memberkin\Core\Capabilities;
use Memberkin\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * "Create missing pages" on Settings → Pages.
 */
final class PageSetup {

	/**
	 * Pages: key => [ title, content ].
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function pages(): array {
		/**
		 * Filters the pages created by the setup tool.
		 *
		 * @param array $pages key => [ title, content ].
		 */
		return (array) apply_filters(
			'memberkin_setup_pages',
			array(
				'levels'       => array( __( 'Membership Levels', 'memberkin' ), '<!-- wp:memberkin/levels /-->' ),
				'checkout'     => array( __( 'Checkout', 'memberkin' ), '<!-- wp:memberkin/checkout /-->' ),
				'confirmation' => array( __( 'Welcome', 'memberkin' ), '<!-- wp:memberkin/confirmation /-->' ),
				'account'      => array( __( 'My Account', 'memberkin' ), '<!-- wp:memberkin/account /-->' ),
				'login'        => array( __( 'Log In', 'memberkin' ), '<!-- wp:memberkin/login /-->' ),
				'profile'      => array( __( 'Edit Profile', 'memberkin' ), '<!-- wp:memberkin/profile /-->' ),
			)
		);
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_memberkin_create_pages', array( $this, 'handle' ) );
		add_action( 'memberkin_settings_tab_before', array( $this, 'button' ) );
		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
	}

	/**
	 * Creates pages that are not set up yet. Returns created keys.
	 *
	 * @return string[]
	 */
	public static function create_missing(): array {
		$created = array();
		$updates = array();
		foreach ( self::pages() as $key => $page ) {
			$current = (int) Settings::get( 'pages', $key, 0 );
			if ( $current && 'trash' !== get_post_status( $current ) && get_post( $current ) ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_title'   => $page[0],
					'post_content' => $page[1],
					'post_status'  => 'publish',
					'post_type'    => 'page',
				),
				true
			);
			if ( ! is_wp_error( $id ) ) {
				$updates[ $key ] = (int) $id;
				$created[]       = $key;
			}
		}
		if ( $updates ) {
			Settings::update( 'pages', $updates );
		}
		return $created;
	}

	/**
	 * Button above the Pages tab fields.
	 *
	 * @param string $tab Tab.
	 */
	public function button( string $tab ): void {
		if ( 'pages' !== $tab ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=memberkin_create_pages' ), 'memberkin_create_pages' );
		echo '<p>' . esc_html__( 'Each page needs its shortcode or block. Missing pages can be created automatically:', 'memberkin' ) . ' <a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Create missing pages', 'memberkin' ) . '</a></p>';
	}

	/**
	 * Handles the button.
	 */
	public function handle(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'memberkin' ), 403 );
		}
		check_admin_referer( 'memberkin_create_pages' );
		$created = self::create_missing();
		/* translators: %d: number of pages. */
		Flash::add( $created ? sprintf( _n( '%d page created.', '%d pages created.', count( $created ), 'memberkin' ), count( $created ) ) : __( 'All pages already exist.', 'memberkin' ) );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'memberkin-settings',
					'tab'     => 'pages',
					'updated' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Labels our pages in the Pages list ("Membership Checkout Page").
	 *
	 * @param array    $states States.
	 * @param \WP_Post $post   Post.
	 * @return array
	 */
	public function post_states( array $states, $post ): array {
		if ( 'page' !== $post->post_type ) {
			return $states;
		}
		foreach ( self::pages() as $key => $page ) {
			if ( (int) Settings::get( 'pages', $key, 0 ) === $post->ID ) {
				/* translators: %s: page purpose. */
				$states[ 'memberkin_' . $key ] = sprintf( __( 'Membership: %s', 'memberkin' ), $page[0] );
			}
		}
		return $states;
	}
}
