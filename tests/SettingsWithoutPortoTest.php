<?php
/**
 * The settings screen on a WooCommerce site whose theme is not Porto.
 *
 * Runs in its own process: the bootstrap loads the Porto stubs unless
 * RCUS_TESTS_PORTO is 'none', and defines WeLaunch's bootstrap function with
 * RCUS_TESTS_WELAUNCH=1; the child inherits the environment.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Settings;

/**
 * Covers Settings::available_fields() without Porto.
 */
#[RunTestsInSeparateProcesses]
final class SettingsWithoutPortoTest extends TestCase {

	/**
	 * Boots a site without Porto.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		putenv( 'RCUS_TESTS_PORTO=none' );
		putenv( 'RCUS_TESTS_WELAUNCH=1' );
	}

	/**
	 * Leaves the environment as the rest of the suite expects it.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		putenv( 'RCUS_TESTS_PORTO' );
		putenv( 'RCUS_TESTS_WELAUNCH' );
	}

	/**
	 * Porto's card is not drawn, and WooCommerce's still is.
	 *
	 * @return void
	 */
	public function test_the_porto_section_is_not_on_the_screen(): void {
		$this->assertNotSame( 'porto', get_template(), 'The process has no Porto, or this case means nothing.' );

		$ids = array_column( Settings::available_fields(), 'id' );

		$this->assertNotContains( 'rcus_porto', $ids );
		$this->assertNotContains( 'rcus_widget_thumb_srcset', $ids );
		$this->assertNotContains( 'rcus_widget_sale_price', $ids );
		$this->assertNotContains( 'rcus_birthdate_style', $ids );
		$this->assertContains( 'rcus_woocommerce', $ids );
		$this->assertContains( 'rcus_cart_sale_price', $ids );
	}

	/**
	 * WeLaunch's card is drawn without the switch for Porto's quick view.
	 *
	 * @return void
	 */
	public function test_the_quick_view_switch_needs_porto(): void {
		$ids = array_column( Settings::available_fields(), 'id' );

		$this->assertContains( 'rcus_multi_inventory', $ids );
		$this->assertContains( 'rcus_inventory_links', $ids );
		$this->assertNotContains( 'rcus_inventory_quick_view', $ids, 'Without Porto there is no quick view to send the store from.' );
	}
}
