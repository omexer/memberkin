<?php
/**
 * One-time admin messages across redirects.
 *
 * @package Memberkin
 */

namespace Memberkin\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Stores messages per user in a short-lived transient and renders them once.
 */
final class Flash {

	/**
	 * Transient key for the current user.
	 */
	private static function key(): string {
		return 'memberkin_flash_' . get_current_user_id();
	}

	/**
	 * Adds a message.
	 *
	 * @param string $message Message (plain text).
	 * @param string $type    success|error|warning|info.
	 */
	public static function add( string $message, string $type = 'success' ): void {
		$messages   = get_transient( self::key() );
		$messages   = is_array( $messages ) ? $messages : array();
		$messages[] = array(
			'message' => $message,
			'type'    => $type,
		);
		set_transient( self::key(), $messages, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Adds several error messages.
	 *
	 * @param array $errors Messages.
	 */
	public static function errors( array $errors ): void {
		foreach ( $errors as $error ) {
			self::add( (string) $error, 'error' );
		}
	}

	/**
	 * Stores submitted form values so a form can be re-filled after a validation error.
	 *
	 * @param array $values Values.
	 */
	public static function keep_input( array $values ): void {
		set_transient( self::key() . '_input', $values, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Retrieves (and clears) kept form values.
	 *
	 * @return array|null
	 */
	public static function old_input(): ?array {
		$values = get_transient( self::key() . '_input' );
		delete_transient( self::key() . '_input' );
		return is_array( $values ) ? $values : null;
	}

	/**
	 * Prints and clears messages.
	 */
	public static function render(): void {
		$messages = get_transient( self::key() );
		if ( ! is_array( $messages ) ) {
			return;
		}
		delete_transient( self::key() );
		foreach ( $messages as $m ) {
			$type = in_array( $m['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $m['type'] : 'info';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $m['message'] ) . '</p></div>';
		}
	}

	/**
	 * Redirects back to a URL and stops.
	 *
	 * @param string $url URL.
	 */
	public static function redirect( string $url ): never {
		wp_safe_redirect( $url );
		exit;
	}
}
