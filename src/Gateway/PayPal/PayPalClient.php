<?php
/**
 * Minimal PayPal REST client.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\PayPal;

use Memberkin\Core\Http;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth client-credentials token (cached in a transient) and JSON requests. No SDK.
 */
final class PayPalClient {

	/**
	 * Constructor.
	 *
	 * @param string $client_id Client id.
	 * @param string $secret    Client secret.
	 * @param string $mode      live|test.
	 */
	public function __construct( private string $client_id, private string $secret, private string $mode ) {}

	/**
	 * API base URL.
	 */
	public function base(): string {
		return 'live' === $this->mode ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
	}

	/**
	 * Transient key of the access token.
	 */
	private function token_key(): string {
		return 'memberkin_paypal_token_' . $this->mode . '_' . substr( md5( $this->client_id ), 0, 12 );
	}

	/**
	 * Access token.
	 *
	 * @param bool $fresh Ignore the cache.
	 * @throws PayPalException When authentication fails.
	 */
	public function token( bool $fresh = false ): string {
		if ( '' === $this->client_id || '' === $this->secret ) {
			throw new PayPalException( 'PayPal is not configured (missing client id or secret).' );
		}
		$cached = $fresh ? false : get_transient( $this->token_key() );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$response = Http::request(
			'POST',
			$this->base() . '/v1/oauth2/token',
			array(
				'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->secret ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
				'body'    => array( 'grant_type' => 'client_credentials' ),
				'form'    => true,
				'channel' => 'paypal',
			)
		);
		$data     = $response->json();
		if ( ! $response->ok() || empty( $data['access_token'] ) ) {
			throw new PayPalException( 'PayPal authentication failed: ' . esc_html( $data['error_description'] ?? $response->error ), (int) $response->status, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $data is PayPal's decoded error response, kept for logging and never printed.
		}
		set_transient( $this->token_key(), (string) $data['access_token'], max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 120 ) );
		return (string) $data['access_token'];
	}

	/**
	 * JSON request. Returns the decoded body (empty for 204).
	 *
	 * @param string            $method     Method.
	 * @param string            $path       Path starting with /v1 or /v2.
	 * @param array|string|null $body       Body (string = raw JSON, sent unchanged).
	 * @param string            $request_id PayPal-Request-Id (idempotency for POST).
	 * @throws PayPalException On errors.
	 */
	public function request( string $method, string $path, array|string|null $body = null, string $request_id = '' ): array {
		$attempt = 0;
		do {
			$headers = array(
				'Authorization' => 'Bearer ' . $this->token( $attempt > 0 ),
				'Prefer'        => 'return=representation',
			);
			if ( '' !== $request_id ) {
				$headers['PayPal-Request-Id'] = $request_id;
			}
			$options = array(
				'headers' => $headers,
				'channel' => 'paypal',
			);
			if ( null !== $body ) {
				// Arrays are JSON-encoded by Http; strings (raw JSON, or "{}" for empty bodies) are sent unchanged.
				$options['body']                    = is_array( $body ) && ! $body ? '{}' : $body;
				$options['headers']['Content-Type'] = 'application/json';
			}
			$response = Http::request( $method, $this->base() . $path, $options );
			++$attempt;
		} while ( 401 === $response->status && $attempt < 2 );

		if ( $response->is_network_error() ) {
			throw new PayPalException( 'Could not connect to PayPal: ' . esc_html( $response->error ) );
		}
		$data = '' === $response->body ? array() : $response->json();
		if ( ! $response->ok() ) {
			$message = (string) ( $data['details'][0]['description'] ?? ( $data['message'] ?? 'PayPal error (HTTP ' . $response->status . ').' ) );
			throw new PayPalException( esc_html( $message ), (int) $response->status, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $data is PayPal's decoded error response, kept for logging and never printed.
		}
		return $data;
	}
}
