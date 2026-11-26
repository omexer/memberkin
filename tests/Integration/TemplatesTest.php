<?php
/**
 * Theme template overrides.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\Templates;

/**
 * @covers \Memberkin\Core\Templates
 */
final class TemplatesTest extends \Memberkin\Tests\TestCase {

	private string $theme_dir = '';

	private string $theme_root = '';

	public function set_up(): void {
		parent::set_up();
		$this->theme_root = trailingslashit( get_temp_dir() ) . 'memberkin-theme-' . wp_rand();
		$this->theme_dir  = $this->theme_root . '/memberkin/access';
		add_filter( 'stylesheet_directory', fn() => $this->theme_root );
	}

	public function tear_down(): void {
		if ( is_file( $this->theme_dir . '/restricted.php' ) ) {
			unlink( $this->theme_dir . '/restricted.php' );
		}
		foreach ( array( $this->theme_dir, dirname( $this->theme_dir ), $this->theme_root ) as $dir ) {
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
		parent::tear_down();
	}

	public function test_theme_overrides_plugin_template(): void {
		$plugin = Templates::locate( 'access/restricted' );
		$this->assertStringStartsWith( MEMBERKIN_DIR . 'templates/', $plugin );
		$this->assertNotSame( '', Templates::version_of( $plugin ) );

		wp_mkdir_p( $this->theme_dir );
		file_put_contents( $this->theme_dir . '/restricted.php', "<?php\n/**\n * @version 0.1.0\n */\necho 'THEME:' . esc_html( \$message ?? '' );\n" );
		$this->assertSame( $this->theme_dir . '/restricted.php', Templates::locate( 'access/restricted' ) );
		$this->assertSame( 'THEME:Hello', Templates::render( 'access/restricted', array( 'message' => 'Hello' ) ) );
		$this->assertSame( '0.1.0', Templates::version_of( Templates::locate( 'access/restricted' ) ) );
	}

	public function test_paths_cannot_escape_the_template_folders(): void {
		$this->assertSame( '', Templates::locate( '../../../wp-config' ) );
		$this->assertSame( '', Templates::render( 'does/not/exist' ) );
	}
}
