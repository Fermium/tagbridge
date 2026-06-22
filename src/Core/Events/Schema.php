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
	 * A WooCommerce order was placed (checkout submitted / order created).
	 *
	 * @var string
	 */
	const ORDER_PLACED = 'order_placed';

	/**
	 * A WooCommerce order reached the completed status.
	 *
	 * @var string
	 */
	const ORDER_COMPLETED = 'order_completed';

	/**
	 * A line item from a completed WooCommerce order (one event per product).
	 *
	 * @var string
	 */
	const PRODUCT_PURCHASED = 'product_purchased';

	/**
	 * A WooCommerce product list (shop, category, or tag archive) was viewed.
	 *
	 * @var string
	 */
	const PRODUCT_LIST_VIEWED = 'product_list_viewed';

	/**
	 * A search was performed (query + result count).
	 *
	 * @var string
	 */
	const PRODUCTS_SEARCHED = 'products_searched';

	/**
	 * A WooCommerce product was removed from the cart.
	 *
	 * @var string
	 */
	const PRODUCT_REMOVED_FROM_CART = 'product_removed_from_cart';

	/**
	 * The WooCommerce cart page was viewed.
	 *
	 * @var string
	 */
	const CART_VIEWED = 'cart_viewed';

	/**
	 * A coupon was applied to the cart.
	 *
	 * @var string
	 */
	const COUPON_APPLIED = 'coupon_applied';

	/**
	 * A coupon was removed from the cart.
	 *
	 * @var string
	 */
	const COUPON_REMOVED = 'coupon_removed';

	/**
	 * A WooCommerce order's payment failed.
	 *
	 * @var string
	 */
	const PAYMENT_FAILED = 'payment_failed';

	/**
	 * A WooCommerce order was refunded (fully or partially).
	 *
	 * @var string
	 */
	const ORDER_REFUNDED = 'order_refunded';

	/**
	 * A WooCommerce order was cancelled.
	 *
	 * @var string
	 */
	const ORDER_CANCELLED = 'order_cancelled';

	/**
	 * A visitor loaded the WooCommerce checkout page.
	 *
	 * @var string
	 */
	const CHECKOUT_VIEWED = 'checkout_viewed';

	/**
	 * A visitor submitted a WooCommerce product review.
	 *
	 * @var string
	 */
	const PRODUCT_REVIEW_SUBMITTED = 'product_review_submitted';

	/**
	 * A WordPress user explicitly logged out.
	 *
	 * @var string
	 */
	const USER_LOGGED_OUT = 'user_logged_out';

	/**
	 * Core (non-commerce) event names, keyed by their settings key.
	 *
	 * @return array<string,string>
	 */
	public static function core_events() {
		return array(
			'user_logged_in'  => self::USER_LOGGED_IN,
			'user_registered' => self::USER_REGISTERED,
			'user_logged_out' => self::USER_LOGGED_OUT,
		);
	}

	/**
	 * WooCommerce event names, keyed by their settings key.
	 *
	 * @return array<string,string>
	 */
	public static function woo_events() {
		return array(
			'product_viewed'            => self::PRODUCT_VIEWED,
			'product_list_viewed'       => self::PRODUCT_LIST_VIEWED,
			'products_searched'         => self::PRODUCTS_SEARCHED,
			'product_added_to_cart'     => self::PRODUCT_ADDED_TO_CART,
			'product_removed_from_cart' => self::PRODUCT_REMOVED_FROM_CART,
			'cart_viewed'               => self::CART_VIEWED,
			'coupon_applied'            => self::COUPON_APPLIED,
			'coupon_removed'            => self::COUPON_REMOVED,
			'checkout_viewed'           => self::CHECKOUT_VIEWED,
			'order_placed'              => self::ORDER_PLACED,
			'order_completed'           => self::ORDER_COMPLETED,
			'product_purchased'         => self::PRODUCT_PURCHASED,
			'payment_failed'            => self::PAYMENT_FAILED,
			'order_refunded'            => self::ORDER_REFUNDED,
			'order_cancelled'           => self::ORDER_CANCELLED,
			'product_review_submitted'  => self::PRODUCT_REVIEW_SUBMITTED,
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
