<?php
/**
 * Removing the prompts above the checkout form.
 *
 * The removal is global once it fires, so the cases where it must NOT fire are
 * what this covers: any other page of the site, and any cart that ships.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Checkout_Focus;

/**
 * Covers RhinnoIT\Customizer\Frontend\Checkout_Focus.
 */
final class CheckoutFocusTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var Checkout_Focus
	 */
	private Checkout_Focus $focus;

	/**
	 * Puts the request on a checkout holding virtual goods.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$options['rcus_checkout_coupon_prompt'] = 'yes';
		WP_Stub::$options['rcus_checkout_login_prompt']  = 'yes';

		$cart = new WC_Cart();
		$cart->fill( true );

		$wc       = new WooCommerce();
		$wc->cart = $cart;

		WP_Stub::$wc        = $wc;
		WP_Stub::$page_type = 'checkout';
		WP_Stub::$removed   = array();

		$this->focus = new Checkout_Focus();
		$this->focus->register();
	}

	/**
	 * Leaves the stubs as the rest of the suite expects them.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		WP_Stub::$wc        = null;
		WP_Stub::$page_type = '';
		WP_Stub::$removed   = array();
	}

	/**
	 * The cart of the current run.
	 *
	 * @return WC_Cart
	 */
	private function cart(): WC_Cart {
		return WP_Stub::$wc->cart;
	}

	/**
	 * Both prompts go on a checkout with nothing to ship.
	 *
	 * @return void
	 */
	public function test_both_prompts_go_on_a_virtual_checkout(): void {
		$this->focus->drop_the_prompts();

		$this->assertContains( 'woocommerce_before_checkout_form:woocommerce_checkout_coupon_form', WP_Stub::$removed );
		$this->assertContains( 'woocommerce_before_checkout_form:woocommerce_checkout_login_form', WP_Stub::$removed );
		$this->assertSame( 10, WP_Stub::$removed_priorities['woocommerce_before_checkout_form:woocommerce_checkout_coupon_form'], 'Where wc-template-hooks.php adds it.' );
		$this->assertSame( 10, WP_Stub::$removed_priorities['woocommerce_before_checkout_form:woocommerce_checkout_login_form'] );
	}

	/**
	 * Each prompt answers to its own switch.
	 *
	 * @return void
	 */
	public function test_each_prompt_has_its_own_switch(): void {
		foreach ( array( 'coupon' => 'login', 'login' => 'coupon' ) as $on => $off ) {
			WP_Stub::$options[ 'rcus_checkout_' . $on . '_prompt' ]  = 'yes';
			WP_Stub::$options[ 'rcus_checkout_' . $off . '_prompt' ] = 'no';
			WP_Stub::$removed = array();

			$focus = new Checkout_Focus();
			$focus->register();
			$focus->drop_the_prompts();

			$this->assertSame( array( 'woocommerce_before_checkout_form:woocommerce_checkout_' . $on . '_form' ), WP_Stub::$removed );
		}
	}

	/**
	 * Either switch alone is enough to hook the removal.
	 *
	 * @return void
	 */
	public function test_either_switch_hooks_the_removal(): void {
		foreach ( array( array( 'yes', 'no' ), array( 'no', 'yes' ), array( 'yes', 'yes' ) ) as $pair ) {
			WP_Stub::$options['rcus_checkout_coupon_prompt'] = $pair[0];
			WP_Stub::$options['rcus_checkout_login_prompt']  = $pair[1];
			WP_Stub::$callbacks = array();

			( new Checkout_Focus() )->register();

			$this->assertSame( array( 'template_redirect:drop_the_prompts' ), WP_Stub::$callbacks, implode( '/', $pair ) );
		}
	}

	/**
	 * With both switches off nothing is hooked at all.
	 *
	 * template_redirect fires on every request, so a callback that would do
	 * nothing is not worth running there.
	 *
	 * @return void
	 */
	public function test_nothing_is_hooked_with_both_switches_off(): void {
		WP_Stub::$options['rcus_checkout_coupon_prompt'] = 'no';
		WP_Stub::$options['rcus_checkout_login_prompt']  = 'no';
		WP_Stub::$callbacks = array();

		( new Checkout_Focus() )->register();

		$this->assertSame( array(), WP_Stub::$callbacks );
	}

	/**
	 * A checkout that ships keeps both prompts.
	 *
	 * A returning customer buying parts has an account worth signing into and
	 * may have been given a coupon.
	 *
	 * @return void
	 */
	public function test_a_checkout_that_ships_keeps_them(): void {
		$this->cart()->fill( true, false );

		$this->focus->drop_the_prompts();

		$this->assertSame( array(), WP_Stub::$removed );
	}

	/**
	 * Nothing is removed anywhere else on the site.
	 *
	 * `template_redirect` fires on every front-end request, so this is the
	 * guard that keeps the rest of the storefront untouched.
	 *
	 * @return void
	 */
	public function test_nothing_is_removed_off_the_checkout(): void {
		foreach ( array( '', 'product', 'cart', 'shop' ) as $page ) {
			WP_Stub::$page_type = $page;
			WP_Stub::$removed   = array();

			$this->focus->drop_the_prompts();

			$this->assertSame( array(), WP_Stub::$removed, sprintf( 'Touched the hooks on "%s".', $page ) );
		}
	}

	/**
	 * An empty cart is left alone.
	 *
	 * @return void
	 */
	public function test_an_empty_cart_is_left_alone(): void {
		$this->cart()->fill();

		$this->focus->drop_the_prompts();

		$this->assertSame( array(), WP_Stub::$removed );
	}

	/**
	 * A cart that is not loaded yet does not fatal.
	 *
	 * @return void
	 */
	public function test_an_unloaded_cart_does_not_fatal(): void {
		WP_Stub::$wc->cart = null;

		$this->focus->drop_the_prompts();

		$this->assertSame( array(), WP_Stub::$removed );
	}

	/**
	 * WooCommerce missing does not fatal.
	 *
	 * @return void
	 */
	public function test_a_missing_woocommerce_does_not_fatal(): void {
		WP_Stub::$wc = null;

		$this->focus->drop_the_prompts();

		$this->assertSame( array(), WP_Stub::$removed );
	}
}
