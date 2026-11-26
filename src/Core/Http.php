<?php
/**
 * HTTP client used by gateways and integrations.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * JSON-oriented wrapper around wp_remote_request() with retries on 429/5xx and redacted logging.
 */
final class Http {

	/**
	 * Performs a request.
	 *
	 * @param string $method  HTTP method.
	 * @param string $url     URL.
	 * @param array  $options {
	 *     Arguments.
	 *
	 *     @type array        $headers  Headers.
	 *     @type array|string $body     Body (arrays are JSON-encoded unless "form" is true).
	 *     @type bool         $form     Send body as application/x-www-form-urlencoded.
	 *     @type int          $timeout  Seconds (default 30).
	 *     @type int          $retries  Retries on network errors/429/5xx (default 2; only for idempotent requests).
	 *     @type string       $channel  Log channel.
	 * }
	 * @return HttpResponse
	 */
	public static function request( string $method, string $url, array $options = array() ): HttpResponse {
		$method  = strtoupper( $method );
		$headers = $options['headers'] ?? array();
		$body    = $options['body'] ?? null;
		$channel = $options['channel'] ?? 'http';
		$retries = (int) ( $options['retries'] ?? ( in_array( $method, array( 'GET', 'HEAD', 'DELETE' ), true ) || isset( $headers['Idempotency-Key'] ) ? 2 : 0 ) );

		if ( is_array( $body ) ) {
			if ( ! empty( $options['form'] ) ) {
				$body                    = http_build_query( $body, '', '&' );
				$headers['Content-Type'] = $headers['Content-Type'] ?? 'application/x-www-form-urlencoded';
			} else {
				$body                    = wp_json_encode( $body );
				$headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
			}
		}
		$headers['Accept'] = $headers['Accept'] ?? 'application/json';

		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'body'        => $body,
			'timeout'     => (int) ( $options['timeout'] ?? 30 ),
			'redirection' => 0,
			'user-agent'  => 'Memberkin/' . MEMBERKIN_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
		);
		/**
		 * Filters outgoing HTTP request arguments.
		 *
		 * @param array  $args    wp_remote_request() args.
		 * @param string $url     URL.
		 * @param string $channel Channel (gateway or integration id).
		 */
		$args = (array) apply_filters( 'memberkin_http_request_args', $args, $url, $channel );

		$attempt = 0;
		do {
			$raw      = wp_remote_request( $url, $args );
			$response = HttpResponse::from_wp( $raw );
			$retry    = $attempt < $retries && ( $response->is_network_error() || 429 === $response->status || $response->status >= 500 );
			if ( $retry ) {
				usleep( (int) ( 250000 * ( 2 ** $attempt ) ) );
			}
			++$attempt;
		} while ( $retry );

		Logger::debug(
			$channel,
			sprintf( '%s %s → %d', $method, preg_replace( '/\?.*/', '', $url ), $response->status ),
			array(
				'request'  => is_string( $body ) ? substr( $body, 0, 2000 ) : null,
				'response' => substr( $response->body, 0, 2000 ),
			)
		);
		if ( $response->is_network_error() ) {
			Logger::warning( $channel, 'HTTP network error: ' . $response->error, array( 'url' => preg_replace( '/\?.*/', '', $url ) ) );
		}
		return $response;
	}
}
