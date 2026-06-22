/**
 * Tagbridge — capture cart_viewed when the CheckoutWC side-cart opens.
 *
 * CheckoutWC replaces the cart page with a slide-out side-cart, so the
 * server-side cart_viewed (which needs a real cart page) never fires. CheckoutWC
 * adds the `cfw-side-cart-open` class to <body> when the drawer opens; we watch
 * for that and send one cart_viewed per open. Stores with a real cart page keep
 * using the server-side event.
 */
( function () {
	if ( typeof window === 'undefined' || typeof document === 'undefined' ) {
		return;
	}

	var OPEN_CLASS = 'cfw-side-cart-open';
	var settings = window.tagbridgeSideCart || {};
	var wasOpen = false;

	function sideCartItemCount() {
		try {
			var items = document.querySelectorAll(
				'.cfw-side-cart-contents .cfw-cart-item, .cfw-side-cart .cfw-cart-item'
			);
			return items.length || null;
		} catch ( e ) {
			return null;
		}
	}

	function capture() {
		if ( ! window.posthog || typeof window.posthog.capture !== 'function' ) {
			return;
		}
		// Don't double-count on a real cart page (server-side already covers it).
		if ( document.body.classList.contains( 'woocommerce-cart' ) ) {
			return;
		}
		window.posthog.capture( 'cart_viewed', {
			source: 'side_cart',
			item_count: sideCartItemCount(),
			currency: settings.currency || null
		} );
	}

	function check() {
		var isOpen = document.body.classList.contains( OPEN_CLASS );
		if ( isOpen && ! wasOpen ) {
			capture();
		}
		wasOpen = isOpen;
	}

	function start() {
		if ( ! document.body ) {
			return;
		}
		wasOpen = document.body.classList.contains( OPEN_CLASS );
		var observer = new MutationObserver( check );
		observer.observe( document.body, { attributes: true, attributeFilter: [ 'class' ] } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
