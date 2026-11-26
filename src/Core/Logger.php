<?php
/**
 * Technical log stored in the memberkin_logs table.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Logger with automatic redaction of secrets and card data.
 */
final class Logger {

	public const LEVELS = array( 'debug', 'info', 'warning', 'error' );

	/**
	 * Context keys whose values are always redacted.
	 */
	private const SENSITIVE_KEYS = array( 'password', 'pass', 'secret', 'secret_key', 'api_key', 'apikey', 'token', 'access_token', 'refresh_token', 'client_secret', 'authorization', 'card', 'card_number', 'number', 'cvc', 'cvv', 'transaction_key', 'signature_key', 'passphrase', 'webhook_secret', 'private_key' );

	/**
	 * Re-entrancy guard (logging must never recurse through hooks that log).
	 *
	 * @var bool
	 */
	private static bool $writing = false;

	/**
	 * Writes an entry.
	 *
	 * @param string $level   One of self::LEVELS.
	 * @param string $channel Channel, e.g. "stripe", "checkout".
	 * @param string $message Message.
	 * @param array  $context Context (redacted before storage).
	 */
	public static function log( string $level, string $channel, string $message, array $context = array() ): void {
		if ( self::$writing || ! in_array( $level, self::LEVELS, true ) ) {
			return;
		}
		if ( 'debug' === $level && ! self::debug_enabled() ) {
			return;
		}
		self::$writing = true;
		try {
			global $wpdb;
			$table = Db::table_name( 'logs' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				array(
					'level'      => $level,
					'channel'    => substr( sanitize_key( $channel ), 0, 40 ),
					'message'    => self::redact_string( $message ),
					'context'    => $context ? wp_json_encode( self::redact( $context ) ) : null,
					'created_at' => Db::now(),
				),
				array( '%s', '%s', '%s', '%s', '%s' )
			);
		} finally {
			self::$writing = false;
		}
	}

	/**
	 * Info entry.
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function info( string $channel, string $message, array $context = array() ): void {
		self::log( 'info', $channel, $message, $context );
	}

	/**
	 * Warning entry.
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function warning( string $channel, string $message, array $context = array() ): void {
		self::log( 'warning', $channel, $message, $context );
	}

	/**
	 * Error entry.
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function error( string $channel, string $message, array $context = array() ): void {
		self::log( 'error', $channel, $message, $context );
	}

	/**
	 * Debug entry (only stored when debug logging is enabled).
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function debug( string $channel, string $message, array $context = array() ): void {
		self::log( 'debug', $channel, $message, $context );
	}

	/**
	 * Whether debug-level logging is on (MEMBERKIN_DEBUG constant or advanced setting).
	 */
	public static function debug_enabled(): bool {
		if ( defined( 'MEMBERKIN_DEBUG' ) ) {
			return (bool) MEMBERKIN_DEBUG;
		}
		return (bool) Settings::get( 'advanced', 'debug_log', false );
	}

	/**
	 * Recursively redacts sensitive keys and card-like numbers.
	 *
	 * @param mixed    $data       Data.
	 * @param string[] $identifiers Keys whose values are known gateway identifiers (e.g. 16-digit references): they keep
	 *                              their digits; sensitive keys are still redacted.
	 * @return mixed
	 */
	public static function redact( mixed $data, array $identifiers = array() ): mixed {
		if ( is_array( $data ) || is_object( $data ) ) {
			$out = array();
			foreach ( (array) $data as $key => $value ) {
				if ( is_string( $key ) && in_array( strtolower( $key ), self::SENSITIVE_KEYS, true ) ) {
					$out[ $key ] = '[redacted]';
				} elseif ( is_string( $key ) && in_array( $key, $identifiers, true ) && is_scalar( $value ) && preg_match( '/^[A-Za-z0-9._-]{1,64}$/', (string) $value ) ) {
					$out[ $key ] = $value;
				} else {
					$out[ $key ] = self::redact( $value, $identifiers );
				}
			}
			return $out;
		}
		if ( is_string( $data ) ) {
			return self::redact_string( $data );
		}
		return $data;
	}

	/**
	 * Masks card-like digit runs (13–19 digits) and bearer tokens inside free text.
	 *
	 * @param string $text Text.
	 */
	public static function redact_string( string $text ): string {
		$text = (string) preg_replace( '/\b(?:\d[ -]?){13,19}\b/', '[redacted-number]', $text );
		$text = (string) preg_replace( '/(Bearer|Basic)\s+[A-Za-z0-9\-\._~\+\/=]+/i', '$1 [redacted]', $text );
		$text = (string) preg_replace( '/\b(sk|rk|whsec)_(live|test)?_?[A-Za-z0-9]{8,}\b/', '[redacted-key]', $text );
		// Client secrets (Stripe intents/sessions) and secret JSON fields in gateway request/response bodies.
		$text = (string) preg_replace( '/\b((?:pi|seti|cs|src)_[A-Za-z0-9]+)_secret_[A-Za-z0-9]+/', '$1_secret_[redacted]', $text );
		return (string) preg_replace( '/("(?:client_secret|access_token|transactionKey|dataValue|cardNumber|cardCode|expirationDate|password)"\s*:\s*")[^"]*"/i', '$1[redacted]"', $text );
	}

	/**
	 * Deletes entries older than the retention period.
	 *
	 * @param int $days Days to keep.
	 */
	public static function prune( int $days ): int {
		global $wpdb;
		$table  = Db::table_name( 'logs' );
		$cutoff = Clock::now()->modify( '-' . max( 1, $days ) . ' days' )->format( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT 5000", $cutoff ) );
	}
}
