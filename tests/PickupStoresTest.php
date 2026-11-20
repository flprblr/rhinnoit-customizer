<?php
/**
 * Only the pickup stores that have the size stay in sight.
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
use RhinnoIT\Customizer\Frontend\Pickup_Stores;

/**
 * Covers RhinnoIT\Customizer\Frontend\Pickup_Stores.
 */
#[CoversClass( Pickup_Stores::class )]
#[RunTestsInSeparateProcesses]
final class PickupStoresTest extends TestCase {

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
	 * Starts every case from an empty request.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
	}

	/**
	 * Off it hooks nothing; on, the style goes after WeLaunch enqueues its own.
	 *
	 * @return void
	 */
	public function test_it_hooks_only_while_on(): void {
		WP_Stub::$options['rcus_pickup_stores_in_stock'] = 'no';
		( new Pickup_Stores() )->register();
		$this->assertSame( array(), WP_Stub::$callbacks );

		WP_Stub::$options['rcus_pickup_stores_in_stock'] = 'yes';
		( new Pickup_Stores() )->register();

		$this->assertSame( array( 'wp_enqueue_scripts:add_style' ), WP_Stub::$callbacks );
		$this->assertSame( 100, WP_Stub::$priorities['wp_enqueue_scripts:add_style'], 'WeLaunch enqueues its stylesheet at 10.' );
	}

	/**
	 * Stores the size has none of go, home delivery stays, and an empty pickup block goes whole.
	 *
	 * Measured on hoka on 2026-10-27 with these rules in the live product
	 * page: 9 US showed delivery, sold out, and Parque Arauco alone under
	 * "Retiro en Tienda"; 8 US delivery, Parque Arauco and Marina.
	 *
	 * @return void
	 */
	public function test_the_rules_keep_home_delivery_in_sight(): void {
		WP_Stub::$options['woocommerce_multi_inventory_options'] = array( 'defaultInventory' => '280' );

		( new Pickup_Stores() )->add_style();

		$css = WP_Stub::$inline_styles['woocommerce-multi-inventory-public'] ?? '';

		$this->assertStringContainsString( ':has(> .woocommerce-multi-inventory-inventories-radio input[type="radio"]:disabled){display:none!important}', $css, 'A store is hidden while WeLaunch disables it, so the list follows every size pick; !important, as hoka\'s custom CSS forces the rows to display:inline!important.' );
		$this->assertStringContainsString( ':not(.woocommerce-multi-inventory-inventories-row-inventory-280)', $css, 'WeLaunch\'s default location, home delivery, stays in sight sold out or not.' );
		$this->assertStringContainsString( ':not(.woocommerce-multi-inventory-inventories-delivery-container > *)', $css, 'So does whatever sits in the Click & Collect delivery block.' );
		$this->assertStringContainsString( '.woocommerce-multi-inventory-inventories-click-collect-container:not(:has(input[type="radio"]:enabled)){display:none!important}', $css, 'The pickup block, title included, goes when no store in it has the size.' );
		$this->assertStringNotContainsString( 'delivery-container:not(', $css, 'The delivery block is never hidden.' );
	}

	/**
	 * Without a default location every store the size has none of goes, and junk is no store id.
	 *
	 * @return void
	 */
	public function test_a_missing_default_location_keeps_no_row(): void {
		foreach ( array( null, array( 'defaultInventory' => '' ), array( 'defaultInventory' => '0' ), array( 'defaultInventory' => '28x' ), array( 'defaultInventory' => array( 280 ) ), 'broken' ) as $options ) {
			WP_Stub::reset();
			WP_Stub::$options['woocommerce_multi_inventory_options'] = $options;

			( new Pickup_Stores() )->add_style();

			$css = WP_Stub::$inline_styles['woocommerce-multi-inventory-public'] ?? '';

			$this->assertStringNotContainsString( '-row-inventory-', $css, var_export( $options, true ) );
			$this->assertStringContainsString( 'input[type="radio"]:disabled){display:none!important}', $css );
		}

		$this->assertSame( Pickup_Stores::css( 0 ), $css );
		$this->assertStringContainsString( '-row-inventory-430)', Pickup_Stores::css( 430 ) );
	}

	/**
	 * With Click & Collect, home delivery is its delivery store: a default location among the pickup stores hides like any other.
	 *
	 * @return void
	 */
	public function test_with_click_and_collect_home_delivery_is_its_delivery_store(): void {
		WP_Stub::$options['woocommerce_multi_inventory_options'] = array(
			'defaultInventory'   => '281',
			'clickCollectEnable' => '1',
			'deliveryInventory'  => '280',
		);

		( new Pickup_Stores() )->add_style();

		$css = WP_Stub::$inline_styles['woocommerce-multi-inventory-public'] ?? '';

		$this->assertStringContainsString( ':not(.woocommerce-multi-inventory-inventories-row-inventory-280)', $css, 'The store Store_Choice ticks as home delivery.' );
		$this->assertStringNotContainsString( '-row-inventory-281', $css, 'A branch, whatever WeLaunch\'s default location says.' );
	}
}
