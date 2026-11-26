<?php
/**
 * Level restriction fields on category, tag and term screens.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Access\Admin;

use Memberkin\Core\Capabilities;
use Memberkin\Core\Plugin;
use Memberkin\Repository\AccessRepository;
use Memberkin\Repository\LevelRepository;
use Memberkin\Service\AccessService;

defined( 'ABSPATH' ) || exit;

/**
 * Content in a restricted term requires one of its levels.
 */
final class TermFields {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'hooks' ) );
	}

	/**
	 * Per-taxonomy hooks.
	 */
	public function hooks(): void {
		if ( ! current_user_can( Capabilities::MANAGE_RULES ) ) {
			return;
		}
		foreach ( AccessService::restrictable_taxonomies() as $taxonomy ) {
			add_action( "{$taxonomy}_add_form_fields", array( $this, 'add_fields' ) );
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'edit_fields' ) );
			add_action( "created_{$taxonomy}", array( $this, 'save' ) );
			add_action( "edited_{$taxonomy}", array( $this, 'save' ) );
		}
	}

	/**
	 * Checkbox list.
	 *
	 * @param int[] $selected Selected level ids.
	 */
	private function checkboxes( array $selected ): string {
		$html = '';
		foreach ( $this->plugin->get( LevelRepository::class )->query( array( 'status' => array( 'active', 'hidden' ) ) ) as $level ) {
			$html .= '<label style="display:block;"><input type="checkbox" name="memberkin_access_levels[]" value="' . (int) $level->id . '" ' . checked( in_array( $level->id, $selected, true ), true, false ) . ' /> ' . esc_html( $level->name ) . '</label>';
		}
		return $html . wp_nonce_field( 'memberkin_access_term', '_memberkin_access_nonce', true, false ) . '<input type="hidden" name="memberkin_access_present" value="1" />';
	}

	/**
	 * Add-term form.
	 */
	public function add_fields(): void {
		echo '<div class="form-field"><span>' . esc_html__( 'Membership access', 'memberkin' ) . '</span>' . $this->checkboxes( array() ) . '<p>' . esc_html__( 'Content in this term is only visible to members of the checked levels.', 'memberkin' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in checkboxes().
	}

	/**
	 * Edit-term form.
	 *
	 * @param \WP_Term $term Term.
	 */
	public function edit_fields( $term ): void {
		$selected = array_keys( $this->plugin->get( AccessRepository::class )->levels_for( 'term', (int) $term->term_id ) );
		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Membership access', 'memberkin' ) . '</th><td>' . $this->checkboxes( $selected ) . '<p class="description">' . esc_html__( 'Content in this term is only visible to members of the checked levels.', 'memberkin' ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Saves.
	 *
	 * @param int $term_id Term.
	 */
	public function save( $term_id ): void {
		if ( empty( $_POST['memberkin_access_present'] ) || ! isset( $_POST['_memberkin_access_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_memberkin_access_nonce'] ) ), 'memberkin_access_term' ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::MANAGE_RULES ) ) {
			return;
		}
		$ids    = isset( $_POST['memberkin_access_levels'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['memberkin_access_levels'] ) ) : array();
		$levels = array();
		foreach ( $ids as $id ) {
			if ( $this->plugin->get( LevelRepository::class )->find( $id ) ) {
				$levels[ $id ] = null;
			}
		}
		$this->plugin->get( AccessRepository::class )->set_levels( 'term', (int) $term_id, $levels );
	}
}
