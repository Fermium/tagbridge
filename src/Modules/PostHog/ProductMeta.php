<?php
/**
 * Product metadata helper.
 *
 * Extracts the category names and descriptive (non-variation) attributes of a
 * WooCommerce product, so events can carry attribute-level context (blade
 * material, origin, and so on) for intent analysis. Variation attributes are
 * excluded here because the selected value rides on the event already.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog;

/**
 * Collects category + attribute metadata for a product.
 */
final class ProductMeta {

	/**
	 * Build a metadata array for a product: categories and descriptive attributes.
	 *
	 * @param \WC_Product|null $product The product.
	 * @return array<string,mixed> { categories: string[], attributes: array<string,string> }
	 */
	public static function collect( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return array();
		}

		$categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );

		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			// Skip variation attributes: the chosen value is captured on the event.
			if ( ! $attribute instanceof \WC_Product_Attribute || $attribute->get_variation() ) {
				continue;
			}

			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
				: $attribute->get_options();

			if ( ! empty( $values ) && ! is_wp_error( $values ) ) {
				$attributes[ wc_attribute_label( $attribute->get_name(), $product ) ] = implode( ', ', (array) $values );
			}
		}

		return array(
			'categories' => is_wp_error( $categories ) ? array() : array_values( $categories ),
			'attributes' => $attributes,
		);
	}

	/**
	 * The product's primary category name (PostHog spec "category"), or null.
	 *
	 * Uses the first assigned product category. Pass the parent for a variation:
	 * variations carry no categories of their own.
	 *
	 * @param \WC_Product|null $product The product.
	 * @return string|null
	 */
	public static function primary_category( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		$names = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		if ( is_wp_error( $names ) || empty( $names ) ) {
			return null;
		}

		return (string) reset( $names );
	}
}
