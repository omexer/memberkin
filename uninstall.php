<?php
/**
 * Uninstall: removes data only if the admin enabled "Delete all data on uninstall" (per site).
 *
 * @package Memberkin
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			if ( str_starts_with( $class_name, 'Memberkin\\' ) ) {
				$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, 15 ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		}
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $memberkin_site_id ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		switch_to_blog( (int) $memberkin_site_id );
		\Memberkin\Core\Uninstaller::uninstall_site();
		restore_current_blog();
	}
} else {
	\Memberkin\Core\Uninstaller::uninstall_site();
}
