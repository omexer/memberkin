<?php
/**
 * Base integration test case.
 *
 * @package Memberkin
 */

namespace Memberkin\Tests;

use Memberkin\Core\Clock;
use Memberkin\Core\Jobs;
use Memberkin\Core\Settings;

/**
 * Resets plugin state between tests. Each test runs inside a DB transaction (WP_UnitTestCase).
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		Clock::unfreeze();
		Settings::flush();
		Jobs::set_sync( true );
		wp_cache_flush();
		memberkin()->container()->reset();
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		Clock::unfreeze();
		parent::tear_down();
	}
}
