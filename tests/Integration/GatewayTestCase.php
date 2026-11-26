<?php
/**
 * Base for gateway tests: HTTP calls are answered by registered fakes and recorded.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests\Integration;

use Memberkin\Core\SafeMode;

/**
 * Nothing leaves the machine: unmatched requests fail the test.
 */
abstract class GatewayTestCase extends BillingTestCase {

	/**
	 * Routes: [method, url regex, responder(array $request): array{0:int,1:array|string}].
	 *
	 * @var array<int, array{0:string,1:string,2:callable}>
	 */
	protected array $routes = array();

	/**
	 * Recorded requests.
	 *
	 * @var array<int, array{method:string,url:string,body:array,raw:string,headers:array}>
	 */
	protected array $requests = array();

	public function set_up(): void {
		parent::set_up();
		$this->routes   = array();
		$this->requests = array();
		SafeMode::confirm_live();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Registers a fake endpoint (later registrations win).
	 *
	 * @param string         $method   Method.
	 * @param string         $pattern  URL regex.
	 * @param callable|array $response Responder or [status, body].
	 */
	protected function route( string $method, string $pattern, callable|array $response ): void {
		array_unshift( $this->routes, array( $method, $pattern, is_callable( $response ) ? $response : static fn() => $response ) );
	}

	/**
	 * Filter callback.
	 *
	 * @param mixed  $pre  Pre.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 */
	public function fake_http( $pre, array $args, string $url ): array {
		$raw  = is_string( $args['body'] ?? null ) ? $args['body'] : '';
		$body = array();
		if ( '' !== $raw ) {
			$json = json_decode( $raw, true );
			if ( is_array( $json ) ) {
				$body = $json;
			} else {
				parse_str( $raw, $body );
			}
		}
		$request          = array(
			'method'  => strtoupper( (string) ( $args['method'] ?? 'GET' ) ),
			'url'     => $url,
			'body'    => $body,
			'raw'     => $raw,
			'headers' => (array) ( $args['headers'] ?? array() ),
		);
		$this->requests[] = $request;
		foreach ( $this->routes as [ $method, $pattern, $responder ] ) {
			if ( $method === $request['method'] && preg_match( $pattern, $url ) ) {
				[ $status, $response ] = $responder( $request );
				return array(
					'headers'  => array(),
					'body'     => is_string( $response ) ? $response : (string) wp_json_encode( $response ),
					'response' => array(
						'code'    => $status,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}
		$this->fail( 'Unexpected HTTP request: ' . $request['method'] . ' ' . $url );
	}

	/**
	 * Requests matching a method and URL regex.
	 *
	 * @param string $method  Method.
	 * @param string $pattern Regex.
	 */
	protected function sent( string $method, string $pattern ): array {
		return array_values( array_filter( $this->requests, static fn( $r ) => $r['method'] === $method && preg_match( $pattern, $r['url'] ) ) );
	}

	/**
	 * Posts a webhook to the REST endpoint.
	 *
	 * @param string $gateway Gateway.
	 * @param string $body    Raw body.
	 * @param array  $headers Headers.
	 */
	protected function webhook( string $gateway, string $body, array $headers ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/memberkin/v1/webhooks/' . $gateway );
		$request->set_body( $body );
		$request->set_header( 'content-type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return rest_do_request( $request );
	}
}
