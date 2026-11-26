<?php
/**
 * Clone/staging protection.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * When the site's fingerprint (home URL + database name + table prefix) differs from the stored one,
 * the site is probably a copy. Safe mode then pauses renewals, retries, member emails, outgoing
 * webhooks and webhook processing until an admin confirms.
 */
final class SafeMode {

	public const OPTION       = 'memberkin_site_fingerprint';
	public const FORCE_OPTION = 'memberkin_safe_mode';

	/**
	 * Current fingerprint.
	 */
	public static function fingerprint(): string {
		global $wpdb;
		$home = untrailingslashit( preg_replace( '#^https?://#i', '', (string) get_option( 'home' ) ) );
		return hash( 'sha256', strtolower( $home ) . '|' . DB_NAME . '|' . $wpdb->prefix );
	}

	/**
	 * Stores the fingerprint on first install.
	 */
	public static function remember_fingerprint_if_missing(): void {
		if ( ! get_option( self::OPTION ) ) {
			update_option( self::OPTION, self::fingerprint(), true );
		}
	}

	/**
	 * Whether safe mode is active.
	 */
	public static function active(): bool {
		if ( defined( 'MEMBERKIN_SAFE_MODE' ) ) {
			return (bool) MEMBERKIN_SAFE_MODE;
		}
		if ( get_option( self::FORCE_OPTION ) ) {
			return true;
		}
		$stored = (string) get_option( self::OPTION, '' );
		return '' !== $stored && ! hash_equals( $stored, self::fingerprint() );
	}

	/**
	 * Whether the site looks like a clone (fingerprint mismatch) rather than manually paused.
	 */
	public static function fingerprint_mismatch(): bool {
		$stored = (string) get_option( self::OPTION, '' );
		return '' !== $stored && ! hash_equals( $stored, self::fingerprint() );
	}

	/**
	 * Admin confirmed this is the live site: adopt the current fingerprint and leave safe mode.
	 */
	public static function confirm_live(): void {
		update_option( self::OPTION, self::fingerprint(), true );
		delete_option( self::FORCE_OPTION );
	}

	/**
	 * Admin wants to stay paused (staging).
	 */
	public static function keep_paused(): void {
		update_option( self::FORCE_OPTION, 1, true );
		update_option( self::OPTION, self::fingerprint(), true );
	}
}
