<?php
/**
 * Holds a store's units for unpaid orders, not only the sum of every store.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Subtracts the units other unpaid orders hold at a store from that store's stock at checkout.
 *
 * WooCommerce reserves a pending order's units for Hold stock minutes, in
 * its wc_reserved_stock table, against the product's _stock - which WeLaunch
 * Multi Inventory keeps as the sum of every store. WeLaunch keeps each
 * store's own stock in woocommerce_multi_inventory_inventories_stock and, at
 * checkout (woocommerce_after_checkout_validation, priority 10), compares
 * only that number with the quantity; it takes a store's units off when the
 * order is paid. So while one shopper pays for a store's last unit at
 * Webpay, a second one passes every check for the same unit at the same
 * store: hoka's branches reported three oversells on 2026-10-05.
 *
 * Here each line with a store picked subtracts what other orders hold of that
 * product at that store: the unexpired wc_reserved_stock rows of the product,
 * from orders still pending or failed, whose line for the product carries the
 * store in _woocommerce_multi_inventory_inventory. Failed counts, unlike in
 * WooCommerce's own sum: a rejected Webpay card leaves the order failed, and
 * the shopper's next try resumes that same order, still failed, while
 * WooCommerce renews its row; Mercado Pago and Fintoc complete a failed order
 * when a second attempt is approved. A failed order the shopper abandons
 * holds its unit until its row expires, as a pending one does. WooCommerce has no API that lists
 * the rows, only their sum, so the rows are read directly, as its own
 * ReserveStock does; the orders are read through wc_get_order(). The
 * shopper's own orders hold nothing against them: the one the session is
 * paying, as WooCommerce leaves it out, and any other unpaid one with the
 * same account or billing email - going back from Webpay and changing the
 * cart leaves an older order still holding the unit.
 *
 * It checks three times. After WeLaunch's own check, at validation. Again
 * once WooCommerce has reserved the new order's units, counting only older
 * orders: two shoppers who submit within the same second both pass the first
 * check, before either row exists, and the newer order is the one refused -
 * thrown from woocommerce_checkout_order_created, which WooCommerce catches,
 * releasing the reservation and showing the message, as when its own
 * reservation fails. And before an existing order is paid from its payment
 * page - My account's Pay button, and where Fintoc sends a shopper who
 * cancelled or failed - a path that runs no checkout validation at all: there
 * WeLaunch checks nothing, so a store that no longer has the units refuses
 * too, unless the order's units were already taken from it; and once the
 * order passes, its own reservation is renewed through WooCommerce, which
 * reserves nothing on that page, so the unit stays held while the shopper
 * pays.
 *
 * A line that WeLaunch already refused, or whose store has no stock number,
 * is left to WeLaunch. Sales made at the store's counter are not seen here:
 * they reach the web only when the ERP writes the store's stock. Staff moving
 * an unpaid order's line to another store in the admin make WeLaunch take the
 * unit off that store at once, so it is counted twice until the order is paid
 * or its hold expires. A payment the gateway takes without any request to the
 * shop - a Mercado Pago preference or a Fintoc session still open after the
 * hold - is not seen either.
 */
final class Store_Stock_Hold {

	/**
	 * Where WeLaunch keeps each store's stock, by store id.
	 */
	const string STOCK_META = 'woocommerce_multi_inventory_inventories_stock';

	/**
	 * The store WeLaunch writes on each order line.
	 */
	const string LINE_STORE = '_woocommerce_multi_inventory_inventory';

	/**
	 * The store WeLaunch keeps on each cart line.
	 */
	const string CART_STORE = 'woocommerce_multi_inventory_inventory';

	/**
	 * WeLaunch's taxonomy of stores.
	 */
	const string TAXONOMY = 'inventories';

	/**
	 * WeLaunch's settings, where reduceStockOnPendingPayments lives.
	 */
	const string WELAUNCH_OPTIONS = 'woocommerce_multi_inventory_options';

	/**
	 * Order meta: WeLaunch took this order's store units and kept them when it failed.
	 *
	 * WP Optimizer's spelling, kept: the failed orders it marked are read in
	 * place, or their units would count twice. Order data, so uninstall.php
	 * never deletes it.
	 */
	const string UNITS_TAKEN = '_rwpo_store_units_taken';

	/**
	 * Statuses whose unexpired reservation holds a store's units.
	 *
	 * @var array<int, string>
	 */
	const array HOLDING = array( 'pending', 'checkout-draft', 'failed' );

	/**
	 * Hooks the checks while the switch is on and WeLaunch is active.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'store_stock_hold' ) || ! function_exists( 'welaunch_woocommerce_multi_inventory_run' ) ) {
			return;
		}

		// After WeLaunch's own store check, at 10.
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'check_held_units' ), 20, 2 );
		// Right after WooCommerce reserves the new order's units, at 10.
		add_action( 'woocommerce_checkout_order_created', array( $this, 'check_new_order' ), 11 );
		add_action( 'woocommerce_before_pay_action', array( $this, 'check_order_to_pay' ) );
		// Before WooCommerce clears the order's stock mark on failed, at 10.
		add_action( 'woocommerce_order_status_failed', array( $this, 'remember_units_taken' ), 5, 2 );
	}

	/**
	 * Marks a failed order whose store units WeLaunch took and will not give back.
	 *
	 * A payment Fintoc validates, or Mercado Pago reviews, puts the order
	 * on-hold first: WeLaunch takes the units off the store and WooCommerce
	 * marks the order's stock reduced. When the payment then fails, WooCommerce
	 * 11 restores _stock and clears that mark, but WeLaunch gives a store its
	 * units back only on pending and cancelled. The store's figure already
	 * lacks this order's units, so its retry must not be refused for them and
	 * its row must not be counted against other shoppers. Read here, while the
	 * mark is still set.
	 *
	 * @param mixed $order_id The order.
	 * @param mixed $order    The order object, when WooCommerce passes it.
	 * @return void
	 */
	public function remember_units_taken( mixed $order_id, mixed $order = null ): void {
		if ( ! $order instanceof \WC_Order ) {
			$order = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : null;
		}

		if ( $order instanceof \WC_Order && $order->get_order_stock_reduced() ) {
			$order->update_meta_data( self::UNITS_TAKEN, 'yes' );
			$order->save_meta_data();
		}
	}

	/**
	 * Refuses a line its store cannot cover once other unpaid orders are counted.
	 *
	 * @param mixed $data   Posted checkout data.
	 * @param mixed $errors The checkout's WP_Error.
	 * @return void
	 */
	public function check_held_units( mixed $data, mixed $errors = null ): void {
		$cart = function_exists( 'WC' ) && WC() instanceof \WooCommerce ? WC()->cart : null;

		if ( ! $errors instanceof \WP_Error || ! $cart instanceof \WC_Cart ) {
			return;
		}

		$shopper = array(
			'order'    => self::order_being_paid(),
			'session'  => self::order_being_paid(),
			'customer' => get_current_user_id(),
			'email'    => is_array( $data ) && is_string( $data['billing_email'] ?? null ) ? $data['billing_email'] : '',
			'before'   => 0,
			'strict'   => false,
		);

		foreach ( $cart->get_cart() as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}

			$problem = self::shortfall( $line['data'] ?? null, $line['quantity'] ?? null, $line[ self::CART_STORE ]['value'] ?? null, $shopper );

			if ( null !== $problem ) {
				$errors->add( 'rcus-store-held', $problem );
			}
		}
	}

	/**
	 * Refuses a new order when an older unpaid order took the store's unit first.
	 *
	 * @param mixed $order The order just created, its units already reserved.
	 * @return void
	 * @throws \Exception When the store cannot cover a line; WooCommerce's create_order() catches it.
	 */
	public function check_new_order( mixed $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$problems = self::order_shortfalls( $order, $order->get_id(), false );

		if ( array() !== $problems ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Built by shortfall(), names escaped; WooCommerce prints it as a notice.
			throw new \Exception( $problems[0] );
		}
	}

	/**
	 * Stops the payment of an existing order its store cannot cover, or holds its units while it is paid.
	 *
	 * @param mixed $order The order about to be paid.
	 * @return void
	 */
	public function check_order_to_pay( mixed $order ): void {
		if ( ! $order instanceof \WC_Order || ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		$problems = self::order_shortfalls( $order, 0, self::units_still_in_store( $order ) );

		// WooCommerce pays only when no error notice is left.
		foreach ( $problems as $problem ) {
			wc_add_notice( $problem, 'error' );
		}

		if ( array() !== $problems || ! function_exists( 'wc_reserve_stock_for_order' ) ) {
			return;
		}

		// The store was just checked. WooCommerce's stock status is not the
		// order's store: WeLaunch answers it from the visitor's store cookie.
		add_filter( 'woocommerce_product_is_in_stock', '__return_true', PHP_INT_MAX );

		try {
			wc_reserve_stock_for_order( $order );
		} catch ( \Exception $e ) {
			// WooCommerce's own words: not enough units left in the sum of the stores.
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
		} finally {
			remove_filter( 'woocommerce_product_is_in_stock', '__return_true', PHP_INT_MAX );
		}
	}

	/**
	 * Whether the order's units are still counted in its stores' stock.
	 *
	 * False once they were taken: WooCommerce marks the order when its stock is
	 * reduced, remember_units_taken() keeps that for a failed order WooCommerce
	 * unmarked, and WeLaunch's reduceStockOnPendingPayments takes a pending
	 * order's units without any mark.
	 *
	 * @param \WC_Order $order The order.
	 * @return bool
	 */
	private static function units_still_in_store( \WC_Order $order ): bool {
		$options = get_option( self::WELAUNCH_OPTIONS );

		if ( is_array( $options ) && ! empty( $options['reduceStockOnPendingPayments'] ) ) {
			return false;
		}

		return ! $order->get_order_stock_reduced() && 'yes' !== $order->get_meta( self::UNITS_TAKEN );
	}

	/**
	 * The messages for an order's lines that their stores cannot cover.
	 *
	 * @param \WC_Order $order  The order.
	 * @param int       $before Count only orders older than this one, or 0 for all.
	 * @param bool      $strict Refuse a store short of the units even before holds: nothing else checks it here.
	 * @return array<int, string>
	 */
	private static function order_shortfalls( \WC_Order $order, int $before, bool $strict ): array {
		$shopper  = array(
			'order'    => $order->get_id(),
			// The session's earlier order, when the shopper typed another email this time.
			'session'  => self::order_being_paid(),
			'customer' => $order->get_customer_id(),
			'email'    => $order->get_billing_email(),
			'before'   => $before,
			'strict'   => $strict,
		);
		$problems = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$problem = self::shortfall( $item->get_product(), $item->get_quantity(), $item->get_meta( self::LINE_STORE ), $shopper );

			if ( null !== $problem ) {
				$problems[] = $problem;
			}
		}

		return $problems;
	}

	/**
	 * The message for a line its store cannot cover, or null.
	 *
	 * @param mixed                                                                                    $product  The line's product.
	 * @param mixed                                                                                    $quantity Units.
	 * @param mixed                                                                                    $store    The store picked.
	 * @param array{order: int, session: int, customer: int, email: string, before: int, strict: bool} $shopper  Whose orders hold nothing against this line.
	 * @return string|null
	 */
	private static function shortfall( mixed $product, mixed $quantity, mixed $store, array $shopper ): ?string {
		$quantity = is_numeric( $quantity ) ? (int) $quantity : 0;

		if ( ! $product instanceof \WC_Product || 0 >= $quantity || ! is_numeric( $store ) || 0 >= (int) $store ) {
			return null;
		}

		$store   = (int) $store;
		$managed = (int) $product->get_stock_managed_by_id();
		$stocks  = get_post_meta( $managed, self::STOCK_META, true );
		$stock   = is_array( $stocks ) && isset( $stocks[ $store ] ) && is_numeric( $stocks[ $store ] ) ? (int) $stocks[ $store ] : null;

		// No number; or, at checkout or on backorder, already short without holds: WeLaunch's own check speaks.
		if ( null === $stock || ( $stock < $quantity && ( ! $shopper['strict'] || $product->backorders_allowed() ) ) ) {
			return null;
		}

		$term = get_term( $store, self::TAXONOMY );
		$name = $term instanceof \WP_Term ? esc_html( $term->name ) : (string) $store;

		if ( $stock < $quantity ) {
			return sprintf(
				/* translators: 1: product name, 2: store name, 3: units the store has. */
				__( '%1$s: %3$d left at %2$s. Place a new order and choose another location.', 'rhinnoit-customizer' ),
				esc_html( $product->get_name() ),
				$name,
				max( 0, $stock )
			);
		}

		$available = $stock - self::held( $managed, $store, $shopper );

		if ( $available >= $quantity ) {
			return null;
		}

		return sprintf(
			/* translators: 1: product name, 2: store name, 3: units the store can still sell. */
			__( '%1$s: %3$d left at %2$s while other customers complete their payment. Choose another location or fewer units, or try again in a few minutes.', 'rhinnoit-customizer' ),
			esc_html( $product->get_name() ),
			$name,
			max( 0, $available )
		);
	}

	/**
	 * Units of a product that other shoppers' unpaid orders hold at a store.
	 *
	 * @param int                                                                                      $product_id The product or variation that manages the stock.
	 * @param int                                                                                      $store      The store.
	 * @param array{order: int, session: int, customer: int, email: string, before: int, strict: bool} $shopper    Whose orders to leave out.
	 * @return int
	 */
	private static function held( int $product_id, int $store, array $shopper ): int {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb || empty( $wpdb->wc_reserved_stock ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce has no API listing reservations; live rows must not be cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT `order_id`, `stock_quantity` FROM %i WHERE `product_id` = %d AND `order_id` != %d AND `expires` > NOW()',
				$wpdb->wc_reserved_stock,
				$product_id,
				$shopper['order']
			),
			ARRAY_A
		);

		$held = 0;

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['order_id'], $row['stock_quantity'] ) ) {
				continue;
			}

			$order_id = (int) $row['order_id'];

			if ( 0 < $shopper['before'] && $order_id >= $shopper['before'] ) {
				continue;
			}

			$order = wc_get_order( $order_id );

			// A failed order whose units the store already lacks holds nothing more.
			if ( ! $order instanceof \WC_Order || ! $order->has_status( self::HOLDING ) || 'yes' === $order->get_meta( self::UNITS_TAKEN ) || self::is_shoppers( $order, $shopper ) ) {
				continue;
			}

			$held += min( (int) $row['stock_quantity'], self::units_at_store( $order, $product_id, $store ) );
		}

		return $held;
	}

	/**
	 * Whether an order is the shopper's own, by account or billing email.
	 *
	 * @param \WC_Order                                                                                $order   The order.
	 * @param array{order: int, session: int, customer: int, email: string, before: int, strict: bool} $shopper The shopper.
	 * @return bool
	 */
	private static function is_shoppers( \WC_Order $order, array $shopper ): bool {
		if ( 0 < $shopper['session'] && $order->get_id() === $shopper['session'] ) {
			return true;
		}

		if ( 0 < $shopper['customer'] && $order->get_customer_id() === $shopper['customer'] ) {
			return true;
		}

		$email = trim( $shopper['email'] );

		return '' !== $email && 0 === strcasecmp( trim( $order->get_billing_email() ), $email );
	}

	/**
	 * Units of a product an order's lines take from a store.
	 *
	 * @param \WC_Order $order      The order.
	 * @param int       $product_id The product or variation that manages the stock.
	 * @param int       $store      The store.
	 * @return int
	 */
	private static function units_at_store( \WC_Order $order, int $product_id, int $store ): int {
		$units = 0;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || ! in_array( $product_id, array( $item->get_variation_id(), $item->get_product_id() ), true ) ) {
				continue;
			}

			if ( (string) $store === (string) $item->get_meta( self::LINE_STORE ) ) {
				$units += (int) $item->get_quantity();
			}
		}

		return $units;
	}

	/**
	 * The pending order this session is paying again, which holds nothing against itself.
	 *
	 * @return int
	 */
	private static function order_being_paid(): int {
		$session = function_exists( 'WC' ) && WC() instanceof \WooCommerce ? WC()->session : null;
		$id      = is_object( $session ) && method_exists( $session, 'get' ) ? $session->get( 'order_awaiting_payment' ) : 0;

		return is_numeric( $id ) ? (int) $id : 0;
	}
}
