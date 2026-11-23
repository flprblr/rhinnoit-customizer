<?php
/**
 * A plain WordPress site, with no WooCommerce at all.
 *
 * Its own process: tests/bootstrap.php takes WC_VERSION from RCUS_TESTS_WC, and
 * the child inherits the environment set before it is spawned. The child loads
 * none of the WooCommerce stubs either, so anything here that calls into
 * WooCommerce is a fatal, exactly as it would be on such a site.
 *
 * The plugin runs its WordPress half here: the services that need no
 * WooCommerce register, the admin page exists and is reachable, and nothing
 * that calls into WooCommerce is hooked or read.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Admin\Menu;
use RhinnoIT\Customizer\Admin\Settings_Page;
use RhinnoIT\Customizer\Install;
use RhinnoIT\Customizer\Plugin;
use RhinnoIT\Customizer\Settings;

#[CoversClass( Plugin::class )]
#[CoversClass( Install::class )]
final class BootWithoutWooCommerceTest extends TestCase {

	public static function setUpBeforeClass(): void {
		putenv( 'RCUS_TESTS_WC=none' );
	}

	public static function tearDownAfterClass(): void {
		putenv( 'RCUS_TESTS_WC' );
	}

	#[RunInSeparateProcess]
	public function test_a_site_without_woocommerce_runs_the_wordpress_half_only(): void {
		$this->assertFalse( defined( 'WC_VERSION' ), 'The child has to boot without WooCommerce or this case means nothing.' );
		$this->assertFalse( function_exists( 'is_shop' ), 'The child has to lack the WooCommerce functions too.' );

		WP_Stub::reset();
		WP_Stub::$options['rcus_express_checkout'] = 'yes';
		WP_Stub::$options['rcus_unpaid_orders']    = 'yes';
		WP_Stub::$options['rcus_cart_sale_price']  = 'yes';
		Plugin::boot();

		$this->assertContains( 'admin_enqueue_scripts:enqueue_admin', WP_Stub::$callbacks, 'The WordPress half runs here.' );
		$this->assertContains( 'admin_menu', WP_Stub::$hooks, 'The page exists, or nobody could configure it.' );

		$this->assertNotContains( 'woocommerce_add_to_cart_redirect', WP_Stub::$hooks, 'Switched on, and still WooCommerce half only.' );
		$this->assertNotContains( 'woocommerce_cancel_unpaid_orders_interval_minutes', WP_Stub::$hooks, 'Switched on, and still WooCommerce half only.' );
		$this->assertNotContains( 'woocommerce_cart_item_price', WP_Stub::$hooks, 'Switched on, and still WooCommerce half only.' );
		$this->assertNotContains( 'admin_notices', WP_Stub::$hooks, 'No WooCommerce is a valid site, not an error to announce.' );
	}

	/**
	 * Activating and deactivating the plugin bump no shipping version where WooCommerce is missing, and break nothing.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_activation_and_deactivation_touch_no_shipping_without_woocommerce(): void {
		$this->assertFalse( class_exists( 'WC_Cache_Helper' ), 'The child has to lack WooCommerce\'s cache helper or this case means nothing.' );

		WP_Stub::reset();

		Install::activate();
		Install::deactivate();

		$this->assertArrayNotHasKey( 'shipping-transient-version', WP_Stub::$transients, 'No WooCommerce, no stored rates to refresh.' );
		$this->assertSame( RCUS_VERSION, WP_Stub::$options[ Install::VERSION_OPTION ], 'The activation still seeds the site.' );
	}

	#[RunInSeparateProcess]
	public function test_the_page_is_reachable_without_woocommerce(): void {
		// manage_woocommerce is a capability WooCommerce registers. On a site
		// without it nobody holds it, and the page would be invisible to all.
		$this->assertSame( 'manage_options', Menu::capability() );

		WP_Stub::reset();
		( new Menu() )->register_pages();

		$this->assertSame( array( 'manage_options' ), array_values( array_unique( array_column( WP_Stub::$menu, 'cap' ) ) ), 'The node and the entry both ask for it, or one of them stays hidden.' );
	}

	#[RunInSeparateProcess]
	public function test_the_woocommerce_section_is_not_on_the_screen(): void {
		$ids = array_column( Settings::available_fields(), 'id' );

		$this->assertContains( 'rcus_enabled', $ids );
		$this->assertNotContains( 'rcus_cart_sale_price', $ids );
		$this->assertNotContains( 'rcus_woocommerce', $ids, 'The section heading goes with its fields.' );
		$this->assertNotContains( 'rcus_porto', $ids, 'Porto\'s section acts on WooCommerce markup.' );
		$this->assertNotContains( 'rcus_widget_thumb_srcset', $ids );
		$this->assertNotContains( 'rcus_multi_inventory', $ids );
	}

	#[RunInSeparateProcess]
	public function test_a_crafted_post_cannot_write_the_woocommerce_options(): void {
		WP_Stub::reset();
		WP_Stub::$options['rcus_enabled']                = 'no';
		WP_Stub::$options['rcus_cart_sale_price']        = 'yes';
		WP_Stub::$options['rcus_checkout_coupon_prompt'] = 'no';

		// Somebody holding manage_options posts the WooCommerce fields, markers
		// and all, on a site whose screen never showed them.
		$_POST = array(
			'rcus_checkout_coupon_prompt' => 'yes',
			'rcus_express_checkout'       => 'yes',
			'rcus_enabled'                => 'yes',
			'rcus_drawn'                  => array( 'rcus_checkout_coupon_prompt', 'rcus_express_checkout', 'rcus_cart_sale_price', 'rcus_enabled' ),
		);

		try {
			( new Settings_Page() )->save();
		} catch ( WP_Halt $e ) {
			unset( $e );
		}

		$this->assertSame( 'no', WP_Stub::$options['rcus_checkout_coupon_prompt'], 'A hidden field cannot be written.' );
		$this->assertArrayNotHasKey( 'rcus_express_checkout', WP_Stub::$options );
		$this->assertSame( 'yes', WP_Stub::$options['rcus_cart_sale_price'], 'A value waiting for WooCommerce is kept.' );
		$this->assertSame( 'yes', WP_Stub::$options['rcus_enabled'], 'The master switch saves normally.' );
	}

	#[RunInSeparateProcess]
	public function test_the_form_draws_only_the_master_switch(): void {
		WP_Stub::reset();

		ob_start();
		( new Settings_Page() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '>WooCommerce</h2>', $html );
		$this->assertStringNotContainsString( 'name="rcus_express_checkout"', $html );
		$this->assertSame( 1, substr_count( $html, '<section class="rcus-card">' ), 'The master switch alone: this plugin has no WordPress card.' );
	}
}
