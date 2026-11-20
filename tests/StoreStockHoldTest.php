<?php
/**
 * A store's units held for unpaid orders, at checkout.
 *
 * Runs in its own process with RCUS_TESTS_WELAUNCH set, so the bootstrap
 * defines WeLaunch's bootstrap function as if the plugin were active; the
 * child inherits the environment.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Store_Stock_Hold;

/**
 * Covers RhinnoIT\Customizer\Frontend\Store_Stock_Hold.
 */
#[CoversClass( Store_Stock_Hold::class )]
#[RunTestsInSeparateProcesses]
final class StoreStockHoldTest extends TestCase {

	/**
	 * The size the owner described: HK1162011DHN100.
	 */
	const int SIZE = 501;

	/**
	 * hoka's stores, by WeLaunch term id.
	 */
	const int ONLINE    = 280;
	const int COSTANERA = 281;
	const int MARINA    = 368;
	const int ARAUCO    = 430;

	/**
	 * Boots a shop with WeLaunch active.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		putenv( 'RCUS_TESTS_WELAUNCH=1' );
	}

	/**
	 * Leaves the environment as the rest of the suite expects it.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		putenv( 'RCUS_TESTS_WELAUNCH' );
	}

	/**
	 * 4 in total: 3 at Marina, 1 at Costanera, 0 at Arauco, as the owner described it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$options['rcus_store_stock_hold'] = 'yes';
		WP_Stub::$post_meta[ self::SIZE ]['woocommerce_multi_inventory_inventories_stock'] = array(
			self::ONLINE    => '0',
			self::COSTANERA => '1',
			self::MARINA    => '3',
			self::ARAUCO    => '0',
		);
		foreach ( array( self::ONLINE => 'Hoka Tienda Online', self::COSTANERA => 'Costanera', self::MARINA => 'Marina', self::ARAUCO => 'Parque Arauco' ) as $id => $name ) {
			WP_Stub::$terms[ $id ]       = new WP_Term( $id, 'inventories' );
			WP_Stub::$terms[ $id ]->name = $name;
		}
		WP_Stub::$wc          = new WooCommerce();
		WP_Stub::$wc->cart    = new WC_Cart();
		WP_Stub::$wc->session = new WC_Session_Handler();
	}

	/**
	 * Another shopper's order, created at checkout, paying at Webpay: WooCommerce reserved its units.
	 *
	 * @param int    $order_id Order.
	 * @param int    $store    Store on its line.
	 * @param int    $units    Units.
	 * @param string $status   Order status.
	 * @param bool   $expired  Whether Hold stock minutes have passed.
	 * @param string $email    Billing email.
	 * @param int    $customer Account, or 0 for a guest.
	 * @return WC_Order
	 */
	private static function paying( int $order_id, int $store, int $units = 1, string $status = 'pending', bool $expired = false, string $email = 'other@shop.test', int $customer = 0 ): WC_Order {
		WP_Stub::$orders[ $order_id ] = new WC_Order(
			$status,
			array( new WC_Order_Item_Product( 400, self::SIZE, $units, array( '_woocommerce_multi_inventory_inventory' => (string) $store ) ) ),
			$order_id,
			$customer,
			$email
		);
		WP_Stub::$reserved[] = array(
			'product_id'     => self::SIZE,
			'order_id'       => $order_id,
			'stock_quantity' => $units,
			'expired'        => $expired,
		);

		return WP_Stub::$orders[ $order_id ];
	}

	/**
	 * This shopper's cart: the size, at a store, as WeLaunch keeps the line.
	 *
	 * @param int $store Store picked.
	 * @param int $units Units.
	 * @return void
	 */
	private static function cart( int $store, int $units = 1 ): void {
		$product                    = new WC_Product( false, self::SIZE, 400 );
		$product->name              = 'Hoka Clifton 10';
		WP_Stub::$wc->cart->items = array(
			array(
				'data'                                  => $product,
				'quantity'                              => $units,
				'woocommerce_multi_inventory_inventory' => array( 'value' => $store ),
			),
		);
	}

	/**
	 * What the checkout says after the check, as WooCommerce passes it.
	 *
	 * @param string $email The billing email this shopper typed.
	 * @return array<int, string>
	 */
	private static function checkout_errors( string $email = 'me@shop.test' ): array {
		$errors = new WP_Error();
		( new Store_Stock_Hold() )->check_held_units( array( 'billing_email' => $email ), $errors );

		return $errors->get_error_messages();
	}

	/**
	 * It hooks after WeLaunch's own store check, after WooCommerce's reservation and before paying an order, and nothing while off.
	 *
	 * @return void
	 */
	public function test_it_hooks_its_three_checks_and_nothing_while_off(): void {
		( new Store_Stock_Hold() )->register();

		$this->assertSame( array( 'woocommerce_after_checkout_validation:check_held_units', 'woocommerce_checkout_order_created:check_new_order', 'woocommerce_before_pay_action:check_order_to_pay', 'woocommerce_order_status_failed:remember_units_taken' ), WP_Stub::$callbacks );
		$this->assertSame( 5, WP_Stub::$priorities['woocommerce_order_status_failed:remember_units_taken'], 'WooCommerce clears its stock mark on failed at 10.' );
		$this->assertSame( 20, WP_Stub::$priorities['woocommerce_after_checkout_validation:check_held_units'], 'WeLaunch checks at 10.' );
		$this->assertSame( 2, WP_Stub::$registered[0][3] );
		$this->assertSame( 11, WP_Stub::$priorities['woocommerce_checkout_order_created:check_new_order'], 'WooCommerce reserves at 10.' );

		WP_Stub::reset();
		WP_Stub::$options['rcus_store_stock_hold'] = 'no';
		( new Store_Stock_Hold() )->register();
		$this->assertSame( array(), WP_Stub::$callbacks );
	}

	/**
	 * The owner's case: while one shopper pays for Costanera's last unit, a second cannot take it.
	 *
	 * WooCommerce alone would let it through: its reservation is against the
	 * sum, 4 minus 1, and WeLaunch sees Costanera's 1.
	 *
	 * @return void
	 */
	public function test_costaneras_last_unit_is_held_for_the_shopper_paying_for_it(): void {
		self::paying( 900, self::COSTANERA );
		self::cart( self::COSTANERA );

		$errors = self::checkout_errors();

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'Hoka Clifton 10: 0 left at Costanera', $errors[0] );
	}

	/**
	 * The same size at another store is still for sale.
	 *
	 * @return void
	 */
	public function test_the_other_stores_units_stay_on_sale(): void {
		self::paying( 900, self::COSTANERA );
		self::cart( self::MARINA );

		$this->assertSame( array(), self::checkout_errors(), 'Marina still has 3.' );

		self::cart( self::MARINA, 3 );
		$this->assertSame( array(), self::checkout_errors(), 'Costanera\'s hold takes nothing from Marina.' );
	}

	/**
	 * Holds at the same store add up, and the message gives what is left.
	 *
	 * @return void
	 */
	public function test_holds_at_a_store_add_up(): void {
		self::paying( 900, self::MARINA );
		self::paying( 901, self::MARINA );
		self::cart( self::MARINA, 2 );

		$errors = self::checkout_errors();
		$this->assertStringContainsString( '1 left at Marina', $errors[0] ?? '' );

		self::cart( self::MARINA );
		$this->assertSame( array(), self::checkout_errors(), 'One unit is still free.' );
	}

	/**
	 * A hold ends when Hold stock minutes pass, when the order is paid or cancelled, or when it is this shopper's own.
	 *
	 * @return void
	 */
	public function test_a_hold_ends_as_woocommerces_does(): void {
		self::cart( self::COSTANERA );

		self::paying( 900, self::COSTANERA, 1, 'pending', true );
		$this->assertSame( array(), self::checkout_errors(), 'Expired after Hold stock minutes.' );

		WP_Stub::$reserved = array();
		self::paying( 901, self::COSTANERA, 1, 'processing' );
		$this->assertSame( array(), self::checkout_errors(), 'Paid: WeLaunch has taken the unit off the store, so nothing is held twice.' );

		WP_Stub::$reserved = array();
		self::paying( 902, self::COSTANERA, 1, 'cancelled' );
		$this->assertSame( array(), self::checkout_errors() );

		WP_Stub::$reserved                          = array();
		WP_Stub::$wc->session->data['order_awaiting_payment'] = 903;
		self::paying( 903, self::COSTANERA );
		$this->assertSame( array(), self::checkout_errors(), 'This shopper paying again holds nothing against itself.' );
	}

	/**
	 * A shopper back from Webpay who changed the cart has an older order still holding the unit: it is theirs.
	 *
	 * WooCommerce then creates a new order and leaves the old one pending, its
	 * reservation alive, and the session points at the new one.
	 *
	 * @return void
	 */
	public function test_the_shoppers_own_older_order_holds_nothing_against_them(): void {
		self::paying( 900, self::COSTANERA, 1, 'pending', false, 'Me@Shop.test' );
		self::cart( self::COSTANERA );

		$this->assertSame( array(), self::checkout_errors( 'me@shop.test ' ), 'Same billing email, whatever its case.' );
		$this->assertCount( 1, self::checkout_errors( 'someone@shop.test' ), 'Another email is another shopper.' );

		WP_Stub::$orders[900]->customer_id = 7;
		WP_Stub::$user_id                  = 7;
		$this->assertSame( array(), self::checkout_errors( 'someone@shop.test' ), 'Same account.' );

		WP_Stub::$user_id = 0;
		$this->assertCount( 1, self::checkout_errors( '' ), 'No account and no email match nothing.' );
	}

	/**
	 * Two shoppers submitting within the same second both pass validation: once both are reserved, the newer order is refused.
	 *
	 * @return void
	 */
	public function test_the_newer_of_two_orders_for_the_last_unit_is_refused_once_reserved(): void {
		$first  = self::paying( 900, self::COSTANERA );
		$second = self::paying( 901, self::COSTANERA, 1, 'pending', false, 'second@shop.test' );

		$service = new Store_Stock_Hold();
		$service->check_new_order( $first );
		$this->addToAssertionCount( 1 );

		try {
			$service->check_new_order( $second );
			$this->fail( 'The second order for Costanera\'s last unit went through.' );
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'Hoka Clifton 10: 0 left at Costanera', $e->getMessage() );
		}

		$marina = self::paying( 902, self::MARINA, 1, 'pending', false, 'third@shop.test' );
		$service->check_new_order( $marina );
		$service->check_new_order( 'not an order' );
	}

	/**
	 * A failed order the shopper is paying again still holds its unit, until its row expires.
	 *
	 * A rejected Webpay card leaves the order failed and the next try resumes
	 * it, still failed; Mercado Pago and Fintoc complete a failed order when a
	 * second attempt is approved. WooCommerce's own sum leaves failed orders out.
	 *
	 * @return void
	 */
	public function test_a_failed_order_still_holds_its_unit(): void {
		self::paying( 900, self::COSTANERA, 1, 'failed' );
		self::cart( self::COSTANERA );

		$this->assertStringContainsString( '0 left at Costanera', self::checkout_errors()[0] ?? '' );

		WP_Stub::$reserved = array();
		self::paying( 901, self::COSTANERA, 1, 'failed', true );
		$this->assertSame( array(), self::checkout_errors(), 'Its row expired: the shopper gave up.' );
	}

	/**
	 * A failed order on the payment page, as Webpay's rejection or Fintoc's return leaves it.
	 *
	 * @param int $id Order.
	 * @return WC_Order
	 */
	private static function to_pay( int $id = 899 ): WC_Order {
		return new WC_Order( 'failed', array( new WC_Order_Item_Product( 400, self::SIZE, 1, array( '_woocommerce_multi_inventory_inventory' => (string) self::COSTANERA ) ) ), $id, 0, 'b@shop.test' );
	}

	/**
	 * Paying an existing order from its payment page runs no checkout validation, so it is checked there.
	 *
	 * @return void
	 */
	public function test_paying_an_existing_order_respects_the_stores_holds(): void {
		$service = new Store_Stock_Hold();
		$service->check_order_to_pay( self::to_pay() );
		$this->assertSame( array(), WP_Stub::$notices, 'Nobody holds Costanera.' );

		self::paying( 900, self::COSTANERA );
		$service->check_order_to_pay( self::to_pay() );
		$this->assertCount( 1, WP_Stub::$notices );
		$this->assertStringStartsWith( 'error: Hoka Clifton 10: 0 left at Costanera while', WP_Stub::$notices[0], 'An error notice stops WooCommerce from paying.' );

		$service->check_order_to_pay( null );
		$this->assertCount( 1, WP_Stub::$notices );
	}

	/**
	 * Paying an order passes only while it holds its unit: WooCommerce reserves nothing on that page.
	 *
	 * @return void
	 */
	public function test_an_order_paid_from_its_page_holds_its_unit_again(): void {
		$service = new Store_Stock_Hold();

		$service->check_order_to_pay( self::to_pay() );
		$this->assertSame( array( 899 ), WP_Stub::$reservations, 'Its own row is renewed while the shopper pays.' );

		self::paying( 900, self::COSTANERA );
		$service->check_order_to_pay( self::to_pay() );
		$this->assertSame( array( 899 ), WP_Stub::$reservations, 'Refused: nothing reserved.' );

		WP_Stub::$reserved      = array();
		WP_Stub::$notices       = array();
		WP_Stub::$reserve_error = 'Not enough units of Hoka Clifton 10 are available in stock to fulfil this order.';
		$service->check_order_to_pay( self::to_pay() );
		$this->assertSame( array( 'error: Not enough units of Hoka Clifton 10 are available in stock to fulfil this order.' ), WP_Stub::$notices, 'WooCommerce\'s own refusal stops the payment too.' );
	}

	/**
	 * A payment that went on-hold and then failed: WeLaunch kept the store's unit, so the store already lacks it.
	 *
	 * @return void
	 */
	public function test_a_failed_order_whose_unit_welaunch_kept_is_neither_refused_nor_counted(): void {
		$service             = new Store_Stock_Hold();
		$order               = self::paying( 900, self::COSTANERA, 1, 'failed', false, 'b@shop.test' );
		$order->stock_reduced = true;

		$service->remember_units_taken( 900, $order );
		$this->assertSame( 'yes', $order->get_meta( '_rwpo_store_units_taken' ) );
		$this->assertSame( 1, $order->meta_saves );

		// WooCommerce 11 then clears its own mark, and the store reads 0.
		$order->stock_reduced = false;
		WP_Stub::$post_meta[ self::SIZE ]['woocommerce_multi_inventory_inventories_stock'][ self::COSTANERA ] = '0';
		$service->check_order_to_pay( $order );
		$this->assertSame( array(), WP_Stub::$notices, 'Its own unit: the retry is not refused.' );

		WP_Stub::$post_meta[ self::SIZE ]['woocommerce_multi_inventory_inventories_stock'][ self::COSTANERA ] = '1';
		self::cart( self::COSTANERA );
		$this->assertSame( array(), self::checkout_errors(), 'The store\'s 1 is another unit: the failed order\'s row is not counted twice.' );

		$plain = self::paying( 901, self::MARINA, 1, 'failed' );
		$service->remember_units_taken( 901, $plain );
		$this->assertSame( '', $plain->get_meta( '_rwpo_store_units_taken' ), 'Never on-hold: nothing was taken.' );
		$service->remember_units_taken( 'x' );
	}

	/**
	 * The renewal ignores WooCommerce's stock status, which WeLaunch answers from the visitor's store cookie.
	 *
	 * @return void
	 */
	public function test_the_renewal_trusts_the_store_check_over_the_cookies_store(): void {
		( new Store_Stock_Hold() )->check_order_to_pay( self::to_pay() );

		$this->assertTrue( WP_Stub::$in_stock_forced, 'Costanera was just checked; the cookie\'s Online store says nothing about it.' );
		$this->assertArrayNotHasKey( 'woocommerce_product_is_in_stock:__return_true', WP_Stub::$present_filters, 'Taken off again afterwards.' );
	}

	/**
	 * A shopper back from a rejected card who corrected the billing email is still the same shopper.
	 *
	 * The session points at the rejected order, which WooCommerce leaves
	 * holding the unit while the changed cart makes a new order.
	 *
	 * @return void
	 */
	public function test_the_sessions_own_failed_order_never_refuses_its_new_order(): void {
		self::paying( 900, self::COSTANERA, 1, 'failed', false, 'tpyo@shop.test' );
		WP_Stub::$wc->session->data['order_awaiting_payment'] = 900;
		$new = self::paying( 905, self::COSTANERA, 1, 'pending', false, 'typo@shop.test' );

		( new Store_Stock_Hold() )->check_new_order( $new );
		$this->addToAssertionCount( 1 );

		self::cart( self::COSTANERA );
		$this->assertSame( array(), self::checkout_errors( 'typo@shop.test' ) );
	}

	/**
	 * On the payment page nothing else checks the store, so a store without the units refuses.
	 *
	 * The order check (2) refused stays pending in My account with its Pay
	 * button, and a failed one is never cancelled: once the store sold its
	 * unit, paying either would take it a second time.
	 *
	 * @return void
	 */
	public function test_a_store_sold_out_refuses_payment_of_an_old_order(): void {
		WP_Stub::$post_meta[ self::SIZE ]['woocommerce_multi_inventory_inventories_stock'][ self::COSTANERA ] = '0';
		$service = new Store_Stock_Hold();

		$service->check_order_to_pay( self::to_pay() );
		$this->assertSame( array( 'error: Hoka Clifton 10: 0 left at Costanera. Place a new order and choose another location.' ), WP_Stub::$notices );
		$this->assertSame( array(), WP_Stub::$reservations );

		WP_Stub::$notices     = array();
		$order                = self::to_pay();
		$order->stock_reduced = true;
		$service->check_order_to_pay( $order );
		$this->assertSame( array(), WP_Stub::$notices, 'Its units were already taken from the store: the 0 is its own.' );

		WP_Stub::$options['woocommerce_multi_inventory_options'] = array( 'reduceStockOnPendingPayments' => '1' );
		$service->check_order_to_pay( self::to_pay() );
		$this->assertSame( array(), WP_Stub::$notices, 'WeLaunch takes an unpaid order\'s units itself.' );

		self::cart( self::COSTANERA );
		$this->assertSame( array(), self::checkout_errors(), 'At checkout WeLaunch refuses it itself.' );

		WP_Stub::$options                       = array( 'rcus_store_stock_hold' => 'yes' );
		$backorder                              = self::to_pay();
		$backorder->items[0]->backorders        = true;
		$service->check_order_to_pay( $backorder );
		$this->assertSame( array(), WP_Stub::$notices, 'Sold on backorder: a short store is expected.' );
	}

	/**
	 * Lines WeLaunch already speaks for, or that carry no store, are left to WeLaunch.
	 *
	 * @return void
	 */
	public function test_lines_it_cannot_judge_are_left_to_welaunch(): void {
		self::paying( 900, self::COSTANERA );

		self::cart( self::COSTANERA, 2 );
		$this->assertSame( array(), self::checkout_errors(), 'Costanera has 1: WeLaunch refuses 2 itself.' );

		self::cart( self::ARAUCO );
		$this->assertSame( array(), self::checkout_errors(), 'Arauco has 0: WeLaunch refuses it.' );

		WP_Stub::$wc->cart->items[0]['woocommerce_multi_inventory_inventory'] = array();
		$this->assertSame( array(), self::checkout_errors(), 'No store picked.' );

		WP_Stub::$post_meta = array();
		self::cart( self::COSTANERA );
		$this->assertSame( array(), self::checkout_errors(), 'No store stock.' );
	}

	/**
	 * Whatever another filter or a missing cart hands over passes without an error.
	 *
	 * @return void
	 */
	public function test_values_it_cannot_read_change_nothing(): void {
		self::paying( 900, self::COSTANERA );
		self::cart( self::COSTANERA );

		$service = new Store_Stock_Hold();
		$service->check_held_units( array() );
		$service->check_held_units( array(), 'not an error object' );

		WP_Stub::$wc = null;
		$errors      = new WP_Error();
		$service->check_held_units( array(), $errors );
		$this->assertSame( array(), $errors->get_error_codes(), 'No cart.' );
	}
}
