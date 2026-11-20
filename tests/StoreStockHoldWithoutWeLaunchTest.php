<?php
/**
 * The store hold on a shop without WeLaunch Multi Inventory.
 *
 * Shared process: the bootstrap defines WeLaunch's bootstrap function only
 * when RCUS_TESTS_WELAUNCH is set, which this class does not.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Store_Stock_Hold;

/**
 * Covers RhinnoIT\Customizer\Frontend\Store_Stock_Hold without WeLaunch.
 */
#[CoversClass( Store_Stock_Hold::class )]
final class StoreStockHoldWithoutWeLaunchTest extends TestCase {

	/**
	 * Without WeLaunch there are no stores to hold, so nothing is hooked.
	 *
	 * @return void
	 */
	public function test_nothing_is_hooked_without_welaunch(): void {
		$this->assertFalse( function_exists( 'welaunch_woocommerce_multi_inventory_run' ), 'The shared process has no WeLaunch, or this case means nothing.' );

		WP_Stub::reset();
		WP_Stub::$options['rcus_store_stock_hold'] = 'yes';
		( new Store_Stock_Hold() )->register();

		$this->assertSame( array(), WP_Stub::$hooks );
	}
}
