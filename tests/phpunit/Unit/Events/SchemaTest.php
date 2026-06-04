<?php
/**
 * Tests for the event Schema.
 *
 * @package Tagbridge\Tests
 */

namespace Tagbridge\Tests\Unit\Events;

use Tagbridge\Core\Events\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( Schema::class )]
final class SchemaTest extends TestCase {

	public function test_event_names_are_stable() {
		$this->assertSame( 'user_logged_in', Schema::USER_LOGGED_IN );
		$this->assertSame( 'user_registered', Schema::USER_REGISTERED );
		$this->assertSame( 'product_viewed', Schema::PRODUCT_VIEWED );
		$this->assertSame( 'product_added_to_cart', Schema::PRODUCT_ADDED_TO_CART );
		$this->assertSame( 'checkout_started', Schema::CHECKOUT_STARTED );
		$this->assertSame( 'order_completed', Schema::ORDER_COMPLETED );
	}

	public function test_core_and_woo_events_partition_all_events() {
		$all = Schema::all_events();
		$this->assertSame(
			array_merge( Schema::core_events(), Schema::woo_events() ),
			$all
		);
		$this->assertCount( 6, $all );
	}

	public function test_keys_map_to_their_event_name() {
		foreach ( Schema::all_events() as $key => $name ) {
			$this->assertSame( $key, $name, 'Settings key should match the event name.' );
		}
	}

	public function test_is_woo_identifies_commerce_events() {
		$this->assertTrue( Schema::is_woo( 'order_completed' ) );
		$this->assertTrue( Schema::is_woo( 'product_viewed' ) );
		$this->assertFalse( Schema::is_woo( 'user_logged_in' ) );
		$this->assertFalse( Schema::is_woo( 'nonexistent' ) );
	}
}
