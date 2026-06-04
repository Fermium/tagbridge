<?php
/**
 * Event taxonomy: the single source of truth for event names.
 *
 * Platform-agnostic. Listeners must never hardcode event-name strings; they
 * reference these constants so the names stay consistent everywhere (and so the
 * dashboards built on them keep working).
 *
 * @package Tagbridge\Core
 */

namespace Tagbridge\Core\Events;

/**
 * Event names and groupings.
 */
final class Schema {

	/**
	 * A WordPress user logged in.
	 *
	 * @var string
	 */
	const USER_LOGGED_IN = 'user_logged_in';

	/**
	 * A new WordPress user registered.
	 *
	 * @var string
	 */
	const USER_REGISTERED = 'user_registered';

	/**
	 * A WooCommerce product page was viewed.
	 *
	 * @var string
	 */
	const PRODUCT_VIEWED = 'product_viewed';

	/**
	 * A WooCommerce product was added to the cart.
	 *
	 * @var string
	 */
	const PRODUCT_ADDED_TO_CART = 'product_added_to_cart';

	/**
	 * A WooCommerce checkout was submitted (order created).
	 *
	 * @var string
	 */
	const CHECKOUT_STARTED = 'checkout_started';

	/**
	 * A WooCommerce order reached the completed status.
	 *
	 * @var string
	 */
	const ORDER_COMPLETED = 'order_completed';

	/**
	 * Core (non-commerce) event names, keyed by their settings key.
	 *
	 * @return array<string,string>
	 */
	public static function core_events() {
		return array(
			'user_logged_in'  => self::USER_LOGGED_IN,
			'user_registered' => self::USER_REGISTERED,
		);
	}

	/**
	 * WooCommerce event names, keyed by their settings key.
	 *
	 * @return array<string,string>
	 */
	public static function woo_events() {
		return array(
			'product_viewed'        => self::PRODUCT_VIEWED,
			'product_added_to_cart' => self::PRODUCT_ADDED_TO_CART,
			'checkout_started'      => self::CHECKOUT_STARTED,
			'order_completed'       => self::ORDER_COMPLETED,
		);
	}

	/**
	 * All event names, keyed by settings key.
	 *
	 * @return array<string,string>
	 */
	public static function all_events() {
		return array_merge( self::core_events(), self::woo_events() );
	}

	/**
	 * Whether a settings key refers to a WooCommerce event.
	 *
	 * @param string $key Settings key.
	 * @return bool
	 */
	public static function is_woo( $key ) {
		return array_key_exists( $key, self::woo_events() );
	}
}
