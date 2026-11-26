<?php
/**
 * "Membership access" meta box, list column and bulk edit for restrictable post types.
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
 * Per-post level assignments.
 */
final class ContentMetaBox {

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
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'columns' ) );
		add_action( 'bulk_edit_custom_box', array( $this, 'bulk_box' ), 10, 2 );
	}

	/**
	 * Adds the meta box.
	 */
	public function add(): void {
		if ( ! current_user_can( Capabilities::MANAGE_RULES ) ) {
			return;
		}
		foreach ( AccessService::restrictable_post_types() as $type ) {
			add_meta_box( 'memberkin-access', __( 'Membership access', 'memberkin' ), array( $this, 'render' ), $type, 'side' );
		}
	}

	/**
	 * Renders the meta box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render( \WP_Post $post ): void {
		$assigned = $this->plugin->get( AccessRepository::class )->levels_for( 'post', $post->ID );
		$levels   = $this->plugin->get( LevelRepository::class )->query( array( 'status' => array( 'active', 'hidden' ) ) );
		wp_nonce_field( 'memberkin_access_post_' . $post->ID, '_memberkin_access_nonce' );
		echo '<input type="hidden" name="memberkin_access_present" value="1" />';
		if ( ! $levels ) {
			echo '<p>' . esc_html__( 'Create a membership level first.', 'memberkin' ) . '</p>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'Only members of the checked levels can see this content. Leave all unchecked for public content (category rules and access rules may still apply).', 'memberkin' ) . '</p>';
		foreach ( $levels as $level ) {
			$checked = array_key_exists( $level->id, $assigned );
			echo '<p><label><input type="checkbox" name="memberkin_access_levels[]" value="' . (int) $level->id . '" ' . checked( $checked, true, false ) . ' /> ' . esc_html( $level->name ) . '</label>';
			/**
			 * Fires after a level's checkbox in the "Membership access" meta box (add-ons print per-level options).
			 *
			 * @param \Memberkin\Domain\Level $level    Level.
			 * @param array<int, int|null>   $assigned Current assignments: level id => delay in days (null = none).
			 * @param \WP_Post               $post     Post.
			 */
			do_action( 'memberkin_content_access_level_fields', $level, $assigned, $post );
			echo '</p>';
		}
	}

	/**
	 * Saves the meta box (and bulk edit).
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post.
	 */
	public function save( $post_id, $post ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! in_array( $post->post_type, AccessService::restrictable_post_types(), true ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::MANAGE_RULES ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$repo = $this->plugin->get( AccessRepository::class );

		// Bulk edit (nonce verified by WordPress for bulk-posts).
		// phpcs:disable WordPress.Security.NonceVerification -- bulk edit is verified by core (check_admin_referer( 'bulk-posts' )).
		if ( isset( $_REQUEST['bulk_edit'], $_REQUEST['memberkin_bulk_access'] ) && '' !== $_REQUEST['memberkin_bulk_access'] ) {
			$mode   = sanitize_key( wp_unslash( $_REQUEST['memberkin_bulk_access'] ) );
			$level  = isset( $_REQUEST['memberkin_bulk_level'] ) ? absint( $_REQUEST['memberkin_bulk_level'] ) : 0;
			$levels = $repo->levels_for( 'post', (int) $post_id );
			if ( 'add' === $mode && $level ) {
				$levels[ $level ] = $levels[ $level ] ?? null;
			} elseif ( 'remove' === $mode && $level ) {
				unset( $levels[ $level ] );
			} elseif ( 'clear' === $mode ) {
				$levels = array();
			}
			$repo->set_levels( 'post', (int) $post_id, $levels );
			return;
		}
		// phpcs:enable

		if ( empty( $_POST['memberkin_access_present'] ) || ! isset( $_POST['_memberkin_access_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_memberkin_access_nonce'] ) ), 'memberkin_access_post_' . $post_id ) ) {
			return;
		}
		$ids     = isset( $_POST['memberkin_access_levels'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['memberkin_access_levels'] ) ) : array();
		$current = $repo->levels_for( 'post', (int) $post_id );
		$levels  = array();
		foreach ( $ids as $id ) {
			if ( $this->plugin->get( LevelRepository::class )->find( $id ) ) {
				$levels[ $id ] = $current[ $id ] ?? null; // Keep a delay set by an add-on.
			}
		}
		/**
		 * Filters the level assignments saved from the "Membership access" meta box (the nonce was verified).
		 *
		 * @param array<int, int|null> $levels  Level id => delay in days (null = none).
		 * @param int                  $post_id Post.
		 */
		$levels = (array) apply_filters( 'memberkin_save_content_access_levels', $levels, (int) $post_id );
		$repo->set_levels( 'post', (int) $post_id, $levels );
	}

	/**
	 * Registers list columns.
	 */
	public function columns(): void {
		if ( ! current_user_can( Capabilities::MANAGE_RULES ) ) {
			return;
		}
		foreach ( AccessService::restrictable_post_types() as $type ) {
			add_filter(
				"manage_{$type}_posts_columns",
				static function ( array $columns ): array {
					$columns['memberkin_access'] = __( 'Access', 'memberkin' );
					return $columns;
				}
			);
			add_action( "manage_{$type}_posts_custom_column", array( $this, 'column' ), 10, 2 );
		}
	}

	/**
	 * Column value.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post.
	 */
	public function column( $column, $post_id ): void {
		if ( 'memberkin_access' !== $column ) {
			return;
		}
		$levels = $this->plugin->get( LevelRepository::class );
		$names  = array();
		foreach ( array_keys( $this->plugin->get( AccessRepository::class )->levels_for( 'post', (int) $post_id ) ) as $level_id ) {
			$level   = $levels->find( $level_id );
			$names[] = $level ? $level->name : '#' . $level_id;
		}
		echo $names ? esc_html( implode( ', ', $names ) ) : '<span class="memberkin-muted">—</span>';
	}

	/**
	 * Bulk edit controls.
	 *
	 * @param string $column    Column.
	 * @param string $post_type Post type.
	 */
	public function bulk_box( $column, $post_type ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- hook signature.
		if ( 'memberkin_access' !== $column ) {
			return;
		}
		echo '<fieldset class="inline-edit-col-right"><div class="inline-edit-col"><span class="title">' . esc_html__( 'Membership access', 'memberkin' ) . '</span> ';
		echo '<select name="memberkin_bulk_access"><option value="">' . esc_html__( '— No change —', 'memberkin' ) . '</option><option value="add">' . esc_html__( 'Add level', 'memberkin' ) . '</option><option value="remove">' . esc_html__( 'Remove level', 'memberkin' ) . '</option><option value="clear">' . esc_html__( 'Make public', 'memberkin' ) . '</option></select> ';
		echo '<select name="memberkin_bulk_level">';
		foreach ( $this->plugin->get( LevelRepository::class )->all() as $level ) {
			echo '<option value="' . (int) $level->id . '">' . esc_html( $level->name ) . '</option>';
		}
		echo '</select></div></fieldset>';
	}
}
