<?php
/**
 * Sending the store picked in Porto's quick view, on a shop without WeLaunch.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Quick_View_Store;

/**
 * Covers RhinnoIT\Customizer\Frontend\Quick_View_Store without WeLaunch.
 */
#[CoversClass( Quick_View_Store::class )]
final class QuickViewStoreWithoutWeLaunchTest extends TestCase {

	/**
	 * Switched on, it hooks nothing where WeLaunch is not active.
	 *
	 * @return void
	 */
	public function test_nothing_is_hooked_without_welaunch(): void {
		WP_Stub::reset();
		WP_Stub::$options['rcus_inventory_quick_view'] = 'yes';

		$this->assertFalse( function_exists( 'welaunch_woocommerce_multi_inventory_run' ), 'The bootstrap loaded WeLaunch.' );

		( new Quick_View_Store() )->register();

		$this->assertSame( array(), WP_Stub::$callbacks );
	}
}
