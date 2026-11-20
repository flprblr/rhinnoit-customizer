<?php
/**
 * Shows a sale item's regular price, struck through, in the cart.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Strikes the regular price through next to the price of an item on sale.
 *
 * Listings and the product page print a sale as the regular price struck
 * through and the sale price; the mini cart, the cart page and the checkout
 * print the sale price alone, so the discount the shopper chose disappears
 * one step before paying. Porto's three templates print the line through
 * WooCommerce's own filters - woocommerce_cart_item_price in the mini cart
 * and the cart's price column, woocommerce_cart_item_subtotal in the cart's
 * subtotal column and the checkout - so the regular price is added there,
 * with WooCommerce's own wc_format_sale_price(): the same markup, screen
 * reader text and styles as the product page.
 *
 * Porto styles a struck price only inside .price or the cart table's cells:
 * in the checkout's product-total div the old price stayed dark and the new
 * one underlined, and in the mini cart its !important gray on every amount
 * hid the red. So the markup gets its own class and a few bytes of CSS that
 * give del Porto's own .price del look (#a7a7a7, 0.8em, the gray its theme
 * stylesheet hard-codes) and let both amounts take their del's and ins's
 * color: the ins red is the shop's own ins rule, never one set here; a shop
 * without one gets Porto's price color, from a :where() fallback that weighs
 * nothing. Measured
 * on hoka's live CSS on 2026-10-05: the mini cart, the cart and the checkout
 * then match the listing.
 *
 * The price shown stays the one WooCommerce computed, untouched, inside the
 * <ins>. The regular price goes through the cart's tax display, for the line's
 * quantity, so both sit on the same footing. Nothing is added when the regular
 * price is not higher, when another filter already struck one through, or
 * when the price is not a string any more (Porto's catalog mode empties it at
 * priority 100, after this).
 */
final class Cart_Sale_Price {

	/**
	 * Class of the span around the struck price.
	 */
	const string WRAPPER = 'rcus-sale-price';

	/**
	 * Handle of the inline style.
	 */
	const string STYLE = 'rcus-cart-sale-price';

	/**
	 * The listing's look for a struck price, wherever the cart prints one.
	 *
	 * Two classes and an element beat Porto's .cart-popup .quantity .amount
	 * !important; font-size needs !important against #mini-cart's id rule.
	 */
	const string CSS = ':where(.rcus-sale-price ins){color:var(--porto-color-price)}'
		. '.rcus-sale-price del{display:inline-block;color:#a7a7a7;font-size:.8em;margin-right:.2143em;text-decoration:line-through;vertical-align:baseline}'
		. '.rcus-sale-price ins{display:inline-block;text-decoration:none;vertical-align:baseline}'
		. '.rcus-sale-price del .woocommerce-Price-amount.amount{color:inherit!important;font-size:inherit!important}'
		. '.rcus-sale-price ins .woocommerce-Price-amount.amount{color:inherit!important}';

	/**
	 * Hooks both price filters while the switch is on.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'cart_sale_price' ) ) {
			return;
		}

		add_filter( 'woocommerce_cart_item_price', array( $this, 'strike_unit_price' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'strike_line_subtotal' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'add_style' ) );
	}

	/**
	 * Prints the CSS in the page head, as an inline style with no file.
	 *
	 * Every page, because the mini cart is in every page's header.
	 *
	 * @return void
	 */
	public function add_style(): void {
		wp_register_style( self::STYLE, false, array(), RCUS_VERSION );
		wp_enqueue_style( self::STYLE );
		wp_add_inline_style( self::STYLE, self::CSS );
	}

	/**
	 * The unit price, in the mini cart and the cart's price column.
	 *
	 * @param mixed $html      Price HTML WooCommerce built.
	 * @param mixed $cart_item The cart line.
	 * @return mixed
	 */
	public function strike_unit_price( mixed $html, mixed $cart_item = null ): mixed {
		return self::strike( $html, $cart_item, 1.0 );
	}

	/**
	 * The line subtotal, in the cart's subtotal column and the checkout.
	 *
	 * @param mixed $html      Subtotal HTML WooCommerce built.
	 * @param mixed $cart_item The cart line.
	 * @return mixed
	 */
	public function strike_line_subtotal( mixed $html, mixed $cart_item = null ): mixed {
		$quantity = is_array( $cart_item ) && is_numeric( $cart_item['quantity'] ?? null ) ? (float) $cart_item['quantity'] : 0.0;

		return self::strike( $html, $cart_item, $quantity );
	}

	/**
	 * Puts the regular price, struck through, before the price.
	 *
	 * @param mixed $html      Price HTML.
	 * @param mixed $cart_item The cart line.
	 * @param float $quantity  Units the price covers.
	 * @return mixed
	 */
	private static function strike( mixed $html, mixed $cart_item, float $quantity ): mixed {
		if ( ! is_string( $html ) || '' === $html || str_contains( $html, '<del' ) || 0 >= $quantity || ! is_array( $cart_item ) ) {
			return $html;
		}

		$product = $cart_item['data'] ?? null;
		$cart    = function_exists( 'WC' ) && WC() instanceof \WooCommerce ? WC()->cart : null;

		if ( ! $product instanceof \WC_Product || ! $cart instanceof \WC_Cart ) {
			return $html;
		}

		$regular = $product->get_regular_price();
		$price   = $product->get_price();

		if ( ! is_numeric( $regular ) || ! is_numeric( $price ) ) {
			return $html;
		}

		$was      = self::to_display( $cart, $product, (float) $regular, $quantity );
		$decimals = wc_get_price_decimals();

		if ( round( $was, $decimals ) <= round( self::to_display( $cart, $product, (float) $price, $quantity ), $decimals ) ) {
			return $html;
		}

		return '<span class="' . self::WRAPPER . '">' . wc_format_sale_price( wc_price( $was ), $html ) . '</span>';
	}

	/**
	 * A price for the given quantity, with or without tax as the cart shows prices.
	 *
	 * @param \WC_Cart    $cart     The cart.
	 * @param \WC_Product $product  The line's product.
	 * @param float       $price    Price per unit, as stored.
	 * @param float       $quantity Units.
	 * @return float
	 */
	private static function to_display( \WC_Cart $cart, \WC_Product $product, float $price, float $quantity ): float {
		$args = array(
			'price' => $price,
			'qty'   => $quantity,
		);

		return (float) ( $cart->display_prices_including_tax() ? wc_get_price_including_tax( $product, $args ) : wc_get_price_excluding_tax( $product, $args ) );
	}
}
