<?php
/**
 * Booting a shop with WeLaunch Multi Inventory active.
 *
 * Its own process: RCUS_TESTS_WELAUNCH makes tests/bootstrap.php define
 * WeLaunch's bootstrap function, and Plugin::boot() is a singleton. In the
 * shared process the WeLaunch corrections hook nothing, so this is the case
 * that notices one of them dropped from Plugin::__construct(), and the one
 * that checks their callbacks can take what WordPress passes.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Inventory_Links;
use RhinnoIT\Customizer\Frontend\Quick_View_Store;
use RhinnoIT\Customizer\Frontend\Size_Stores;
use RhinnoIT\Customizer\Frontend\Store_Choice;
use RhinnoIT\Customizer\Plugin;

#[CoversClass( Plugin::class )]
final class BootWithWeLaunchTest extends TestCase {

	public static function setUpBeforeClass(): void {
		putenv( 'RCUS_TESTS_WELAUNCH=1' );
	}

	public static function tearDownAfterClass(): void {
		putenv( 'RCUS_TESTS_WELAUNCH' );
	}

	#[RunInSeparateProcess]
	public function test_both_welaunch_corrections_register_and_take_what_they_get(): void {
		WP_Stub::reset();
		WP_Stub::$options['rcus_inventory_links']      = 'yes';
		WP_Stub::$options['rcus_inventory_quick_view'] = 'yes';

		Plugin::boot();

		$this->assertContains( 'init:build_cacheable_pages_for_the_default_store', WP_Stub::$callbacks );
		$this->assertContains( 'wp_enqueue_scripts:keep_links_clean', WP_Stub::$callbacks );
		$this->assertContains( 'wp_enqueue_scripts:send_the_picked_store', WP_Stub::$callbacks );

		$checked = 0;

		foreach ( WP_Stub::$registered as list( $hook, $class, $method, $accepted ) ) {
			if ( null === $class || ! str_contains( (string) $class, 'Inventory_Links' ) && ! str_contains( (string) $class, 'Quick_View_Store' ) ) {
				continue;
			}

			$required = ( new ReflectionMethod( $class, $method ) )->getNumberOfRequiredParameters();

			$this->assertLessThanOrEqual( $accepted, $required, sprintf( '%s::%s needs %d arguments; %s passes %d.', $class, $method, $required, $hook, $accepted ) );
			++$checked;
		}

		$this->assertSame( 3, $checked, 'Every WeLaunch callback was checked.' );
	}

	/**
	 * Every WeLaunch correction is booted with its switch at its default.
	 *
	 * Matched on the class, because Size_Stores and Store_Choice both hook
	 * wp_enqueue_scripts:add_script. A merge that dropped one of these lines
	 * kept every other case green while the shop went back to an older
	 * behaviour.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_every_welaunch_correction_is_booted(): void {
		WP_Stub::reset();

		Plugin::boot();

		$classes = array_unique( array_filter( array_column( WP_Stub::$registered, 1 ) ) );

		foreach ( array( 'Inventory_Links', 'Quick_View_Store', 'Size_Stores', 'Store_Choice', 'Pickup_Stores', 'Store_Stock_Hold' ) as $service ) {
			$this->assertContains( 'RhinnoIT\\Customizer\\Frontend\\' . $service, $classes, $service . ' hooked nothing: dropped from Plugin::__construct(), or its switch no longer defaults on.' );
		}
	}

	/**
	 * The WeLaunch scripts register in the order they print.
	 *
	 * They all go on WeLaunch's own handle, where WordPress prints the scripts
	 * of one priority in the order they were added: Inventory_Links',
	 * Quick_View_Store's and Size_Stores' at 99 as Plugin::__construct()
	 * registers them, then Store_Choice's at 100, which ticks from the radios
	 * Size_Stores' script sets. Each class's own case pins its priority.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_the_welaunch_scripts_register_in_the_order_they_print(): void {
		WP_Stub::reset();

		Plugin::boot();

		$order = array();

		foreach ( WP_Stub::$registered as list( $hook, $class ) ) {
			if ( 'wp_enqueue_scripts' === $hook && in_array( $class, array( Inventory_Links::class, Quick_View_Store::class, Size_Stores::class, Store_Choice::class ), true ) ) {
				$order[] = $class;
			}
		}

		$this->assertSame( array( Inventory_Links::class, Quick_View_Store::class, Size_Stores::class, Store_Choice::class ), $order );
	}

	#[RunInSeparateProcess]
	public function test_the_multi_inventory_section_is_on_the_screen(): void {
		$ids = array_column( RhinnoIT\Customizer\Settings::available_fields(), 'id' );

		$this->assertContains( 'rcus_multi_inventory', $ids );
		$this->assertContains( 'rcus_store_stock_hold', $ids );
		$this->assertContains( 'rcus_inventory_links', $ids );
		$this->assertContains( 'rcus_inventory_quick_view', $ids );
		$this->assertContains( 'rcus_store_choice', $ids );
		$this->assertContains( 'rcus_store_choice_delivery', $ids );
		$this->assertContains( 'rcus_pickup_stores_in_stock', $ids );
		$this->assertContains( 'rcus_size_stores_at_once', $ids );
	}
}
