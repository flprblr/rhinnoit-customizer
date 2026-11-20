<?php
/**
 * Sending the store picked in Porto's quick view.
 *
 * Runs in its own process with RCUS_TESTS_WELAUNCH set, so the bootstrap
 * defines WeLaunch's bootstrap function as if the plugin were active; the
 * child inherits the environment.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Quick_View_Store;

/**
 * Covers RhinnoIT\Customizer\Frontend\Quick_View_Store.
 */
#[CoversClass( Quick_View_Store::class )]
#[RunTestsInSeparateProcesses]
final class QuickViewStoreTest extends TestCase {

	/**
	 * Boots a shop with WeLaunch active.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		putenv( 'RCUS_TESTS_WELAUNCH=1' );
	}

	/**
	 * Leaves the environment as the rest of the suite expects it.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		putenv( 'RCUS_TESTS_WELAUNCH' );
	}

	/**
	 * Hooked after WeLaunch enqueues its script, and only while switched on.
	 *
	 * @return void
	 */
	public function test_it_hooks_only_while_on(): void {
		WP_Stub::reset();
		WP_Stub::$options['rcus_inventory_quick_view'] = 'no';
		( new Quick_View_Store() )->register();
		$this->assertSame( array(), WP_Stub::$callbacks, 'Off, it hooks nothing.' );

		WP_Stub::$options['rcus_inventory_quick_view'] = 'yes';
		( new Quick_View_Store() )->register();
		$this->assertSame( array( 'wp_enqueue_scripts:send_the_picked_store' ), WP_Stub::$callbacks );
		$this->assertSame( 99, WP_Stub::$priorities['wp_enqueue_scripts:send_the_picked_store'], 'WeLaunch enqueues at 10.' );
	}

	/**
	 * The store ticked in the add's own form joins the request Porto is about to post.
	 *
	 * Measured live: a quick view add with Parque Arauco ticked posted
	 * action=porto_add_to_cart&product_id=…&quantity=1&attribute_pa_talla-mujer=…
	 * and the line went in under the online store.
	 *
	 * @return void
	 */
	public function test_the_ticked_store_joins_portos_request(): void {
		WP_Stub::reset();
		( new Quick_View_Store() )->send_the_picked_store();

		$this->assertSame( array( array( 'woocommerce-multi-inventory-public', 'after' ) ), WP_Stub::$inline_at );

		$script = WP_Stub::$inline[0];

		$this->assertStringStartsWith( 'window.jQuery&&', $script );
		$this->assertStringContainsString( 'on("adding_to_cart",function(e,b,d)', $script, 'Porto hands over the object it is about to post.' );
		$this->assertStringContainsString( 'closest("form")', $script, 'Only the store of the form that is adding.' );
		$this->assertStringContainsString( ':checked:enabled', $script, 'A disabled store is not sent by a form either.' );
		$this->assertStringContainsString( '!d.woocommerce_multi_inventory_inventory', $script, 'A store already in the request stays.' );
		$this->assertStringContainsString( 'catch(x){}', $script, 'A failure never stops the add.' );
	}
}
