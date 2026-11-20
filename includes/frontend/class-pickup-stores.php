<?php
/**
 * Shows only the pickup stores that have the size picked.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Hides the WeLaunch Multi Inventory stores a size has none of, home delivery aside.
 *
 * WeLaunch disables the radio of each store without stock for the size
 * picked, from its live answer, and leaves it on the page. A theme that hides
 * WeLaunch's In stock and Out of stock text - hoka's custom CSS does - leaves
 * a faint grey radio as the only sign, and shoppers could not tell which
 * stores had the size. One rule hides each row whose radio is disabled,
 * except home delivery: whatever sits in WeLaunch's Click & Collect delivery
 * block, and the row of the store Store_Choice ticks as home delivery, stay
 * in sight with stock or without (owner's decision, 2026-10-27). A second
 * hides the pickup block, title included, when no store in it is enabled. A
 * size sold out everywhere is already unavailable on the size picker.
 *
 * The rows follow every redraw on their own, as WeLaunch enables and
 * disables the radios; before a size is picked WeLaunch keeps the list
 * hidden. Both rules are !important: hoka's custom CSS forces those rows to
 * display:inline!important. A browser without :has() drops them and shows
 * the whole list, as before. Without WeLaunch it does nothing.
 */
final class Pickup_Stores {

	/**
	 * Handle WeLaunch registers its storefront stylesheet under.
	 */
	const string STYLE = 'woocommerce-multi-inventory-public';

	/**
	 * Hooks the style while the switch is on and WeLaunch is active.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'pickup_stores_in_stock' ) || ! function_exists( 'welaunch_woocommerce_multi_inventory_run' ) ) {
			return;
		}

		// After WeLaunch enqueues its stylesheet, at 10.
		add_action( 'wp_enqueue_scripts', array( $this, 'add_style' ), 100 );
	}

	/**
	 * Prints the rules after WeLaunch's stylesheet, wherever it loads.
	 *
	 * @return void
	 */
	public function add_style(): void {
		// The store Store_Choice ticks as home delivery: 280, the online store, on hoka.
		wp_add_inline_style( self::STYLE, self::css( Store_Choice::delivery_store() ) );
	}

	/**
	 * The rules, with home delivery's row kept in sight.
	 *
	 * @param int $delivery_store The home delivery store, 0 when WeLaunch's settings name none.
	 * @return string
	 */
	public static function css( int $delivery_store ): string {
		$keep = 0 < $delivery_store ? ':not(.woocommerce-multi-inventory-inventories-row-inventory-' . $delivery_store . ')' : '';

		return '.woocommerce-multi-inventory-inventories-row:not(.woocommerce-multi-inventory-inventories-delivery-container > *)' . $keep
			. ':has(> .woocommerce-multi-inventory-inventories-radio input[type="radio"]:disabled){display:none!important}'
			. '.woocommerce-multi-inventory-inventories-click-collect-container:not(:has(input[type="radio"]:enabled)){display:none!important}';
	}
}
