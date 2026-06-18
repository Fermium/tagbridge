/**
 * Tagbridge - capture WooCommerce product variant selections.
 *
 * Listens to WooCommerce's variation form and sends a `product_variant_selected`
 * event to PostHog when a shopper picks a complete variation (size, colour, and
 * so on). Works with variation dropdowns and swatch plugins, which sync the same
 * underlying selects. Requires jQuery, which WooCommerce loads on variable
 * product pages.
 */
( function () {
	'use strict';

	if ( ! window.jQuery ) {
		return;
	}

	window.jQuery( function ( $ ) {
		$( '.variations_form' ).each( function () {
			var $form = $( this );

			$form.on( 'found_variation', function ( event, variation ) {
				if ( ! window.posthog || typeof window.posthog.capture !== 'function' ) {
					return;
				}

				var attributes = {};
				$form.find( '.variations select' ).each( function () {
					var $select = $( this );
					var key = ( $select.data( 'attribute_name' ) || $select.attr( 'name' ) || '' )
						.replace( /^attribute_(pa_)?/, '' );
					if ( key ) {
						attributes[ key ] = $select.find( 'option:selected' ).text() || $select.val();
					}
				} );

				var titleEl = document.querySelector( '.product_title' );

				window.posthog.capture( 'product_variant_selected', {
					product_id: $form.data( 'product_id' ) || null,
					product_name: titleEl ? titleEl.textContent.trim() : null,
					variation_id: variation ? variation.variation_id : null,
					price: variation ? variation.display_price : null,
					in_stock: variation ? variation.is_in_stock : null,
					variant: Object.keys( attributes ).map( function ( k ) {
						return k + ': ' + attributes[ k ];
					} ).join( ', ' ),
					attributes: attributes
				} );
			} );
		} );
	} );
}() );
