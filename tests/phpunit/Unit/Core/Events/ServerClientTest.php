<?php
/**
 * Tests for the failure-tolerant server-side PostHog client.
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Core\Events;

use Tagbridge\Core\Events\ServerClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( ServerClient::class )]
final class ServerClientTest extends TestCase {

	public function test_enabling_error_tracking_preloads_the_payload_builder() {
		$this->assertTrue(
			class_exists( '\PostHog\PostHog', false ) || class_exists( '\PostHog\PostHog' ),
			'posthog-php must be installed for this test.'
		);

		$client = new ServerClient();
		$ok     = $client->init(
			'phc_test',
			'https://example.test',
			array(
				'enabled'        => true,
				'capture_errors' => true,
			)
		);

		$this->assertTrue( $ok, 'init() should succeed with error tracking enabled.' );
		// The whole point of the fix: the payload builder is loaded eagerly at
		// init() time, so the exception handlers never need a fresh disk read at
		// the moment a handler fires (when the disk may be failing).
		$this->assertTrue(
			class_exists( '\PostHog\ExceptionPayloadBuilder', false ),
			'ExceptionPayloadBuilder must be preloaded before error tracking is enabled.'
		);
	}

	public function test_init_without_error_tracking_still_succeeds() {
		$client = new ServerClient();

		$this->assertTrue( $client->init( 'phc_test', 'https://example.test' ) );
		$this->assertTrue( $client->is_ready() );
	}

	public function test_init_fails_with_empty_api_key() {
		$client = new ServerClient();

		$this->assertFalse( $client->init( '', 'https://example.test' ) );
		$this->assertFalse( $client->is_ready() );
	}
}
