<?php
/**
 * Activity log (member history).
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Records business events in memberkin_events. Used for member history, reminder de-duplication and reports.
 */
final class Events {

	/**
	 * Records an event.
	 *
	 * @param string $event       Event name, e.g. "membership.activated".
	 * @param int    $user_id     Member the event concerns (0 if none).
	 * @param string $object_type Object type, e.g. "membership".
	 * @param int    $object_id   Object id.
	 * @param array  $data        Extra data (redacted, JSON).
	 * @param string $actor_type  user|admin|system|gateway (auto-detected when empty).
	 * @param int    $actor_id    Acting user id.
	 */
	public static function record( string $event, int $user_id = 0, string $object_type = '', int $object_id = 0, array $data = array(), string $actor_type = '', int $actor_id = -1 ): int {
		global $wpdb;
		if ( -1 === $actor_id ) {
			$actor_id = get_current_user_id();
		}
		if ( '' === $actor_type ) {
			if ( wp_doing_cron() || 0 === $actor_id ) {
				$actor_type = 'system';
			} elseif ( $actor_id === $user_id ) {
				$actor_type = 'user';
			} else {
				$actor_type = 'admin';
			}
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Db::table_name( 'events' ),
			array(
				'user_id'     => $user_id,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'event'       => substr( $event, 0, 64 ),
				'data'        => $data ? wp_json_encode( Logger::redact( $data ) ) : null,
				'actor_type'  => $actor_type,
				'actor_id'    => max( 0, $actor_id ),
				'created_at'  => Db::now(),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Events for a member, newest first.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Max rows.
	 * @param int $offset  Offset.
	 * @return array<int, object>
	 */
	public static function for_user( int $user_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = Db::table_name( 'events' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $user_id, $limit, $offset ) );
		foreach ( $rows as $row ) {
			$row->data = $row->data ? json_decode( $row->data, true ) : array();
		}
		return $rows;
	}

	/**
	 * Whether an event with the given name exists for an object (used to de-duplicate reminders).
	 *
	 * @param string $event       Event name.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 */
	public static function exists( string $event, string $object_type, int $object_id ): bool {
		global $wpdb;
		$table = Db::table_name( 'events' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE object_type = %s AND object_id = %d AND event = %s LIMIT 1", $object_type, $object_id, $event ) );
	}
}
