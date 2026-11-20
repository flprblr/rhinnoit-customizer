<?php
/**
 * The pickup store list on a shop without WeLaunch Multi Inventory.
 *
 * Shared process: the bootstrap defines WeLaunch's bootstrap function only
 * when RCUS_TESTS_WELAUNCH is set, which this class does not.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Pickup_Stores;

/**
 * Covers RhinnoIT\Customizer\Frontend\Pickup_Stores without WeLaunch.
 */
#[CoversClass( Pickup_Stores::class )]
final class PickupStoresWithoutWeLaunchTest extends TestCase {

	/**
	 * Without WeLaunch there is no store list, so nothing is hooked.
	 *
	 * @return void
	 */
	public function test_nothing_is_hooked_without_welaunch(): void {
		$this->assertFalse( function_exists( 'welaunch_woocommerce_multi_inventory_run' ), 'The shared process has no WeLaunch, or this case means nothing.' );

		WP_Stub::reset();
		WP_Stub::$options['rcus_pickup_stores_in_stock'] = 'yes';
		( new Pickup_Stores() )->register();

		$this->assertSame( array(), WP_Stub::$hooks );
	}
}
