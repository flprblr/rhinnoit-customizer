<?php
/**
 * A sale item's regular price, struck through, in the cart.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Cart_Sale_Price;

/**
 * Covers RhinnoIT\Customizer\Frontend\Cart_Sale_Price.
 */
#[CoversClass( Cart_Sale_Price::class )]
final class CartSalePriceTest extends TestCase {

	/**
	 * Starts from a cart showing prices with tax, as hoka's does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$wc       = new WooCommerce();
		WP_Stub::$wc->cart = new WC_Cart();
	}

	/**
	 * The cart line of a Clifton 10 at $74.990, regular $149.990, as on hoka's Cyber.
	 *
	 * @param int    $quantity Units.
	 * @param string $regular  Regular price.
	 * @param string $price    Active price.
	 * @return array<string, mixed>
	 */
	private static function line( int $quantity = 1, string $regular = '149990', string $price = '74990' ): array {
		$product                = new WC_Product();
		$product->regular_price = $regular;
		$product->price         = $price;

		return array(
			'data'     => $product,
			'quantity' => $quantity,
		);
	}

	/**
	 * It ships on, by the owner's decision, and its switch and the master switch turn it off.
	 *
	 * @return void
	 */
	public function test_it_ships_on_and_the_switches_turn_it_off(): void {
		( new Cart_Sale_Price() )->register();

		$this->assertSame( array( 'woocommerce_cart_item_price', 'woocommerce_cart_item_subtotal', 'wp_enqueue_scripts' ), WP_Stub::$hooks, 'On with no stored value.' );

		WP_Stub::reset();
		WP_Stub::$options['rcus_cart_sale_price'] = 'no';
		( new Cart_Sale_Price() )->register();

		$this->assertSame( array(), WP_Stub::$hooks );

		WP_Stub::reset();
		WP_Stub::$options['rcus_cart_sale_price'] = 'yes';
		WP_Stub::$options['rcus_enabled']         = 'no';
		( new Cart_Sale_Price() )->register();

		$this->assertSame( array(), WP_Stub::$hooks, 'The master switch turns it off too.' );
	}

	/**
	 * On, it hooks both filters Porto's templates print the line through, before its catalog mode.
	 *
	 * @return void
	 */
	public function test_the_switch_hooks_both_price_filters_before_portos_catalog_mode(): void {
		WP_Stub::$options['rcus_cart_sale_price'] = 'yes';

		( new Cart_Sale_Price() )->register();

		$this->assertSame( array( 'woocommerce_cart_item_price:strike_unit_price', 'woocommerce_cart_item_subtotal:strike_line_subtotal', 'wp_enqueue_scripts:add_style' ), WP_Stub::$callbacks );
		$this->assertSame( 20, WP_Stub::$priorities['woocommerce_cart_item_price:strike_unit_price'], 'Porto empties prices at 100.' );
		$this->assertSame( 20, WP_Stub::$priorities['woocommerce_cart_item_subtotal:strike_line_subtotal'] );
	}

	/**
	 * The unit price of an item on sale gets the regular price struck through before it.
	 *
	 * The price WooCommerce built stays inside the <ins>, as it was.
	 *
	 * @return void
	 */
	public function test_a_sale_item_shows_its_regular_price_struck_through(): void {
		$price = wc_price( 74990 );
		$html  = ( new Cart_Sale_Price() )->strike_unit_price( $price, self::line() );

		$this->assertStringStartsWith( '<span class="rwpo-sale-price"><del aria-hidden="true">' . wc_price( 149990 ) . '</del> ', $html );
		$this->assertStringEndsWith( '</span></span>', $html, 'The wrapper closes after WooCommerce\'s own markup.' );
		$this->assertStringContainsString( '<ins aria-hidden="true">' . $price . '</ins>', $html );
		$this->assertMatchesRegularExpression( '/<span class="screen-reader-text">Original price was: \S*149\.990\.<\/span>/', $html, 'Screen readers hear the regular price.' );
	}

	/**
	 * The line subtotal strikes the regular price for the line's quantity.
	 *
	 * @return void
	 */
	public function test_the_subtotal_strikes_the_regular_price_for_the_quantity(): void {
		$subtotal = wc_price( 149980 );
		$html     = ( new Cart_Sale_Price() )->strike_line_subtotal( $subtotal, self::line( 2 ) );

		$this->assertStringStartsWith( '<span class="rwpo-sale-price"><del aria-hidden="true">' . wc_price( 299980 ) . '</del> ', $html );
		$this->assertStringContainsString( '<ins aria-hidden="true">' . $subtotal . '</ins>', $html );
	}

	/**
	 * A cart showing prices without tax compares both prices without tax.
	 *
	 * @return void
	 */
	public function test_the_regular_price_follows_the_carts_tax_display(): void {
		WP_Stub::$tax_rate              = 1.19;
		WP_Stub::$wc->cart->incl_tax = false;

		$html = ( new Cart_Sale_Price() )->strike_unit_price( wc_price( 63017 ), self::line( 1, '126042', '63017' ) );

		$this->assertStringStartsWith( '<span class="rwpo-sale-price"><del aria-hidden="true">' . wc_price( 126042 ) . '</del> ', $html );

		WP_Stub::$wc->cart->incl_tax = true;
		$html                        = ( new Cart_Sale_Price() )->strike_unit_price( wc_price( 74990 ), self::line( 1, '126042', '63017' ) );

		$this->assertStringStartsWith( '<span class="rwpo-sale-price"><del aria-hidden="true">' . wc_price( 149990 ) . '</del> ', $html, 'With tax, the regular price is shown with tax too.' );
	}

	/**
	 * The CSS goes in as an inline style with no file, scoped to the wrapper.
	 *
	 * The look itself was measured on hoka's live CSS: inside the mini cart,
	 * the cart and the checkout the struck price is #a7a7a7 at 0.8em and the
	 * price takes the shop's ins color, as in the listing.
	 *
	 * @return void
	 */
	public function test_the_style_is_inline_and_scoped_to_the_wrapper(): void {
		( new Cart_Sale_Price() )->add_style();

		$this->assertSame( array( 'register:rcus-cart-sale-price:false', 'enqueue:rcus-cart-sale-price' ), WP_Stub::$styles, 'No file to request.' );

		$css = WP_Stub::$inline_styles['rcus-cart-sale-price'];

		foreach ( preg_split( '/}/', $css, -1, PREG_SPLIT_NO_EMPTY ) as $rule ) {
			$this->assertMatchesRegularExpression( '/^(:where\()?\.rwpo-sale-price /', $rule, 'Nothing outside the wrapper is restyled.' );
		}

		$this->assertStringContainsString( 'color:#a7a7a7;font-size:.8em', $css );
		$this->assertStringNotContainsString( '#dc3545', strtolower( $css ), 'The red is the shop\'s own ins rule.' );
	}

	/**
	 * Lines that are not on sale, or that something else already handled, are left as they came.
	 *
	 * @return void
	 */
	public function test_lines_without_a_lower_price_are_left_alone(): void {
		$service = new Cart_Sale_Price();
		$price   = wc_price( 149990 );

		$this->assertSame( $price, $service->strike_unit_price( $price, self::line( 1, '149990', '149990' ) ), 'Not on sale.' );
		$this->assertSame( $price, $service->strike_unit_price( $price, self::line( 1, '', '149990' ) ), 'No regular price.' );
		$this->assertSame( $price, $service->strike_unit_price( $price, self::line( 1, '99990', '149990' ) ), 'Dearer than the regular price.' );

		$struck = wc_format_sale_price( 149990, 74990 );
		$this->assertSame( $struck, $service->strike_unit_price( $struck, self::line() ), 'Another filter already struck it through.' );
		$this->assertSame( $price, $service->strike_line_subtotal( $price, self::line( 0 ) ), 'No quantity.' );
	}

	/**
	 * Whatever another filter or a theme hands over passes through, without an error.
	 *
	 * @return void
	 */
	public function test_values_it_cannot_read_pass_through(): void {
		$service = new Cart_Sale_Price();
		$price   = wc_price( 74990 );

		$this->assertSame( '', $service->strike_unit_price( '', self::line() ), 'Porto\'s catalog mode empties it.' );
		$this->assertNull( $service->strike_unit_price( null, self::line() ) );
		$this->assertSame( $price, $service->strike_unit_price( $price ), 'A filter applied with one argument.' );
		$this->assertSame( $price, $service->strike_unit_price( $price, array( 'quantity' => 1 ) ), 'A line without its product.' );
		$this->assertSame( $price, $service->strike_line_subtotal( $price, array( 'data' => self::line()['data'], 'quantity' => 'x' ) ) );

		WP_Stub::$wc = null;
		$this->assertSame( $price, $service->strike_unit_price( $price, self::line() ), 'No cart to read the tax display from.' );
	}
}
