<?php
/**
 * Plugin Name:       Memberkin
 * Plugin URI:        https://github.com/omexer/memberkin
 * Description:       Membership levels, recurring payments with Stripe and PayPal, content restriction, member accounts and emails.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Omexer
 * Author URI:        https://omexer.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       memberkin
 * Domain Path:       /languages
 *
 * @package Memberkin
 */

defined( 'ABSPATH' ) || exit;

define( 'MEMBERKIN_VERSION', '0.1.0' );
define( 'MEMBERKIN_FILE', __FILE__ );
define( 'MEMBERKIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEMBERKIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MEMBERKIN_MIN_PHP', '8.1' );
define( 'MEMBERKIN_MIN_WP', '6.5' );

/**
 * Returns a list of unmet requirement messages (empty when all are met).
 *
 * @return string[]
 */
function memberkin_requirement_errors(): array {
	$errors = array();
	if ( version_compare( PHP_VERSION, MEMBERKIN_MIN_PHP, '<' ) ) {
		/* translators: 1: required PHP version, 2: current PHP version. */
		$errors[] = sprintf( __( 'Memberkin requires PHP %1$s or newer. You are running %2$s.', 'memberkin' ), MEMBERKIN_MIN_PHP, PHP_VERSION );
	}
	if ( version_compare( get_bloginfo( 'version' ), MEMBERKIN_MIN_WP, '<' ) ) {
		/* translators: 1: required WordPress version, 2: current WordPress version. */
		$errors[] = sprintf( __( 'Memberkin requires WordPress %1$s or newer. You are running %2$s.', 'memberkin' ), MEMBERKIN_MIN_WP, get_bloginfo( 'version' ) );
	}
	if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
		$errors[] = __( 'Memberkin requires the PHP sodium extension.', 'memberkin' );
	}
	return $errors;
}

if ( memberkin_requirement_errors() ) {
	add_action(
		'admin_notices',
		static function () {
			foreach ( memberkin_requirement_errors() as $message ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
			}
		}
	);
	return;
}

// Autoloading: Composer when present, otherwise a minimal PSR-4 loader for src/.
if ( is_readable( MEMBERKIN_DIR . 'vendor/autoload.php' ) ) {
	require_once MEMBERKIN_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			if ( ! str_starts_with( $class_name, 'Memberkin\\' ) ) {
				return;
			}
			$file = MEMBERKIN_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( 'Memberkin\\' ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

// Action Scheduler must be loaded from the main plugin file so its version resolver runs before plugins_loaded.
if ( is_readable( MEMBERKIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once MEMBERKIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

require_once MEMBERKIN_DIR . 'src/functions.php';

register_activation_hook( __FILE__, array( \Memberkin\Core\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Memberkin\Core\Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Memberkin\Core\Plugin::instance(), 'boot' ), 5 );
