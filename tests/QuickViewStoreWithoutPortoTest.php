<?php
/**
 * Sending the store picked in Porto's quick view, on a WeLaunch shop without Porto.
 *
 * Its own process: the bootstrap defines WeLaunch's bootstrap function with
 * RCUS_TESTS_WELAUNCH=1 and skips the Porto stubs with RCUS_TESTS_PORTO=none;
 * the child inherits the environment.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Quick_View_Store;

/**
 * Covers RhinnoIT\Customizer\Frontend\Quick_View_Store without Porto.
 */
#[CoversClass( Quick_View_Store::class )]
#[RunTestsInSeparateProcesses]
final class QuickViewStoreWithoutPortoTest extends TestCase {

	/**
	 * Boots a WeLaunch shop on another theme.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		putenv( 'RCUS_TESTS_WELAUNCH=1' );
		putenv( 'RCUS_TESTS_PORTO=none' );
	}

	/**
	 * Leaves the environment as the rest of the suite expects it.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		putenv( 'RCUS_TESTS_WELAUNCH' );
		putenv( 'RCUS_TESTS_PORTO' );
	}

	/**
	 * Its switch is offered only on Porto, so off Porto it hooks nothing.
	 *
	 * @return void
	 */
	public function test_nothing_is_hooked_off_porto(): void {
		WP_Stub::reset();
		WP_Stub::$options['rcus_inventory_quick_view'] = 'yes';

		$this->assertTrue( function_exists( 'welaunch_woocommerce_multi_inventory_run' ), 'The child has to boot with WeLaunch or this case means nothing.' );
		$this->assertNotSame( 'porto', get_template() );

		( new Quick_View_Store() )->register();

		$this->assertSame( array(), WP_Stub::$callbacks, 'A theme whose add posts from a form would get its store rewritten, with no switch to stop it.' );
	}
}
