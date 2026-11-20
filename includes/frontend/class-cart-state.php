<?php
/**
 * The one question both journey services ask about the cart.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Answers whether the cart holds anything that has to be shipped.
 *
 * Deliberately stricter than `WC_Cart::needs_shipping()`. That one also answers
 * false for a whole shop — when shipping is switched off, when no shipping
 * method is enabled, or when another plugin filters it — which would take every
 * cart down the shortened journey, not just the ones that deserve it.
 *
 * The checkout is where the shopper picks how the order reaches them, so the
 * only cart allowed to skip a step is one where that question does not exist:
 * every single line is a virtual product. Anything unreadable counts as
 * shippable, because guessing wrong sends someone past a decision they needed
 * to make.
 */
final class Cart_State {

	/**
	 * Whether every line of the cart is a virtual product.
	 *
	 * @param \WC_Cart $cart The loaded cart.
	 * @return bool False for an empty cart: there is no journey to shorten.
	 */
	public static function nothing_to_ship( \WC_Cart $cart ): bool {
		$items = $cart->get_cart();

		if ( ! is_array( $items ) || array() === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			$product = is_array( $item ) && isset( $item['data'] ) ? $item['data'] : null;

			if ( ! $product instanceof \WC_Product || ! $product->is_virtual() ) {
				return false;
			}
		}

		return true;
	}
}
