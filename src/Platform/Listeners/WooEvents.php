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
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Listeners;

use Tagbridge\Platform\Events\Dispatcher;
use Tagbridge\Platform\Identity\Identity;

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
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), 10, 6 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_checkout' ), 10, 3 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_order_completed' ), 10, 1 );
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
				'price'      => (float) $product->get_price(),
				'currency'   => get_woocommerce_currency(),
			)
		);
	}

	/**
	 * Capture an add-to-cart.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $product_id    Product id.
	 * @param int    $quantity      Quantity.
	 * @return void
	 */
	public function on_add_to_cart( $cart_item_key, $product_id, $quantity ) {
		$product = wc_get_product( $product_id );

		$this->dispatcher->capture(
			'product_added_to_cart',
			$this->visitor_distinct_id(),
			array(
				'product_id' => (int) $product_id,
				'name'       => $product ? $product->get_name() : '',
				'quantity'   => (int) $quantity,
				'currency'   => get_woocommerce_currency(),
			)
		);
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
