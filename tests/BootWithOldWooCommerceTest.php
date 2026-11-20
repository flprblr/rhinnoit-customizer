<?php
/**
 * The support gate on a shop whose WooCommerce is below the minimum.
 *
 * Separate from the missing-WooCommerce case so that dropping the version half
 * of the check and keeping the defined() half cannot pass.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Admin\Menu;
use RhinnoIT\Customizer\Plugin;
use RhinnoIT\Customizer\Settings;

#[CoversClass( Plugin::class )]
final class BootWithOldWooCommerceTest extends TestCase {

	public static function setUpBeforeClass(): void {
		putenv( 'RCUS_TESTS_WC=7.9' );
		// WeLaunch defines its bootstrap function whether or not WooCommerce runs.
		putenv( 'RCUS_TESTS_WELAUNCH=1' );
	}

	public static function tearDownAfterClass(): void {
		putenv( 'RCUS_TESTS_WC' );
		putenv( 'RCUS_TESTS_WELAUNCH' );
	}

	#[RunInSeparateProcess]
	public function test_a_woocommerce_below_the_minimum_gets_the_wordpress_half_and_a_notice(): void {
		$this->assertSame( '7.9', WC_VERSION );

		WP_Stub::reset();
		Plugin::boot();

		$this->assertContains( 'admin_notices', WP_Stub::$hooks, 'The shop is told why half is missing.' );
		$this->assertContains( 'admin_enqueue_scripts:enqueue_admin', WP_Stub::$callbacks, 'And still gets the half that does not need WooCommerce.' );
		$this->assertNotContains( 'woocommerce_cart_item_price', WP_Stub::$hooks, 'The WooCommerce half stays off on a version it does not support.' );
		$this->assertNotContains( 'init:build_cacheable_pages_for_the_default_store', WP_Stub::$callbacks, 'WeLaunch\'s services too, with WeLaunch active.' );

		$ids = array_column( Settings::available_fields(), 'id' );

		foreach ( array( 'rcus_woocommerce', 'rcus_cart_sale_price', 'rcus_porto', 'rcus_multi_inventory' ) as $id ) {
			$this->assertNotContains( $id, $ids, 'No switch for a half that never registers.' );
		}

		// Supported decides what the plugin does with WooCommerce; active
		// decides only what exists. An old version is the one place they differ.
		$this->assertSame( 'manage_woocommerce', Menu::capability(), 'Shop managers keep the page: WooCommerce is there, just old.' );
	}
}
