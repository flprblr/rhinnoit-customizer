<?php
/**
 * Shows the WeLaunch stores of a size the moment it is picked.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Puts each size's stores in the product page, and shows them before WeLaunch's answer arrives.
 *
 * Each time a size is picked WeLaunch Multi Inventory asks the server which
 * stores have it, in two admin-ajax requests nothing can cache (about half a
 * second each on hoka on 2026-10-27, and they were 23% of its PHP time during
 * the 2026-10-02 peak), and until the answer arrives it keeps the previous
 * size's stores in sight, the previous store ticked and the add button as it
 * was. If the request fails, as under a sale's load, the first size picked
 * shows no store at all and the button stays disabled: the shopper is stuck.
 *
 * WooCommerce already prints every size in the page (data-product_variations).
 * Each one gets rcus_stores here: the stores whose number is above zero, read
 * as WeLaunch reads them (the parent's numbers for a size whose stock the
 * parent manages). A store with no number is left out: WeLaunch's own answer
 * leaves its radio as it was. A size whose stores have no number at all gets
 * nothing, and WeLaunch decides alone, as before.
 *
 * When a size is picked, before WeLaunch's handler reads the ticked store, the
 * script after WeLaunch's sets each radio from that list (unticking one that
 * goes off), shows the list, and enables the add button when a store on the
 * screen has the size (a store hidden from the frontend has no radio, so it
 * does not count), which WeLaunch's check of the parent at page load had
 * disabled; Store_Choice, after it, ticks from the same list. With
 * WeLaunch's "In stock / Out of stock" display, the stock line WooCommerce
 * writes - which follows the default store, so a size only a branch has
 * reads "Out of stock" - says In stock at once when the store WeLaunch is
 * about to check has the size, as WeLaunch's own answer will.
 *
 * WeLaunch's requests still run and correct anything that changed since the
 * page was cached - a store's last unit sold before the purge reached
 * Kinsta's edge - and the size's own answer replaces the page's list for the
 * rest of the visit. A check that answers for another size (the one at page
 * load, or an earlier pick) does not leave the button off. With nothing
 * ticked, WeLaunch checks the store in its cookie, which a returning shopper
 * carries for a year; when that store lacks the size it disables the button
 * and says out of stock, so after its check, while no store is ticked and one
 * on the screen has the size, the button is lifted and the line says In
 * stock: the add then asks for a choice (Store_Choice). A late answer for
 * another size, which WeLaunch draws on whatever size is shown, is followed
 * by this size's stores again. Its spinner is hidden, since the list is
 * already there. Sales are still checked on the server: at the add by
 * Store_Choice while its switch is on, which refuses a store with none of
 * the size and one hidden from the site (WeLaunch's own check lets both
 * through), and at the checkout by WeLaunch and Store_Stock_Hold.
 *
 * Without WeLaunch it does nothing.
 */
final class Size_Stores {

	/**
	 * The size's stores, added to WooCommerce's variation data.
	 */
	const string KEY = 'rcus_stores';

	/**
	 * The script added after WeLaunch's own, and before Store_Choice's, which reads what it leaves.
	 *
	 * It acts on found_variation, which WooCommerce 11 fires as the size is
	 * picked; show_variation, which WeLaunch waits for, comes about 300 ms
	 * later (measured on hoka). Its spinner is hidden a moment after
	 * show_variation, once WeLaunch has shown it.
	 */
	const string SCRIPT = 'window.jQuery&&(function($){var s=\'input[type="radio"][name="' . Store_Choice::FIELD . '"]\',o=window.' . Inventory_Links::OPTIONS . '||{},w=null;'
		. 'function u(){if(w&&w.ok)w.g.find(".single_add_to_cart_button").prop("disabled",!1)}'
		. 'function A(g,t){var r=g.find(s);r.each(function(){var n=-1===t.indexOf(this.value);this.disabled=n;if(n)this.checked=!1});g.find(".woocommerce-multi-inventory-inventories-variable").show();return r.filter(":enabled").length}'
		. '$(document).on("found_variation",".variations_form",function(e,v){try{w=null;if(!v||!Array.isArray(v.' . self::KEY . '))return;var g=$(this);if(!g.find(s).length)return;'
		. 'w={g:g,v:v,i:String(v.variation_id),t:v.' . self::KEY . '};w.ok=!!(A(g,w.t)&&v.is_in_stock&&v.is_purchasable);u()}catch(x){}})'
		. '.on("show_variation",".variations_form",function(){var z=w;z&&setTimeout(function(){try{z.g.find(".woocommerce-multi-inventory-manager-table-spinner").hide();if(z!==w||"inout"!==o.productPageStockDisplay||!o.textsInStock)return;'
		. 'var c=z.g.find(s+":checked").val();if(c?-1<z.t.indexOf(c):z.ok&&!/(?:^|; )' . Inventory_Links::STORE_COOKIE . '=/.test(document.cookie))z.g.find(".stock").text(o.textsInStock)}catch(x){}},0)})'
		. '.on("hide_variation reset_data",".variations_form",function(){w=null})'
		. '.ajaxComplete(function(e,x,j){try{var d=j&&"string"===typeof j.data?j.data:"",i,r;if(!w||-1===d.indexOf("action=woocommerce_multi_inventory_get_"))return;'
		. 'i=(d.match(/(?:^|&)variation_id=([^&]*)/)||[])[1]||"";'
		. 'if(-1<d.indexOf("action=woocommerce_multi_inventory_get_variation_stock")){if(i===w.i){r=x.responseJSON||JSON.parse(x.responseText);if(!r||!r.status||!r.inventories_stock||"object"!==typeof r.inventories_stock)return;'
		. 'r=r.inventories_stock;w.t=w.v.' . self::KEY . '=Object.keys(r).filter(function(k){return 0<+r[k]})}w.ok=!!(A(w.g,w.t)&&w.v.is_in_stock&&w.v.is_purchasable);return}'
		. 'if(i!==w.i)return u();'
		. 'if(w.ok&&!w.g.find(s+":checked:enabled").length&&w.g.find(s+":enabled").length){u();if("inout"===o.productPageStockDisplay&&o.textsInStock)w.g.find(".stock").text(o.textsInStock)}}catch(y){}})})(jQuery);';

	/**
	 * Hooks both halves while the switch is on and WeLaunch is active.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'size_stores_at_once' ) || ! function_exists( 'welaunch_woocommerce_multi_inventory_run' ) ) {
			return;
		}

		add_filter( 'woocommerce_available_variation', array( $this, 'add_stores' ), 10, 3 );
		// After WeLaunch enqueues its script, at 10, and before Store_Choice's, at 100.
		add_action( 'wp_enqueue_scripts', array( $this, 'add_script' ), 99 );
	}

	/**
	 * Adds the size's stores to the data WooCommerce prints for it.
	 *
	 * @param mixed $data      The size's data.
	 * @param mixed $product   The variable product.
	 * @param mixed $variation The size.
	 * @return mixed
	 */
	public function add_stores( mixed $data, mixed $product = null, mixed $variation = null ): mixed {
		if ( ! is_array( $data ) || ! $variation instanceof \WC_Product ) {
			return $data;
		}

		$stores = self::stores( (int) $variation->get_stock_managed_by_id() );

		if ( null !== $stores ) {
			$data[ self::KEY ] = $stores;
		}

		return $data;
	}

	/**
	 * Prints the script after WeLaunch's, wherever that script loads.
	 *
	 * @return void
	 */
	public function add_script(): void {
		wp_add_inline_script( Inventory_Links::HANDLE, self::SCRIPT, 'after' );
	}

	/**
	 * The stores with stock, by WeLaunch's numbers for a product.
	 *
	 * @param int $product_id The product whose numbers count: the size, or its parent.
	 * @return array<int, string>|null Null when no store has a number.
	 */
	private static function stores( int $product_id ): ?array {
		$numbers = get_post_meta( $product_id, Store_Stock_Hold::STOCK_META, true );

		if ( ! is_array( $numbers ) ) {
			return null;
		}

		$known  = false;
		$stores = array();

		foreach ( $numbers as $store => $units ) {
			if ( ! is_scalar( $units ) || '' === trim( (string) $units ) ) {
				continue;
			}

			$known = true;

			if ( is_numeric( $units ) && 0 < (float) $units ) {
				$stores[] = (string) $store;
			}
		}

		return $known ? $stores : null;
	}
}
