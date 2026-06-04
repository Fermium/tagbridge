<?php
/**
 * Tests for the platform-agnostic identity Resolver.
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Identity;

use Tagbridge\Core\Identity\Resolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( Resolver::class )]
final class ResolverTest extends TestCase {

	public function test_cookie_name_is_built_from_the_project_key() {
		$this->assertSame( 'ph_phc_abc123_posthog', Resolver::cookie_name( 'phc_abc123' ) );
	}

	public function test_parse_distinct_id_from_valid_json() {
		$cookie = '{"distinct_id":"abc-123","$sesid":[1,2]}';
		$this->assertSame( 'abc-123', Resolver::parse_distinct_id( $cookie ) );
	}

	public function test_parse_distinct_id_from_url_encoded_json() {
		$cookie = rawurlencode( '{"distinct_id":"enc-456"}' );
		$this->assertSame( 'enc-456', Resolver::parse_distinct_id( $cookie ) );
	}

	public function test_parse_distinct_id_returns_null_when_cookie_absent() {
		$this->assertNull( Resolver::parse_distinct_id( null ) );
		$this->assertNull( Resolver::parse_distinct_id( '' ) );
	}

	public function test_parse_distinct_id_returns_null_for_malformed_json() {
		$this->assertNull( Resolver::parse_distinct_id( 'not-json-at-all' ) );
		$this->assertNull( Resolver::parse_distinct_id( '{broken' ) );
	}

	public function test_parse_distinct_id_returns_null_when_key_missing_or_empty() {
		$this->assertNull( Resolver::parse_distinct_id( '{"foo":"bar"}' ) );
		$this->assertNull( Resolver::parse_distinct_id( '{"distinct_id":""}' ) );
		$this->assertNull( Resolver::parse_distinct_id( '{"distinct_id":123}' ) );
	}

	public function test_stable_user_id_is_deterministic_and_prefixed() {
		$a = Resolver::stable_user_id( 42, 'salt-1' );
		$b = Resolver::stable_user_id( 42, 'salt-1' );
		$this->assertSame( $a, $b );
		$this->assertStringStartsWith( 'wp_', $a );
	}

	public function test_stable_user_id_changes_with_user_and_salt() {
		$base = Resolver::stable_user_id( 42, 'salt-1' );
		$this->assertNotSame( $base, Resolver::stable_user_id( 43, 'salt-1' ) );
		$this->assertNotSame( $base, Resolver::stable_user_id( 42, 'salt-2' ) );
	}

	public function test_stable_user_id_does_not_leak_the_raw_id() {
		// The id must be a hash, not just the raw user id appended to the prefix.
		$id = Resolver::stable_user_id( 42, 'salt-1' );
		$this->assertNotSame( 'wp_42', $id );
		$this->assertSame( 64, strlen( substr( $id, 3 ) ) ); // sha256 hex.
	}

	public function test_resolve_prefers_identified_id() {
		$result = Resolver::resolve_distinct_id( 'cookie-id', 'wp_user-id' );
		$this->assertSame( 'wp_user-id', $result['distinct_id'] );
		$this->assertSame( Resolver::SOURCE_IDENTIFIED, $result['source'] );
	}

	public function test_resolve_falls_back_to_cookie_when_anonymous() {
		$result = Resolver::resolve_distinct_id( 'cookie-id', null );
		$this->assertSame( 'cookie-id', $result['distinct_id'] );
		$this->assertSame( Resolver::SOURCE_COOKIE, $result['source'] );
	}

	public function test_resolve_uses_fallback_when_no_cookie_or_identity() {
		$result = Resolver::resolve_distinct_id( null, null, 'fallback-id' );
		$this->assertSame( 'fallback-id', $result['distinct_id'] );
		$this->assertSame( Resolver::SOURCE_FALLBACK, $result['source'] );
	}

	public function test_resolve_returns_none_when_nothing_available() {
		$result = Resolver::resolve_distinct_id( null, null );
		$this->assertSame( '', $result['distinct_id'] );
		$this->assertSame( Resolver::SOURCE_NONE, $result['source'] );
	}

	public function test_person_properties_keeps_allowed_and_drops_empty() {
		$props = Resolver::person_properties(
			array(
				'email'   => '  user@example.com ',
				'name'    => 'Jane Doe',
				'role'    => '',
				'unknown' => 'ignored',
			)
		);
		$this->assertSame(
			array(
				'email' => 'user@example.com',
				'name'  => 'Jane Doe',
			),
			$props
		);
	}

	public function test_person_properties_empty_input_returns_empty() {
		$this->assertSame( array(), Resolver::person_properties( array() ) );
	}
}
