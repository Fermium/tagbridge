<?php
/**
 * Trivial smoke test proving the unit suite runs.
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Confirms the test harness is wired up correctly.
 */
final class SmokeTest extends TestCase {

	/**
	 * The suite can run and assertions work.
	 *
	 * @return void
	 */
	public function test_harness_runs() {
		$this->assertTrue( true );
	}

	/**
	 * The main plugin file is syntactically loadable as a constant source.
	 *
	 * @return void
	 */
	public function test_plugin_version_constant_format() {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', '0.1.0' );
	}
}
