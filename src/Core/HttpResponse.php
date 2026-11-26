<?php
/**
 * HTTP response value object.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Normalised response.
 */
final class HttpResponse {

	/**
	 * Constructor.
	 *
	 * @param int    $status  HTTP status (0 on network error).
	 * @param string $body    Raw body.
	 * @param array  $headers Lower-cased headers.
	 * @param string $error   Network error message.
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $body,
		public readonly array $headers = array(),
		public readonly string $error = ''
	) {}

	/**
	 * Builds from a wp_remote_request() result.
	 *
	 * @param array|\WP_Error $raw Result.
	 */
	public static function from_wp( array|\WP_Error $raw ): self {
		if ( is_wp_error( $raw ) ) {
			return new self( 0, '', array(), $raw->get_error_message() );
		}
		$headers = array();
		foreach ( (array) wp_remote_retrieve_headers( $raw ) as $key => $value ) {
			$headers[ strtolower( (string) $key ) ] = $value;
		}
		return new self( (int) wp_remote_retrieve_response_code( $raw ), (string) wp_remote_retrieve_body( $raw ), $headers );
	}

	/**
	 * Whether the request failed before getting a response.
	 */
	public function is_network_error(): bool {
		return 0 === $this->status;
	}

	/**
	 * Whether status is 2xx.
	 */
	public function ok(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Decoded JSON body (array) or empty array.
	 */
	public function json(): array {
		$data = json_decode( $this->body, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Header value.
	 *
	 * @param string $name Header name.
	 */
	public function header( string $name ): string {
		$value = $this->headers[ strtolower( $name ) ] ?? '';
		return is_array( $value ) ? (string) reset( $value ) : (string) $value;
	}
}
