<?php
/**
 * Processes stored webhook events.
 *
 * @package Memberkin
 */

namespace Memberkin\Gateway\Webhooks;

use Memberkin\Core\Clock;
use Memberkin\Core\Db;
use Memberkin\Core\Jobs;
use Memberkin\Core\Logger;
use Memberkin\Core\SafeMode;
use Memberkin\Gateway\Contracts\VerifiedWebhook;
use Memberkin\Gateway\GatewayRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Loads a stored event, asks the gateway to normalise it and hands the events to the dispatcher.
 * Retries with backoff (1, 5, 30, 120 minutes) and marks the event failed after 5 attempts.
 */
final class WebhookProcessor {

	private const BACKOFF_MINUTES = array( 1, 5, 30, 120 );

	/**
	 * Constructor.
	 *
	 * @param GatewayRegistry   $gateways   Gateways.
	 * @param WebhookDispatcher $dispatcher Dispatcher.
	 */
	public function __construct(
		private GatewayRegistry $gateways,
		private WebhookDispatcher $dispatcher
	) {}

	/**
	 * Job handler. Returns the final status.
	 *
	 * @param int $id Stored event id.
	 * @throws \RuntimeException Internally when the gateway is gone (caught; the event is marked failed).
	 */
	public function process( int $id ): string {
		global $wpdb;
		$table = Db::table_name( 'webhook_events' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row || in_array( $row->status, array( 'processed', 'ignored' ), true ) ) {
			return $row->status ?? 'missing';
		}
		if ( SafeMode::active() ) {
			return 'received'; // Kept for later; replayed from the Webhook Events screen.
		}
		// Claim the row (another worker may be processing it).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'processing', attempts = attempts + 1 WHERE id = %d AND status IN ('received','failed')", $id ) );
		if ( ! $claimed ) {
			return 'processing';
		}

		$gateway = $this->gateways->get( (string) $row->gateway );
		$status  = 'processed';
		$error   = null;
		try {
			if ( ! $gateway ) {
				throw new \RuntimeException( 'Gateway not available.' );
			}
			$webhook = new VerifiedWebhook( (string) $row->gateway, (string) $row->gateway_mode, (string) $row->event_id, (string) $row->event_type, (array) json_decode( (string) $row->payload, true ) );
			$events  = $gateway->parse_webhook( $webhook );
			$handled = false;
			foreach ( $events as $event ) {
				$handled = $this->dispatcher->dispatch( $gateway->id(), $event ) || $handled;
			}
			if ( ! $handled ) {
				$status = 'ignored';
			}
		} catch ( \Throwable $e ) {
			$status = 'failed';
			$error  = $e->getMessage();
			Logger::error( (string) $row->gateway, 'Webhook processing failed: ' . $error, array( 'event' => $row->event_id ) );
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'status'       => $status,
				'last_error'   => $error,
				'processed_at' => 'failed' === $status ? null : Db::now(),
			),
			array( 'id' => $id )
		);

		if ( 'failed' === $status ) {
			$attempt = (int) $row->attempts + 1;
			if ( $attempt <= count( self::BACKOFF_MINUTES ) ) {
				Jobs::at( Clock::timestamp() + 60 * self::BACKOFF_MINUTES[ $attempt - 1 ], 'memberkin_process_webhook', array( $id ), false );
			}
		}
		/**
		 * Fires after a webhook event was processed.
		 *
		 * @param int    $id     Stored event id.
		 * @param string $status processed|ignored|failed.
		 */
		do_action( 'memberkin_webhook_processed', $id, $status );
		return $status;
	}
}
