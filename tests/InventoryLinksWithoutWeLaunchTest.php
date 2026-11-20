<?php
/**
 * Keeping WeLaunch's store out of links and cached pages, on a shop without WeLaunch.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Inventory_Links;

/**
 * Covers RhinnoIT\Customizer\Frontend\Inventory_Links without WeLaunch.
 */
#[CoversClass( Inventory_Links::class )]
final class InventoryLinksWithoutWeLaunchTest extends TestCase {

	/**
	 * Switched on, it hooks nothing where WeLaunch is not active.
	 *
	 * @return void
	 */
	public function test_nothing_is_hooked_without_welaunch(): void {
		WP_Stub::reset();
		WP_Stub::$options['rcus_inventory_links'] = 'yes';

		$this->assertFalse( function_exists( 'welaunch_woocommerce_multi_inventory_run' ), 'The bootstrap loaded WeLaunch.' );

		( new Inventory_Links() )->register();

		$this->assertSame( array(), WP_Stub::$callbacks );
	}
}
