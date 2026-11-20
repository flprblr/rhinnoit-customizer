<?php
/**
 * Before WooCommerce 8.8 the coupon split is neither offered nor hooked.
 *
 * Its own process: WC_VERSION comes from RCUS_TESTS_WC, which the shared
 * process has already defined as a WooCommerce 11.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Coupon_Split;
use RhinnoIT\Customizer\Settings;

/**
 * Covers RhinnoIT\Customizer\Frontend\Coupon_Split on WooCommerce 8.7.
 */
#[CoversClass( Coupon_Split::class )]
final class CouponSplitBeforeItemsFilterTest extends TestCase {

	/**
	 * A WooCommerce above the plugin's minimum that never passes woocommerce_coupon_get_items_to_apply.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		putenv( 'RCUS_TESTS_WC=8.7.1' );
	}

	/**
	 * Back to the shared WooCommerce.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		putenv( 'RCUS_TESTS_WC' );
	}

	/**
	 * The switch would do nothing there, so it is hidden and hooks nothing.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_woocommerce_8_7_gets_neither_the_switch_nor_the_hook(): void {
		WP_Stub::reset();
		WP_Stub::$options['rcus_cart_coupon_split'] = 'yes';

		( new Coupon_Split() )->register();

		$ids = array_column( Settings::available_fields(), 'id' );

		$this->assertFalse( Coupon_Split::can_split() );
		$this->assertNotContains( 'woocommerce_coupon_get_items_to_apply', WP_Stub::$hooks );
		$this->assertContains( 'rcus_cart_sale_price', $ids, 'The WooCommerce section is still offered: 8.7 is above the minimum.' );
		$this->assertNotContains( 'rcus_cart_coupon_split', $ids );
	}
}
