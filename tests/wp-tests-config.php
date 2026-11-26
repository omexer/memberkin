<?php
/**
 * WordPress test-suite configuration.
 *
 * Uses the separate database "membership_test" (override with MEMBERKIN_TEST_DB_NAME). Credentials are read from the
 * site's wp-config.php (or MEMBERKIN_TEST_DB_USER / MEMBERKIN_TEST_DB_PASSWORD / MEMBERKIN_TEST_DB_HOST env vars), so no
 * secrets are stored in this repository. The test suite DROPS AND RECREATES all tables in the test database.
 *
 * @package Memberkin
 */

$memberkin_site_root = dirname( __DIR__, 4 ) . '/';
$memberkin_site_cfg  = is_readable( $memberkin_site_root . 'wp-config.php' ) ? (string) file_get_contents( $memberkin_site_root . 'wp-config.php' ) : '';

$memberkin_read = static function ( string $name, string $fallback ) use ( $memberkin_site_cfg ): string {
	if ( preg_match( "/define\(\s*['\"]" . $name . "['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)/", $memberkin_site_cfg, $m ) ) {
		return $m[1];
	}
	return $fallback;
};

define( 'ABSPATH', getenv( 'MEMBERKIN_WP_ABSPATH' ) ?: $memberkin_site_root );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', getenv( 'MEMBERKIN_TEST_DB_NAME' ) ?: 'membership_test' );
define( 'DB_USER', getenv( 'MEMBERKIN_TEST_DB_USER' ) ?: $memberkin_read( 'DB_USER', 'root' ) );
define( 'DB_PASSWORD', getenv( 'MEMBERKIN_TEST_DB_PASSWORD' ) ?: $memberkin_read( 'DB_PASSWORD', '' ) );
define( 'DB_HOST', getenv( 'MEMBERKIN_TEST_DB_HOST' ) ?: $memberkin_read( 'DB_HOST', 'localhost' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY', 'memberkin-tests-auth-key' );
define( 'SECURE_AUTH_KEY', 'memberkin-tests-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'memberkin-tests-logged-in-key' );
define( 'NONCE_KEY', 'memberkin-tests-nonce-key' );
define( 'AUTH_SALT', 'memberkin-tests-auth-salt' );
define( 'SECURE_AUTH_SALT', 'memberkin-tests-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'memberkin-tests-logged-in-salt' );
define( 'NONCE_SALT', 'memberkin-tests-nonce-salt' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Memberkin Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'MEMBERKIN_TEST_GATEWAY', true );
define( 'MEMBERKIN_TEST_TEST_WEBHOOK_SECRET', 'whsec_unit_tests_only' );
