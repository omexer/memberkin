<?php
/**
 * Validation failure with field messages.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by services when input is invalid. Messages are translated and safe to show to the user.
 */
class ValidationException extends \InvalidArgumentException {

	/**
	 * Messages keyed by field (or numeric).
	 *
	 * @var array<int|string, string>
	 */
	private array $errors;

	/**
	 * Constructor.
	 *
	 * @param array<int|string, string> $errors Messages.
	 */
	public function __construct( array $errors ) {
		// Messages are escaped where they are thrown (so they are safe even if printed directly); keep plain text
		// here because every output path escapes again (HTML) or needs plain text (REST JSON).
		$this->errors = array_map( static fn( $message ) => is_string( $message ) ? wp_specialchars_decode( $message, ENT_QUOTES ) : $message, $errors );
		parent::__construct( implode( ' ', $this->errors ) );
	}

	/**
	 * Messages.
	 *
	 * @return array<int|string, string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * As a WP_Error (for REST responses).
	 *
	 * @param string $code   Error code.
	 * @param int    $status HTTP status.
	 */
	public function to_wp_error( string $code = 'memberkin_invalid', int $status = 400 ): \WP_Error {
		return new \WP_Error(
			$code,
			$this->getMessage(),
			array(
				'status' => $status,
				'errors' => $this->errors,
			)
		);
	}
}
