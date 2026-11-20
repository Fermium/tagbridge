<?php
/**
 * Tests for the server-side consent rule on reading the posthog-js cookie.
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Identity;

use PHPUnit\Framework\TestCase;
use Tagbridge\Modules\PostHog\Consent;

final class ConsentTest extends TestCase {

	const COOKIE = 'wp_consent_statistics';

	private function allowed( array $client, array $cookies, $has_consent = null ) {
		return Consent::cookie_read_allowed( $client, $cookies, $has_consent, self::COOKIE, 'statistics' );
	}

	public function test_consent_gating_is_off_by_default() {
		$defaults = \Tagbridge\Modules\PostHog\Settings::defaults();
		$this->assertFalse( $defaults['client']['require_consent'] );
		$this->assertFalse( Consent::required( $defaults['client'] ), 'Default install keeps cookies, replay and cookie-stitched server events.' );
	}

	public function test_reads_cookie_when_consent_not_required() {
		$this->assertTrue( $this->allowed( array( 'require_consent' => false ), array() ) );
	}

	public function test_cookieless_mode_never_waits_for_consent() {
		$this->assertFalse( Consent::required( array( 'require_consent' => true, 'cookieless' => true ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( Consent::required( array( 'require_consent' => true ) ) );
	}

	public function test_without_consent_api_needs_an_explicit_allow_cookie() {
		$client = array( 'require_consent' => true );
		$this->assertFalse( $this->allowed( $client, array() ), 'No banner, no choice: a leftover posthog cookie is not read.' );
		$this->assertFalse( $this->allowed( $client, array( self::COOKIE => 'deny' ) ) );
		$this->assertFalse( $this->allowed( $client, array( 'wp_consent_marketing' => 'allow' ) ) );
		$this->assertTrue( $this->allowed( $client, array( self::COOKIE => 'allow' ) ) );
	}

	public function test_consent_api_decides_when_active() {
		$client = array( 'require_consent' => true );
		$asked  = array();
		$yes    = function ( $category ) use ( &$asked ) {
			$asked[] = $category;
			return true;
		};
		$no     = function () {
			return false;
		};

		$this->assertTrue( $this->allowed( $client, array(), $yes ), 'Opt-out regions: the Consent API may allow without a cookie.' );
		$this->assertSame( array( 'statistics' ), $asked );
		$this->assertFalse( $this->allowed( $client, array( self::COOKIE => 'allow' ), $no ) );
	}
}
