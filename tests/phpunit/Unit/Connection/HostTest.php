<?php
/**
 * Tests for the platform-agnostic Host resolver.
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Connection;

use Tagbridge\Core\Connection\Host;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( Host::class )]
final class HostTest extends TestCase {

	public function test_resolves_us_region() {
		$this->assertSame( Host::US, Host::resolve( Host::REGION_US ) );
	}

	public function test_resolves_eu_region() {
		$this->assertSame( Host::EU, Host::resolve( Host::REGION_EU ) );
	}

	public function test_unknown_region_falls_back_to_us() {
		$this->assertSame( Host::US, Host::resolve( 'something-else' ) );
	}

	public function test_resolves_custom_host() {
		$this->assertSame(
			'https://posthog.example.com',
			Host::resolve( Host::REGION_CUSTOM, 'https://posthog.example.com' )
		);
	}

	public function test_custom_host_trailing_slash_is_trimmed() {
		$this->assertSame(
			'https://posthog.example.com',
			Host::resolve( Host::REGION_CUSTOM, 'https://posthog.example.com/' )
		);
	}

	public function test_custom_host_whitespace_is_trimmed() {
		$this->assertSame(
			'https://posthog.example.com',
			Host::resolve( Host::REGION_CUSTOM, '  https://posthog.example.com  ' )
		);
	}

	public function test_invalid_custom_host_resolves_empty() {
		$this->assertSame( '', Host::resolve( Host::REGION_CUSTOM, 'not a url' ) );
		$this->assertSame( '', Host::resolve( Host::REGION_CUSTOM, '' ) );
	}

	public function test_ftp_scheme_is_not_valid() {
		$this->assertFalse( Host::is_valid_url( 'ftp://posthog.example.com' ) );
	}

	public function test_https_and_http_are_valid() {
		$this->assertTrue( Host::is_valid_url( 'https://posthog.example.com' ) );
		$this->assertTrue( Host::is_valid_url( 'http://localhost:8000' ) );
	}

	public function test_flags_endpoint_is_built_correctly() {
		$this->assertSame(
			'https://us.i.posthog.com/flags?v=2',
			Host::flags_endpoint( Host::US )
		);
		$this->assertSame(
			'https://posthog.example.com/flags?v=2',
			Host::flags_endpoint( 'https://posthog.example.com/' )
		);
	}

	public function test_regions_list() {
		$this->assertSame(
			array( 'us', 'eu', 'custom' ),
			Host::regions()
		);
	}
}
