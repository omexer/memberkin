<?php
/**
 * Base class for feature modules.
 *
 * @package Memberkin
 */

namespace Memberkin\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A module groups the hooks, services, screens and tables of one feature.
 * Core modules are always enabled; optional modules can be toggled in Settings → Modules.
 */
abstract class Module {

	/**
	 * Unique module id (lowercase, dashes).
	 */
	abstract public function id(): string;

	/**
	 * Human readable label.
	 */
	abstract public function label(): string;

	/**
	 * Registers hooks and services. Only called when the module is enabled.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	abstract public function register( Plugin $plugin ): void;

	/**
	 * Short description shown on the Modules screen.
	 */
	public function description(): string {
		return '';
	}

	/**
	 * Core modules cannot be disabled.
	 */
	public function is_core(): bool {
		return false;
	}

	/**
	 * Default enabled state for optional modules on a new install.
	 */
	public function enabled_by_default(): bool {
		return true;
	}

	/**
	 * Ids of modules that must be enabled for this one to work.
	 *
	 * @return string[]
	 */
	public function requires(): array {
		return array();
	}

	/**
	 * Whether external requirements (for example another plugin) are met.
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Migrations owned by this module (run when the module is enabled).
	 *
	 * @return array<int, class-string<Migrations\Migration>>
	 */
	public function migrations(): array {
		return array();
	}

	/**
	 * Recurring jobs this module needs: hook => interval in seconds.
	 *
	 * @return array<string, int>
	 */
	public function recurring_jobs(): array {
		return array();
	}
}
