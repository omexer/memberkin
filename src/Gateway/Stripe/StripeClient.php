<?php
/**
 * Minimal Stripe REST client.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Stripe;

use Memberkin\Core\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Form-encoded requests to api.stripe.com with a pinned API version. No SDK.
 */
final class StripeClient {

	public const API_VERSION = '2024-06-20';
	private const BASE       = 'https://api.stripe.com/v1/';

	/**
	 * Constructor.
	 *
	 * @param string $secret_key Secret key.
	 */
	public function __construct( private string $secret_key ) {}

	/**
	 * Sends a request and returns the decoded object.
	 *
	 * @param string $method          GET|POST|DELETE.
	 * @param string $path            Path, e.g. "payment_intents".
	 * @param array  $params          Parameters (nested arrays allowed).
	 * @param string $idempotency_key Idempotency key (POST).
	 * @throws StripeException On API or network errors.
	 */
	public function request( string $method, string $path, array $params = array(), string $idempotency_key = '' ): array {
		if ( '' === $this->secret_key ) {
			throw new StripeException( 'Stripe is not configured (missing secret key).' );
		}
		$method  = strtoupper( $method );
		$url     = self::BASE . ltrim( $path, '/' );
		$params  = self::normalize( $params );
		$headers = array(
			'Authorization'  => 'Bearer ' . $this->secret_key,
			'Stripe-Version' => self::API_VERSION,
		);
		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}
		$options = array(
			'headers' => $headers,
			'channel' => 'stripe',
		);
		if ( 'GET' === $method || 'DELETE' === $method ) {
			if ( $params ) {
				$url .= '?' . http_build_query( $params, '', '&' );
			}
		} else {
			$options['body'] = $params;
			$options['form'] = true;
		}
		$response = Http::request( $method, $url, $options );
		if ( $response->is_network_error() ) {
			throw new StripeException( 'Could not connect to Stripe: ' . esc_html( $response->error ) );
		}
		$data = $response->json();
		if ( ! $response->ok() ) {
			$error = (array) ( $data['error'] ?? array() );
			throw new StripeException( esc_html( $error['message'] ?? 'Stripe error (HTTP ' . $response->status . ').' ), (int) $response->status, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $error is Stripe's decoded error object, kept for logging and decline codes, never printed.
		}
		return $data;
	}

	/**
	 * Booleans become "true"/"false"; nulls are dropped (Stripe rejects "1" for booleans in some places).
	 *
	 * @param array $params Params.
	 */
	public static function normalize( array $params ): array {
		$out = array();
		foreach ( $params as $key => $value ) {
			if ( null === $value ) {
				continue;
			}
			if ( is_bool( $value ) ) {
				$out[ $key ] = $value ? 'true' : 'false';
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = self::normalize( $value );
			} else {
				$out[ $key ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * Verifies a Stripe-Signature header ("t=…,v1=…[,v1=…]") over "{t}.{body}".
	 *
	 * @param string $header    Header value.
	 * @param string $body      Raw body.
	 * @param string $secret    Endpoint secret (whsec_…).
	 * @param int    $now       Current timestamp.
	 * @param int    $tolerance Seconds.
	 */
	public static function signature_valid( string $header, string $body, string $secret, int $now, int $tolerance = 300 ): bool {
		if ( '' === $secret || '' === $header ) {
			return false;
		}
		$timestamp  = null;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] && ctype_digit( $pair[1] ) ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}
		if ( null === $timestamp || ! $signatures || abs( $now - $timestamp ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}
}
