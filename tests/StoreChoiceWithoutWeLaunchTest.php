<?php
/**
 * The location choice on a shop without WeLaunch Multi Inventory.
 *
 * Shared process: the bootstrap defines WeLaunch's bootstrap function only
 * when RCUS_TESTS_WELAUNCH is set, which this class does not.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Store_Choice;

/**
 * Covers RhinnoIT\Customizer\Frontend\Store_Choice without WeLaunch.
 */
#[CoversClass( Store_Choice::class )]
final class StoreChoiceWithoutWeLaunchTest extends TestCase {

	/**
	 * Without WeLaunch there are no locations to pick, so nothing is hooked.
	 *
	 * @return void
	 */
	public function test_nothing_is_hooked_without_welaunch(): void {
		$this->assertFalse( function_exists( 'welaunch_woocommerce_multi_inventory_run' ), 'The shared process has no WeLaunch, or this case means nothing.' );

		WP_Stub::reset();
		WP_Stub::$options['rcus_store_choice'] = 'yes';
		( new Store_Choice() )->register();

		$this->assertSame( array(), WP_Stub::$hooks );
	}
}
