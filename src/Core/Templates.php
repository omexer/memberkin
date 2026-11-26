<?php
/**
 * Template loader with theme overrides.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Looks up templates in child theme, parent theme, then the plugin's templates/ directory.
 */
final class Templates {

	/**
	 * Absolute path of a template, or '' if not found.
	 *
	 * @param string $name Relative path without extension, e.g. "account/memberships".
	 */
	public static function locate( string $name ): string {
		$name = trim( str_replace( array( '..', '\\' ), array( '', '/' ), $name ), '/' ) . '.php';

		$candidates = array(
			trailingslashit( get_stylesheet_directory() ) . 'memberkin/' . $name,
			trailingslashit( get_template_directory() ) . 'memberkin/' . $name,
			MEMBERKIN_DIR . 'templates/' . $name,
		);
		$found      = '';
		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				$found = $path;
				break;
			}
		}
		/**
		 * Filters the resolved template path.
		 *
		 * @param string $found Path ('' when not found).
		 * @param string $name  Requested template.
		 */
		return (string) apply_filters( 'memberkin_template_path', $found, $name );
	}

	/**
	 * Renders a template and returns the output.
	 *
	 * @param string $name Template name.
	 * @param array  $vars Variables available to the template as $args and extracted names.
	 */
	public static function render( string $name, array $vars = array() ): string {
		$path = self::locate( $name );
		if ( '' === $path ) {
			return '';
		}
		ob_start();
		( static function ( string $memberkin_template_path, array $args ): void {
			extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template variables, documented per template.
			include $memberkin_template_path;
		} )( $path, $vars );
		return (string) ob_get_clean();
	}

	/**
	 * Version declared in a template's "@version" header.
	 *
	 * @param string $path File path.
	 */
	public static function version_of( string $path ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}
		// Docblock tag "@version 1.2.3" in the first 8 KB (get_file_data() only reads "Name: value" headers).
		$head = (string) file_get_contents( $path, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return preg_match( '/^[ \t\/*#]*@version[ \t]+([0-9A-Za-z.\-]+)/m', $head, $m ) ? $m[1] : '';
	}
}
