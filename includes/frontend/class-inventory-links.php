<?php
/**
 * Keeps WeLaunch Multi Inventory's store out of links and cached pages.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Leaves links without ?inventory= and builds every cacheable page for the default store.
 *
 * WeLaunch "WooCommerce Multi Inventory" remembers a store in a cookie that
 * lasts a year and that every add to cart sets - the store the item ships
 * from, the online one by default. While the cookie exists its script
 * appends ?inventory=<store> to every link on the page, and Kinsta caches no
 * URL with a query string it does not know: from the first add on, every
 * page a returning shopper opens is a full render, and none of those links
 * can be prefetched. A shop that shows every store's stock on the product
 * page and lets the shopper pick there needs the parameter for nothing.
 *
 * Two halves that are only safe together, hence one switch:
 *
 * - The script is told, through its own options object, not to rewrite
 *   links: `disableStateReplace` is WeLaunch's setting for exactly that.
 * - With clean links the cache would be handed pages rendered for that
 *   cookie. WeLaunch reads it on init and takes stock status, availability
 *   and the quantity limit from the remembered store, so one shopper's store
 *   would be served to everyone - "out of stock" on sizes the online store
 *   has. So on a request the cache may keep - a GET, no cart, not logged in,
 *   no ?inventory= or ?add-to-cart=, and the cart, checkout and account
 *   pages only without a session that could hold a cart - the cookie is set
 *   aside before WeLaunch reads it, and the page is the one a first visit
 *   gets. The browser keeps the cookie: adding to the cart, a cart, checkout
 *   and WeLaunch's own AJAX still see it.
 *
 * Without WeLaunch it does nothing.
 */
final class Inventory_Links {

	/**
	 * The cookie WeLaunch remembers the store in.
	 */
	const string STORE_COOKIE = 'woocommerce_multi_inventory_inventory';

	/**
	 * Handle WeLaunch registers its storefront script under.
	 */
	const string HANDLE = 'woocommerce-multi-inventory-public';

	/**
	 * The options object WeLaunch hands its script.
	 */
	const string OPTIONS = 'woocommerce_multi_inventory_options';

	/**
	 * Cookie WooCommerce keeps while the cart has items.
	 *
	 * WooCommerce's own name, copied rather than read from the Optimizer,
	 * which holds it too: PHP evaluates every class constant on the first new,
	 * so naming a class of another plugin would be a fatal on every request
	 * of a shop without that plugin.
	 */
	const string CART_COOKIE = 'woocommerce_items_in_cart';

	/**
	 * Start of the name of WooCommerce's session cookie, the one that keeps the cart.
	 *
	 * WooCommerce's own name, copied for the same reason as CART_COOKIE.
	 */
	const string SESSION_COOKIE = 'wp_woocommerce_session_';

	/**
	 * WooCommerce pages that run cart logic, which a page cache keeps only for a visitor without a session.
	 *
	 * The Optimizer keeps the same three pages out of its prefetch rules, in a
	 * list of its own; the two are kept in step by hand.
	 *
	 * @var array<int, string>
	 */
	const array CART_PAGES = array( 'cart', 'checkout', 'myaccount' );

	/**
	 * Keeps the default store ticked unless the shopper picks another, or the cart belongs to one.
	 *
	 * The owner's rule: every store is offered, the online one is ticked by
	 * default, and a pickup store only when the shopper picks it. WeLaunch's
	 * script breaks it twice after a size pick: it ticks the store the cookie
	 * remembers, a pickup store for anyone who once bought for pickup, and it
	 * ticks that store or the default over the one the shopper just picked.
	 * So after each of its stock answers the store is put back: the shopper's
	 * own pick while it has stock, else - without a cart - the default. With a
	 * cart the cart's store stays, because WeLaunch empties the cart of every
	 * other store's lines on the next add (measured live: "Se eliminaron los
	 * artículos del carrito que no eran de la misma tienda"). When the ticked
	 * store is not the one WeLaunch last checked, its own change handler runs
	 * again, at most twice per size pick: enough for answers out of order, and
	 * a stop where WeLaunch checks some other store whatever happens (its
	 * "select" display).
	 *
	 * Left out while Store_Choice is on: that one decides which location is
	 * ticked, from WeLaunch's stock for the size, and this one ticks the
	 * default even where that store has none. Each would re-run WeLaunch's
	 * check for the store the other then unticks.
	 */
	const string DEFAULT_STORE_SCRIPT = 'window.jQuery&&(function($){var o=window.woocommerce_multi_inventory_options||{},f=o.defaultInventory?String(o.defaultInventory):null,'
		. 's=\'input[name="woocommerce_multi_inventory_inventory"]\',m=null,a=null,c=0;'
		. 'function i(d){return(d.match(/(?:^|&)inventory_id=(\\d+)/)||[])[1]||null}'
		. 'function k(){return/(?:^|;\\s*)' . self::CART_COOKIE . '=/.test(document.cookie)}'
		. '$(document).on("change",s,function(e){if(e.originalEvent&&this.checked)m=this.value}).on("show_variation",function(){c=0})'
		. '.ajaxSend(function(e,x,q){var d=q&&"string"===typeof q.data?q.data:"";if(-1<d.indexOf("action=woocommerce_multi_inventory_get_stock"))a=i(d)})'
		. '.ajaxComplete(function(e,x,q){var d=q&&"string"===typeof q.data?q.data:"";if(-1===d.indexOf("action=woocommerce_multi_inventory_get_"))return;'
		. 'setTimeout(function(){var n=$(s+":checked").first().val(),p=m&&$(s+"[value=\\""+m+"\\"]:enabled").length?m:null,w=p||(k()?n:f);if(!w)return;'
		. 'if(n!==w)$(s+"[value=\\""+w+"\\"]").first().prop("checked",!0);'
		. 'if(a&&a!==w&&2>c){c++;$(s+":checked").first().trigger("change")}},0)})})(jQuery);';

	/**
	 * Whether DEFAULT_STORE_SCRIPT is printed: not while Store_Choice decides the ticked store.
	 *
	 * @var bool
	 */
	private bool $default_store = true;

	/**
	 * Hooks both halves while the switch is on and WeLaunch is active.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'inventory_links' ) || ! function_exists( 'welaunch_woocommerce_multi_inventory_run' ) ) {
			return;
		}

		$this->default_store = ! Settings::feature( 'store_choice' );

		// Before WeLaunch reads the cookie, at 10.
		add_action( 'init', array( $this, 'build_cacheable_pages_for_the_default_store' ), 0 );

		// After WeLaunch enqueues its script, at 10.
		add_action( 'wp_enqueue_scripts', array( $this, 'keep_links_clean' ), 99 );
	}

	/**
	 * Turns WeLaunch's link rewriting off, and keeps the store it ticks the right one.
	 *
	 * WordPress prints the options object, the first script, WeLaunch's
	 * script, then the second (DEFAULT_STORE_SCRIPT), unless Store_Choice
	 * decides the ticked store.
	 *
	 * @return void
	 */
	public function keep_links_clean(): void {
		wp_add_inline_script( self::HANDLE, 'window.' . self::OPTIONS . '&&(' . self::OPTIONS . '.disableStateReplace="1");', 'before' );

		if ( $this->default_store ) {
			wp_add_inline_script( self::HANDLE, self::DEFAULT_STORE_SCRIPT, 'after' );
		}
	}

	/**
	 * Sets the store cookie aside for this request when the page cache may keep the page.
	 *
	 * @return void
	 */
	public function build_cacheable_pages_for_the_default_store(): void {
		if ( isset( $_COOKIE[ self::STORE_COOKIE ] ) && self::cache_may_keep_this_request() ) {
			unset( $_COOKIE[ self::STORE_COOKIE ] );
		}
	}

	/**
	 * Whether this request is one a page cache could store and serve to others.
	 *
	 * Decided on init, before the query is parsed, so the shop's own pages are
	 * matched by path.
	 *
	 * @return bool
	 */
	private static function cache_may_keep_this_request(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || is_admin() || wp_doing_ajax() || wp_doing_cron() || is_user_logged_in() ) {
			return false;
		}

		// Kinsta skips its cache for a cart, WeLaunch reads a store asked for in
		// the query, and WooCommerce adds to the cart from a link on any URL.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only presence is read, to leave the request alone.
		if ( isset( $_COOKIE[ self::CART_COOKIE ] ) || isset( $_GET['inventory'] ) || isset( $_GET['wc-ajax'] ) || isset( $_GET['rest_route'] ) || isset( $_REQUEST['add-to-cart'] ) ) {
			return false;
		}

		$path = self::request_path();

		if ( null === $path || str_starts_with( $path . '/', trim( rest_get_url_prefix(), '/' ) . '/' ) ) {
			return false;
		}

		// The cart, checkout and account pages run cart logic, which needs the
		// store; without a session there is no cart, and a cache that keeps
		// those pages for anonymous visitors (Kinsta does) keeps this one.
		if ( self::has_a_session() ) {
			foreach ( self::CART_PAGES as $page ) {
				$id  = wc_get_page_id( $page );
				$uri = 0 < $id ? get_page_uri( $id ) : '';
				$uri = is_string( $uri ) ? trim( $uri, '/' ) : '';

				if ( '' !== $uri && str_starts_with( $path . '/', $uri . '/' ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Whether the visitor carries WooCommerce's session cookie, the one that keeps a cart.
	 *
	 * @return bool
	 */
	private static function has_a_session(): bool {
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( str_starts_with( (string) $name, self::SESSION_COOKIE ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The requested path relative to the home URL, without slashes at either end.
	 *
	 * @return string|null Null when the request carries none that can be read.
	 */
	private static function request_path(): ?string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = '' === $uri ? null : wp_parse_url( $uri, PHP_URL_PATH );

		if ( ! is_string( $path ) ) {
			return null;
		}

		$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = is_string( $home ) ? trim( $home, '/' ) : '';
		$path = trim( rawurldecode( $path ), '/' );

		if ( '' !== $home ) {
			if ( $path !== $home && ! str_starts_with( $path, $home . '/' ) ) {
				return null;
			}

			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}

		return $path;
	}
}
