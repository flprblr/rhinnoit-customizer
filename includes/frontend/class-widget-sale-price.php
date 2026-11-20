<?php
/**
 * Paints a sale price in Porto's product widgets the color the listings use.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the sale price in a product widget take the shop's ins color.
 *
 * Listings print a sale as the regular price struck through in gray and the
 * sale price in the shop's own ins color (hoka's custom CSS makes every ins
 * #DC3545). Porto's .product_list_widget .product-details .amount gives every
 * amount in its product widgets - the products beside a product page - the
 * theme's price color, and the amount inside the ins sits under that rule,
 * so the sale price came out in the price color instead of red. One rule a
 * class more specific lets that amount inherit its ins's color, and the ins
 * itself gets Porto's price color inside :where(), which weighs nothing: any
 * ins rule of the shop's wins over it, and a shop without one keeps the
 * price color the widget had (an ins inheriting the plain text color would
 * have turned it the lighter body color).
 * Measured on hoka's product page on 2026-10-05: the widget's sale prices
 * turn #DC3545, the struck prices stay #A7A7A7, and the mini cart, which
 * shares the classes, keeps its own !important gray.
 */
final class Widget_Sale_Price {

	/**
	 * Handle of the inline style.
	 */
	const string STYLE = 'rcus-widget-sale-price';

	/**
	 * A fallback no ins rule loses to, then three classes and an element over Porto's three classes.
	 */
	const string CSS = ':where(.product_list_widget .product-details ins){color:var(--porto-color-price)}'
		. '.product_list_widget .product-details ins .woocommerce-Price-amount.amount{color:inherit}';

	/**
	 * Hooks the style while the switch is on.
	 *
	 * Porto is asked for when the style is added, never here: plugins boot
	 * on plugins_loaded, before the theme's functions.php is read, and 2.7.10
	 * asked here and hooked nothing on any Porto shop.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'widget_sale_price' ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'add_style' ) );
	}

	/**
	 * Prints the rule in the page head, as an inline style with no file.
	 *
	 * @return void
	 */
	public function add_style(): void {
		if ( ! function_exists( 'porto_widget_product_thumbnail' ) ) {
			return;
		}

		wp_register_style( self::STYLE, false, array(), RCUS_VERSION );
		wp_enqueue_style( self::STYLE );
		wp_add_inline_style( self::STYLE, self::CSS );
	}
}
