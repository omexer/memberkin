<?php
/**
 * Settings screen.
 *
 * @package Memberkin
 */

namespace Memberkin\Modules\Foundation;

use Memberkin\Admin\Flash;
use Memberkin\Core\Capabilities;
use Memberkin\Core\Crypto;
use Memberkin\Core\Plugin;
use Memberkin\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders tabbed settings and saves them through admin-post.php.
 */
final class SettingsScreen {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		add_action( 'admin_post_memberkin_save_settings', array( $this, 'save' ) );
	}

	/**
	 * Current tab id.
	 */
	private function current_tab(): string {
		$tabs = SettingsFields::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $tabs[ $tab ] ) ? $tab : (string) array_key_first( $tabs );
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'memberkin' ), 403 );
		}
		$tab  = $this->current_tab();
		$base = admin_url( 'admin.php?page=memberkin-settings' );
		?>
		<div class="wrap memberkin-settings">
			<h1><?php esc_html_e( 'Membership Settings', 'memberkin' ); ?></h1>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['updated'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'memberkin' ); ?></p></div>
			<?php endif; ?>
			<?php Flash::render(); ?>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'memberkin' ); ?>">
				<?php foreach ( SettingsFields::tabs() as $id => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $id, $base ) ); ?>" class="nav-tab <?php echo $id === $tab ? 'nav-tab-active' : ''; ?>" <?php echo $id === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="memberkin_save_settings" />
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
				<?php wp_nonce_field( 'memberkin_save_settings_' . $tab ); ?>
				<?php
				if ( 'modules' === $tab ) {
					$this->render_modules();
				} else {
					/**
					 * Fires before the fields of a settings tab (for custom tab content, e.g. gateways).
					 *
					 * @param string $tab Tab id.
					 */
					do_action( 'memberkin_settings_tab_before', $tab );
					$this->render_fields( $tab );
					/**
					 * Fires after the fields of a settings tab.
					 *
					 * @param string $tab Tab id.
					 */
					do_action( 'memberkin_settings_tab_after', $tab );
				}
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the fields of a tab.
	 *
	 * @param string $tab Tab id.
	 */
	private function render_fields( string $tab ): void {
		$fields = array_filter( SettingsFields::all(), static fn( $f ) => $f['tab'] === $tab );
		if ( ! $fields ) {
			return;
		}
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $fields as $field ) {
			$this->render_field( $field );
		}
		echo '</tbody></table>';
	}

	/**
	 * Renders one field row.
	 *
	 * @param array $field Field.
	 */
	public function render_field( array $field ): void {
		$id       = 'memberkin-' . $field['section'] . '-' . $field['key'];
		$name     = 'memberkin[' . $field['section'] . '][' . $field['key'] . ']';
		$value    = Settings::get( $field['section'], $field['key'] );
		$constant = $field['constant'] ?? '';
		$locked   = $constant && defined( $constant );

		if ( ! empty( $field['heading'] ) ) {
			echo '<tr><th colspan="2" scope="colgroup"><h2 style="margin:1em 0 0;">' . esc_html( $field['heading'] ) . '</h2></th></tr>';
		}
		if ( 'info' === $field['type'] ) {
			// Read-only help row (no input, so never saved). Descriptions of info rows are trusted, plugin-built HTML.
			echo '<tr><th scope="row">' . esc_html( $field['label'] ) . '</th><td><p>' . wp_kses_post( (string) ( $field['description'] ?? '' ) ) . '</p></td></tr>';
			return;
		}
		echo '<tr><th scope="row">';
		if ( 'checkbox' !== $field['type'] ) {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label>';
		} else {
			echo esc_html( $field['label'] );
		}
		echo '</th><td>';

		if ( $locked ) {
			/* translators: %s: constant name. */
			echo '<p><code>' . esc_html( $constant ) . '</code> ' . esc_html__( 'is defined in wp-config.php and overrides this setting.', 'memberkin' ) . '</p>';
			echo '</td></tr>';
			return;
		}

		switch ( $field['type'] ) {
			case 'checkbox':
				echo '<label for="' . esc_attr( $id ) . '"><input type="hidden" name="' . esc_attr( $name ) . '" value="0" /><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( (bool) $value, true, false ) . ' /> ' . esc_html__( 'Enabled', 'memberkin' ) . '</label>';
				break;
			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
				$current_value = is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value;
				foreach ( $field['options'] as $option_value => $option_label ) {
					echo '<option value="' . esc_attr( (string) $option_value ) . '" ' . selected( (string) $option_value, $current_value, false ) . '>' . esc_html( $option_label ) . '</option>';
				}
				echo '</select>';
				break;
			case 'page':
				wp_dropdown_pages(
					array(
						'name'              => esc_attr( $name ),
						'id'                => esc_attr( $id ),
						'selected'          => (int) $value,
						'show_option_none'  => esc_html__( '— Select a page —', 'memberkin' ),
						'option_none_value' => '0',
						'post_status'       => array( 'publish', 'private', 'draft' ),
					)
				);
				if ( $value ) {
					echo ' <a href="' . esc_url( (string) get_edit_post_link( (int) $value ) ) . '">' . esc_html__( 'Edit', 'memberkin' ) . '</a>';
				}
				break;
			case 'textarea':
			case 'html':
				echo '<textarea class="large-text" rows="4" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
				break;
			case 'number':
				echo '<input type="number" class="small-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) (int) $value ) . '"' . ( isset( $field['min'] ) ? ' min="' . (int) $field['min'] . '"' : '' ) . ( isset( $field['max'] ) ? ' max="' . (int) $field['max'] . '"' : '' ) . ' />';
				break;
			case 'int_list':
				echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( implode( ',', (array) $value ) ) . '" />';
				break;
			case 'secret':
				$plain = Crypto::decrypt( (string) $value );
				echo '<input type="password" autocomplete="new-password" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="" placeholder="' . esc_attr( '' !== $plain ? Crypto::mask( $plain ) : '' ) . '" />';
				if ( '' !== $plain ) {
					echo '<p class="description">' . esc_html__( 'Saved. Leave empty to keep it, or enter "-" to remove it.', 'memberkin' ) . '</p>';
				}
				break;
			default:
				$type = in_array( $field['type'], array( 'email', 'url' ), true ) ? $field['type'] : 'text';
				echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" placeholder="' . esc_attr( $field['placeholder'] ?? '' ) . '" />';
		}
		if ( ! empty( $field['description'] ) ) {
			echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
		}
		if ( $constant ) {
			/* translators: %s: constant name. */
			echo '<p class="description">' . wp_kses( sprintf( __( 'Can also be set with %s in wp-config.php.', 'memberkin' ), '<code>' . esc_html( $constant ) . '</code>' ), array( 'code' => array() ) ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Renders module toggles.
	 */
	private function render_modules(): void {
		$registry = $this->plugin->modules();
		echo '<table class="widefat striped memberkin-modules"><thead><tr><th scope="col">' . esc_html__( 'Module', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Description', 'memberkin' ) . '</th><th scope="col">' . esc_html__( 'Enabled', 'memberkin' ) . '</th></tr></thead><tbody>';
		foreach ( $registry->all() as $module ) {
			if ( $module->is_core() ) {
				continue;
			}
			$id = 'memberkin-module-' . $module->id();
			echo '<tr><td><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $module->label() ) . '</strong></label></td><td>' . esc_html( $module->description() );
			if ( ! $module->is_available() ) {
				echo '<br /><em>' . esc_html__( 'Not available: a required plugin is missing.', 'memberkin' ) . '</em>';
			}
			echo '</td><td><input type="hidden" name="memberkin_modules[' . esc_attr( $module->id() ) . ']" value="0" /><input type="checkbox" id="' . esc_attr( $id ) . '" name="memberkin_modules[' . esc_attr( $module->id() ) . ']" value="1" ' . checked( $registry->is_enabled( $module->id() ), true, false ) . disabled( ! $module->is_available(), true, false ) . ' /></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Saves a tab.
	 */
	public function save(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'memberkin' ), 403 );
		}
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		check_admin_referer( 'memberkin_save_settings_' . $tab );

		if ( 'modules' === $tab ) {
			$submitted = isset( $_POST['memberkin_modules'] ) && is_array( $_POST['memberkin_modules'] ) ? wp_unslash( $_POST['memberkin_modules'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value cast to bool below.
			foreach ( $this->plugin->modules()->all() as $module ) {
				if ( ! $module->is_core() && array_key_exists( $module->id(), $submitted ) ) {
					$enable = '1' === (string) $submitted[ $module->id() ];
					if ( ! $enable && $this->plugin->modules()->is_enabled( $module->id() ) ) {
						/**
						 * Return a message to keep a module on (for example while it still holds data that needs it).
						 *
						 * @param string $reason Reason ('' = it may be turned off).
						 * @param string $id     Module id.
						 */
						$reason = (string) apply_filters( 'memberkin_module_disable_blocked', '', $module->id() );
						if ( '' !== $reason ) {
							Flash::add( $reason, 'error' );
							continue;
						}
					}
					$this->plugin->modules()->set_enabled( $module->id(), $enable );
				}
			}
			delete_transient( 'memberkin_jobs_checked' );
			update_option( 'memberkin_flush_rewrite', 1, true );
		} else {
			$raw     = isset( $_POST['memberkin'] ) && is_array( $_POST['memberkin'] ) ? wp_unslash( $_POST['memberkin'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field below.
			$updates = array();
			foreach ( SettingsFields::all() as $field ) {
				if ( $field['tab'] !== $tab ) {
					continue;
				}
				if ( ! empty( $field['constant'] ) && defined( $field['constant'] ) ) {
					continue;
				}
				$section = $field['section'];
				if ( ! isset( $raw[ $section ] ) || ! array_key_exists( $field['key'], (array) $raw[ $section ] ) ) {
					continue;
				}
				$updates[ $section ][ $field['key'] ] = SettingsFields::sanitize( $field, $raw[ $section ][ $field['key'] ], Settings::get( $section, $field['key'] ) );
			}
			foreach ( $updates as $section => $values ) {
				Settings::update( $section, $values );
			}
			/**
			 * Fires after a settings tab was saved (for custom tab content).
			 *
			 * @param string $tab Tab id.
			 * @param array  $raw Unslashed submitted data (unsanitised).
			 */
			do_action( 'memberkin_settings_saved', $tab, $raw );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'memberkin-settings',
					'tab'     => $tab,
					'updated' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
