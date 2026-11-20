<?php
/**
 * Each size's stores travel in the page and show the moment the size is picked.
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
use RhinnoIT\Customizer\Frontend\Size_Stores;

/**
 * Covers RhinnoIT\Customizer\Frontend\Size_Stores.
 */
#[CoversClass( Size_Stores::class )]
#[RunTestsInSeparateProcesses]
final class SizeStoresTest extends TestCase {

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
	 * Off it hooks nothing; on, the data goes in every size and the script before Store_Choice's.
	 *
	 * @return void
	 */
	public function test_it_hooks_only_while_on(): void {
		WP_Stub::$options['rcus_size_stores_at_once'] = 'no';
		( new Size_Stores() )->register();
		$this->assertSame( array(), WP_Stub::$callbacks );

		WP_Stub::$options['rcus_size_stores_at_once'] = 'yes';
		( new Size_Stores() )->register();

		$this->assertSame( array( 'woocommerce_available_variation:add_stores', 'wp_enqueue_scripts:add_script' ), WP_Stub::$callbacks );
		$this->assertSame( 99, WP_Stub::$priorities['wp_enqueue_scripts:add_script'], 'Before Store_Choice\'s script, at 100, which ticks from the radios this one sets.' );
	}

	/**
	 * The stores whose number is above zero, read where WeLaunch reads them.
	 *
	 * Numbers from hoka's Rincon 5, 2026-10-27: WeLaunch's answer mixes
	 * strings and integers, and a store can have no number at all.
	 *
	 * @return void
	 */
	public function test_each_size_carries_its_stores(): void {
		$service = new Size_Stores();
		$size    = new WC_Product( false, 42672 );

		WP_Stub::$post_meta[42672]['woocommerce_multi_inventory_inventories_stock'] = array(
			280 => '4',
			281 => '0',
			368 => '',
			430 => 1,
			999 => '-1',
		);

		$this->assertSame( array( 'rcus_stores' => array( '280', '430' ) ), $service->add_stores( array(), null, $size ), 'Zero, negative and blank are no stock; ids as the radios carry them.' );

		$parented              = new WC_Product( false, 42673, 42666 );
		$parented->stock_owner = 42666;
		WP_Stub::$post_meta[42666]['woocommerce_multi_inventory_inventories_stock'] = array( 368 => '2' );

		$this->assertSame( array( '368' ), $service->add_stores( array(), null, $parented )['rcus_stores'] ?? null, 'A size whose stock its parent manages reads the parent\'s numbers, as WeLaunch does.' );

		WP_Stub::$post_meta[42672]['woocommerce_multi_inventory_inventories_stock'] = array(
			280 => '0',
			430 => 0,
		);

		$this->assertSame( array( 'rcus_stores' => array() ), $service->add_stores( array(), null, $size ), 'Numbers and none above zero: every store off.' );
	}

	/**
	 * A size with no store numbers, or data that is not WooCommerce's, is left to WeLaunch.
	 *
	 * @return void
	 */
	public function test_without_numbers_welaunch_decides(): void {
		$service = new Size_Stores();
		$size    = new WC_Product( false, 42672 );

		foreach ( array( null, 'broken', array(), array( 280 => '', 281 => ' ' ), array( 280 => array( 2 ) ) ) as $numbers ) {
			WP_Stub::$post_meta[42672]['woocommerce_multi_inventory_inventories_stock'] = $numbers;

			$this->assertSame( array( 'price_html' => 'x' ), $service->add_stores( array( 'price_html' => 'x' ), null, $size ), var_export( $numbers, true ) );
		}

		$this->assertSame( 'kept', $service->add_stores( 'kept', null, $size ), 'Whatever another filter returned passes through.' );
		$this->assertSame( array(), $service->add_stores( array(), null, 42672 ), 'Not a product.' );
	}

	/**
	 * The script sets the list as the size is picked, keeps the button usable, and lets WeLaunch correct it.
	 *
	 * Measured on hoka on 2026-10-27 with the same script in the live
	 * product page: the list and the ticked store were right 15-22 ms after a
	 * size pick, the stock line and the button about 300 ms later when
	 * WooCommerce showed the size, against about 800 ms before; with
	 * WeLaunch's requests aborted, as under a sale's load, the list still
	 * showed and the button stayed usable; with a store wrongly in the page,
	 * WeLaunch's answer took it off within 1.2 s.
	 *
	 * @return void
	 */
	public function test_the_script_shows_the_stores_before_welaunch_answers(): void {
		( new Size_Stores() )->add_script();

		$this->assertSame( array( array( 'woocommerce-multi-inventory-public', 'after' ) ), WP_Stub::$inline_at );

		$script = WP_Stub::$inline[0];

		$this->assertStringStartsWith( 'window.jQuery&&', $script );
		$this->assertStringContainsString( '$(document).on("found_variation",".variations_form",', $script, 'As the size is picked: WooCommerce 11 fires show_variation, which WeLaunch waits for, about 300 ms later.' );
		$this->assertStringContainsString( 'if(!v||!Array.isArray(v.rcus_stores))return;', $script, 'A size without store numbers is left to WeLaunch.' );
		$this->assertStringContainsString( 'this.disabled=n;if(n)this.checked=!1', $script, 'A store the size has none of goes off, and unticked: WeLaunch checks whatever is ticked.' );
		$this->assertStringContainsString( '.woocommerce-multi-inventory-inventories-variable").show()', $script, 'WeLaunch keeps the list hidden until its answer, forever if it fails.' );
		$this->assertStringContainsString( 'w.ok=!!(A(g,w.t)&&v.is_in_stock&&v.is_purchasable)', $script, 'The button only for a size a store on the screen has.' );
		$this->assertStringContainsString( 'return r.filter(":enabled").length}', $script, 'A store hidden from the frontend has no radio, so it does not count.' );
		$this->assertStringContainsString( '.prop("disabled",!1)', $script, 'WeLaunch\'s check of the parent at page load disables the button.' );
		$this->assertStringContainsString( 'if(i!==w.i)return u();', $script, 'A check answering for another size does not leave the button off.' );
		$this->assertStringContainsString( 'if(w.ok&&!w.g.find(s+":checked:enabled").length&&w.g.find(s+":enabled").length){u();', $script, 'With nothing ticked WeLaunch checks its cookie\'s store and disables the button when that store lacks the size; a store on the screen has it, so the add asks for a choice instead.' );
		$this->assertStringContainsString( 'w.t=w.v.rcus_stores=Object.keys(r).filter(function(k){return 0<+r[k]})', $script, 'The size\'s own answer replaces the page\'s list for the rest of the visit, for Store_Choice too.' );
		$this->assertStringContainsString( 'w.ok=!!(A(w.g,w.t)&&w.v.is_in_stock&&w.v.is_purchasable);return}', $script, 'A late answer for another size, drawn by WeLaunch on whatever size is shown, is followed by this size\'s stores again.' );
		$this->assertStringContainsString( 'manager-table-spinner").hide()', $script, 'The list is already there.' );
		$this->assertStringContainsString( '"inout"!==o.productPageStockDisplay', $script, 'The stock line only on WeLaunch\'s In stock / Out of stock display.' );
		$this->assertStringContainsString( 'z.ok&&!/(?:^|; )woocommerce_multi_inventory_inventory=/.test(document.cookie)', $script, 'With nothing ticked WeLaunch checks its cookie\'s store: no line is promised for it.' );
		$this->assertStringContainsString( '.text(o.textsInStock)', $script, 'Text, never markup.' );
		$this->assertStringContainsString( 'catch(x){}', $script, 'A failure leaves WeLaunch to do as before.' );
	}
}
