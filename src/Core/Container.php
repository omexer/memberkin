<?php
/**
 * Minimal service container.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Lazily builds and caches shared services by id.
 */
final class Container {

	/**
	 * Factories keyed by service id.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Built instances keyed by service id.
	 *
	 * @var array<string, mixed>
	 */
	private array $instances = array();

	/**
	 * Ids of instances that were built lazily (and may be reset).
	 *
	 * @var array<string, true>
	 */
	private array $built = array();

	/**
	 * Registers a shared service factory. Re-registering replaces the previous factory and instance.
	 *
	 * @param string   $id      Service id (usually a class name).
	 * @param callable $factory Receives the container, returns the service.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Registers an already built instance.
	 *
	 * @param string $id       Service id.
	 * @param mixed  $instance Instance.
	 */
	public function instance( string $id, mixed $instance ): void {
		$this->instances[ $id ] = $instance;
	}

	/**
	 * Whether a service is known.
	 *
	 * @param string $id Service id.
	 */
	public function has( string $id ): bool {
		return isset( $this->instances[ $id ] ) || isset( $this->factories[ $id ] );
	}

	/**
	 * Returns a service, building it on first use. Classes without a factory are built with no arguments.
	 *
	 * @template T
	 * @param class-string<T>|string $id Service id.
	 * @return T|mixed
	 * @throws \RuntimeException When the service cannot be resolved.
	 */
	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}
		if ( isset( $this->factories[ $id ] ) ) {
			$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
			$this->built[ $id ]     = true;
			return $this->instances[ $id ];
		}
		if ( class_exists( $id ) ) {
			$this->instances[ $id ] = new $id();
			$this->built[ $id ]     = true;
			return $this->instances[ $id ];
		}
		throw new \RuntimeException( sprintf( 'Service "%s" is not registered.', esc_html( $id ) ) );
	}

	/**
	 * Drops lazily built instances (used by tests to reset state). Explicit instances are kept.
	 */
	public function reset(): void {
		foreach ( array_keys( $this->built ) as $id ) {
			unset( $this->instances[ $id ] );
		}
		$this->built = array();
	}
}
