<?php
/**
 * Ticks no WeLaunch store the shopper did not pick, and home delivery only with stock.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the shopper to pick home delivery or a store before a product goes in the cart.
 *
 * WeLaunch Multi Inventory draws its locations as radio buttons each time a
 * size is picked, and ticks one: the store kept in its cookie, or the online
 * store. Nothing ticked is no safer: WeLaunch then files the line under the
 * store in its cookie, without asking. On hoka a shopper could end up
 * collecting at a branch they never chose, at the height of Cyber, with the
 * branch saturated (owner's decision, 2026-10-06: no store is ticked but by
 * the shopper).
 *
 * After every AJAX request - WeLaunch's redraws, and Porto's quick view, which
 * arrives with the cookie's store ticked even when the size has none there -
 * this unticks every location except the one the shopper clicked on this
 * page, while it is still enabled, and ticks that one again when WeLaunch's
 * answer ticked another in its place (2.8.6 to 2.8.8 lost it: the shopper
 * was asked to choose again). With its second switch it then ticks home
 * delivery - Click & Collect's delivery store, which WeLaunch draws above the
 * pickup stores, or without Click & Collect its default location - in each
 * add to cart form where the shopper clicked no other location, when the
 * size's stock gives it some - the page's per-size stores first with
 * Size_Stores on, WeLaunch's answer after - and the cart holds nothing for
 * another store: WeLaunch keeps one store per cart and would empty the cart
 * on the add (owner's decision, 2026-10-27). The cart's store travels in a
 * cookie of its own, sent with WooCommerce's cart cookies on the requests
 * that already carry them, which no page cache keeps. Without stock for
 * delivery nothing is ticked. WeLaunch enables the add button and writes the
 * stock line for the location it checked when the size was picked, so when
 * the one left ticked is another, its own change handler runs again, at most
 * twice per size pick. Inventory_Links leaves its default-store script out
 * while this is on: two scripts ticking the same radios undo each other's
 * checks.
 *
 * The add to cart is stopped before anything else sees the click while the
 * form has enabled locations and none of them ticked, and a line above them
 * says why. An add from a form with a location to tick carries a marker
 * field - Porto's AJAX add gets it, and the ticked store, which Porto leaves
 * out - and the server refuses an add that has the marker and no location,
 * before WeLaunch's own check would fall back to the cookie. It also
 * refuses, there, three adds WeLaunch lets through and its checkout then
 * refuses, or does not: a store the site does not offer, which a page cached
 * before the store was hidden still draws; a store with no units of the
 * size, which WeLaunch validates against its default location instead while
 * it files the line under the store posted (with some units but fewer than
 * asked, WeLaunch refuses it itself); and, with no store posted, a size no
 * store the site offers has any of, for which WeLaunch's answer about a
 * hidden store enabled the button.
 *
 * A store the site does not offer is one WeLaunch's frontend flag leaves off
 * the product page, unless it is WeLaunch's default location or Click &
 * Collect's delivery store, which WeLaunch files lines under by itself. It is
 * never the visitor's store either: WeLaunch's cookie, which lasts a year, or
 * a ?inventory= link naming one is set aside before WeLaunch reads it, so the
 * line goes where it would for a first visit, and nothing is sold from a
 * store the owner hid (Parque Arauco, on hoka, since 2026-10-06). Where
 * WeLaunch picks every line's store at the checkout itself (its "always use
 * the order flow" option) the server leaves the store alone.
 *
 * Lists and other add buttons, which have no locations to pick, are left as
 * they are. Without WeLaunch it does nothing.
 */
final class Store_Choice {

	/**
	 * The location field WeLaunch's form posts.
	 */
	const string FIELD = 'woocommerce_multi_inventory_inventory';

	/**
	 * Hidden field added to forms that show locations, so the server knows one had to be picked.
	 *
	 * WP Optimizer's spelling, kept: pages cached or still open from before the
	 * switch post it. The option rcus_store_choice is another key.
	 */
	const string MARKER = 'rwpo_store_choice';

	/**
	 * Cookie with the store of every line in the cart, 0 when no line has one; none when they differ.
	 *
	 * WP Optimizer's spelling, kept: the script on pages cached or still open
	 * from before the switch reads it.
	 */
	const string CART_STORE_COOKIE = 'rwpo_cart_store';

	/**
	 * Term meta WeLaunch shows a store on the product page with, when it holds "on".
	 */
	const string SHOWN_META = 'woocommerce_multi_inventory_frontend';

	/**
	 * The script added after WeLaunch's own; __TEXT__ is the message, as JSON, and __DELIVERY__ the delivery store, as JSON, or null.
	 *
	 * Radios only: WeLaunch's "Hidden" display posts the store in a hidden
	 * field and its "Select" display in a select, and neither is a choice to
	 * leave open. The marker is added when the add happens, and only while the
	 * form has a location the shopper can tick: a size on backorder has every
	 * location disabled, and WeLaunch lets that add through. On WeLaunch's
	 * "Label" display the locations are folded away until "change" is clicked,
	 * so a refused add unfolds them. On its "Label & Popup" display with Click
	 * & Collect, pickup is a radio of its own (woocommerce_multi_inventory_fake)
	 * and the store is the one the popup saved: ticked, that is the choice.
	 *
	 * Delivery's stock is WeLaunch's answer for the size just picked
	 * (woocommerce_multi_inventory_get_variation_stock, every store's number),
	 * or first the size's stores the page carries when Size_Stores is on,
	 * which that answer then corrects: a store with no number counts as none,
	 * as WeLaunch then leaves its radio as it was. A simple product has no
	 * such request, so its radio counts as printed - WeLaunch disables a store
	 * with none, but never Click & Collect's delivery radio, whose row carries
	 * WeLaunch's on-stock class instead - until WeLaunch's own check of that
	 * store (woocommerce_multi_inventory_get_stock) says otherwise; Porto's
	 * quick view gets no such check. With a cart, delivery is ticked only
	 * while CART_STORE_COOKIE names it or no line has a store: WeLaunch's own
	 * cookie holds the store it validated on the last add, and a cart kept
	 * from another device, or an add it validated against its default
	 * location, leaves the two apart. A shopper who clicked a store keeps it -
	 * WeLaunch's answers tick a store of their own in the same group, so the
	 * click is put back while that store has the size - and is not moved to
	 * delivery when a size has none there. WeLaunch's check asks about the
	 * ticked location, else its cookie's, else none; the answer to none names
	 * the store the server chose, which counts as checked.
	 *
	 * When the page carries the size's stores (Size_Stores, whose script runs
	 * first), the tick is decided from them as the size is picked
	 * (found_variation), before WooCommerce fires show_variation, where
	 * WeLaunch reads the ticked store for its check: that check then asks
	 * about the right one and needs no second request.
	 */
	const string SCRIPT = 'window.jQuery&&(function($){var s=\'input[type="radio"][name="' . self::FIELD . '"]\',h=\'input[name="' . self::MARKER . '"]\',p=\'input[name="woocommerce_multi_inventory_fake"]:checked\',t=__TEXT__,k="rcus-store-choice",'
		. 'D=__DELIVERY__,m=null,M=null,q=null,a=null,c=2,N={},T={};'
		. 'function P(d,n){var r=d.match(new RegExp("(?:^|&)"+n+"=([^&]*)"));return r?r[1]:""}'
		. 'function C(){var r=document.cookie.match(/(?:^|; )' . Inventory_Links::STORE_COOKIE . '=([^;]*)/);if(!r)return"";try{r=JSON.parse(decodeURIComponent(r[1]))}catch(x){return""}return null==r?"":String(r)}'
		. 'function K(){var r=document.cookie.match(/(?:^|;\\s*)' . self::CART_STORE_COOKIE . '=([^;]*)/);return!/(?:^|;\\s*)' . Inventory_Links::CART_COOKIE . '=/.test(document.cookie)||!!r&&("0"===r[1]||r[1]===D)}'
		. 'function f(z){$(s).each(function(){if(this.checked&&!(m&&this.value===m&&!this.disabled))this.checked=!1});'
		. 'if(M&&M.isConnected&&!M.disabled&&!M.checked&&!$(M).closest("form").find(s+":checked").length){M.checked=!0;$(M).closest("form").find("."+k).remove()}'
		. 'if(D&&(!m||m===D)&&K())$(s).closest("form.cart").each(function(){var e=$(this),r=e.find(s+\'[value="\'+D+\'"]\'),v=e.find(\'input[name="variation_id"]\'),u;if(!r.length||r.prop("disabled")||e.find(p).length)return;'
		. 'u=v.length?N[v.val()]:T[e.find(\'[name="add-to-cart"]\').val()];'
		. 'if(void 0===u&&!v.length)u=!r.closest(".woocommerce-multi-inventory-inventories-delivery-container").length||0<r.closest(".woocommerce-multi-inventory-inventories-row").find(".woocommerce-multi-inventory-inventories-stock-on-stock").length;'
		. 'if(!0===u){r.first().prop("checked",!0);e.find("."+k).remove()}});'
		. 'if(z)return;var n=$(s+":checked").first(),w=n.length?n.val():C();if(null!==q&&q!==w&&!(""===q&&a===w)&&0<c&&$(s).length){c--;(n.length?n:$(s).first()).trigger("change")}}'
		. '$(document).on("change",s,function(e){if(e.originalEvent&&this.checked){m=this.value;M=this;$(this).closest("form").find("."+k).remove()}})'
		. '.on("found_variation",".variations_form",function(e,v){c=2;if(!v)return;var i=String(v.variation_id);if(Array.isArray(v.' . Size_Stores::KEY . ')){if(D)N[i]=-1<v.' . Size_Stores::KEY . '.indexOf(D);f(1)}else delete N[i]})'
		. '.on("adding_to_cart",function(e,b,d){try{if(!d||"object"!==typeof d||!b||!b.closest)return;var g=b.closest("form");if(!g.find(s+":enabled").length||g.find(p).length)return;'
		. 'd.' . self::MARKER . '="1";var v=g.find(s+":checked:enabled").val();if(v&&!d.' . self::FIELD . ')d.' . self::FIELD . '=v}catch(x){}})'
		. '.ajaxSend(function(e,x,j){var d=j&&"string"===typeof j.data?j.data:"";if(-1<d.indexOf("action=woocommerce_multi_inventory_get_stock"))q=P(d,"inventory_id")})'
		. '.ajaxComplete(function(e,x,j){var d=j&&"string"===typeof j.data?j.data:"",r=null,i;if(-1<d.indexOf("action=woocommerce_multi_inventory_get_")){try{r=x.responseJSON||JSON.parse(x.responseText)}catch(y){}'
		. 'if(r&&"object"===typeof r){i=P(d,"variation_id");if(-1<d.indexOf("action=woocommerce_multi_inventory_get_variation_stock")){if(D&&i)N[i]=!!r.status&&0<+(r.inventories_stock||{})[D]}'
		. 'else if(-1<d.indexOf("action=woocommerce_multi_inventory_get_stock")){a=null==r.inventory_id?"":String(r.inventory_id);if(D&&(!i||"0"===i)&&P(d,"inventory_id")===D)T[P(d,"product_id")]=!!r.status}}}setTimeout(f,1)});'
		. 'function b(e){var g=$(e.target).closest("form");if(!g.length)return;g.find(h).remove();var r=g.find(s+":enabled");if(!r.length||g.find(p).length)return;'
		. 'if(g.find(s+":checked:enabled").length){g.append(\'<input type="hidden" name="' . self::MARKER . '" value="1">\');return}'
		. 'e.preventDefault();e.stopImmediatePropagation();g.find(".woocommerce-multi-inventory-label-container .woocommerce-multi-inventory-inventories-row").show();'
		. 'if(!g.find("."+k).length){var c=r.first().closest(".woocommerce-multi-inventory-inventories-container");'
		. '$(\'<p class="\'+k+\'" role="alert" style="color:#e02b27;font-weight:600;margin:.5em 0"></p>\').text(t).insertBefore(c.length?c:r.first().closest("label"))}'
		. 'var n=g.find("."+k)[0];n&&n.scrollIntoView&&n.scrollIntoView({block:"center"})}'
		. 'document.addEventListener("click",function(e){if($(e.target).closest(".single_add_to_cart_button").length)b(e)},!0);'
		. 'document.addEventListener("submit",function(e){if($(e.target).is("form.cart"))b(e)},!0);f()})(jQuery);';

	/**
	 * Whether home delivery is ticked while it has the size.
	 *
	 * @var bool
	 */
	private bool $delivery = false;

	/**
	 * Hooks both halves while the switch is on and WeLaunch is active.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'store_choice' ) || ! function_exists( 'welaunch_woocommerce_multi_inventory_run' ) ) {
			return;
		}

		$this->delivery = Settings::feature( 'store_choice_delivery' );

		// Before WeLaunch reads its cookie and the query, at 10.
		add_action( 'init', array( $this, 'set_aside_a_hidden_store' ), 0 );
		// After WeLaunch enqueues its script, at 10, and after Inventory_Links' at 99.
		add_action( 'wp_enqueue_scripts', array( $this, 'add_script' ), 100 );
		// Before WeLaunch's own check, at 10, which falls back to the cookie's store.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'require_a_store' ), 5, 4 );

		if ( $this->delivery ) {
			add_action( 'woocommerce_set_cart_cookies', array( $this, 'remember_the_cart_store' ) );
		}
	}

	/**
	 * The message the shopper sees when no location is ticked.
	 *
	 * @return string
	 */
	private static function message(): string {
		return __( 'Choose home delivery or a store to pick it up.', 'rhinnoit-customizer' );
	}

	/**
	 * Prints the script after WeLaunch's, wherever that script loads.
	 *
	 * @return void
	 */
	public function add_script(): void {
		$text     = wp_json_encode( self::message() );
		$delivery = $this->delivery ? self::delivery_store() : 0;

		if ( false === $text ) {
			return;
		}

		$script = strtr(
			self::SCRIPT,
			array(
				'__TEXT__'     => $text,
				// A store id: digits only, so the quotes make it a JSON string.
				'__DELIVERY__' => 0 < $delivery ? '"' . $delivery . '"' : 'null',
			)
		);

		wp_add_inline_script( Inventory_Links::HANDLE, $script, 'after' );
	}

	/**
	 * Home delivery: Click & Collect's delivery store, or without Click & Collect WeLaunch's default location.
	 *
	 * Click & Collect draws home delivery from its own setting, above the
	 * pickup stores, where the default location is one more store to pick.
	 *
	 * @return int 0 when WeLaunch's settings name none.
	 */
	public static function delivery_store(): int {
		$options = self::welaunch_options();

		if ( ! empty( $options['clickCollectEnable'] ) ) {
			return self::store_id( $options['deliveryInventory'] ?? '' );
		}

		return self::store_id( $options['defaultInventory'] ?? '' );
	}

	/**
	 * Sends CART_STORE_COOKIE with WooCommerce's cart cookies, and deletes it with them.
	 *
	 * WooCommerce sets its cart cookies on every request with a cart, and only
	 * there: a request without one, which a page cache may keep, sends
	 * nothing. A line without a store is left in the cart by WeLaunch on any
	 * add, so it does not count. A cart whose lines name different stores, or
	 * that cannot be read, gets no cookie: its store is not known. An add
	 * sets the cookies before WeLaunch empties the cart of other stores'
	 * lines, so a cart it is about to empty counts as unknown until the next
	 * page.
	 *
	 * @param mixed $set Whether WooCommerce set its cookies (true) or deleted them.
	 * @return void
	 */
	public function remember_the_cart_store( mixed $set ): void {
		if ( ! function_exists( 'wc_setcookie' ) ) {
			return;
		}

		$store = true === $set ? self::cart_store() : null;
		$sent  = isset( $_COOKIE[ self::CART_STORE_COOKIE ] ) && is_string( $_COOKIE[ self::CART_STORE_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::CART_STORE_COOKIE ] ) ) : null;

		if ( $store === $sent ) {
			return;
		}

		if ( null === $store ) {
			wc_setcookie( self::CART_STORE_COOKIE, '0', time() - HOUR_IN_SECONDS );
			unset( $_COOKIE[ self::CART_STORE_COOKIE ] );

			return;
		}

		wc_setcookie( self::CART_STORE_COOKIE, $store );
		$_COOKIE[ self::CART_STORE_COOKIE ] = $store;
	}

	/**
	 * The store every line of the cart is filed under, as WeLaunch compares them before emptying a cart.
	 *
	 * @return string|null "0" when no line has one; null when they differ, or the cart cannot be read.
	 */
	private static function cart_store(): ?string {
		$cart = function_exists( 'WC' ) && WC() instanceof \WooCommerce ? WC()->cart : null;

		if ( ! $cart instanceof \WC_Cart ) {
			return null;
		}

		$stores = array();

		foreach ( $cart->get_cart() as $line ) {
			$store = is_array( $line ) && is_array( $line[ Store_Stock_Hold::CART_STORE ] ?? null ) ? ( $line[ Store_Stock_Hold::CART_STORE ]['value'] ?? '' ) : '';

			if ( empty( $store ) ) {
				continue;
			}

			if ( ! is_numeric( $store ) || 0 >= (int) $store ) {
				return null;
			}

			$stores[ (int) $store ] = true;
		}

		if ( 1 < count( $stores ) ) {
			return null;
		}

		return array() === $stores ? '0' : (string) array_key_first( $stores );
	}

	/**
	 * Sets WeLaunch's cookie, and a ?inventory= link, aside for this request when it names a store the site does not offer.
	 *
	 * WeLaunch reads both on init and takes the visitor's store from them:
	 * the store a line goes to when the form posts none, stock status, prices.
	 * Set aside, the request is a first visit's, with the default location.
	 * The browser keeps the cookie until WeLaunch's next add writes the
	 * store it validated over it.
	 *
	 * @return void
	 */
	public function set_aside_a_hidden_store(): void {
		// Read as WeLaunch reads them, through intval(); only presence and that number.
		$cookie = isset( $_COOKIE[ Inventory_Links::STORE_COOKIE ] ) && is_string( $_COOKIE[ Inventory_Links::STORE_COOKIE ] ) ? (int) $_COOKIE[ Inventory_Links::STORE_COOKIE ] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WeLaunch's own link, read as WeLaunch reads it, to leave the request alone.
		$query = isset( $_GET['inventory'] ) && is_string( $_GET['inventory'] ) ? (int) $_GET['inventory'] : 0;

		if ( 0 >= $cookie && 0 >= $query ) {
			return;
		}

		$options = self::welaunch_options();

		if ( 0 < $cookie && ! self::is_offered( $cookie, $options ) ) {
			unset( $_COOKIE[ Inventory_Links::STORE_COOKIE ] );
		}

		if ( 0 < $query && ! self::is_offered( $query, $options ) ) {
			unset( $_GET['inventory'] );
		}
	}

	/**
	 * Refuses an add whose line would go to no location, to one the site does not offer, or to one without the units.
	 *
	 * WooCommerce passes the product and quantity, and the size from the
	 * product page's form; its AJAX add and Porto's pass the size as the
	 * product.
	 *
	 * @param mixed $passed       Whether the add is valid so far.
	 * @param mixed $product_id   The product, or the size itself.
	 * @param mixed $quantity     Units asked for.
	 * @param mixed $variation_id The size picked, when the form posts one.
	 * @return mixed
	 */
	public function require_a_store( mixed $passed, mixed $product_id = 0, mixed $quantity = 1, mixed $variation_id = 0 ): mixed {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce's add to cart form, read as WooCommerce reads it.
		$asked = isset( $_POST[ self::MARKER ] ) && is_string( $_POST[ self::MARKER ] );
		$store = isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';
		// phpcs:enable

		if ( true !== $passed ) {
			return $passed;
		}

		$problem = $asked && '' === $store ? self::message() : self::store_problem( $store, $product_id, $quantity, $variation_id );

		if ( null === $problem ) {
			return $passed;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $problem, 'error' );
		}

		return false;
	}

	/**
	 * Why the line cannot go where WeLaunch would file it, or null.
	 *
	 * @param string $posted       The store the form posted, '' for none.
	 * @param mixed  $product_id   The product, or the size itself.
	 * @param mixed  $quantity     Units asked for.
	 * @param mixed  $variation_id The size picked, when the form posts one.
	 * @return string|null
	 */
	private static function store_problem( string $posted, mixed $product_id, mixed $quantity, mixed $variation_id ): ?string {
		$options = self::welaunch_options();

		// WeLaunch's checkout then picks every line's store itself.
		if ( ! empty( $options['orderFlowAlwaysUse'] ) ) {
			return null;
		}

		// WeLaunch files the line under the posted store through intval().
		$store = (int) $posted;

		if ( 0 < $store && ! self::is_offered( $store, $options ) ) {
			$term = get_term( $store, Store_Stock_Hold::TAXONOMY );

			return $term instanceof \WP_Term
				/* translators: %s: store name. */
				? sprintf( __( '%s does not take online orders. Choose home delivery or another store.', 'rhinnoit-customizer' ), esc_html( $term->name ) )
				: self::message();
		}

		$id       = is_numeric( $variation_id ) && 0 < (int) $variation_id ? (int) $variation_id : ( is_numeric( $product_id ) ? (int) $product_id : 0 );
		$product  = 0 < $id && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		$quantity = is_numeric( $quantity ) ? (float) $quantity : 0.0;

		// A variable product without its size, which WooCommerce refuses, a bundle and a sale on backorder are left to WeLaunch, as it leaves them.
		if ( ! $product instanceof \WC_Product || 0 >= $quantity || $product->is_type( 'variable' ) || $product->is_type( 'bundle' ) || $product->backorders_allowed() || $product->is_on_backorder() ) {
			return null;
		}

		$numbers = self::numbers( (int) $product->get_stock_managed_by_id() );

		// Without store numbers WeLaunch decides alone.
		if ( null === $numbers ) {
			return null;
		}

		if ( '' === $posted ) {
			return self::unavailable( $product, $numbers, $options );
		}

		if ( 0 >= $store ) {
			return null;
		}

		$default = self::store_id( $options['defaultInventory'] ?? '' );
		// WeLaunch's own check weighs the posted store, or its default location when the posted one has no units, or none.
		$weighed = ! empty( $numbers[ $store ] ) ? $store : ( 0 < $default && ! empty( $numbers[ $default ] ) ? $default : 0 );
		// It refuses fewer units than asked, unless its "allow empty products" skips the check, and refuses none at all only when it requires a location.
		$refused = 0 < $weighed ? empty( $options['inventoryAllowEmptyProducts'] ) && $quantity > self::units( $numbers, $weighed ) : ! empty( $options['inventoryRequired'] );

		// Refused, WeLaunch says why; let through, the line goes in under the posted store.
		if ( $refused || $quantity <= self::units( $numbers, $store ) ) {
			return null;
		}

		$term = get_term( $store, Store_Stock_Hold::TAXONOMY );

		return sprintf(
			/* translators: 1: product name, 2: store name, 3: units the store has. */
			__( '%1$s: %3$d left at %2$s. Choose another location or fewer units.', 'rhinnoit-customizer' ),
			esc_html( $product->get_name() ),
			$term instanceof \WP_Term ? esc_html( $term->name ) : (string) $store,
			max( 0, (int) self::units( $numbers, $store ) )
		);
	}

	/**
	 * Why an add that posts no store cannot go in, or null.
	 *
	 * WeLaunch then files the line under the visitor's store - never a hidden
	 * one, set aside on init - or its default location, and when neither has
	 * units it lets the add through for its checkout to refuse. Refused here
	 * when no store the site offers has any: a size only a hidden store has,
	 * whose button WeLaunch's answer about that store enabled.
	 *
	 * @param \WC_Product               $product The size, or the product.
	 * @param array<int|string, scalar> $numbers WeLaunch's numbers for it.
	 * @param array<mixed>              $options WeLaunch's settings.
	 * @return string|null
	 */
	private static function unavailable( \WC_Product $product, array $numbers, array $options ): ?string {
		// WeLaunch's own check refuses it when it requires a location.
		if ( ! empty( $options['inventoryRequired'] ) ) {
			return null;
		}

		foreach ( $numbers as $store => $units ) {
			// A number WeLaunch's check weighs, as it tells them apart.
			if ( ! empty( $units ) && self::is_offered( (int) $store, $options ) ) {
				return null;
			}
		}

		return sprintf(
			/* translators: %s: product name. */
			__( '%s is not available online.', 'rhinnoit-customizer' ),
			esc_html( $product->get_name() )
		);
	}

	/**
	 * Whether the site offers a store: WeLaunch draws it on the product page, or files lines under it by itself.
	 *
	 * @param int          $store   The store.
	 * @param array<mixed> $options WeLaunch's settings.
	 * @return bool
	 */
	private static function is_offered( int $store, array $options ): bool {
		// Its default location takes the lines nobody picked a store for, and Click & Collect draws its delivery store whatever its flag says.
		if ( in_array( $store, array( self::store_id( $options['defaultInventory'] ?? '' ), self::store_id( $options['deliveryInventory'] ?? '' ) ), true ) ) {
			return true;
		}

		// The flag as the product page's query matches it: any row holding "on", in any case.
		foreach ( (array) get_term_meta( $store, self::SHOWN_META, false ) as $shown ) {
			if ( is_string( $shown ) && 'on' === strtolower( trim( $shown ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * WeLaunch's numbers for a product, without the blank ones it leaves out.
	 *
	 * @param int $product_id The product whose numbers count: the size, or its parent.
	 * @return array<int|string, scalar>|null Null when no store has a number.
	 */
	private static function numbers( int $product_id ): ?array {
		$numbers = get_post_meta( $product_id, Store_Stock_Hold::STOCK_META, true );

		if ( ! is_array( $numbers ) ) {
			return null;
		}

		$numbers = array_filter(
			$numbers,
			static function ( mixed $units ): bool {
				return is_scalar( $units ) && '' !== trim( (string) $units );
			}
		);

		return array() === $numbers ? null : $numbers;
	}

	/**
	 * A store's units, none when it has no number.
	 *
	 * @param array<int|string, scalar> $numbers WeLaunch's numbers.
	 * @param int                       $store   The store.
	 * @return float
	 */
	private static function units( array $numbers, int $store ): float {
		return isset( $numbers[ $store ] ) && is_numeric( $numbers[ $store ] ) ? (float) $numbers[ $store ] : 0.0;
	}

	/**
	 * A store id from WeLaunch's settings.
	 *
	 * @param mixed $id The setting's value.
	 * @return int 0 when it holds no store id.
	 */
	private static function store_id( mixed $id ): int {
		return is_scalar( $id ) && 1 === preg_match( '/^[1-9]\d*$/', (string) $id ) ? (int) $id : 0;
	}

	/**
	 * WeLaunch's settings.
	 *
	 * @return array<mixed>
	 */
	private static function welaunch_options(): array {
		$options = get_option( Store_Stock_Hold::WELAUNCH_OPTIONS );

		return is_array( $options ) ? $options : array();
	}
}
