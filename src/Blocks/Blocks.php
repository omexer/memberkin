<?php
/**
 * Blocks.
 *
 * @package Memberkin
 */

namespace Memberkin\Blocks;

use Memberkin\Domain\Level;
use Memberkin\Modules\Access\RestrictShortcode;
use Memberkin\Repository\LevelGroupRepository;
use Memberkin\Repository\LevelRepository;

defined( 'ABSPATH' ) || exit;

/**
 * One server-rendered block per frontend feature (metadata in /blocks/{name}/block.json). Each block renders through
 * its shortcode's handler and the do_shortcode_tag filter, so a block and its shortcode always show the same thing
 * (and modules that extend a shortcode, such as invite and gift cards on the account page, work in both).
 *
 * The editor script (assets/js/blocks.js) is plain JavaScript using WordPress's own wp.* packages: no build step.
 */
final class Blocks {

	/**
	 * Block → shortcode.
	 */
	public const SHORTCODES = array(
		'levels'          => 'memberkin_levels',
		'level'           => 'memberkin_level',
		'checkout'        => 'memberkin_checkout',
		'confirmation'    => 'memberkin_confirmation',
		'account'         => 'memberkin_account',
		'profile'         => 'memberkin_profile',
		'login'           => 'memberkin_login',
		'members-content' => 'memberkin_restrict',
	);

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ), 20 );
		add_filter( 'block_categories_all', array( $this, 'category' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_data' ) );
	}

	/**
	 * Registers the editor script, the shared style and the blocks.
	 */
	public function register_blocks(): void {
		wp_register_script( 'memberkin-blocks', MEMBERKIN_URL . 'assets/js/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ), MEMBERKIN_VERSION, true );
		wp_set_script_translations( 'memberkin-blocks', 'memberkin', MEMBERKIN_DIR . 'languages' );
		if ( ! wp_style_is( 'memberkin-frontend', 'registered' ) ) {
			wp_register_style( 'memberkin-frontend', MEMBERKIN_URL . 'assets/css/frontend.css', array(), MEMBERKIN_VERSION );
		}
		foreach ( array_keys( self::SHORTCODES ) as $name ) {
			register_block_type(
				MEMBERKIN_DIR . 'blocks/' . $name,
				array( 'render_callback' => fn( array $attributes, string $content = '' ) => $this->render( $name, $attributes, $content ) )
			);
		}
	}

	/**
	 * "Membership" block category.
	 *
	 * @param array $categories Categories.
	 */
	public function category( $categories ): array {
		$categories   = (array) $categories;
		$categories[] = array(
			'slug'  => 'memberkin',
			'title' => __( 'Membership', 'memberkin' ),
			'icon'  => null,
		);
		return $categories;
	}

	/**
	 * Levels and groups for the block settings.
	 */
	public function editor_data(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$levels = array_map(
			static fn( Level $l ) => array(
				'id'   => $l->id,
				'name' => $l->name,
			),
			memberkin()->get( LevelRepository::class )->all()
		);
		$groups = array_map(
			static fn( $g ) => array(
				'id'   => $g->id,
				'name' => $g->name,
			),
			memberkin()->get( LevelGroupRepository::class )->all()
		);
		wp_add_inline_script(
			'memberkin-blocks',
			'window.memberkinBlocks = ' . wp_json_encode(
				array(
					'levels' => $levels,
					'groups' => $groups,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Shortcode attributes of a block.
	 *
	 * @param string $name       Block.
	 * @param array  $attributes Block attributes.
	 * @return array<string, string>
	 */
	public static function shortcode_atts( string $name, array $attributes ): array {
		$ids = static fn( $v ) => implode( ',', array_filter( array_map( 'absint', (array) $v ) ) );
		switch ( $name ) {
			case 'levels':
				return array_filter(
					array(
						'levels' => $ids( $attributes['levels'] ?? array() ),
						'group'  => ! empty( $attributes['group'] ) ? (string) absint( $attributes['group'] ) : '',
					),
					'strlen'
				);
			case 'level':
				return array(
					'level'      => (string) absint( $attributes['level'] ?? 0 ),
					'text'       => sanitize_text_field( (string) ( $attributes['text'] ?? '' ) ),
					'show_name'  => ! isset( $attributes['showName'] ) || $attributes['showName'] ? '1' : '0',
					'show_price' => ! isset( $attributes['showPrice'] ) || $attributes['showPrice'] ? '1' : '0',
				);
			case 'members-content':
				return array(
					'levels'  => $ids( $attributes['levels'] ?? array() ),
					'show_to' => in_array( $attributes['showTo'] ?? '', array( 'members', 'non-members', 'logged-in', 'logged-out' ), true ) ? (string) $attributes['showTo'] : 'members',
					'message' => (string) ( $attributes['message'] ?? 'default' ),
				);
		}
		return array();
	}

	/**
	 * Renders a block through its shortcode.
	 *
	 * @param string $name       Block.
	 * @param array  $attributes Attributes.
	 * @param string $content    Inner blocks (members content).
	 */
	public function render( string $name, array $attributes, string $content = '' ): string {
		global $shortcode_tags;
		$tag = self::SHORTCODES[ $name ] ?? '';
		if ( '' === $tag || ! isset( $shortcode_tags[ $tag ] ) ) {
			return ''; // The module behind it is switched off.
		}
		/**
		 * Filters the shortcode attributes a block renders with (add-ons map their own block attributes).
		 *
		 * @param array<string, string> $atts       Shortcode attributes.
		 * @param string                $name       Block name without namespace.
		 * @param array                 $attributes Block attributes.
		 */
		$atts = (array) apply_filters( 'memberkin_block_shortcode_atts', self::shortcode_atts( $name, $attributes ), $name, $attributes );
		$html = (string) call_user_func( $shortcode_tags[ $tag ], $atts, 'members-content' === $name ? $content : null, $tag );
		/** This filter is documented in wp-includes/shortcodes.php */
		$html = (string) apply_filters( 'do_shortcode_tag', $html, $tag, $atts, array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
		if ( '' === trim( $html ) ) {
			return '';
		}
		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}
}
