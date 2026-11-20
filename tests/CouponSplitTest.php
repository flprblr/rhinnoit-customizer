<?php
/**
 * A fixed cart coupon is split by price, through WooCommerce's own WC_Discounts.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Coupon_Split;
use RhinnoIT\Customizer\Settings;

/**
 * Covers RhinnoIT\Customizer\Frontend\Coupon_Split.
 *
 * Every case runs WooCommerce 11.2.2's WC_Discounts (tests/stubs/woocommerce-core/),
 * fed as WC_Cart_Totals feeds it, so the split without the switch is
 * WooCommerce's real one.
 */
#[CoversClass( Coupon_Split::class )]
final class CouponSplitTest extends TestCase {

	/**
	 * The filter WC_Discounts passes the items through.
	 */
	const string FILTER = 'woocommerce_coupon_get_items_to_apply';

	/**
	 * WooCommerce's legacy per-item filter.
	 */
	const string LEGACY = 'woocommerce_coupon_get_discount_amount';

	/**
	 * A clean slate.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
	}

	/**
	 * The cart that reached hoka's orders at 0 for the socks.
	 *
	 * @return array<string, array{0: float, 1: int, 2: int}> Item key => unit price, quantity, product id.
	 */
	private static function shoes_and_socks(): array {
		return array(
			'shoes' => array( 179990.0, 1, 41911 ),
			'socks' => array( 4990.0, 1, 28715 ),
		);
	}

	/**
	 * Applies the coupons as WC_Cart_Totals does, with the switch on or off.
	 *
	 * @param array<string, array{0: float, 1: int, 2: int}> $lines   Item key => unit price, quantity, product id.
	 * @param array<int, WC_Coupon>                          $coupons In the order WooCommerce applies them.
	 * @param bool                                           $split   Whether the switch is on.
	 * @param object|null                                    $totalled The cart or order being totalled; a cart by default.
	 * @return array<string, array<string, int|float>> Coupon code => item key => discount.
	 */
	private static function apply( array $lines, array $coupons, bool $split = true, ?object $totalled = null ): array {
		WP_Stub::$options['rcus_cart_coupon_split'] = $split ? 'yes' : 'no';
		WP_Stub::$callbacks                         = array();
		unset( WP_Stub::$live_filters[ self::FILTER ] );

		$correction = new Coupon_Split();
		$correction->register();

		if ( in_array( self::FILTER . ':split', WP_Stub::$callbacks, true ) ) {
			WP_Stub::$live_filters[ self::FILTER ] = array( $correction, 'split' );
		}

		$discounts = new WC_Discounts( $totalled ?? new WC_Cart() );
		$items     = array();

		foreach ( $lines as $key => list( $price, $quantity, $product_id ) ) {
			$item           = new stdClass();
			$item->key      = $key;
			$item->object   = array(
				'key'      => $key,
				'quantity' => $quantity,
			);
			$item->product  = new WC_Product( false, $product_id );
			$item->quantity = $quantity;
			$item->price    = wc_add_number_precision_deep( $price * $quantity );
			$items[ $key ]  = $item;
		}

		$discounts->set_items( $items );

		foreach ( $coupons as $coupon ) {
			$discounts->apply_coupon( $coupon, false );
		}

		return $discounts->get_discounts();
	}

	/**
	 * The coupon of the screenshot.
	 *
	 * @return array<int, WC_Coupon>
	 */
	private static function ten_thousand(): array {
		return array( new WC_Coupon( 'diez', 'fixed_cart', 10000.0 ) );
	}

	/**
	 * Without the switch the socks reach the order at 0: WooCommerce's own split, measured.
	 *
	 * @return void
	 */
	public function test_woocommerce_alone_takes_the_socks_to_zero(): void {
		$rows = self::apply( self::shoes_and_socks(), self::ten_thousand(), false );

		$this->assertSame(
			array(
				'shoes' => 5010,
				'socks' => 4990,
			),
			$rows['diez'],
			'WooCommerce 11.2 gives each unit 5,000, caps the socks at their 4,990 and hands the 10 left to the shoes.'
		);
	}

	/**
	 * With it each line takes its share of the price.
	 *
	 * @return void
	 */
	public function test_the_coupon_is_split_by_price(): void {
		$rows = self::apply( self::shoes_and_socks(), self::ten_thousand() );

		$this->assertSame(
			array(
				'shoes' => 9730,
				'socks' => 270,
			),
			$rows['diez'],
			'10,000 x 179,990 / 184,980 = 9,730.24 and 10,000 x 4,990 / 184,980 = 269.76: the peso the floors leave goes to the socks.'
		);
	}

	/**
	 * The split is the last word on the filter, and the switch off hooks nothing.
	 *
	 * @return void
	 */
	public function test_it_hooks_last_and_only_while_the_switch_is_on(): void {
		WP_Stub::$options['rcus_cart_coupon_split'] = 'no';
		( new Coupon_Split() )->register();
		$this->assertNotContains( self::FILTER, WP_Stub::$hooks );

		WP_Stub::$options['rcus_cart_coupon_split'] = 'yes';
		( new Coupon_Split() )->register();
		$this->assertSame( PHP_INT_MAX, WP_Stub::$priorities[ self::FILTER . ':split' ], 'Another plugin\'s choice of items comes first.' );
		$this->assertContains( 'rcus_cart_coupon_split', array_column( Settings::available_fields(), 'id' ), 'WooCommerce 11 passes the items, so the switch is offered.' );
	}

	/**
	 * A line weighs its price times its quantity.
	 *
	 * @return void
	 */
	public function test_a_line_weighs_its_quantity(): void {
		$lines          = self::shoes_and_socks();
		$lines['socks'] = array( 4990.0, 2, 28715 );

		$this->assertSame(
			array(
				'shoes' => 9475,
				'socks' => 525,
			),
			self::apply( $lines, self::ten_thousand() )['diez'],
			'Two pairs of socks weigh 9,980 of 189,970: 525.35, and the shoes\' 9,474.65 takes the peso left.'
		);
	}

	/**
	 * The lines add up to the coupon exactly.
	 *
	 * @return void
	 */
	public function test_the_cents_the_floors_leave_go_to_the_largest_remainders(): void {
		$lines = array(
			'a' => array( 1000.0, 1, 1 ),
			'b' => array( 1000.0, 1, 2 ),
			'c' => array( 1000.0, 1, 3 ),
		);

		$this->assertSame(
			array(
				'a' => 34,
				'b' => 33,
				'c' => 33,
			),
			self::apply( $lines, array( new WC_Coupon( 'cien', 'fixed_cart', 100.0 ) ) )['cien'],
			'Three equal remainders: the peso goes to the first line in WooCommerce\'s order.'
		);
	}

	/**
	 * A coupon worth more than the cart frees every line, as WooCommerce does.
	 *
	 * @return void
	 */
	public function test_a_coupon_worth_more_than_the_cart_frees_every_line(): void {
		$coupon = array( new WC_Coupon( 'todo', 'fixed_cart', 200000.0 ) );
		$rows   = self::apply( self::shoes_and_socks(), $coupon );

		$this->assertSame(
			array(
				'shoes' => 179990,
				'socks' => 4990,
			),
			$rows['todo']
		);
		$this->assertSame( self::apply( self::shoes_and_socks(), $coupon, false ), $rows );
	}

	/**
	 * A line an earlier coupon left little of takes what is left; the others share the rest by price.
	 *
	 * @return void
	 */
	public function test_a_line_with_little_left_passes_the_rest_to_the_others(): void {
		$rows = self::apply( self::three_lines(), self::nearly_free_c_then_sixteen_thousand() );

		$this->assertSame(
			array(
				'a' => 10600,
				'b' => 5300,
				'c' => 100,
			),
			$rows['fijo'],
			'c\'s share of 1,000 does not fit in the 100 left of it; a and b split the other 15,900 two to one.'
		);
	}

	/**
	 * With "Calculate coupon discounts sequentially" a line weighs what earlier coupons left of it.
	 *
	 * @return void
	 */
	public function test_sequential_discounts_weigh_what_is_left(): void {
		WP_Stub::$options['woocommerce_calc_discounts_sequentially'] = 'yes';

		$rows = self::apply( self::three_lines(), self::nearly_free_c_then_sixteen_thousand() );

		$this->assertSame(
			array(
				'a' => 10659,
				'b' => 5330,
				'c' => 11,
			),
			$rows['fijo'],
			'Weights 100,000, 50,000 and 100: 10,659.56, 5,329.78 and 10.66, the two pesos left going to b and c.'
		);
	}

	/**
	 * Percent and fixed product coupons get WooCommerce's own split.
	 *
	 * @return void
	 */
	public function test_other_coupon_types_are_left_to_woocommerce(): void {
		foreach ( array( new WC_Coupon( 'pct', 'percent', 10.0 ), new WC_Coupon( 'unit', 'fixed_product', 1000.0 ) ) as $coupon ) {
			$this->assertSame( self::apply( self::shoes_and_socks(), array( $coupon ), false ), self::apply( self::shoes_and_socks(), array( $coupon ) ), $coupon->get_discount_type() );
		}

		$coupon = array( new WC_Coupon( 'cero', 'fixed_cart', 0.0 ) );
		$this->assertSame( self::apply( self::shoes_and_socks(), $coupon, false ), self::apply( self::shoes_and_socks(), $coupon ), 'WooCommerce sends a coupon with no amount through for its filters.' );
	}

	/**
	 * An order recalculated in the admin gets the cart's split.
	 *
	 * @return void
	 */
	public function test_an_order_gets_the_same_split(): void {
		$this->assertSame(
			array(
				'shoes' => 9730,
				'socks' => 270,
			),
			self::apply( self::shoes_and_socks(), self::ten_thousand(), true, new WC_Order() )['diez'],
			'WC_Order::recalculate_coupons() splits through the same WC_Discounts.'
		);
	}

	/**
	 * A plugin on the legacy filter that keeps the amounts lets the split stand.
	 *
	 * @return void
	 */
	public function test_a_plugin_that_keeps_the_amounts_lets_the_split_stand(): void {
		WP_Stub::$live_filters[ self::LEGACY ] = static fn ( $discount ) => $discount;

		$this->assertSame( 270, self::apply( self::shoes_and_socks(), self::ten_thousand() )['diez']['socks'] );
		$this->assertSame( array(), WP_Stub::$logs );
	}

	/**
	 * A plugin that changes the amounts in the cart keeps WooCommerce's split, its filter included.
	 *
	 * @return void
	 */
	public function test_a_plugin_that_changes_the_amounts_keeps_woocommerces_split(): void {
		// Keeps the socks out of fixed cart coupons, as a plugin can through WooCommerce's legacy filter.
		WP_Stub::$live_filters[ self::LEGACY ] = static fn ( $discount, $price, $cart_item ) => 'socks' === ( $cart_item['key'] ?? '' ) ? 0.0 : $discount;

		$woocommerce = self::apply( self::shoes_and_socks(), self::ten_thousand(), false );

		$this->assertSame( $woocommerce, self::apply( self::shoes_and_socks(), self::ten_thousand() ) );
		$this->assertSame( 10000.0, $woocommerce['diez']['shoes'], 'WooCommerce hands the excluded socks\' part to the shoes.' );
		$this->assertCount( 1, WP_Stub::$logs );
		$this->assertStringContainsString( 'stepped aside for coupon "diez": a plugin changes its amounts', WP_Stub::$logs[0] );
	}

	/**
	 * WooCommerce does not send an order's shares through the legacy filter, and neither does this.
	 *
	 * @return void
	 */
	public function test_an_order_is_split_whatever_the_legacy_filter_says(): void {
		WP_Stub::$live_filters[ self::LEGACY ] = static fn () => 0.0;

		$this->assertSame( 270, self::apply( self::shoes_and_socks(), self::ten_thousand(), true, new WC_Order() )['diez']['socks'] );
	}

	/**
	 * A two-decimal currency is split in cents.
	 *
	 * @return void
	 */
	public function test_a_two_decimal_currency_is_split_in_cents(): void {
		WP_Stub::$price_decimals = 2;

		$lines = array(
			'shoes' => array( 179.99, 1, 1 ),
			'socks' => array( 4.99, 1, 2 ),
		);

		$this->assertSame(
			array(
				'shoes' => 9.73,
				'socks' => 0.27,
			),
			self::apply( $lines, array( new WC_Coupon( 'ten', 'fixed_cart', 10.0 ) ) )['ten']
		);
	}

	/**
	 * Anything WooCommerce would not have passed is returned as it came.
	 *
	 * @return void
	 */
	public function test_what_woocommerce_would_not_pass_is_left_alone(): void {
		$correction = new Coupon_Split();
		$discounts  = new WC_Discounts( new WC_Cart() );
		$coupon     = self::ten_thousand()[0];
		$item       = new stdClass();
		$item->key  = 'shoes';

		$this->assertSame( 'items', $correction->split( 'items', $coupon, $discounts ) );
		$this->assertSame( array(), $correction->split( array(), $coupon, $discounts ) );
		$this->assertSame( array( array( 'key' => 'shoes' ) ), $correction->split( array( array( 'key' => 'shoes' ) ), $coupon, $discounts ), 'An array, not the stdClass WooCommerce builds.' );
		$this->assertSame( array( $item ), $correction->split( array( $item ), $coupon, $discounts ), 'No price.' );
		$this->assertSame( array( $item ), $correction->split( array( $item ), 'diez', $discounts ), 'No coupon.' );
		$this->assertSame( array( $item ), $correction->split( array( $item ), $coupon, null ), 'No WC_Discounts.' );
		$this->assertSame( array(), WP_Stub::$logs );
	}

	/**
	 * Called outside WC_Discounts::apply_coupon(), with no row started, it leaves the coupon to WooCommerce.
	 *
	 * @return void
	 */
	public function test_without_the_coupons_row_it_steps_aside(): void {
		$discounts   = new WC_Discounts( new WC_Cart() );
		$item        = new stdClass();
		$item->key   = 'shoes';
		$item->price = 179990.0;
		$discounts->set_items( array( 'shoes' => $item ) );

		$this->assertSame( array( $item ), ( new Coupon_Split() )->split( array( $item ), self::ten_thousand()[0], $discounts ) );
		$this->assertSame( array(), $discounts->get_discounts() );
		$this->assertCount( 1, WP_Stub::$logs );
		$this->assertStringContainsString( 'had not started its row', WP_Stub::$logs[0] );
	}

	/**
	 * Three lines, the cheapest of which an earlier coupon can nearly free.
	 *
	 * @return array<string, array{0: float, 1: int, 2: int}>
	 */
	private static function three_lines(): array {
		return array(
			'a' => array( 100000.0, 1, 1 ),
			'b' => array( 50000.0, 1, 2 ),
			'c' => array( 10000.0, 1, 3 ),
		);
	}

	/**
	 * 99% off c alone, which leaves 100 of it, then 16,000 off the cart.
	 *
	 * @return array<int, WC_Coupon>
	 */
	private static function nearly_free_c_then_sixteen_thousand(): array {
		return array(
			new WC_Coupon( 'casi', 'percent', 99.0, array( 3 ) ),
			new WC_Coupon( 'fijo', 'fixed_cart', 16000.0 ),
		);
	}
}
