<?php
/**
 * Sends the store picked in Porto's quick view with the add to cart.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Copies the ticked WeLaunch Multi Inventory store into Porto's AJAX add to cart.
 *
 * Porto's quick view adds to the cart over admin-ajax with a request it
 * builds itself: product, quantity and the variation's attributes, nothing
 * else from the form. WeLaunch reads the store a line ships from or is
 * picked up at only from the posted form, so a shopper who ticked a pickup
 * store in the quick view got the line under the online store - and when
 * that store had none of the size, checkout refused it. Porto announces the
 * request with adding_to_cart, handing over the object it is about to post,
 * so the store ticked in the same form is added to it there. WooCommerce's
 * own listing buttons fire the same event outside any form, and leave
 * unchanged. The product page posts its form natively and is not affected.
 *
 * Without WeLaunch, or off Porto, it does nothing.
 */
final class Quick_View_Store {

	/**
	 * The script added after WeLaunch's own.
	 */
	const string SCRIPT = 'window.jQuery&&jQuery(document.body).on("adding_to_cart",function(e,b,d){try{'
		. 'var v=b&&b.closest?b.closest("form").find(\'input[name="woocommerce_multi_inventory_inventory"]:checked:enabled\').val():null;'
		. 'if(v&&d&&"object"===typeof d&&!d.woocommerce_multi_inventory_inventory)d.woocommerce_multi_inventory_inventory=v}catch(x){}});';

	/**
	 * Hooks the correction while its switch is on, on Porto with WeLaunch active.
	 *
	 * @return void
	 */
	public function register(): void {
		// The quick view is Porto's, and its switch is offered only on Porto, so
		// nothing may run anywhere else. The template is an option: known now.
		if ( ! Settings::feature( 'inventory_quick_view' ) || ! function_exists( 'welaunch_woocommerce_multi_inventory_run' ) || 'porto' !== get_template() ) {
			return;
		}

		// After WeLaunch enqueues its script, at 10.
		add_action( 'wp_enqueue_scripts', array( $this, 'send_the_picked_store' ), 99 );
	}

	/**
	 * Prints the listener after WeLaunch's script, wherever that script loads.
	 *
	 * @return void
	 */
	public function send_the_picked_store(): void {
		wp_add_inline_script( Inventory_Links::HANDLE, self::SCRIPT, 'after' );
	}
}
