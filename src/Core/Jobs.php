<?php
/**
 * Background jobs on top of Action Scheduler.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper so the rest of the plugin never calls Action Scheduler directly.
 * In synchronous mode (tests, or when AS is unavailable) jobs run immediately via do_action().
 */
final class Jobs {

	public const GROUP = 'memberkin';

	/**
	 * Whether jobs execute immediately instead of being queued.
	 *
	 * @var bool
	 */
	private static bool $sync = false;

	/**
	 * Jobs that were requested while in synchronous mode but scheduled in the future (for test inspection).
	 *
	 * @var array<int, array{hook:string,args:array,timestamp:int}>
	 */
	private static array $deferred = array();

	/**
	 * Enables or disables synchronous mode.
	 *
	 * @param bool $sync Mode.
	 */
	public static function set_sync( bool $sync ): void {
		self::$sync     = $sync;
		self::$deferred = array();
	}

	/**
	 * Jobs deferred in synchronous mode.
	 *
	 * @return array<int, array{hook:string,args:array,timestamp:int}>
	 */
	public static function deferred(): array {
		return self::$deferred;
	}

	/**
	 * Whether Action Scheduler is loaded.
	 */
	private static function available(): bool {
		return function_exists( 'as_enqueue_async_action' ) && ! self::$sync;
	}

	/**
	 * Runs a job as soon as possible.
	 *
	 * @param string $hook   Action hook.
	 * @param array  $args   Arguments (must be JSON serialisable, keep small).
	 * @param bool   $unique Skip if an identical pending job exists.
	 */
	public static function async( string $hook, array $args = array(), bool $unique = false ): void {
		if ( ! self::available() ) {
			do_action_ref_array( $hook, array_values( $args ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- runs our own queued job hooks synchronously.
			return;
		}
		as_enqueue_async_action( $hook, $args, self::GROUP, $unique );
	}

	/**
	 * Runs a job at a given time.
	 *
	 * @param int    $timestamp Unix time.
	 * @param string $hook      Action hook.
	 * @param array  $args      Arguments.
	 * @param bool   $unique    Skip if an identical pending job exists.
	 */
	public static function at( int $timestamp, string $hook, array $args = array(), bool $unique = true ): void {
		if ( ! self::available() ) {
			self::$deferred[] = array(
				'hook'      => $hook,
				'args'      => $args,
				'timestamp' => $timestamp,
			);
			return;
		}
		if ( $unique && as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
			return;
		}
		as_schedule_single_action( $timestamp, $hook, $args, self::GROUP );
	}

	/**
	 * Ensures a recurring job exists with the given interval.
	 *
	 * @param string $hook     Action hook.
	 * @param int    $interval Seconds.
	 */
	public static function ensure_recurring( string $hook, int $interval ): void {
		if ( ! self::available() ) {
			return;
		}
		if ( as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
			return;
		}
		// Spread first runs a little so many sites on one server do not run in the same second.
		as_schedule_recurring_action( time() + wp_rand( 30, 300 ), $interval, $hook, array(), self::GROUP, true );
	}

	/**
	 * Cancels pending jobs for a hook (optionally matching args).
	 *
	 * @param string     $hook Action hook.
	 * @param array|null $args Arguments or null for all.
	 */
	public static function cancel( string $hook, ?array $args = null ): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( $hook, $args ?? array(), self::GROUP );
	}

	/**
	 * Cancels every pending job of the plugin (deactivation).
	 */
	public static function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}

	/**
	 * Whether a matching job is pending.
	 *
	 * @param string     $hook Action hook.
	 * @param array|null $args Arguments.
	 */
	public static function is_scheduled( string $hook, ?array $args = null ): bool {
		if ( ! self::available() ) {
			foreach ( self::$deferred as $job ) {
				if ( $job['hook'] === $hook && ( null === $args || $job['args'] === $args ) ) {
					return true;
				}
			}
			return false;
		}
		return as_has_scheduled_action( $hook, $args, self::GROUP );
	}
}
