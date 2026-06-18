<?php
/**
 * WooCommerce event listeners.
 *
 * Only registers when WooCommerce is active. Uses WooCommerce CRUD objects
 * (HPOS-safe), never direct post meta on orders. Event names come from the
 * Schema. The distinct id is stored on the order at checkout so the later
 * "order completed" event (which may fire from the admin) still resolves to the
 * same person as the checkout session.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog\Listeners;

use Tagbridge\Modules\PostHog\Events\Dispatcher;
use Tagbridge\Modules\PostHog\Identity;
use Tagbridge\Modules\PostHog\ProductMeta;

/**
 * Listens for WooCommerce commerce events.
 */
final class WooEvents {

	/**
	 * Order meta key storing the resolved distinct id at checkout time.
	 *
	 * @var string
	 */
	const ORDER_DISTINCT_ID_META = '_tagbridge_distinct_id';

	/**
	 * The event dispatcher.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Constructor.
	 *
	 * @param Dispatcher $dispatcher Event dispatcher.
	 */
	public function __construct( Dispatcher $dispatcher ) {
		$this->dispatcher = $dispatcher;
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Register hooks, only when WooCommerce is active.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! self::woocommerce_active() ) {
			return;
		}

		add_action( 'template_redirect', array( $this, 'on_product_view' ) );
		add_action( 'template_redirect', array( $this, 'on_product_list_view' ) );
		add_action( 'template_redirect', array( $this, 'on_search' ) );
		add_action( 'template_redirect', array( $this, 'on_cart_view' ) );
		add_action( 'template_redirect', array( $this, 'on_checkout_view' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), 10, 6 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'on_remove_from_cart' ), 10, 2 );
		add_action( 'woocommerce_applied_coupon', array( $this, 'on_coupon_applied' ), 10, 1 );
		add_action( 'woocommerce_removed_coupon', array( $this, 'on_coupon_removed' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_checkout' ), 10, 3 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_order_completed' ), 10, 1 );
		add_action( 'woocommerce_order_status_failed', array( $this, 'on_payment_failed' ), 10, 2 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_order_cancelled' ), 10, 2 );
		add_action( 'comment_post', array( $this, 'on_product_review' ), 10, 3 );
	}

	/**
	 * Capture a product view on the single product page.
	 *
	 * @return void
	 */
	public function on_product_view() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product ) {
			return;
		}

		$this->dispatcher->capture(
			'product_viewed',
			$this->visitor_distinct_id(),
			array(
				'product_id' => $product->get_id(),
				'name'       => $product->get_name(),
				'sku'        => $product->get_sku(),
				'price'      => (float) $product->get_price(),
				'currency'   => get_woocommerce_currency(),
			) + ProductMeta::collect( $product )
		);
	}

	/**
	 * Capture an add-to-cart, including the specific SKU/variant being added.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $product_id    Product id.
	 * @param int    $quantity      Quantity.
	 * @param int    $variation_id  Variation id (0 for simple products).
	 * @param array  $variation     Chosen variation attributes (slug values).
	 * @return void
	 */
	public function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = array() ) {
		$product = wc_get_product( $product_id );

		$props = array(
			'product_id' => (int) $product_id,
			'name'       => $product ? $product->get_name() : '',
			'quantity'   => (int) $quantity,
			'currency'   => get_woocommerce_currency(),
		);

		// The exact SKU/variant added — the strongest "which SKU do people want" signal.
		if ( $variation_id ) {
			$variation_product     = wc_get_product( $variation_id );
			$props['variation_id'] = (int) $variation_id;
			$props['sku']          = $variation_product ? $variation_product->get_sku() : null;
			$props['variant']      = $this->readable_variation( $variation );
		} elseif ( $product ) {
			$props['sku'] = $product->get_sku();
		}

		if ( $product ) {
			$props += ProductMeta::collect( $product );
		}

		$this->dispatcher->capture( 'product_added_to_cart', $this->visitor_distinct_id(), $props );
	}

	/**
	 * Turn a chosen-variation array (attribute_pa_size => m) into a readable
	 * string (size: m), or null when empty.
	 *
	 * @param array $variation Chosen variation attributes.
	 * @return string|null
	 */
	private function readable_variation( $variation ) {
		if ( ! is_array( $variation ) || empty( $variation ) ) {
			return null;
		}

		$parts = array();
		foreach ( $variation as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$key     = preg_replace( '/^attribute_(pa_)?/', '', (string) $key );
			$parts[] = $key . ': ' . $value;
		}

		return $parts ? implode( ', ', $parts ) : null;
	}

	/**
	 * Capture checkout submission and remember the distinct id on the order.
	 *
	 * @param int       $order_id    Order id.
	 * @param array     $posted_data Posted checkout data.
	 * @param \WC_Order $order      The order.
	 * @return void
	 */
	public function on_checkout( $order_id, $posted_data, $order = null ) {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		$distinct_id = $this->visitor_distinct_id();

		// Persist so "order completed" later resolves to the same person.
		$order->update_meta_data( self::ORDER_DISTINCT_ID_META, $distinct_id );
		$order->save();

		$this->dispatcher->capture(
			'checkout_started',
			$distinct_id,
			array(
				'order_id'   => $order->get_id(),
				'value'      => (float) $order->get_total(),
				'currency'   => $order->get_currency(),
				'item_count' => $order->get_item_count(),
			)
		);
	}

	/**
	 * Capture a completed order.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function on_order_completed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$this->dispatcher->capture(
			'order_completed',
			$this->order_distinct_id( $order ),
			array(
				'order_id'   => $order->get_id(),
				'value'      => (float) $order->get_total(),
				'currency'   => $order->get_currency(),
				'item_count' => $order->get_item_count(),
			)
		);
	}

	/**
	 * Capture a product-list view (shop, category, or tag archive).
	 *
	 * @return void
	 */
	public function on_product_list_view() {
		if ( ! function_exists( 'is_shop' ) ) {
			return;
		}

		$term = null;
		if ( is_shop() ) {
			$list_type = 'shop';
		} elseif ( is_product_category() ) {
			$list_type = 'category';
			$term      = get_queried_object();
		} elseif ( is_product_tag() ) {
			$list_type = 'tag';
			$term      = get_queried_object();
		} else {
			return;
		}

		$this->dispatcher->capture(
			'product_list_viewed',
			$this->visitor_distinct_id(),
			array(
				'list_type'     => $list_type,
				'term_id'       => $term instanceof \WP_Term ? (int) $term->term_id : null,
				'term_name'     => $term instanceof \WP_Term ? $term->name : null,
				'product_count' => isset( $GLOBALS['wp_query'] ) ? (int) $GLOBALS['wp_query']->found_posts : null,
			)
		);
	}

	/**
	 * Capture a search, with the query and the number of results.
	 *
	 * @return void
	 */
	public function on_search() {
		if ( ! function_exists( 'is_search' ) || ! is_search() ) {
			return;
		}

		$this->dispatcher->capture(
			'products_searched',
			$this->visitor_distinct_id(),
			array(
				'query'        => get_search_query(),
				'result_count' => isset( $GLOBALS['wp_query'] ) ? (int) $GLOBALS['wp_query']->found_posts : null,
			)
		);
	}

	/**
	 * Capture a view of the cart page.
	 *
	 * @return void
	 */
	public function on_cart_view() {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}

		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		$this->dispatcher->capture(
			'cart_viewed',
			$this->visitor_distinct_id(),
			array(
				'item_count' => $cart ? (int) $cart->get_cart_contents_count() : null,
				'value'      => $cart ? (float) $cart->get_cart_contents_total() : null,
				'currency'   => get_woocommerce_currency(),
			)
		);
	}

	/**
	 * Capture a cart item removal.
	 *
	 * @param string        $cart_item_key Removed cart item key.
	 * @param \WC_Cart|null $cart          The cart instance.
	 * @return void
	 */
	public function on_remove_from_cart( $cart_item_key, $cart = null ) {
		$removed = ( $cart instanceof \WC_Cart && isset( $cart->removed_cart_contents[ $cart_item_key ] ) )
			? $cart->removed_cart_contents[ $cart_item_key ]
			: array();

		$product_id = isset( $removed['product_id'] ) ? (int) $removed['product_id'] : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		$this->dispatcher->capture(
			'product_removed_from_cart',
			$this->visitor_distinct_id(),
			array(
				'product_id' => $product_id,
				'name'       => $product ? $product->get_name() : '',
				'quantity'   => isset( $removed['quantity'] ) ? (int) $removed['quantity'] : null,
				'currency'   => get_woocommerce_currency(),
			)
		);
	}

	/**
	 * Capture a coupon being applied.
	 *
	 * @param string $coupon_code The coupon code.
	 * @return void
	 */
	public function on_coupon_applied( $coupon_code ) {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		$this->dispatcher->capture(
			'coupon_applied',
			$this->visitor_distinct_id(),
			array(
				'coupon_code'    => (string) $coupon_code,
				'cart_value'     => $cart ? (float) $cart->get_cart_contents_total() : null,
				'discount_total' => $cart ? (float) $cart->get_discount_total() : null,
				'currency'       => get_woocommerce_currency(),
			)
		);
	}

	/**
	 * Capture a coupon being removed.
	 *
	 * @param string $coupon_code The coupon code.
	 * @return void
	 */
	public function on_coupon_removed( $coupon_code ) {
		$this->dispatcher->capture(
			'coupon_removed',
			$this->visitor_distinct_id(),
			array(
				'coupon_code' => (string) $coupon_code,
				'currency'    => get_woocommerce_currency(),
			)
		);
	}

	/**
	 * Capture a failed payment.
	 *
	 * @param int            $order_id Order id.
	 * @param \WC_Order|null $order   The order.
	 * @return void
	 */
	public function on_payment_failed( $order_id, $order = null ) {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		$this->dispatcher->capture(
			'payment_failed',
			$this->order_distinct_id( $order ),
			array(
				'order_id'       => $order->get_id(),
				'value'          => (float) $order->get_total(),
				'currency'       => $order->get_currency(),
				'payment_method' => $order->get_payment_method(),
			)
		);
	}

	/**
	 * Capture an order refund (full or partial).
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 * @return void
	 */
	public function on_order_refunded( $order_id, $refund_id = 0 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$refund        = $refund_id ? wc_get_order( $refund_id ) : null;
		$refund_amount = $refund instanceof \WC_Order_Refund
			? (float) $refund->get_amount()
			: (float) $order->get_total_refunded();

		$this->dispatcher->capture(
			'order_refunded',
			$this->order_distinct_id( $order ),
			array(
				'order_id'      => $order->get_id(),
				'refund_id'     => (int) $refund_id,
				'refund_amount' => $refund_amount,
				'order_value'   => (float) $order->get_total(),
				'currency'      => $order->get_currency(),
			)
		);
	}

	/**
	 * Capture a cancelled order.
	 *
	 * @param int            $order_id Order id.
	 * @param \WC_Order|null $order   The order.
	 * @return void
	 */
	public function on_order_cancelled( $order_id, $order = null ) {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		$this->dispatcher->capture(
			'order_cancelled',
			$this->order_distinct_id( $order ),
			array(
				'order_id' => $order->get_id(),
				'value'    => (float) $order->get_total(),
				'currency' => $order->get_currency(),
			)
		);
	}

	/**
	 * Capture a view of the checkout page.
	 *
	 * @return void
	 */
	public function on_checkout_view() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		// Skip the order-received / thank-you page — that is order_completed territory.
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return;
		}

		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		$this->dispatcher->capture(
			'checkout_viewed',
			$this->visitor_distinct_id(),
			array(
				'item_count' => $cart ? (int) $cart->get_cart_contents_count() : null,
				'value'      => $cart ? (float) $cart->get_cart_contents_total() : null,
				'currency'   => get_woocommerce_currency(),
			)
		);
	}

	/**
	 * Capture a WooCommerce product review submission.
	 *
	 * @param int        $comment_id       The new comment id.
	 * @param int|string $comment_approved 1 if approved, 0 if pending, 'spam'.
	 * @param array      $comment_data     The comment data array.
	 * @return void
	 */
	public function on_product_review( $comment_id, $comment_approved, $comment_data ) {
		// Only track approved reviews on product post types.
		if ( 1 !== (int) $comment_approved ) {
			return;
		}

		$post_id = isset( $comment_data['comment_post_ID'] ) ? (int) $comment_data['comment_post_ID'] : 0;
		if ( ! $post_id || 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		$rating  = get_comment_meta( $comment_id, 'rating', true );
		$product = wc_get_product( $post_id );

		$this->dispatcher->capture(
			'product_review_submitted',
			$this->visitor_distinct_id(),
			array(
				'product_id' => $post_id,
				'name'       => $product ? $product->get_name() : '',
				'rating'     => $rating ? (int) $rating : null,
			)
		);
	}

	/**
	 * Distinct id for an in-session visitor (logged-in stable id or cookie id),
	 * with a generated fallback when neither is available.
	 *
	 * @return string
	 */
	private function visitor_distinct_id() {
		$resolved = Identity::server_distinct_id();
		return '' !== $resolved['distinct_id'] ? $resolved['distinct_id'] : wp_generate_uuid4();
	}

	/**
	 * Distinct id for an order, preferring the id saved at checkout, then the
	 * customer's stable id, then a deterministic per-order fallback.
	 *
	 * @param \WC_Order $order The order.
	 * @return string
	 */
	private function order_distinct_id( \WC_Order $order ) {
		$saved = (string) $order->get_meta( self::ORDER_DISTINCT_ID_META );
		if ( '' !== $saved ) {
			return $saved;
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 ) {
			return Identity::stable_id_for_user( $customer_id );
		}

		return 'wc_order_' . $order->get_id();
	}
}
