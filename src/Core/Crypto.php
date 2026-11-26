<?php
/**
 * Symmetric encryption for secrets stored in the database.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Uses libsodium secretbox. Key: MEMBERKIN_ENCRYPTION_KEY constant (any string; hashed to 32 bytes),
 * otherwise derived from the site's AUTH_KEY and SECURE_AUTH_SALT.
 *
 * Changing the key or those salts makes stored secrets unreadable; admins then re-enter them.
 */
final class Crypto {

	private const PREFIX = 'em1:';

	/**
	 * Derived 32-byte key.
	 */
	private static function key(): string {
		if ( defined( 'MEMBERKIN_ENCRYPTION_KEY' ) && '' !== (string) MEMBERKIN_ENCRYPTION_KEY ) {
			$material = (string) MEMBERKIN_ENCRYPTION_KEY;
		} else {
			$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
			if ( '' === $material ) {
				$material = wp_salt( 'auth' );
			}
		}
		return sodium_crypto_generichash( 'memberkin-secrets|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypts a string. Empty strings are stored as empty strings.
	 *
	 * @param string $plain Plain text.
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypts a value produced by encrypt(). Returns '' when it cannot be decrypted.
	 * Values without the prefix are treated as legacy plain text.
	 *
	 * @param string $stored Stored value.
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}
		if ( ! str_starts_with( $stored, self::PREFIX ) ) {
			return $stored;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		return false === $plain ? '' : $plain;
	}

	/**
	 * URL-safe random token (default 32 bytes → 43 chars).
	 *
	 * @param int $bytes Entropy bytes.
	 */
	public static function token( int $bytes = 32 ): string {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Random code from an unambiguous alphabet (no 0/O/1/I/L).
	 *
	 * @param int $length Length.
	 */
	public static function code( int $length = 10 ): string {
		$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
		$out      = '';
		$max      = strlen( $alphabet ) - 1;
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $alphabet[ random_int( 0, $max ) ];
		}
		return $out;
	}

	/**
	 * Masks a secret for display ("••••abcd").
	 *
	 * @param string $secret Secret.
	 */
	public static function mask( string $secret ): string {
		if ( '' === $secret ) {
			return '';
		}
		return str_repeat( '•', 8 ) . substr( $secret, -4 );
	}
}
