<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and Memberkin.
 *
 * @package Memberkin
 */

$memberkin_root = dirname( __DIR__ );
require_once $memberkin_root . '/vendor/autoload.php';
require_once $memberkin_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
}
if ( getenv( 'WP_MULTISITE' ) ) {
	define( 'WP_TESTS_MULTISITE', true );
}

$memberkin_wp_tests = getenv( 'WP_PHPUNIT__DIR' ) ?: $memberkin_root . '/vendor/wp-phpunit/wp-phpunit';
require_once $memberkin_wp_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $memberkin_root ) {
		require $memberkin_root . '/memberkin.php';
	}
);

// Tests write files (exports, imports, field uploads, protected media): keep them out of the site's real uploads.
$memberkin_test_uploads = sys_get_temp_dir() . '/memberkin-test-uploads-' . ( getenv( 'WP_MULTISITE' ) ? 'ms' : 'single' );
if ( is_dir( $memberkin_test_uploads ) ) {
	$memberkin_items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $memberkin_test_uploads, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $memberkin_items as $memberkin_item ) {
		$memberkin_item->isDir() ? rmdir( $memberkin_item->getPathname() ) : unlink( $memberkin_item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
tests_add_filter(
	'upload_dir',
	static function ( array $dirs ) use ( $memberkin_test_uploads ): array {
		$real            = $dirs['basedir'];
		$dirs['basedir'] = $memberkin_test_uploads . substr( $real, strlen( WP_CONTENT_DIR . '/uploads' ) );
		$dirs['path']    = $dirs['basedir'] . $dirs['subdir'];
		return $dirs;
	},
	1000
);

// The test gateway lives with the tests, not in the plugin.
tests_add_filter(
	'memberkin_register_gateways',
	static function ( $registry ) {
		if ( \Memberkin\Tests\Support\TestGateway::allowed() ) {
			$registry->register( new \Memberkin\Tests\Support\TestGateway() );
		}
		$registry->register( new \Memberkin\Tests\Support\ManualGateway() );
	}
);

tests_add_filter(
	'plugins_loaded',
	static function () {
		// Install tables, caps and defaults into the (freshly recreated) test database.
		\Memberkin\Core\Installer::install_site();
		\Memberkin\Core\Jobs::set_sync( true );
	},
	1
);

require $memberkin_wp_tests . '/includes/bootstrap.php';
require_once __DIR__ . '/TestCase.php';
