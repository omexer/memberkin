<?php
/**
 * Runs pending migrations.
 *
 * @package Memberkin
 */

namespace Memberkin\Core\Migrations;

use Memberkin\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks applied migration ids in an option and runs the missing ones in order.
 */
final class Migrator {

	public const OPTION = 'memberkin_migrations';
	public const LOCK   = 'memberkin_migrating';

	/**
	 * Ids of applied migrations.
	 *
	 * @return string[]
	 */
	public static function applied(): array {
		$applied = get_option( self::OPTION, array() );
		return is_array( $applied ) ? array_values( $applied ) : array();
	}

	/**
	 * Returns the migrations from the given list that have not run yet.
	 *
	 * @param array<int, class-string<Migration>> $classes Migration classes in order.
	 * @return Migration[]
	 */
	public static function pending( array $classes ): array {
		$applied = array_flip( self::applied() );
		$pending = array();
		foreach ( $classes as $class ) {
			$migration = new $class();
			if ( ! isset( $applied[ $migration->id() ] ) ) {
				$pending[] = $migration;
			}
		}
		return $pending;
	}

	/**
	 * Runs pending migrations. Returns the ids that ran.
	 *
	 * @param array<int, class-string<Migration>> $classes Migration classes in order.
	 * @return string[]
	 * @throws \Throwable When a migration fails (after logging).
	 */
	public static function run( array $classes ): array {
		$pending = self::pending( $classes );
		if ( ! $pending ) {
			return array();
		}
		// Prevent two concurrent requests from migrating at the same time.
		if ( get_transient( self::LOCK ) ) {
			return array();
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$ran = array();
		try {
			foreach ( $pending as $migration ) {
				$migration->up();
				$applied   = self::applied();
				$applied[] = $migration->id();
				update_option( self::OPTION, array_values( array_unique( $applied ) ), true );
				$ran[] = $migration->id();
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'migrations', 'Migration failed: ' . $e->getMessage(), array( 'ran' => $ran ) );
			throw $e;
		} finally {
			delete_transient( self::LOCK );
		}
		return $ran;
	}
}
