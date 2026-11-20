<?php
/**
 * Skipping the cart page.
 *
 * Both hooks run on every add to cart, including the ordinary journey through
 * the catalogue, so the cases that must NOT redirect matter more than the one
 * that must.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Express_Checkout;

/**
 * Covers RhinnoIT\Customizer\Frontend\Express_Checkout.
 */
final class ExpressCheckoutTest extends TestCase {

	/**
	 * The URL WooCommerce would have used.
	 *
	 * @var string
	 */
	private const CART = 'https://shop.test/carrito/';

	/**
	 * Where a cart that skips the cart page should end up.
	 *
	 * @var string
	 */
	private const CHECKOUT = 'https://shop.test/finalizar-compra/';

	/**
	 * Gives every test a loaded cart holding virtual goods, and the virtual switch on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$options['rcus_express_checkout'] = 'yes';

		$cart = new WC_Cart();
		$cart->fill( true );

		$wc       = new WooCommerce();
		$wc->cart = $cart;

		WP_Stub::$wc           = $wc;
		WP_Stub::$checkout_url = self::CHECKOUT;
	}

	/**
	 * Leaves the stubs as the rest of the suite expects them.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		WP_Stub::$wc           = null;
		WP_Stub::$checkout_url = self::CHECKOUT;
	}

	/**
	 * A registered service, as the plugin builds it.
	 *
	 * @param bool $virtual Whether "virtual products" is on.
	 * @param bool $every   Whether "every product" is on.
	 * @return Express_Checkout
	 */
	private function service( bool $virtual = true, bool $every = false ): Express_Checkout {
		WP_Stub::$options['rcus_express_checkout']     = $virtual ? 'yes' : 'no';
		WP_Stub::$options['rcus_express_checkout_all'] = $every ? 'yes' : 'no';

		$express = new Express_Checkout();
		$express->register();

		return $express;
	}

	/**
	 * What an AJAX add answers: the JSON sent, or null when it was left alone.
	 *
	 * @param Express_Checkout $express Service under test.
	 * @return mixed
	 */
	private function ajax_add( Express_Checkout $express ): mixed {
		try {
			$express->send_ajax_add_to_checkout();
		} catch ( WP_Halt $e ) {
			unset( $e );
		}

		return WP_Stub::$json_sent;
	}

	/**
	 * The cart object of the current run.
	 *
	 * @return WC_Cart
	 */
	private function cart(): WC_Cart {
		return WP_Stub::$wc->cart;
	}

	/**
	 * Nothing is hooked while both switches are off.
	 *
	 * @return void
	 */
	public function test_nothing_is_hooked_with_both_switches_off(): void {
		$this->service( false, false );

		$this->assertSame( array(), WP_Stub::$callbacks );
	}

	/**
	 * Either switch hooks the two paths, the AJAX one last.
	 *
	 * @return void
	 */
	public function test_either_switch_hooks_both_paths(): void {
		foreach ( array( array( true, false ), array( false, true ), array( true, true ) ) as $pair ) {
			WP_Stub::$callbacks = array();

			$this->service( ...$pair );

			$this->assertSame(
				array( 'woocommerce_add_to_cart_redirect:redirect_to_checkout', 'woocommerce_ajax_added_to_cart:send_ajax_add_to_checkout' ),
				WP_Stub::$callbacks
			);
			$this->assertSame( PHP_INT_MAX, WP_Stub::$priorities['woocommerce_ajax_added_to_cart:send_ajax_add_to_checkout'], 'Other listeners to a successful add run first.' );
		}
	}

	/**
	 * Virtual products: a cart with nothing to ship goes to checkout, both ways.
	 *
	 * @return void
	 */
	public function test_a_cart_with_nothing_to_ship_goes_to_checkout(): void {
		$express = $this->service();

		$this->assertSame( self::CHECKOUT, $express->redirect_to_checkout( self::CART, new WC_Product( true ) ) );
		$this->assertSame(
			array( 'error' => true, 'product_url' => self::CHECKOUT ),
			$this->ajax_add( $express ),
			'Both WooCommerce and Porto go to product_url when error is set.'
		);
	}

	/**
	 * Virtual products: a cart holding anything physical keeps the cart page.
	 *
	 * Someone assembling an order of parts needs the cart page to keep adding
	 * to it. The decision reads the cart after the add, so a physical product
	 * added from a virtual product's page or its quick view stays here too.
	 *
	 * @return void
	 */
	public function test_a_cart_that_ships_keeps_the_cart_page(): void {
		$this->cart()->fill( true, false );
		$express = $this->service();

		$this->assertSame( self::CART, $express->redirect_to_checkout( self::CART, new WC_Product( false ) ) );
		$this->assertNull( $this->ajax_add( $express ), 'The AJAX add answers as usual.' );
	}

	/**
	 * Every product: any cart goes to checkout, both ways.
	 *
	 * @return void
	 */
	public function test_every_product_sends_any_cart_to_checkout(): void {
		$this->cart()->fill( true, false );
		$express = $this->service( false, true );

		$this->assertSame( self::CHECKOUT, $express->redirect_to_checkout( self::CART, new WC_Product( false ) ) );
		$this->assertSame( array( 'error' => true, 'product_url' => self::CHECKOUT ), $this->ajax_add( $express ) );
	}

	/**
	 * The cart link WooCommerce prints on every page keeps pointing at the cart.
	 *
	 * WooCommerce runs the same filter with no product to build it; that call
	 * is not an add, and "View cart" after adding something physical must
	 * still open the cart.
	 *
	 * @return void
	 */
	public function test_the_printed_cart_link_is_not_an_add(): void {
		$this->assertSame( self::CART, $this->service()->redirect_to_checkout( self::CART ) );
		$this->assertSame( self::CART, $this->service( false, true )->redirect_to_checkout( self::CART, null ) );
	}

	/**
	 * An empty cart is left alone.
	 *
	 * @return void
	 */
	public function test_an_empty_cart_is_left_alone(): void {
		$this->cart()->fill();
		$express = $this->service();

		$this->assertSame( self::CART, $express->redirect_to_checkout( self::CART, new WC_Product( true ) ) );
		$this->assertNull( $this->ajax_add( $express ) );
	}

	/**
	 * A cart that is not loaded, or no WooCommerce at all, does not fatal.
	 *
	 * WC()->cart is null before wp_loaded and on requests where WooCommerce
	 * built no session.
	 *
	 * @return void
	 */
	public function test_an_unloaded_cart_does_not_fatal(): void {
		$express = $this->service();

		WP_Stub::$wc->cart = null;
		$this->assertSame( self::CART, $express->redirect_to_checkout( self::CART, new WC_Product( true ) ) );
		$this->assertNull( $this->ajax_add( $express ) );

		WP_Stub::$wc = null;
		$this->assertSame( self::CART, $express->redirect_to_checkout( self::CART, new WC_Product( true ) ) );
		$this->assertNull( $this->ajax_add( $express ) );
	}

	/**
	 * A checkout page that does not exist leaves the journey untouched.
	 *
	 * @return void
	 */
	public function test_a_missing_checkout_page_leaves_the_journey_alone(): void {
		foreach ( array( '', false ) as $checkout ) {
			WP_Stub::$checkout_url = $checkout;
			WP_Stub::$json_sent    = null;
			$express               = $this->service( true, true );

			$this->assertSame( self::CART, $express->redirect_to_checkout( self::CART, new WC_Product( true ) ) );
			$this->assertNull( $this->ajax_add( $express ), 'Sending the shopper to an empty URL would strand them.' );
		}
	}

	/**
	 * Whatever URL arrives is what comes back when the redirect does not apply.
	 *
	 * Another plugin may have already filtered it, and this one must not
	 * discard that decision.
	 *
	 * @return void
	 */
	public function test_a_url_from_another_plugin_survives(): void {
		$this->cart()->fill( true, false );

		$this->assertSame(
			'https://shop.test/otra-cosa/',
			$this->service()->redirect_to_checkout( 'https://shop.test/otra-cosa/', new WC_Product( false ) )
		);
	}
}
