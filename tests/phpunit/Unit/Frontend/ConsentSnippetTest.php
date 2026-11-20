<?php
/**
 * Tests for the consent-aware posthog-js init snippet.
 *
 * The snippet's PHP side only builds a string; what matters is how the
 * generated JavaScript behaves. When Node is available the snippet is run
 * against a fake posthog object and a fake document.
 *
 * @package Tagbridge\Tests
 *
 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler, WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec, WordPress.WP.AlternativeFunctions.json_encode_json_encode
 */

namespace Tagbridge\Tests\Unit\Frontend;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Tagbridge\Modules\PostHog\Frontend\Enqueue;

final class ConsentSnippetTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build the snippet for a config.
	 *
	 * @param array<string,mixed> $config Init config.
	 * @return string
	 */
	private function snippet( array $config ) {
		$method = new \ReflectionMethod( Enqueue::class, 'consent_init_call' );
		return $method->invoke( new Enqueue(), 'phc_test', $config );
	}

	/**
	 * Run a snippet in Node with a fake browser; return the recorded posthog calls.
	 *
	 * @param string $snippet     The snippet.
	 * @param string $cookie      document.cookie value.
	 * @param string $after       JS run after the snippet (e.g. fire a consent event).
	 * @return array<int,array{0:string,1:mixed}>
	 */
	private function run_in_node( $snippet, $cookie, $after = '' ) {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		if ( '' === $node ) {
			$this->markTestSkipped( 'Node is not available.' );
		}

		$harness = 'var calls=[],listeners={};'
			. 'var document={cookie:' . json_encode( $cookie ) . ',readyState:"complete",'
			. 'addEventListener:function(n,f){(listeners[n]=listeners[n]||[]).push(f);}};'
			. 'var window={};'
			. 'var posthog={};["init","set_config","startSessionRecording","stopSessionRecording"].forEach(function(m){posthog[m]=function(){calls.push([m,JSON.parse(JSON.stringify(Array.prototype.slice.call(arguments)))]);};});'
			. 'function fire(d){(listeners.wp_listen_for_consent_change||[]).forEach(function(f){f({detail:d});});}'
			. $snippet . "\n" . $after
			. ';process.stdout.write(JSON.stringify(calls));';

		$file = tempnam( sys_get_temp_dir(), 'tbjs' );
		file_put_contents( $file, $harness ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$out = shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$calls = json_decode( (string) $out, true );
		$this->assertIsArray( $calls, 'Snippet failed in Node: ' . $out );
		return $calls;
	}

	public function test_without_consent_starts_cookieless_with_replay_off() {
		$calls = $this->run_in_node(
			$this->snippet(
				array(
					'api_host'                  => 'https://eu.i.posthog.com',
					'disable_session_recording' => false,
				)
			),
			''
		);

		$this->assertSame( 'init', $calls[0][0] );
		$this->assertSame( 'phc_test', $calls[0][1][0] );
		$this->assertSame( 'memory', $calls[0][1][1]['persistence'] );
		$this->assertTrue( $calls[0][1][1]['disable_session_recording'] );
		$this->assertCount( 1, $calls, 'Nothing else runs until consent.' );
	}

	public function test_returning_visitor_with_consent_cookie_starts_normally() {
		$calls = $this->run_in_node(
			$this->snippet( array( 'disable_session_recording' => false ) ),
			'foo=bar; wp_consent_statistics=allow'
		);

		$this->assertArrayNotHasKey( 'persistence', $calls[0][1][1] );
		$this->assertFalse( $calls[0][1][1]['disable_session_recording'] );
		$this->assertCount( 1, $calls );
	}

	public function test_consent_given_later_enables_cookies_and_replay() {
		$calls = $this->run_in_node(
			$this->snippet( array( 'disable_session_recording' => false ) ),
			'',
			'fire({statistics:"allow"});fire({statistics:"allow"});'
		);

		$this->assertSame(
			array(
				array( 'set_config', array( array( 'persistence' => 'localStorage+cookie' ) ) ),
				array( 'startSessionRecording', array() ),
			),
			array_slice( $calls, 1 ),
			'Upgrades once, even if the event repeats.'
		);
	}

	public function test_consent_given_later_keeps_replay_off_when_site_disabled_it() {
		$calls = $this->run_in_node(
			$this->snippet( array( 'disable_session_recording' => true ) ),
			'',
			'fire({statistics:"allow"});'
		);

		$this->assertSame( array( 'set_config' ), array_column( array_slice( $calls, 1 ), 0 ) );
	}

	public function test_consent_withdrawn_goes_back_to_cookieless() {
		$calls = $this->run_in_node(
			$this->snippet( array( 'disable_session_recording' => false ) ),
			'wp_consent_statistics=allow',
			'fire({statistics:"deny"});'
		);

		$this->assertSame(
			array(
				array( 'stopSessionRecording', array() ),
				array( 'set_config', array( array( 'persistence' => 'memory' ) ) ),
			),
			array_slice( $calls, 1 )
		);
	}

	public function test_other_categories_are_ignored() {
		$calls = $this->run_in_node(
			$this->snippet( array( 'disable_session_recording' => false ) ),
			'wp_consent_marketing=allow',
			'fire({marketing:"allow"});'
		);

		$this->assertSame( 'memory', $calls[0][1][1]['persistence'] );
		$this->assertCount( 1, $calls );
	}
}
