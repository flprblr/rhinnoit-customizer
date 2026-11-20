<?php
/**
 * The slice of WooCommerce the plugin touches.
 *
 * Required by tests/bootstrap.php only when WC_VERSION is defined.
 *
 * @package RhinnoIT\Customizer
 */

function wc_get_page_id( $page ) { return WP_Stub::$wc_pages[ $page ] ?? -1; }

function is_product() { return 'product' === WP_Stub::$page_type; }

function is_cart() { return 'cart' === WP_Stub::$page_type; }

function is_checkout() { return 'checkout' === WP_Stub::$page_type; }

function is_account_page() { return 'account' === WP_Stub::$page_type; }

function is_shop() { return 'shop' === WP_Stub::$page_type; }

function is_product_taxonomy() { return 'taxonomy' === WP_Stub::$page_type; }

function wc_get_logger() {
	return new class {
		/**
		 * Records a log line.
		 *
		 * @param string $level   Level.
		 * @param string $message Message.
		 * @param array  $context Context.
		 * @return void
		 */
		public function log( $level, $message, $context = array() ): void {
			WP_Stub::$logs[] = $level . ': ' . $message;
		}
	};
}

/**
 * Minimal product: only what the services and WC_Discounts ask it.
 */
class WC_Product {

	public bool $virtual;
	public int $id;
	public int $parent;

	/**
	 * @param bool $virtual Whether the product ships.
	 * @param int  $id      Product id.
	 * @param int  $parent  Parent id, for a variation.
	 */
	public function __construct( bool $virtual = false, int $id = 0, int $parent = 0 ) {
		$this->virtual = $virtual;
		$this->id      = $id;
		$this->parent  = $parent;
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_parent_id(): int {
		return $this->parent;
	}

	/**
	 * Whether the product needs no shipping.
	 *
	 * @return bool
	 */
	public function is_virtual(): bool {
		return $this->virtual;
	}

	/** @var string Regular price, as WooCommerce stores it: a numeric string or ''. */
	public string $regular_price = '';

	/** @var string Active price: the sale price while a sale runs. */
	public string $price = '';

	public function get_regular_price(): string {
		return $this->regular_price;
	}

	public function get_price(): string {
		return $this->price;
	}

	/** @var string Product name. */
	public string $name = 'Product';

	public function get_name(): string {
		return $this->name;
	}

	/** @var int|null The product whose stock this one uses: itself, or the parent for a variation managed by it. */
	public ?int $stock_owner = null;

	public function get_stock_managed_by_id(): int {
		return $this->stock_owner ?? $this->id;
	}

	/** Whether the product sells on backorder. */
	public bool $backorders = false;

	public function backorders_allowed(): bool {
		return $this->backorders;
	}

	/** Whether its stock is on backorder now. */
	public bool $on_backorder = false;

	public function is_on_backorder(): bool {
		return $this->on_backorder;
	}

	/** @var string simple, variable, variation, bundle... */
	public string $type = 'simple';

	public function is_type( string|array $type ): bool {
		return in_array( $this->type, (array) $type, true );
	}
}

/**
 * A line of an order, with the store WeLaunch writes on it.
 */
class WC_Order_Item_Product {

	/**
	 * @param int                  $product_id   Product.
	 * @param int                  $variation_id Variation, or 0.
	 * @param int                  $quantity     Units.
	 * @param array<string, mixed> $meta         Line meta.
	 */
	public function __construct( public int $product_id = 0, public int $variation_id = 0, public int $quantity = 1, public array $meta = array() ) {}

	public function get_product_id(): int {
		return $this->product_id;
	}

	public function get_variation_id(): int {
		return $this->variation_id;
	}

	public function get_quantity(): int {
		return $this->quantity;
	}

	public function get_meta( string $key ): mixed {
		return $this->meta[ $key ] ?? '';
	}

	/** @var string The product's name. */
	public string $name = 'Hoka Clifton 10';

	/** Whether the product sells on backorder. */
	public bool $backorders = false;

	/**
	 * The line's product: the variation when there is one.
	 *
	 * @return WC_Product
	 */
	public function get_product(): WC_Product {
		$product       = $this->variation_id ? new WC_Product( false, $this->variation_id, $this->product_id ) : new WC_Product( false, $this->product_id );
		$product->name       = $this->name;
		$product->backorders = $this->backorders;

		return $product;
	}
}

/**
 * An order: its status, its lines and who placed it.
 */
class WC_Order {

	/**
	 * @param string                            $status        Status without the wc- prefix.
	 * @param array<int, WC_Order_Item_Product> $items         Lines.
	 * @param int                               $id            Order id.
	 * @param int                               $customer_id   Account, or 0 for a guest.
	 * @param string                            $billing_email Billing email.
	 */
	public function __construct( public string $status = 'pending', public array $items = array(), public int $id = 0, public int $customer_id = 0, public string $billing_email = '' ) {}

	public function get_id(): int {
		return $this->id;
	}

	public function get_customer_id(): int {
		return $this->customer_id;
	}

	public function get_billing_email(): string {
		return $this->billing_email;
	}

	/** Whether WooCommerce has reduced the order's stock. */
	public bool $stock_reduced = false;

	public function get_order_stock_reduced(): bool {
		return $this->stock_reduced;
	}

	/** @var array<string, mixed> Order meta. */
	public array $meta = array();

	/** Times save_meta_data() ran. */
	public int $meta_saves = 0;

	public function get_meta( string $key ): mixed {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function save_meta_data(): void {
		++$this->meta_saves;
	}

	/** When the order was created, or null. */
	public ?DateTimeImmutable $date_created = null;

	public function get_date_created(): ?DateTimeImmutable {
		return $this->date_created;
	}

	/**
	 * @param string|array<int, string> $status One status or several.
	 * @return bool
	 */
	public function has_status( string|array $status ): bool {
		return in_array( $this->status, (array) $status, true );
	}

	/**
	 * @return array<int, WC_Order_Item_Product>
	 */
	public function get_items(): array {
		return $this->items;
	}
}

function wc_get_order( $order_id ) { return WP_Stub::$orders[ (int) $order_id ] ?? false; }

function wc_get_product( $the_product = false ) { return WP_Stub::$products[ (int) $the_product ] ?? false; }

function wc_add_notice( $message, $notice_type = 'success', $data = array() ) { WP_Stub::$notices[] = $notice_type . ': ' . $message; }

/**
 * WooCommerce's reservation of an order's units: records the order, or throws what WP_Stub::$reserve_error says.
 *
 * @param WC_Order|int $order Order.
 * @return void
 * @throws Exception As WooCommerce's ReserveStockException, when the units are not there.
 */
function wc_reserve_stock_for_order( $order ) {
	if ( '' !== WP_Stub::$reserve_error ) {
		throw new Exception( WP_Stub::$reserve_error );
	}

	WP_Stub::$reservations[] = $order instanceof WC_Order ? $order->get_id() : (int) $order;
	WP_Stub::$in_stock_forced = isset( WP_Stub::$present_filters['woocommerce_product_is_in_stock:__return_true'] );
}

/**
 * Minimal cart: only what the journey services ask it.
 */
class WC_Cart {

	/** @var array<int, array<string, mixed>> */
	public array $items = array();

	/**
	 * Cart lines, in the shape WooCommerce hands them over.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_cart(): array {
		return $this->items;
	}

	/**
	 * Fills the cart with products of the given kind.
	 *
	 * @param bool ...$virtual One flag per line.
	 * @return void
	 */
	public function fill( bool ...$virtual ): void {
		$this->items = array();

		foreach ( $virtual as $is_virtual ) {
			$this->items[] = array( 'data' => new WC_Product( $is_virtual ) );
		}
	}

	/** Whether the cart shows prices with tax, WooCommerce's woocommerce_tax_display_cart. */
	public bool $incl_tax = true;

	public function display_prices_including_tax(): bool {
		return $this->incl_tax;
	}
}

/**
 * WooCommerce's session: only the values Store_Stock_Hold reads from it.
 */
class WC_Session_Handler {

	/** @var array<string, mixed> Session values. */
	public array $data = array();

	public function get( string $key, mixed $default_value = null ): mixed {
		return $this->data[ $key ] ?? $default_value;
	}
}

/**
 * Stands in for the WooCommerce singleton.
 */
class WooCommerce {

	public ?WC_Cart $cart = null;

	/** The cookie handler, or the Store API's own handler for a cart token. */
	public ?object $session = null;
}

function WC() { return WP_Stub::$wc; }

function wc_get_checkout_url() { return WP_Stub::$checkout_url; }

/** Tax rate the price helpers apply, 1.19 for Chile's IVA; 1.0 is a price entered with tax. */
function wc_stub_tax_rate(): float { return WP_Stub::$tax_rate; }

function wc_get_price_decimals() { return WP_Stub::$price_decimals; }

function wc_price( $price ) { return '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">&#36;</span>' . number_format( (float) $price, 0, ',', '.' ) . '</bdi></span>'; }

function wc_get_price_including_tax( $product, $args = array() ) { return (float) ( $args['price'] ?? $product->get_price() ) * (float) ( $args['qty'] ?? 1 ) * wc_stub_tax_rate(); }

function wc_get_price_excluding_tax( $product, $args = array() ) { return (float) ( $args['price'] ?? $product->get_price() ) * (float) ( $args['qty'] ?? 1 ); }

/**
 * WooCommerce 11.1's own, line for line but the filter.
 */
function wc_format_sale_price( $regular_price, $sale_price ) {
	$formatted_regular_price = is_numeric( $regular_price ) ? wc_price( $regular_price ) : $regular_price;
	$formatted_sale_price    = is_numeric( $sale_price ) ? wc_price( $sale_price ) : $sale_price;

	$price  = '<del aria-hidden="true">' . $formatted_regular_price . '</del> ';
	$price .= '<span class="screen-reader-text">' . esc_html( sprintf( __( 'Original price was: %s.' ), wp_strip_all_tags( $formatted_regular_price ) ) ) . '</span>';
	$price .= '<ins aria-hidden="true">' . $formatted_sale_price . '</ins>';
	$price .= '<span class="screen-reader-text">' . esc_html( sprintf( __( 'Current price is: %s.' ), wp_strip_all_tags( $formatted_sale_price ) ) ) . '</span>';

	return $price;
}

function wc_setcookie( $name, $value, $expire = 0, $secure = false, $httponly = false ) { WP_Stub::$cookies[] = array( (string) $name, (string) $value, (int) $expire ); }

/**
 * A coupon as WC_Discounts reads it: code, type, amount and the products a product coupon is for.
 */
class WC_Coupon {

	/**
	 * @param string          $code        Coupon code.
	 * @param string          $type        percent, fixed_cart or fixed_product.
	 * @param float           $amount      Amount, or percentage.
	 * @param array<int, int> $product_ids Products a percent or fixed product coupon is for; none means every one.
	 */
	public function __construct( public string $code = 'coupon', public string $type = 'fixed_cart', public float $amount = 0.0, public array $product_ids = array() ) {}

	public function get_code(): string {
		return $this->code;
	}

	public function get_discount_type(): string {
		return $this->type;
	}

	/**
	 * @param string|array<int, string> $type One type or several.
	 */
	public function is_type( string|array $type ): bool {
		return $this->type === $type || ( is_array( $type ) && in_array( $this->type, $type, true ) );
	}

	public function get_amount(): float {
		return $this->amount;
	}

	public function get_limit_usage_to_x_items(): ?int {
		return null;
	}

	/**
	 * As WooCommerce answers it: never for a cart coupon, which is valid for the cart instead.
	 */
	public function is_valid_for_product( WC_Product $product, mixed $values = array() ): bool {
		return $this->is_type( array( 'fixed_product', 'percent' ) ) && ( array() === $this->product_ids || in_array( $product->get_id(), $this->product_ids, true ) );
	}

	public function is_valid_for_cart(): bool {
		return $this->is_type( array( 'fixed_cart' ) );
	}
}

/*
 * WooCommerce's own WC_Discounts, which splits the coupons of every cart and
 * of an order recalculated in the admin, and the NumberUtil it rounds with.
 * tests/stubs/woocommerce-core/ holds both copied unchanged from WooCommerce
 * 11.2.2 (includes/class-wc-discounts.php, src/Utilities/NumberUtil.php), so
 * Coupon_Split is tested against the real split rather than a stand-in. The
 * functions below are WooCommerce's own (includes/wc-core-functions.php), and
 * the constants hold the values WooCommerce defines.
 */
define( 'WC_ROUNDING_PRECISION', 6 );
define( 'WC_DISCOUNT_ROUNDING_MODE', PHP_ROUND_HALF_DOWN );

function wc_get_rounding_precision() {
	$precision = wc_get_price_decimals() + 2;
	if ( $precision < absint( WC_ROUNDING_PRECISION ) ) {
		$precision = absint( WC_ROUNDING_PRECISION );
	}

	return apply_filters( 'woocommerce_internal_rounding_precision', $precision );
}

function wc_add_number_precision( ?float $value, bool $round = true ) {
	if ( ! $value ) {
		return 0.0;
	}

	$result          = $value * pow( 10, wc_get_price_decimals() );
	$round_precision = $round ? wc_get_rounding_precision() - wc_get_price_decimals() : wc_get_rounding_precision();

	return Automattic\WooCommerce\Utilities\NumberUtil::round( $result, $round_precision );
}

function wc_remove_number_precision( $value ) {
	if ( ! $value ) {
		return 0.0;
	}

	$cent_precision = pow( 10, wc_get_price_decimals() );
	return $value / $cent_precision;
}

function wc_add_number_precision_deep( $value, $round = true ) {
	if ( ! is_array( $value ) ) {
		return wc_add_number_precision( (float) $value, $round );
	}

	foreach ( $value as $key => $sub_value ) {
		$value[ $key ] = wc_add_number_precision_deep( $sub_value, $round );
	}

	return $value;
}

function wc_remove_number_precision_deep( $value ) {
	if ( ! is_array( $value ) ) {
		return wc_remove_number_precision( $value );
	}

	foreach ( $value as $key => $sub_value ) {
		$value[ $key ] = wc_remove_number_precision_deep( $sub_value );
	}

	return $value;
}

function wc_round_discount( $value, $precision ) {
	return Automattic\WooCommerce\Utilities\NumberUtil::round( $value, $precision, WC_DISCOUNT_ROUNDING_MODE );
}

require_once __DIR__ . '/woocommerce-core/NumberUtil.php';
require_once __DIR__ . '/woocommerce-core/class-wc-discounts.php';
