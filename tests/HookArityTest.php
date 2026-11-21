<?php
/**
 * Every hooked method can be called with the arguments WordPress will pass.
 *
 * add_action() passes as many arguments as it was told to accept. A method
 * that requires more is an ArgumentCountError on every request that fires
 * the hook, and the stubs, which call nothing, cannot catch it any other way.
 *
 * Its own process with WeLaunch Multi Inventory active (RCUS_TESTS_WELAUNCH):
 * the WeLaunch services hook nothing without it, so in the shared process
 * their callbacks never reached the check.
 *
 * Add every new service to the list below.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
final class HookArityTest extends TestCase {

	public static function setUpBeforeClass(): void {
		putenv( 'RCUS_TESTS_WELAUNCH=1' );
	}

	public static function tearDownAfterClass(): void {
		putenv( 'RCUS_TESTS_WELAUNCH' );
	}

	public function test_no_hooked_method_needs_more_arguments_than_it_gets(): void {
		WP_Stub::reset();

		foreach ( RhinnoIT\Customizer\Settings::option_ids() as $option ) {
			WP_Stub::$options[ $option ] = 'yes';
		}

		// A list holds ids, not 'yes': with nothing chosen the shipping rules
		// are inert and hook nothing to check.
		WP_Stub::$options['rcus_shipping_block_methods']      = array( '6' );
		WP_Stub::$options['rcus_shipping_block_communes']     = array( 'Paine' );
		WP_Stub::$options['rcus_shipping_exclusion_communes'] = array( 'Penco' );

		$services = array(
			new RhinnoIT\Customizer\Assets(),
			new RhinnoIT\Customizer\Frontend\Widget_Thumbnail(),
			new RhinnoIT\Customizer\Frontend\Widget_Sale_Price(),
			new RhinnoIT\Customizer\Frontend\Express_Checkout(),
			new RhinnoIT\Customizer\Frontend\Checkout_Focus(),
			new RhinnoIT\Customizer\Frontend\Commune_Select(),
			new RhinnoIT\Customizer\Frontend\Cart_Sale_Price(),
			new RhinnoIT\Customizer\Frontend\Coupon_Split(),
			new RhinnoIT\Customizer\Frontend\Shipping_Block(),
			new RhinnoIT\Customizer\Frontend\Shipping_Exclusion(),
			new RhinnoIT\Customizer\Frontend\Inventory_Links(),
			new RhinnoIT\Customizer\Frontend\Quick_View_Store(),
			new RhinnoIT\Customizer\Frontend\Size_Stores(),
			new RhinnoIT\Customizer\Frontend\Store_Choice(),
			new RhinnoIT\Customizer\Frontend\Pickup_Stores(),
			new RhinnoIT\Customizer\Frontend\Store_Stock_Hold(),
			new RhinnoIT\Customizer\Frontend\Unpaid_Orders(),
			new RhinnoIT\Customizer\Frontend\Fintoc_Session_Expiry(),
			new RhinnoIT\Customizer\Admin\Menu(),
			new RhinnoIT\Customizer\Admin\Plugin_Links(),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		$checked = 0;
		$classes = array();

		foreach ( WP_Stub::$registered as list( $hook, $class, $method, $accepted ) ) {
			if ( null === $class ) {
				continue;
			}

			$required = ( new ReflectionMethod( $class, $method ) )->getNumberOfRequiredParameters();

			$this->assertLessThanOrEqual( $accepted, $required, sprintf( '%s::%s needs %d arguments; %s passes %d.', $class, $method, $required, $hook, $accepted ) );
			++$checked;
			$classes[ $class ] = true;
		}

		foreach ( array( 'Shipping_Block', 'Shipping_Exclusion', 'Inventory_Links', 'Quick_View_Store', 'Size_Stores', 'Store_Choice', 'Pickup_Stores', 'Store_Stock_Hold' ) as $conditional ) {
			$this->assertArrayHasKey( 'RhinnoIT\\Customizer\\Frontend\\' . $conditional, $classes, sprintf( '%s hooked nothing, so its callbacks went unchecked.', $conditional ) );
		}

		// The listed services hook forty-three methods: Assets 1,
		// Widget_Thumbnail 2, Widget_Sale_Price 1, Express_Checkout 2,
		// Checkout_Focus 1, Commune_Select 1, Cart_Sale_Price 3, Coupon_Split 1,
		// Shipping_Block 4, Shipping_Exclusion 6, Inventory_Links 2,
		// Quick_View_Store 1, Size_Stores 2, Store_Choice 4, Pickup_Stores 1,
		// Store_Stock_Hold 4, Unpaid_Orders 1, Fintoc_Session_Expiry 1, Menu 3,
		// Settings_Page 1 through Menu, and Plugin_Links 1. The save hook both
		// shipping services add calls a static method, which takes nothing.
		// Fewer means the list above lost a service; raise it with every
		// service added, lower it with one removed.
		$this->assertGreaterThanOrEqual( 43, $checked, 'Most hooks went unchecked, so this case proves little.' );
	}
}
