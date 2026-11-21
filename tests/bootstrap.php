<?php
/**
 * Test bootstrap: the slice of WordPress the plugin touches, plus a sandbox.
 *
 * RCUS_PATH points at a temporary directory whose includes/ is a symlink to the
 * real source, so tests can create and corrupt build/ files without touching
 * the repository.
 *
 * @package RhinnoIT\Customizer
 */

$plugin_dir = dirname( __DIR__ );
$sandbox    = sys_get_temp_dir() . '/rcus-tests-' . getmypid();

mkdir( $sandbox . '/build', 0777, true );
symlink( $plugin_dir . '/includes', $sandbox . '/includes' );

register_shutdown_function(
	static function () use ( $sandbox ) {
		array_map( 'unlink', glob( $sandbox . '/build/*' ) ?: array() );
		@rmdir( $sandbox . '/build' );
		@unlink( $sandbox . '/includes' );
		@rmdir( $sandbox );
	}
);

define( 'ABSPATH', $sandbox . '/' );
define( 'RCUS_PATH', $sandbox . '/' );
define( 'RCUS_URL', 'https://shop.test/wp-content/plugins/rhinnoit-customizer/' );
define( 'RCUS_VERSION', '1.0.0' );
define( 'RCUS_FILE', $sandbox . '/rhinnoit-customizer.php' );
define( 'RCUS_BASENAME', 'rhinnoit-customizer/rhinnoit-customizer.php' );
define( 'RCUS_MENU_PARENT_SLUG', 'rhinnoit-tools' );
define( 'RCUS_MENU_PARENT_TITLE', 'RHINNO IT' );
define( 'RCUS_MENU_TITLE', 'Customizer' );
/*
 * What WooCommerce reports, and whether it is there at all. Both come from the
 * environment so a test in its own process can exercise the support gate in
 * Plugin, which is the only thing between a shop without WooCommerce and a
 * fatal error. Unset means a supported WooCommerce, which is what every other
 * case wants.
 */
if ( 'none' !== getenv( 'RCUS_TESTS_WC' ) ) {
	define( 'WC_VERSION', (string) ( getenv( 'RCUS_TESTS_WC' ) ?: '11.0.1' ) );
}
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WP_DEBUG', false );

// WeLaunch Multi Inventory, for the cases that run with it active: RCUS_TESTS_WELAUNCH=1.
if ( '1' === getenv( 'RCUS_TESTS_WELAUNCH' ) ) {
	function welaunch_woocommerce_multi_inventory_run() {}
}

/*
 * WP Optimizer still active beside this plugin, for the cases of the
 * hand-over: RCUS_TESTS_WP_OPTIMIZER=1. Its main file defines this when it
 * loads; the name is WP Optimizer's, written out, not this plugin's.
 */
if ( '1' === getenv( 'RCUS_TESTS_WP_OPTIMIZER' ) ) {
	define( 'RWPO_VERSION', '2.8.13' );
}

/**
 * Mutable state the stubs read. Reset it from setUp().
 */
final class WP_Stub {

	public static bool $is_admin = true;
	/** @var array<int, string> srcset by attachment id, as wp_get_attachment_image_srcset() returns it. */
	public static array $srcsets = array();
	/** @var array<string, int> Named callbacks registered on a filter, hook:callback => priority, for has_filter(). */
	public static array $present_filters = array();
	/** @var array<int, object> Orders by id, for wc_get_order(). */
	public static array $orders = array();
	/** @var array<int, array{product_id: int, order_id: int, stock_quantity: int, expired: bool}> Rows of WooCommerce's wc_reserved_stock. */
	public static array $reserved = array();
	/** @var array<int, WP_Term> Terms by id, for get_term(). */
	public static array $terms = array();
	/** @var array<int, WC_Product> Products by id, for wc_get_product(). */
	public static array $products = array();
	/** The logged-in user, for get_current_user_id(); 0 is a guest. */
	public static int $user_id = 0;
	/** @var array<int, string> WooCommerce notices added, as "type: message". */
	public static array $notices = array();
	/** @var array<int, int> Orders wc_reserve_stock_for_order() reserved, by id, in order. */
	public static array $reservations = array();
	/** What wc_reserve_stock_for_order() throws, as WooCommerce's ReserveStockException would, or '' for nothing. */
	public static string $reserve_error = '';
	/** Whether woocommerce_product_is_in_stock answered true while the last reservation ran. */
	public static bool $in_stock_forced = false;
	/** @var array<string, int> WooCommerce page => page id, as wc_get_page_id() answers. */
	public static array $wc_pages = array();
	/** @var array<int, string> Page id => URI, as get_page_uri() answers. */
	public static array $page_uris = array();
	/** @var array<int, array<string, mixed>> Post id => meta key => value, for get_post_meta(). */
	public static array $post_meta = array();
	/** @var array<string, array<int, array<string, mixed>>> Meta type => object id => key => value, for get_metadata(). */
	public static array $metadata = array();
	public static bool $doing_cron = false;
	public static bool $doing_ajax = false;
	public static bool $logged_in  = false;
	/** What home_url( '/' ) answers. */
	public static string $home_url = 'https://shop.test/';
	/** @var array<int, array{0: string, 1: string, 2: int}> Name, value and expiry of each wc_setcookie() call. */
	public static array $cookies = array();
	/** @var array<int, string> Every _doing_it_wrong() call, as "function: message". */
	public static array $doing_it_wrong = array();
	/** @var array<string, mixed> */
	public static array $options = array();
	/** @var array<string, mixed> */
	public static array $transients = array();
	/** @var array<int, array<string, mixed>> */
	public static array $enqueued = array();
	/** @var array<int, string> */
	public static array $inline = array();
	/** @var array<int, array{0: string, 1: string}> Handle and position of each wp_add_inline_script() call. */
	public static array $inline_at = array();
	/** @var array<int, string> Style handles registered and enqueued, in order. */
	public static array $styles = array();
	/** @var array<int, string> Script handles another plugin registered, for wp_script_is(). */
	public static array $registered_scripts = array();
	/** @var array<int, string> Style handles another plugin registered, for wp_style_is(). */
	public static array $registered_styles = array();
	/** @var array<string, string> Inline CSS by handle. */
	public static array $inline_styles = array();
	/** @var array<int, string> */
	public static array $logs = array();
	/** @var array<int, string> */
	public static array $hooks = array();
	/**
	 * Hook and callback of every add_action()/add_filter(), as "hook:method".
	 *
	 * $hooks only says a hook was used; two services on the same hook need
	 * this to tell which one registered.
	 *
	 * @var array<int, string>
	 */
	public static array $callbacks = array();
	/**
	 * Priority of every hooked callback, keyed "hook:method".
	 *
	 * A callback that has to run before or after another plugin's says so with
	 * its priority, and nothing else would notice that number falling back to
	 * 10: the menu_order filter that keeps the node below Dashboard is one.
	 *
	 * @var array<string, int>
	 */
	public static array $priorities = array();
	/** @var array<int, string> Every remove_action(), as "hook:callback". */
	public static array $removed = array();
	/** @var array<string, int> Same, for remove_action(). */
	public static array $removed_priorities = array();
	/**
	 * Hook => callback apply_filters() runs with every argument, and has_filter( $hook ) reports.
	 *
	 * For a class copied from WooCommerce that calls the plugin, or another
	 * plugin, through a filter.
	 *
	 * @var array<string, callable>
	 */
	public static array $live_filters = array();
	/**
	 * Every do_action() call: the hook, then its arguments.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public static array $fired = array();
	/**
	 * Every add_action()/add_filter(): hook, class of the object, method, accepted args.
	 *
	 * @var array<int, array{0: string, 1: string|null, 2: string, 3: int}>
	 */
	public static array $registered = array();
	/** @var array<int, array<string, mixed>> */
	public static array $menu = array();
	/**
	 * Core's $submenu table: what the node actually shows, back-link included.
	 *
	 * $menu records the calls the plugin makes; this one records what WordPress
	 * ends up with, which is what decides the href of the top-level node.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public static array $submenu = array();
	/** @var array<string, string> */
	public static array $page_hooks = array();
	public static string $page_type = 'product';
	/** What WC() answers: the WooCommerce singleton, or null. */
	public static mixed $wc = null;
	/** What wc_get_checkout_url() answers. */
	public static mixed $checkout_url = 'https://shop.test/finalizar-compra/';
	/** @var mixed What wp_send_json() was last asked to send, or null. */
	public static mixed $json_sent = null;
	/** Tax rate the price helpers apply, 1.19 for Chile's IVA; 1.0 is a price entered with tax. */
	public static float $tax_rate = 1.0;
	/** Decimals of the shop's currency, for wc_get_price_decimals(): 0 for CLP. */
	public static int $price_decimals = 0;
	/**
	 * Capabilities the code under test asked about, in order.
	 *
	 * The stub answers with $can whatever it is handed, so this is the only way
	 * a test can tell that the guard checks the capability it should.
	 *
	 * @var array<int, string>
	 */
	public static array $caps_checked = array();
	public static bool $json_fails = false;
	public static bool $can = true;
	public static bool $nonce_ok = true;
	public static ?string $redirect = null;
	public static string $nonce_action = '';

	/**
	 * Restores a clean slate.
	 *
	 * @return void
	 */
	public static function reset(): void {
		/*
		 * A configured plugin: switched on. The master switch can ship off, and
		 * every feature answers no while it is, so the cases about what the
		 * plugin does would have nothing to test without this.
		 */
		self::$options = array(
			'rcus_enabled' => 'yes',
		);
		self::$is_admin        = true;
		self::$srcsets         = array();
		self::$orders          = array();
		self::$reserved        = array();
		self::$terms           = array();
		self::$products        = array();
		self::$user_id         = 0;
		self::$notices         = array();
		self::$reservations    = array();
		self::$reserve_error   = '';
		self::$in_stock_forced = false;
		self::$wc_pages        = array();
		self::$page_uris       = array();
		self::$post_meta       = array();
		self::$metadata        = array();
		self::$doing_cron      = false;
		self::$doing_ajax      = false;
		self::$logged_in       = false;
		self::$home_url        = 'https://shop.test/';
		self::$cookies         = array();
		self::$doing_it_wrong  = array();
		// Porto hooks its srcset trim when its functions.php loads, on every request.
		self::$present_filters = function_exists( 'porto_image_srcset_filter_sizes' ) ? array( 'wp_calculate_image_srcset:porto_image_srcset_filter_sizes' => 10 ) : array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/';
		$_COOKIE                   = array();
		$_REQUEST                  = array();
		self::$transients = array();
		self::$enqueued   = array();
		self::$inline     = array();
		self::$inline_at  = array();
		self::$styles        = array();
		self::$inline_styles = array();
		self::$registered_scripts = array();
		self::$registered_styles  = array();
		self::$logs       = array();
		self::$hooks      = array();
		self::$callbacks  = array();
		self::$priorities = array();
		self::$removed            = array();
		self::$removed_priorities = array();
		self::$live_filters       = array();
		self::$fired      = array();
		self::$registered = array();
		self::$menu       = array();
		self::$submenu    = array();
		self::$page_hooks = array();
		self::$page_type  = 'product';
		self::$wc             = null;
		self::$checkout_url   = 'https://shop.test/finalizar-compra/';
		self::$json_sent      = null;
		self::$tax_rate       = 1.0;
		self::$price_decimals = 0;

		self::$caps_checked = array();
		self::$json_fails = false;
		self::$can        = true;
		self::$nonce_ok   = true;
		self::$redirect   = null;
		self::$nonce_action = '';
		$_GET             = array();
		$_POST            = array();

		array_map( 'unlink', glob( RCUS_PATH . 'build/*' ) ?: array() );
	}
}

/** Thrown in place of wp_die()/exit so tests can assert on it. */
final class WP_Halt extends RuntimeException {}

function __( $text ) { return $text; }
function esc_html__( $text ) { return $text; }
function wp_strip_all_tags( $text ) { return trim( strip_tags( (string) $text ) ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $url ) { return str_replace( array( '"', '<', '>' ), '', (string) $url ); }
function wp_unslash( $value ) { return $value; }
/**
 * Strict on purpose.
 *
 * Modern WordPress guards sanitize_key() with is_scalar(), but the sniff is
 * filterable and older cores were not. Making the stub assume a string proves
 * the plugin never hands it anything else, instead of leaning on core.
 *
 * @param mixed $key Raw value.
 * @return string
 */
function sanitize_key( $key ) {
	if ( ! is_string( $key ) ) {
		throw new TypeError( 'sanitize_key() received ' . get_debug_type( $key ) );
	}

	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}
function sanitize_title( $title ) { return strtolower( str_replace( ' ', '-', (string) $title ) ); }
function admin_url( $path ) { return '/wp-admin/' . $path; }
function add_query_arg( $key, $value, $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . "$key=$value"; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function is_admin() { return WP_Stub::$is_admin; }
function load_plugin_textdomain( $domain, $abs, $path ) { return true; }

function get_option( $key, $default_value = false ) { return WP_Stub::$options[ $key ] ?? $default_value; }
function add_option( $key, $value ) { WP_Stub::$options[ $key ] ??= $value; return true; }
function update_option( $key, $value ) { WP_Stub::$options[ $key ] = $value; return true; }
function get_transient( $key ) { return WP_Stub::$transients[ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { WP_Stub::$transients[ $key ] = $value; return true; }

function rcus_stub_callback_name( $cb ) { return is_array( $cb ) ? (string) $cb[1] : ( is_string( $cb ) ? $cb : 'closure' ); }
function rcus_stub_register( $hook, $cb, $priority, $args ) {
	WP_Stub::$hooks[]      = $hook;
	WP_Stub::$callbacks[]  = $hook . ':' . rcus_stub_callback_name( $cb );
	WP_Stub::$priorities[ $hook . ':' . rcus_stub_callback_name( $cb ) ] = (int) $priority;
	WP_Stub::$registered[] = array( (string) $hook, is_array( $cb ) && is_object( $cb[0] ) ? get_class( $cb[0] ) : null, rcus_stub_callback_name( $cb ), (int) $args );
}
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { rcus_stub_register( $hook, $cb, $priority, $args ); }
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { if ( is_string( $cb ) ) { WP_Stub::$present_filters[ $hook . ':' . $cb ] = (int) $priority; } rcus_stub_register( $hook, $cb, $priority, $args ); }
function remove_action( $hook, $cb, $priority = 10 ) { WP_Stub::$removed[] = $hook . ':' . ( is_string( $cb ) ? $cb : 'closure' ); WP_Stub::$removed_priorities[ $hook . ':' . ( is_string( $cb ) ? $cb : 'closure' ) ] = $priority; return true; }
function apply_filters( $hook, $value, ...$args ) { if ( isset( WP_Stub::$live_filters[ $hook ] ) ) { return ( WP_Stub::$live_filters[ $hook ] )( $value, ...$args ); } return $value; }
function has_filter( $hook, $callback = false ) { if ( false === $callback ) { return isset( WP_Stub::$live_filters[ $hook ] ); } return is_string( $callback ) && isset( WP_Stub::$present_filters[ $hook . ':' . $callback ] ) ? WP_Stub::$present_filters[ $hook . ':' . $callback ] : false; }
function remove_filter( $hook, $callback, $priority = 10 ) { $key = $hook . ':' . ( is_string( $callback ) ? $callback : '' ); $had = ( WP_Stub::$present_filters[ $key ] ?? null ) === (int) $priority; unset( WP_Stub::$present_filters[ $key ] ); return $had; }
function absint( $maybeint ) { return abs( (int) $maybeint ); }
function wp_list_pluck( $input_list, $field ) { return array_map( static fn ( $item ) => is_object( $item ) ? $item->$field : $item[ $field ], $input_list ); }
function get_page_uri( $page = 0 ) { return WP_Stub::$page_uris[ (int) $page ] ?? false; }

function wp_json_encode( $data ) { return WP_Stub::$json_fails ? false : json_encode( $data ); }
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) { WP_Stub::$enqueued[] = compact( 'handle', 'deps', 'ver' ); }
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) { WP_Stub::$styles[] = 'enqueue:' . $handle; }
function wp_script_is( $handle, $status = 'enqueued' ) { return 'registered' === $status ? in_array( $handle, WP_Stub::$registered_scripts, true ) : in_array( $handle, array_column( WP_Stub::$enqueued, 'handle' ), true ); }
function wp_style_is( $handle, $status = 'enqueued' ) { return 'registered' === $status ? in_array( $handle, WP_Stub::$registered_styles, true ) : in_array( 'enqueue:' . $handle, WP_Stub::$styles, true ); }
function wp_register_style( $handle, $src, $deps = array(), $ver = false ) { WP_Stub::$styles[] = 'register:' . $handle . ':' . var_export( $src, true ); return true; }
/**
 * Core's srcset for a size, with what Porto's wp_calculate_image_srcset trim does to it.
 *
 * Porto drops every candidate narrower than 320 pixels or wider than the box
 * (porto_image_srcset_filter_sizes); for an 85-pixel box nothing is left and
 * core returns false - reproduced with Porto 7.9.3's own function.
 */
function wp_get_attachment_image_srcset( $attachment_id, $size = 'medium', $image_meta = null ) {
	if ( isset( WP_Stub::$present_filters['wp_calculate_image_srcset:porto_image_srcset_filter_sizes'] ) && 'widget-thumb-medium' === $size ) {
		return false;
	}

	return WP_Stub::$srcsets[ (int) $attachment_id ] ?? false;
}
function wp_add_inline_style( $handle, $data ) { WP_Stub::$inline_styles[ (string) $handle ] = (string) $data; return true; }
function wp_style_add_data( $handle, $key, $value ) {}
function wp_set_script_translations( $handle, $domain, $path ) { WP_Stub::$enqueued[] = array( 'handle' => $handle . ':i18n' ); }
function wp_add_inline_script( $handle, $js, $position ) { WP_Stub::$inline[] = $js; WP_Stub::$inline_at[] = array( (string) $handle, (string) $position ); }
function wp_nonce_field( $action ) { WP_Stub::$nonce_action = (string) $action; echo '<input type="hidden" name="_wpnonce" value="stub"/>'; }
function submit_button( $text = '', $type = 'primary', $name = 'submit' ) { echo '<input type="submit" value="Save Changes"/>'; }

function current_user_can( $cap ) { WP_Stub::$caps_checked[] = (string) $cap; return WP_Stub::$can; }
function check_admin_referer( $action ) {
	if ( ! WP_Stub::$nonce_ok ) { throw new WP_Halt( 'nonce' ); }
	return true;
}
function wp_die( $message ) { throw new WP_Halt( $message ); }
function do_action( $hook, ...$args ) { WP_Stub::$fired[] = array_merge( array( $hook ), $args ); }
function wp_send_json( $data ) { WP_Stub::$json_sent = $data; throw new WP_Halt( 'json' ); }
function wp_safe_redirect( $url ) { WP_Stub::$redirect = $url; throw new WP_Halt( 'redirect' ); }
// WC_Discounts asks it of every coupon it applies.
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function get_post_meta( $post_id, $key = '', $single = false ) {
	$value = WP_Stub::$post_meta[ (int) $post_id ][ $key ] ?? null;
	// As core: one value when $single, else the list of every row's value.
	return $single ? ( $value ?? '' ) : ( null === $value ? array() : array( $value ) );
}
function get_metadata( $type, $id, $key = '', $single = false ) { return WP_Stub::$metadata[ $type ][ (int) $id ][ $key ] ?? ( $single ? '' : array() ); }
function get_term_meta( $term_id, $key = '', $single = false ) { return get_metadata( 'term', $term_id, $key, $single ); }
function get_term( $term, $taxonomy = '' ) { return WP_Stub::$terms[ (int) $term ] ?? null; }
function get_current_user_id() { return WP_Stub::$user_id; }
function wp_doing_cron() { return WP_Stub::$doing_cron; }
function wp_doing_ajax() { return WP_Stub::$doing_ajax; }
function is_user_logged_in() { return WP_Stub::$logged_in; }
function home_url( $path = '' ) { return rtrim( WP_Stub::$home_url, '/' ) . '/' . ltrim( (string) $path, '/' ); }
function rest_get_url_prefix() { return 'wp-json'; }
// As core: both strip the tags, and only the textarea one keeps the line breaks.
function sanitize_text_field( $str ) { return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) ); }
function sanitize_textarea_field( $str ) { return trim( strip_tags( (string) $str ) ); }
function esc_textarea( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url_raw( $url ) { return str_replace( array( '"', '<', '>', ' ' ), '', (string) $url ); }

/** The parent theme's directory: Porto's, unless the process runs with RCUS_TESTS_PORTO=none. */
function get_template() { return function_exists( 'porto_widget_product_thumbnail' ) ? 'porto' : 'twentytwentyfive'; }

function _doing_it_wrong( $function_name, $message, $version ) { WP_Stub::$doing_it_wrong[] = $function_name . ': ' . $message; }

/*
 * The two core functions the HTML API's set_attribute() calls, as core has
 * them: the attributes whose value is a URL, and the Unicode noncharacters an
 * attribute name may not hold.
 */
function wp_kses_uri_attributes() {
	return array( 'action', 'archive', 'background', 'cite', 'classid', 'codebase', 'data', 'formaction', 'href', 'icon', 'longdesc', 'manifest', 'poster', 'profile', 'src', 'usemap', 'xmlns' );
}
function wp_has_noncharacters( string $text ): bool {
	return 1 === preg_match( '~\xEF(?:\xB7[\x90-\xAF]|\xBF[\xBE\xBF])|(?:\xF0[\x9F\xAF\xBF]|[\xF1-\xF3][\x8F\x9F\xAF\xBF]|\xF4\x8F)\xBF[\xBE\xBF]~', $text );
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Core's error collection: codes and messages.
	 */
	class WP_Error {
		/** @var array<string, array<int, string>> */
		public array $errors = array();
		public function add( $code, $message, $data = '' ) { $this->errors[ (string) $code ][] = (string) $message; }
		public function get_error_messages( $code = '' ) { return '' === $code ? array_merge( array(), ...array_values( $this->errors ) ) : ( $this->errors[ $code ] ?? array() ); }
		public function get_error_codes() { return array_keys( $this->errors ); }
	}
}

/**
 * $wpdb, for the one table read directly: WooCommerce's wc_reserved_stock, whose rows it has no API for.
 *
 * get_results() answers the prepared query the way MySQL would for the rows in
 * WP_Stub::$reserved: same product, another order, not yet expired.
 */
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

class wpdb {
	public string $wc_reserved_stock = 'wp_wc_reserved_stock';
	public string $last_query        = '';
	/** @var array<int, mixed> */
	private array $args = array();
	public function prepare( $query, ...$args ) { $this->args = $args; return (string) $query; }
	public function get_results( $query, $output = 'OBJECT' ) {
		$this->last_query = (string) $query;
		// %i is the table, then the product and the order left out.
		list( , $product, $exclude ) = array_map( 'intval', $this->args + array( '', 0, 0 ) );
		$rows = array();
		foreach ( WP_Stub::$reserved as $row ) {
			if ( $row['product_id'] === $product && $row['order_id'] !== $exclude && ! $row['expired'] ) {
				$found  = array( 'order_id' => (string) $row['order_id'], 'stock_quantity' => (string) $row['stock_quantity'] );
				$rows[] = ARRAY_A === $output ? $found : (object) $found;
			}
		}
		return $rows;
	}
}
$GLOBALS['wpdb'] = new wpdb();

class WP_Term { public int $term_id = 0; public string $taxonomy = ''; public string $name = ''; public function __construct( int $id = 0, string $taxonomy = 'product_cat' ) { $this->term_id = $id; $this->taxonomy = $taxonomy; } }

function add_menu_page( $page_title, $menu_title, $cap, $slug, $cb = '', $icon = '', $position = null ) {
	WP_Stub::$page_hooks[ $slug ] = sanitize_title( $menu_title );
	WP_Stub::$menu[] = array( 'type' => 'parent', 'slug' => $slug, 'label' => $menu_title, 'cap' => $cap, 'position' => $position, 'icon' => $icon, 'callable' => is_callable( $cb ) );
}

/**
 * Records the child and, as core does, the back-link to the parent.
 *
 * Core inserts the parent as the first child of a node that had none, and
 * menu-header.php then takes the href of the node from that first child. That
 * is the whole failure: with a callback-less parent the link goes nowhere.
 *
 * @param string $parent     Parent slug.
 * @param string $page_title Page title.
 * @param string $menu_title Menu label.
 * @param string $cap        Capability.
 * @param string $slug       Page slug.
 * @param mixed  $cb         Render callback.
 * @return string|false Hook name, which is also the screen id.
 */
function add_submenu_page( $parent, $page_title, $menu_title, $cap, $slug, $cb = '' ) {
	if ( ! WP_Stub::$can ) {
		return false;
	}

	if ( ! isset( WP_Stub::$submenu[ $parent ] ) && $slug !== $parent ) {
		foreach ( WP_Stub::$menu as $item ) {
			if ( 'parent' === $item['type'] && $item['slug'] === $parent ) {
				WP_Stub::$submenu[ $parent ][0] = array( 'slug' => $parent, 'label' => $item['label'], 'callable' => $item['callable'] );
			}
		}
	}

	WP_Stub::$submenu[ $parent ][] = array( 'slug' => $slug, 'label' => $menu_title, 'callable' => is_callable( $cb ) );
	WP_Stub::$menu[]               = array( 'type' => 'child', 'slug' => $slug, 'label' => $menu_title, 'cap' => $cap, 'parent' => $parent, 'callable' => is_callable( $cb ) );

	// get_plugin_page_hookname(): the parent's own hook, then the page slug.
	// It is also the screen id, which is what WooCommerce matches against.
	return ( WP_Stub::$page_hooks[ $parent ] ?? 'admin' ) . '_page_' . $slug;
}

/**
 * Drops one entry, leaving the parent key declared as core does.
 *
 * That detail is what keeps the back-link from coming back when the next plugin
 * of the group hangs its own page off the node.
 *
 * @param string $menu_slug    Parent slug.
 * @param string $submenu_slug Entry to remove.
 * @return array<string, mixed>|false
 */
function remove_submenu_page( $menu_slug, $submenu_slug ) {
	if ( ! isset( WP_Stub::$submenu[ $menu_slug ] ) ) {
		return false;
	}

	foreach ( WP_Stub::$submenu[ $menu_slug ] as $i => $item ) {
		if ( $submenu_slug === $item['slug'] ) {
			unset( WP_Stub::$submenu[ $menu_slug ][ $i ] );

			return $item;
		}
	}

	return false;
}

/*
 * The WooCommerce screens are WordPress pages too, and the shop is one whenever
 * its page is what got queried. Modelling that is the point: a bare is_page()
 * is true on all of them, and a stub that said otherwise would hide it.
 */
function is_page() { return in_array( WP_Stub::$page_type, array( 'page', 'shop', 'cart', 'checkout', 'account' ), true ); }
/*
 * The bare flag is true on every single - a post, a page, a product, any CPT -
 * and only the argument narrows it. Modelling that is the point: with the flag
 * tied to 'post', simplifying is_singular( 'post' ) to is_singular() in the
 * code passed the suite while loading the bundle on every product.
 */
function is_singular( $type = '' ) {
	$singular = is_page() || in_array( WP_Stub::$page_type, array( 'post', 'product' ), true );

	if ( '' === $type || array() === $type ) {
		return $singular;
	}

	return $singular && in_array( WP_Stub::$page_type, (array) $type, true );
}

/*
 * WooCommerce exists only in the processes that define WC_VERSION. The
 * WooCommerce half of the plugin is tested with it; the processes that run
 * without it get none of its functions or classes, exactly as a plain
 * WordPress site would, so code of the WordPress half that calls into
 * WooCommerce by mistake fails its test with a fatal instead of passing on a
 * stub.
 */
if ( defined( 'WC_VERSION' ) ) {
	require_once __DIR__ . '/stubs/woocommerce.php';
}

// Porto, which every shop runs; RCUS_TESTS_PORTO=none boots a site without it.
if ( 'none' !== getenv( 'RCUS_TESTS_PORTO' ) ) {
	require_once __DIR__ . '/stubs/porto.php';
}

/*
 * WordPress's HTML API, which Widget_Thumbnail rewrites Porto's widget
 * thumbnails with. Core loads it on every request since 6.5; tests/stubs/html-api/
 * holds the classes the Tag Processor needs, copied unchanged from WordPress
 * 7.1.2 (wp-includes/html-api/ and wp-includes/class-wp-token-map.php), so
 * the cases run the real parser rather than a stand-in. Loaded only when
 * missing: a harness that loads a site's own core first is tested against
 * that one.
 */
if ( ! class_exists( 'WP_HTML_Tag_Processor', false ) ) {
	foreach ( array( 'class-wp-token-map.php', 'html5-named-character-references.php', 'class-wp-html-decoder.php', 'class-wp-html-attribute-token.php', 'class-wp-html-span.php', 'class-wp-html-text-replacement.php', 'class-wp-html-tag-processor.php' ) as $rcus_html_api_file ) {
		require_once __DIR__ . '/stubs/html-api/' . $rcus_html_api_file;
	}
}

$GLOBALS['admin_page_hooks'] = &WP_Stub::$page_hooks;

require_once $plugin_dir . '/includes/autoload.php';
