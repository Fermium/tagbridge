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

	public function test_parse_session_id_from_valid_cookie() {
		$cookie = '{"distinct_id":"abc-123","$sesid":[1700000000000,"0192abcd-uuid","1699999999000"]}';
		$this->assertSame( '0192abcd-uuid', Resolver::parse_session_id( $cookie ) );
	}

	public function test_parse_session_id_from_url_encoded_cookie() {
		$cookie = rawurlencode( '{"$sesid":[1,"enc-session",1]}' );
		$this->assertSame( 'enc-session', Resolver::parse_session_id( $cookie ) );
	}

	public function test_parse_session_id_returns_null_when_cookie_absent_or_malformed() {
		$this->assertNull( Resolver::parse_session_id( null ) );
		$this->assertNull( Resolver::parse_session_id( '' ) );
		$this->assertNull( Resolver::parse_session_id( 'not-json' ) );
	}

	public function test_parse_session_id_returns_null_when_sesid_missing_or_wrong_shape() {
		$this->assertNull( Resolver::parse_session_id( '{"distinct_id":"abc-123"}' ) );
		$this->assertNull( Resolver::parse_session_id( '{"$sesid":"not-an-array"}' ) );
		$this->assertNull( Resolver::parse_session_id( '{"$sesid":[1700000000000]}' ) );
		$this->assertNull( Resolver::parse_session_id( '{"$sesid":[1700000000000,""]}' ) );
		$this->assertNull( Resolver::parse_session_id( '{"$sesid":[1700000000000,123]}' ) );
	}

	public function test_parse_session_id_returns_id_when_still_fresh() {
		$now      = 1700000000000;
		$activity = $now - 1000;      // 1s idle.
		$start    = $now - 60000;     // 1min old.
		$cookie   = '{"$sesid":[' . $activity . ',"fresh-session",' . $start . ']}';
		$this->assertSame( 'fresh-session', Resolver::parse_session_id( $cookie, $now ) );
	}

	public function test_parse_session_id_returns_null_when_idle_past_the_timeout() {
		$now      = 1700000000000;
		$activity = $now - ( Resolver::SESSION_MAX_IDLE_MS + 1000 ); // idle > 30min.
		$cookie   = '{"$sesid":[' . $activity . ',"stale-session",' . ( $activity - 1000 ) . ']}';
		$this->assertNull( Resolver::parse_session_id( $cookie, $now ) );
	}

	public function test_parse_session_id_returns_null_when_session_exceeds_max_length() {
		$now      = 1700000000000;
		$activity = $now - 1000; // recently active...
		$start    = $now - ( Resolver::SESSION_MAX_LENGTH_MS + 1000 ); // ...but started > 24h ago.
		$cookie   = '{"$sesid":[' . $activity . ',"aged-session",' . $start . ']}';
		$this->assertNull( Resolver::parse_session_id( $cookie, $now ) );
	}

	public function test_parse_session_id_skips_freshness_check_without_a_clock() {
		// No $now_ms given: return the id regardless of how old the timestamps are.
		$cookie = '{"$sesid":[1,"ancient-session",1]}';
		$this->assertSame( 'ancient-session', Resolver::parse_session_id( $cookie ) );
	}

	public function test_parse_session_id_ignores_non_numeric_timestamps() {
		$now    = 1700000000000;
		$cookie = '{"$sesid":[null,"no-ts-session",null]}';
		$this->assertSame( 'no-ts-session', Resolver::parse_session_id( $cookie, $now ) );
	}

	public function test_is_user_id_true_for_a_stable_user_id() {
		$id = Resolver::stable_user_id( 7, 'salt' );
		$this->assertTrue( Resolver::is_user_id( $id ) );
		$this->assertTrue( Resolver::is_user_id( 'wp_anything' ) );
	}

	public function test_is_user_id_false_for_anonymous_ids() {
		$this->assertFalse( Resolver::is_user_id( '0192abcd-1234-7000-8000-000000000000' ) ); // posthog uuid.
		$this->assertFalse( Resolver::is_user_id( 'wc_sess_42' ) );
		$this->assertFalse( Resolver::is_user_id( 'wc_order_100' ) );
		$this->assertFalse( Resolver::is_user_id( '' ) );
	}

	public function test_is_user_id_false_for_non_strings() {
		$this->assertFalse( Resolver::is_user_id( null ) );
		$this->assertFalse( Resolver::is_user_id( 123 ) );
	}
}
