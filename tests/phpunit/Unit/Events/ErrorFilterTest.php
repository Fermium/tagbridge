<?php
/**
 * Tests for the PHP error filter in front of PostHog error tracking.
 *
 * @package Tagbridge\Tests
 *
 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler, WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec, WordPress.WP.AlternativeFunctions.json_encode_json_encode
 */

namespace Tagbridge\Tests\Unit\Events;

use Tagbridge\Core\Events\ErrorFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( ErrorFilter::class )]
final class ErrorFilterTest extends TestCase {

	/**
	 * Errors the fake "PostHog" handler received.
	 *
	 * @var array<int,array{0:int,1:string}>
	 */
	private $received = array();

	/**
	 * How many error handlers this test pushed, to pop in tearDown.
	 *
	 * @var int
	 */
	private $pushed = 0;

	protected function tearDown(): void {
		for ( ; $this->pushed > 0; $this->pushed-- ) {
			restore_error_handler();
		}
		parent::tearDown();
	}

	private function install_fake_posthog_and_filter( ErrorFilter $filter ) {
		$this->received = array();
		set_error_handler(
			function ( $errno, $message ) {
				$this->received[] = array( $errno, $message );
				return true;
			}
		);
		$this->assertTrue( $filter->install() );
		$this->pushed = 2;
	}

	public function test_default_drops_notices_and_deprecations() {
		$filter = new ErrorFilter();
		$this->assertTrue( $filter->is_benign( E_NOTICE, 'x' ) );
		$this->assertTrue( $filter->is_benign( E_USER_NOTICE, 'x' ) );
		$this->assertTrue( $filter->is_benign( E_DEPRECATED, 'x' ) );
		$this->assertTrue( $filter->is_benign( E_USER_DEPRECATED, 'x' ) );
	}

	public function test_default_keeps_real_warnings() {
		$filter = new ErrorFilter();
		$this->assertFalse( $filter->is_benign( E_WARNING, 'Undefined variable $order' ) );
		$this->assertFalse( $filter->is_benign( E_USER_ERROR, 'boom' ) );
	}

	public function test_default_drops_parse_str_nesting_warning() {
		$filter = new ErrorFilter();
		$this->assertTrue(
			$filter->is_benign(
				E_WARNING,
				'parse_str(): Input variable nesting level exceeded 64. To increase the limit change max_input_nesting_level in php.ini.'
			)
		);
	}

	public function test_custom_messages_and_levels() {
		$filter = new ErrorFilter( 0, array( 'Undefined array key "flatsome-js"', '' ) );
		$this->assertTrue( $filter->is_benign( E_WARNING, 'Undefined array key "flatsome-js"' ) );
		$this->assertFalse( $filter->is_benign( E_NOTICE, 'something else' ), 'Level 0 keeps notices.' );
		$this->assertFalse( $filter->is_benign( E_WARNING, '' ), 'An empty fragment must not match everything.' );
	}

	public function test_handle_passes_real_errors_to_the_wrapped_handler() {
		$this->install_fake_posthog_and_filter( new ErrorFilter() );

		trigger_error( 'real problem', E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error

		$this->assertSame( array( array( E_USER_WARNING, 'real problem' ) ), $this->received );
	}

	public function test_handle_keeps_benign_errors_away_from_the_wrapped_handler() {
		$filter = new ErrorFilter();
		$this->install_fake_posthog_and_filter( $filter );

		// Call the handler directly: triggering a real notice would reach PHPUnit's output.
		$this->assertFalse( $filter->handle( E_USER_NOTICE, 'noise' ) );
		$this->assertFalse( $filter->handle( E_WARNING, 'parse_str(): Input variable nesting level exceeded 64.' ) );
		$this->assertSame( array(), $this->received );
	}

	public function test_install_without_a_handler_leaves_state_unchanged() {
		set_error_handler( null );
		$filter = new ErrorFilter();
		$this->assertFalse( $filter->install() );
		// The current handler is still null: setting one returns it.
		$this->assertNull( set_error_handler( null ) );
		$this->pushed = 2;
	}
}
