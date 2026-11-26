<?php
/**
 * Webhook REST endpoint: POST /wp-json/memberkin/v1/webhooks/{gateway}.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Webhooks;

use Memberkin\Core\Db;
use Memberkin\Core\Jobs;
use Memberkin\Core\Logger;
use Memberkin\Gateway\Contracts\InvalidSignature;
use Memberkin\Gateway\GatewayRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies, stores (idempotently) and queues webhooks; responds fast.
 */
final class WebhookController {

	/**
	 * Constructor.
	 *
	 * @param GatewayRegistry $gateways Gateways.
	 */
	public function __construct( private GatewayRegistry $gateways ) {}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			'memberkin/v1',
			'/webhooks/(?P<gateway>[a-z0-9_\-]+)',
			array(
				'methods'             => array( 'POST' ),
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // Authenticity is checked by the gateway signature.
				'args'                => array(
					'gateway' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Handles a webhook request.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$gateway = $this->gateways->get( (string) $request['gateway'] );
		if ( ! $gateway ) {
			return new \WP_REST_Response( array( 'error' => 'unknown_gateway' ), 404 );
		}
		try {
			$webhook = $gateway->verify_webhook( $request );
		} catch ( InvalidSignature $e ) {
			Logger::warning( $gateway->id(), 'Rejected webhook: ' . $e->getMessage(), array( 'ip' => self::client_ip() ) );
			return new \WP_REST_Response( array( 'error' => 'invalid_signature' ), 400 );
		}

		global $wpdb;
		$table = Db::table_name( 'webhook_events' );
		// INSERT IGNORE + unique (gateway, mode, event_id) = exactly-once storage.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from our prefix.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (gateway, gateway_mode, event_id, event_type, payload, signature_valid, status, attempts, received_at) VALUES (%s, %s, %s, %s, %s, 1, 'received', 0, %s)",
				$webhook->gateway,
				$webhook->mode,
				mb_substr( $webhook->event_id, 0, 191 ),
				mb_substr( $webhook->type, 0, 100 ),
				wp_json_encode( Logger::redact( $webhook->payload, $gateway instanceof \Memberkin\Gateway\AbstractGateway ? $gateway->webhook_identifier_keys() : array() ) ),
				Db::now()
			)
		);
		// phpcs:enable
		if ( ! $inserted ) {
			return new \WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}
		$id = (int) $wpdb->insert_id;
		/**
		 * Fires when a verified webhook was stored.
		 *
		 * @param int    $id      Stored event id.
		 * @param string $gateway Gateway id.
		 * @param string $type    Event type.
		 */
		do_action( 'memberkin_webhook_received', $id, $webhook->gateway, $webhook->type );
		Jobs::async( 'memberkin_process_webhook', array( $id ), true );
		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * REMOTE_ADDR (never trust forwarding headers for security decisions).
	 */
	public static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
