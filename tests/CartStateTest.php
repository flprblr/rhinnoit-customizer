<?php
/**
 * The predicate both journey services depend on.
 *
 * Every shortened journey hangs off this answer, and the expensive mistake is
 * a false positive: sending someone past the checkout step where they choose
 * how the order reaches them. So the cases that must answer NO are the ones
 * worth writing down.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Cart_State;

#[CoversClass( Cart_State::class )]
final class CartStateTest extends TestCase {

	/**
	 * A cart of nothing but virtual products has nothing to ship.
	 *
	 * @return void
	 */
	public function test_an_all_virtual_cart_ships_nothing(): void {
		$cart = new WC_Cart();
		$cart->fill( true, true, true );

		$this->assertTrue( Cart_State::nothing_to_ship( $cart ) );
	}

	/**
	 * One physical line is enough to keep the normal journey.
	 *
	 * @return void
	 */
	public function test_a_single_physical_line_is_enough(): void {
		foreach ( array( array( false, true, true ), array( true, false, true ), array( true, true, false ) ) as $mix ) {
			$cart = new WC_Cart();
			$cart->fill( ...$mix );

			$this->assertFalse(
				Cart_State::nothing_to_ship( $cart ),
				'A mixed cart still has to choose a delivery method.'
			);
		}
	}

	/**
	 * An empty cart has no journey to shorten.
	 *
	 * @return void
	 */
	public function test_an_empty_cart_answers_no(): void {
		$this->assertFalse( Cart_State::nothing_to_ship( new WC_Cart() ) );
	}

	/**
	 * A line whose product could not be loaded counts as shippable.
	 *
	 * WooCommerce leaves the key out for a product that was deleted or is no
	 * longer readable. Guessing "virtual" there would skip a step the shopper
	 * needed; guessing "physical" only costs them the cart page.
	 *
	 * @return void
	 */
	public function test_an_unreadable_line_counts_as_shippable(): void {
		$cart = new WC_Cart();
		$cart->fill( true );

		foreach ( array( array(), array( 'data' => null ), array( 'data' => 'not a product' ), 'not even an array' ) as $line ) {
			$cart->items[] = $line;

			$this->assertFalse(
				Cart_State::nothing_to_ship( $cart ),
				'An unreadable line must never be assumed virtual.'
			);

			array_pop( $cart->items );
		}
	}

	/**
	 * The store's shipping configuration never enters into it.
	 *
	 * This is the whole reason the predicate exists rather than calling
	 * WC_Cart::needs_shipping(): that one answers false for every cart in a
	 * shop with no shipping method enabled, which would shorten the journey
	 * for physical orders too.
	 *
	 * @return void
	 */
	public function test_the_answer_comes_only_from_the_products(): void {
		$cart = new WC_Cart();
		$cart->fill( false );

		$this->assertFalse(
			Cart_State::nothing_to_ship( $cart ),
			'A physical cart ships, whatever the shop has configured.'
		);
	}
}
