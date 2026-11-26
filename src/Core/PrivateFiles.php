<?php
/**
 * Private working directory inside uploads.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The uploads/memberkin-private folder (deny-all .htaccess for Apache; nginx sites should block the folder, see DEVELOPER.md).
 * Files are served only through capability-checked handlers and use unguessable names.
 */
final class PrivateFiles {

	/**
	 * Directory path (created on demand), optionally a sub-folder.
	 *
	 * @param string $sub Sub-folder (letters, digits, dashes).
	 */
	public static function dir( string $sub = '' ): string {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] ) . 'memberkin-private';
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
		if ( ! file_exists( $base . '/.htaccess' ) ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $base . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $base . '/index.html', '' );
			// phpcs:enable
		}
		$sub = preg_replace( '/[^a-z0-9-]/', '', strtolower( $sub ) );
		if ( '' === $sub ) {
			return $base;
		}
		$dir = $base . '/' . $sub;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/**
	 * Whether files in the private folder can be downloaded over HTTP (true = exposed, false = blocked, null = the
	 * check could not run). Uses a temporary probe file and a loopback request; cached for 12 hours.
	 *
	 * @param bool $fresh Ignore the cached result.
	 */
	public static function publicly_reachable( bool $fresh = false ): ?bool {
		$cached = get_transient( 'memberkin_private_exposed' );
		if ( ! $fresh && false !== $cached ) {
			return 'unknown' === $cached ? null : 'yes' === $cached;
		}
		$token  = Crypto::token( 16 );
		$name   = 'probe-' . strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $token ) ) . '.txt';
		$path   = self::dir() . '/' . $name;
		$url    = trailingslashit( wp_get_upload_dir()['baseurl'] ) . 'memberkin-private/' . $name;
		$result = null;
		if ( false !== file_put_contents( $path, $token ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 5,
					'redirection' => 0,
					'sslverify'   => false, // Loopback to this site; many local setups use self-signed certificates.
				)
			);
			wp_delete_file( $path );
			if ( ! is_wp_error( $response ) ) {
				$result = 200 === (int) wp_remote_retrieve_response_code( $response ) && str_contains( (string) wp_remote_retrieve_body( $response ), $token );
			}
		}
		set_transient( 'memberkin_private_exposed', null === $result ? 'unknown' : ( $result ? 'yes' : 'no' ), 12 * HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * The nginx rule that blocks the private folder.
	 */
	public static function nginx_rule(): string {
		return 'location ^~ ' . untrailingslashit( (string) wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH ) ) . '/memberkin-private/ { deny all; return 404; }';
	}

	/**
	 * Whether a path is inside the private directory (guards every read/delete).
	 *
	 * @param string $path Path.
	 */
	public static function contains( string $path ): bool {
		$real = realpath( $path );
		$base = realpath( self::dir() );
		return false !== $real && false !== $base && str_starts_with( $real, $base . DIRECTORY_SEPARATOR );
	}

	/**
	 * Deletes files older than a number of days in a sub-folder.
	 *
	 * @param string $sub  Sub-folder.
	 * @param int    $days Age.
	 */
	public static function prune( string $sub, int $days ): int {
		$count = 0;
		foreach ( (array) glob( self::dir( $sub ) . '/*.csv' ) as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < time() - $days * DAY_IN_SECONDS ) {
				wp_delete_file( $file );
				++$count;
			}
		}
		return $count;
	}
}
