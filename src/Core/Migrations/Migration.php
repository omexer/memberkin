<?php
/**
 * Migration contract.
 *
 * @package Memberkin
 */

namespace Memberkin\Core\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * A forward-only, idempotent schema or data change.
 */
interface Migration {

	/**
	 * Stable unique id, e.g. "core_20261123_001". Never change it after release.
	 */
	public function id(): string;

	/**
	 * Applies the change. Must be safe to run more than once.
	 */
	public function up(): void;
}
