<?php
/**
 * Saved payment method persistence.
 *
 * @package Memberkin
 */

namespace Memberkin\Repository;

use Memberkin\Core\Db;
use Memberkin\Domain\PaymentMethod;

defined( 'ABSPATH' ) || exit;

/**
 * SQL for memberkin_payment_methods.
 */
class PaymentMethodRepository {

	/**
	 * By id.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?PaymentMethod {
		global $wpdb;
		$table = Db::table_name( 'payment_methods' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * By gateway token.
	 *
	 * @param int    $user_id User.
	 * @param string $gateway Gateway.
	 * @param string $token   Token.
	 */
	public function find_by_token( int $user_id, string $gateway, string $token ): ?PaymentMethod {
		global $wpdb;
		$table = Db::table_name( 'payment_methods' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND gateway = %s AND token = %s", $user_id, $gateway, $token ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * A user's methods.
	 *
	 * @param int $user_id User.
	 * @return PaymentMethod[]
	 */
	public function for_user( int $user_id ): array {
		global $wpdb;
		$table = Db::table_name( 'payment_methods' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY is_default DESC, id DESC", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Card methods expiring at the end of the given month.
	 *
	 * @param int $year  Year.
	 * @param int $month Month.
	 * @return PaymentMethod[]
	 */
	public function expiring( int $year, int $month ): array {
		global $wpdb;
		$table = Db::table_name( 'payment_methods' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE exp_year = %d AND exp_month = %d", $year, $month ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Inserts or updates (by user+gateway+token). Returns the id.
	 *
	 * @param PaymentMethod $pm Method.
	 */
	public function upsert( PaymentMethod $pm ): int {
		global $wpdb;
		$existing = $this->find_by_token( $pm->user_id, $pm->gateway, $pm->token );
		$data     = array(
			'user_id'      => $pm->user_id,
			'gateway'      => $pm->gateway,
			'gateway_mode' => $pm->gateway_mode,
			'token'        => $pm->token,
			'type'         => $pm->type,
			'brand'        => $pm->brand,
			'last4'        => $pm->last4,
			'exp_month'    => $pm->exp_month,
			'exp_year'     => $pm->exp_year,
			'is_default'   => $pm->is_default ? 1 : 0,
			'updated_at'   => Db::now(),
		);
		$table    = Db::table_name( 'payment_methods' );
		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => $existing->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$pm->id = $existing->id;
		} else {
			$data['created_at'] = Db::now();
			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$pm->id = (int) $wpdb->insert_id;
		}
		if ( $pm->is_default ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_default = 0 WHERE user_id = %d AND gateway = %s AND id <> %d", $pm->user_id, $pm->gateway, $pm->id ) );
		}
		return $pm->id;
	}

	/**
	 * Deletes a method.
	 *
	 * @param int $id Id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Db::table_name( 'payment_methods' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Row → entity.
	 *
	 * @param array $row Row.
	 */
	private function hydrate( array $row ): PaymentMethod {
		return new PaymentMethod(
			(int) $row['id'],
			(int) $row['user_id'],
			(string) $row['gateway'],
			(string) $row['gateway_mode'],
			(string) $row['token'],
			(string) $row['type'],
			$row['brand'],
			$row['last4'],
			null !== $row['exp_month'] ? (int) $row['exp_month'] : null,
			null !== $row['exp_year'] ? (int) $row['exp_year'] : null,
			(bool) $row['is_default']
		);
	}
}
