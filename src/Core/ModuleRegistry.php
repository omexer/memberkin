<?php
/**
 * Keeps track of modules and their enabled state.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Module registry.
 */
final class ModuleRegistry {

	public const OPTION = 'memberkin_modules';

	/**
	 * Registered modules keyed by id.
	 *
	 * @var array<string, Module>
	 */
	private array $modules = array();

	/**
	 * Adds a module.
	 *
	 * @param Module $module Module.
	 * @throws \InvalidArgumentException On duplicate ids.
	 */
	public function add( Module $module ): void {
		if ( isset( $this->modules[ $module->id() ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Module "%s" is already registered.', esc_html( $module->id() ) ) );
		}
		$this->modules[ $module->id() ] = $module;
	}

	/**
	 * All modules.
	 *
	 * @return array<string, Module>
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * A module by id.
	 *
	 * @param string $id Module id.
	 */
	public function get( string $id ): ?Module {
		return $this->modules[ $id ] ?? null;
	}

	/**
	 * Stored on/off choices for optional modules.
	 *
	 * @return array<string, bool>
	 */
	private function stored_states(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? array_map( 'boolval', $stored ) : array();
	}

	/**
	 * Whether a module is enabled (core modules always are; dependencies must be enabled too).
	 *
	 * @param string $id Module id.
	 * @param array  $seen Internal recursion guard.
	 */
	public function is_enabled( string $id, array $seen = array() ): bool {
		$module = $this->get( $id );
		if ( ! $module || isset( $seen[ $id ] ) ) {
			return false;
		}
		if ( ! $module->is_available() ) {
			return false;
		}
		$seen[ $id ] = true;
		if ( ! $module->is_core() ) {
			$states  = $this->stored_states();
			$enabled = $states[ $id ] ?? $module->enabled_by_default();
			if ( ! $enabled ) {
				return false;
			}
		}
		foreach ( $module->requires() as $dependency ) {
			if ( ! $this->is_enabled( $dependency, $seen ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Enabled modules, in registration order.
	 *
	 * @return array<string, Module>
	 */
	public function enabled(): array {
		return array_filter( $this->modules, fn( Module $m ) => $this->is_enabled( $m->id() ) );
	}

	/**
	 * Persists the on/off state of an optional module.
	 *
	 * @param string $id      Module id.
	 * @param bool   $enabled State.
	 */
	public function set_enabled( string $id, bool $enabled ): void {
		$module = $this->get( $id );
		if ( ! $module || $module->is_core() ) {
			return;
		}
		$states        = $this->stored_states();
		$states[ $id ] = $enabled;
		update_option( self::OPTION, $states, true );
	}
}
