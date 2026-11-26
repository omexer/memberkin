<?php
/**
 * Checkout sessions (replace PHP sessions; enable offsite returns and double-submit protection).
 *
 * @package Memberkin
 */

namespace Memberkin\Repository;

use Memberkin\Core\Clock;
use Memberkin\Core\Crypto;
use Memberkin\Core\Db;

defined( 'ABSPATH' ) || exit;

/**
 * SQL for memberkin_checkout_sessions. Rows are plain objects: id, token, user_id, level_ids, quote, form_data, order_id, status, expires_at.
 */
class CheckoutSessionRepository {

	/**
	 * Creates a session and returns its token.
	 *
	 * @param int   $user_id   User (0 = guest).
	 * @param int[] $level_ids Levels.
	 * @param int   $ttl       Lifetime in seconds.
	 */
	public function create( int $user_id, array $level_ids, int $ttl = DAY_IN_SECONDS ): object {
		global $wpdb;
		$token = Crypto::token();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::table_name( 'checkout_sessions' ),
			array(
				'token'      => $token,
				'user_id'    => $user_id,
				'level_ids'  => implode( ',', array_map( 'intval', $level_ids ) ),
				'status'     => 'open',
				'expires_at' => Db::datetime( Clock::now()->modify( '+' . $ttl . ' seconds' ) ),
				'created_at' => Db::now(),
			)
		);
		return $this->find( $token );
	}

	/**
	 * Session by token (expired sessions are returned too; callers check status).
	 *
	 * @param string $token Token.
	 */
	public function find( string $token ): ?object {
		if ( ! preg_match( '/^[A-Za-z0-9_\-]{20,64}$/', $token ) ) {
			return null;
		}
		global $wpdb;
		$table = Db::table_name( 'checkout_sessions' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return null;
		}
		$row->form_data = $row->form_data ? (array) json_decode( (string) $row->form_data, true ) : array();
		return $row;
	}

	/**
	 * Session by order.
	 *
	 * @param int $order_id Order.
	 */
	public function find_by_order( int $order_id ): ?object {
		global $wpdb;
		$table = Db::table_name( 'checkout_sessions' );
		$token = $wpdb->get_var( $wpdb->prepare( "SELECT token FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT 1", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $token ? $this->find( (string) $token ) : null;
	}

	/**
	 * Updates columns.
	 *
	 * @param int   $id     Session id.
	 * @param array $fields Columns (form_data arrays are JSON encoded).
	 */
	public function update( int $id, array $fields ): void {
		global $wpdb;
		if ( isset( $fields['form_data'] ) && is_array( $fields['form_data'] ) ) {
			$fields['form_data'] = wp_json_encode( $fields['form_data'] );
		}
		$wpdb->update( Db::table_name( 'checkout_sessions' ), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
