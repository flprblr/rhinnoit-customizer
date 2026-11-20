<?php
/**
 * No WeLaunch location ticked until the shopper picks one.
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
use RhinnoIT\Customizer\Frontend\Store_Choice;

/**
 * Covers RhinnoIT\Customizer\Frontend\Store_Choice.
 */
#[CoversClass( Store_Choice::class )]
#[RunTestsInSeparateProcesses]
final class StoreChoiceTest extends TestCase {

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
		$_POST = array();
	}

	/**
	 * Hoka's stores, as WeLaunch keeps them: home delivery 280 and three branches, Parque Arauco hidden from the site.
	 *
	 * @return void
	 */
	private static function hoka(): void {
		WP_Stub::$options['woocommerce_multi_inventory_options'] = array(
			'defaultInventory'   => '280',
			'clickCollectEnable' => '1',
			'deliveryInventory'  => '280',
		);

		foreach ( array( 280 => 'Hoka Tienda Online', 281 => 'Hoka Costanera', 368 => 'Hoka Marina', 430 => 'Hoka Parque Arauco' ) as $id => $name ) {
			WP_Stub::$terms[ $id ]       = new WP_Term( $id, 'inventories' );
			WP_Stub::$terms[ $id ]->name = $name;

			if ( 430 !== $id ) {
				WP_Stub::$metadata['term'][ $id ]['woocommerce_multi_inventory_frontend'] = array( 'on' );
			}
		}
	}

	/**
	 * A size of a variable product, with WeLaunch's numbers for it.
	 *
	 * @param array<int, mixed> $numbers Units by store.
	 * @return WC_Product
	 */
	private static function a_size( array $numbers ): WC_Product {
		$size       = new WC_Product( false, 42672, 42666 );
		$size->type = 'variation';
		$size->name = 'Rincon 4 - 9 US';

		WP_Stub::$products[42672]                                                   = $size;
		WP_Stub::$post_meta[42672]['woocommerce_multi_inventory_inventories_stock'] = $numbers;

		return $size;
	}

	/**
	 * Off it hooks nothing; on, the script goes after Inventory_Links', the checks before WeLaunch's, the cart's store only with delivery.
	 *
	 * @return void
	 */
	public function test_it_hooks_only_while_on(): void {
		WP_Stub::$options['rcus_store_choice'] = 'no';
		( new Store_Choice() )->register();
		$this->assertSame( array(), WP_Stub::$callbacks );

		WP_Stub::$options['rcus_store_choice']          = 'yes';
		WP_Stub::$options['rcus_store_choice_delivery'] = 'no';
		( new Store_Choice() )->register();

		$this->assertSame( array( 'init:set_aside_a_hidden_store', 'wp_enqueue_scripts:add_script', 'woocommerce_add_to_cart_validation:require_a_store' ), WP_Stub::$callbacks );
		$this->assertSame( 0, WP_Stub::$priorities['init:set_aside_a_hidden_store'], 'WeLaunch reads its cookie and the query on init, at 10.' );
		$this->assertSame( 100, WP_Stub::$priorities['wp_enqueue_scripts:add_script'], 'Inventory_Links adds its default-store script at 99.' );
		$this->assertSame( 5, WP_Stub::$priorities['woocommerce_add_to_cart_validation:require_a_store'], 'WeLaunch checks at 10, falling back to its cookie.' );
		$this->assertContains( array( 'woocommerce_add_to_cart_validation', Store_Choice::class, 'require_a_store', 4 ), WP_Stub::$registered, 'The product, quantity and size WooCommerce passes.' );

		WP_Stub::reset();
		WP_Stub::$options['rcus_store_choice']          = 'yes';
		WP_Stub::$options['rcus_store_choice_delivery'] = 'yes';
		( new Store_Choice() )->register();

		$this->assertContains( 'woocommerce_set_cart_cookies:remember_the_cart_store', WP_Stub::$callbacks, 'With delivery ticked, the cart\'s store travels beside WooCommerce\'s cart cookies.' );
	}

	/**
	 * The script unticks after WeLaunch redraws, keeps the shopper's click, and stops an add with nothing ticked.
	 *
	 * Measured on hoka on 2026-10-06 by running the same script in the live
	 * product page: no location ticked after a size, the add stopped with the
	 * message, and the location clicked kept across a size change. 2.7.19
	 * reacted only to WeLaunch's own requests, and the quick view kept the
	 * cookie's store ticked, disabled for that size.
	 *
	 * @return void
	 */
	public function test_the_script_leaves_the_choice_to_the_shopper(): void {
		( new Store_Choice() )->add_script();

		$this->assertSame( array( array( 'woocommerce-multi-inventory-public', 'after' ) ), WP_Stub::$inline_at );

		$script = WP_Stub::$inline[0];

		$this->assertStringStartsWith( 'window.jQuery&&', $script );
		$this->assertStringNotContainsString( '__TEXT__', $script );
		$this->assertStringContainsString( 't="Choose home delivery or a store to pick it up."', $script, 'The message, as JSON.' );
		$this->assertStringNotContainsString( '__DELIVERY__', $script );
		$this->assertStringContainsString( 'D=null,', $script, 'Its own switch off, delivery is never ticked either.' );
		$this->assertStringContainsString( '}}}setTimeout(f,1)})', $script, 'After every request, WeLaunch\'s redraws and Porto\'s quick view alike: 2.7.19 reacted only to WeLaunch\'s and missed the quick view.' );
		$this->assertStringContainsString( ':checked:enabled', $script, 'A ticked location the size has none of is no choice: the quick view arrived with one.' );
		$this->assertStringContainsString( 'on("adding_to_cart"', $script );
		$this->assertStringContainsString( 'd.rwpo_store_choice="1"', $script, 'Porto\'s AJAX add carries the marker too, so the server checks it.' );
		$this->assertStringContainsString( 'e.originalEvent&&this.checked', $script, 'Only a real click is the shopper\'s choice.' );
		$this->assertStringContainsString( '!this.disabled', $script, 'A location the size has none of is not kept.' );
		$this->assertStringContainsString( 'addEventListener("click"', $script );
		$this->assertStringContainsString( ',!0)', $script, 'In the capture phase, before Porto\'s own add to cart.' );
		$this->assertStringContainsString( 'stopImmediatePropagation', $script );
		$this->assertStringContainsString( 'name="rwpo_store_choice"', $script, 'The marker the server checks.' );
		$this->assertStringContainsString( '.text(t)', $script, 'The message is text, never markup.' );
		$this->assertStringContainsString( 's=\'input[type="radio"][name="woocommerce_multi_inventory_inventory"]\'', $script, 'Radios only: WeLaunch\'s Hidden display posts its store in a hidden field, which is never :checked, so every add was stopped.' );
		$this->assertStringContainsString( 'if(v&&!d.woocommerce_multi_inventory_inventory)d.woocommerce_multi_inventory_inventory=v', $script, 'Porto\'s AJAX add leaves the ticked store out; with the marker and no store, every quick view add was refused while the quick view switch was off.' );
		$this->assertStringContainsString( '.woocommerce-multi-inventory-label-container .woocommerce-multi-inventory-inventories-row").show()', $script, 'WeLaunch\'s Label display folds the locations away until "change" is clicked.' );
		$this->assertStringNotContainsString( 'append', substr( $script, 0, (int) strpos( $script, 'function b(' ) ), 'No marker on load or after a redraw: a size on backorder has every location disabled, and its add was refused.' );
		$this->assertLessThan( strpos( $script, 'g.append(' ), strpos( $script, 'if(!r.length||g.find(p).length)return;' ), 'The marker goes on only while a location can be ticked.' );
		$this->assertLessThan( strpos( $script, 'd.rwpo_store_choice="1"' ), strpos( $script, 'if(!g.find(s+":enabled").length||g.find(p).length)return;' ), 'Porto\'s add too.' );
		$this->assertStringContainsString( 'p=\'input[name="woocommerce_multi_inventory_fake"]:checked\'', $script, 'Label & Popup with Click & Collect: pickup ticked is the choice, and the popup saved the store.' );
		$this->assertStringContainsString( 'if(!r.length||g.find(p).length)return;', $script );
	}

	/**
	 * Delivery is ticked only with its own switch, and only where WeLaunch's live stock has the size.
	 *
	 * Measured on hoka on 2026-10-27 by running the same script in the live
	 * product page and quick view: delivery ticked on sizes the online store
	 * had; nothing ticked, and the add button enabled, on a size only Parque
	 * Arauco had (WeLaunch had checked delivery for it and disabled the
	 * button); nothing ticked with a branch's cart; a returning shopper whose
	 * cookie named a branch without the size got delivery and an enabled
	 * button. A branch the shopper clicked stayed theirs.
	 *
	 * @return void
	 */
	public function test_delivery_is_ticked_only_with_its_switch_and_stock(): void {
		self::hoka();
		WP_Stub::$options['rcus_store_choice']          = 'yes';
		WP_Stub::$options['rcus_store_choice_delivery'] = 'no';
		$service = new Store_Choice();
		$service->register();
		$service->add_script();

		$this->assertStringContainsString( 'D=null,', WP_Stub::$inline[0], 'Off: no location is ticked but by the shopper, as before 2.8.6.' );

		WP_Stub::reset();
		self::hoka();
		WP_Stub::$options['rcus_store_choice']          = 'yes';
		WP_Stub::$options['rcus_store_choice_delivery'] = 'yes';
		$service = new Store_Choice();
		$service->register();
		$service->add_script();

		$script = WP_Stub::$inline[0];

		$this->assertStringContainsString( 'D="280",', $script, 'Delivery is WeLaunch\'s own location, a string as the radios carry it, never a number in the code.' );
		$this->assertStringNotContainsString( 'o.defaultInventory', $script, 'Read on the server, where Click & Collect\'s delivery store is known.' );
		$this->assertStringContainsString( 'N[i]=!!r.status&&0<+(r.inventories_stock||{})[D]', $script, 'WeLaunch\'s live answer for the size sets it (after the page\'s per-size stores, with Size_Stores); a store with no number counts as none.' );
		$this->assertStringContainsString( 'if(Array.isArray(v.rcus_stores)){if(D)N[i]=-1<v.rcus_stores.indexOf(D);f(1)}else delete N[i]', $script, 'With the size\'s stores in the page the tick is decided as the size is picked; without them, a size picked again waits for its new answer.' );
		$this->assertStringContainsString( 'on("found_variation",".variations_form",function(e,v){c=2;', $script, 'Before WooCommerce fires show_variation, where WeLaunch reads the ticked store for its check.' );
		$this->assertStringContainsString( 'if(z)return;', $script, 'No second check from there: WeLaunch\'s own, a moment later, asks about the store ticked here.' );
		$this->assertStringContainsString( 'm=this.value;M=this;', $script, 'The radio the shopper clicked is kept, not only its value.' );
		$this->assertStringContainsString( 'if(M&&M.isConnected&&!M.disabled&&!M.checked&&!$(M).closest("form").find(s+":checked").length){M.checked=!0;$(M).closest("form").find("."+k).remove()}', $script, 'WeLaunch\'s answers tick its own store in the same group, which unticked the shopper\'s branch: 2.8.6 to 2.8.8 then asked them to choose again (reproduced in a jsdom harness with WeLaunch\'s and WooCommerce\'s real scripts).' );
		$this->assertLessThan( strpos( $script, 'if(D&&(!m||m===D)&&K())' ), strpos( $script, 'if(M&&M.isConnected' ), 'Before delivery is considered, which a shopper\'s branch keeps out.' );
		$this->assertStringContainsString( 'if(D&&(!m||m===D)&&K())', $script, 'A shopper who clicked a store is never moved to delivery.' );
		$this->assertStringContainsString( 'closest("form.cart")', $script, 'Add to cart forms only.' );
		$this->assertStringContainsString( 'r.prop("disabled")', $script, 'Never a location WeLaunch disabled for the size.' );
		$this->assertStringContainsString( 'q!==w&&!(""===q&&a===w)&&0<c', $script, 'WeLaunch checks again only a location it did not check...' );
		$this->assertStringContainsString( 'function(e,v){c=2;', $script, '...at most twice per size pick.' );
	}

	/**
	 * With a cart, delivery is ticked only while the cart's own store is known to be delivery.
	 *
	 * 2.8.6 to 2.8.10 took the cart's store from WeLaunch's cookie, which holds
	 * the store WeLaunch validated on the last add: a cart kept from another
	 * device, or a branch with none of the size, which WeLaunch validates
	 * against its default location (and writes that into the cookie, refused
	 * or not), left the cookie on delivery over a pickup cart, and the next
	 * add emptied it.
	 *
	 * @return void
	 */
	public function test_with_a_cart_delivery_needs_the_carts_own_store(): void {
		( new Store_Choice() )->add_script();

		$script = WP_Stub::$inline[0];

		$this->assertStringContainsString( 'function K(){var r=document.cookie.match(/(?:^|;\\s*)rwpo_cart_store=([^;]*)/);return!/(?:^|;\\s*)woocommerce_items_in_cart=/.test(document.cookie)||!!r&&("0"===r[1]||r[1]===D)}', $script, 'No cart; or the cart\'s store is delivery, or no line has one: WeLaunch keeps those lines on any add. Without the cookie the store is not known.' );
		$this->assertStringNotContainsString( 'C()===D', $script, 'Never WeLaunch\'s cookie for the cart.' );
		$this->assertStringContainsString( 'w=n.length?n.val():C()', $script, 'That cookie still says which store WeLaunch\'s next check asks about.' );
	}

	/**
	 * The cookie names the store every line of the cart is filed under, and goes when the cart does or the store is not known.
	 *
	 * @return void
	 */
	public function test_the_carts_store_travels_beside_the_cart_cookies(): void {
		$service        = new Store_Choice();
		WP_Stub::$wc       = new WooCommerce();
		WP_Stub::$wc->cart = new WC_Cart();
		$line           = static function ( mixed $store ): array {
			return array(
				'data'                                  => new WC_Product(),
				'woocommerce_multi_inventory_inventory' => array(
					'label' => 'Tienda',
					'value' => $store,
				),
			);
		};

		WP_Stub::$wc->cart->items = array( $line( 280 ), $line( '280' ) );
		$service->remember_the_cart_store( true );

		$this->assertSame( array( array( 'rwpo_cart_store', '280', 0 ) ), WP_Stub::$cookies, 'One store, as WeLaunch compares them: a browser-session cookie, as WooCommerce\'s cart cookies are.' );
		$this->assertSame( '280', $_COOKIE['rwpo_cart_store'] );

		$service->remember_the_cart_store( true );
		$this->assertCount( 1, WP_Stub::$cookies, 'Unchanged, nothing is sent again: WooCommerce sets its cookies up to three times a request.' );

		WP_Stub::$cookies         = array();
		WP_Stub::$wc->cart->items = array( $line( 368 ), array( 'data' => new WC_Product() ), $line( '' ), $line( 0 ) );
		$service->remember_the_cart_store( true );
		$this->assertSame( array( array( 'rwpo_cart_store', '368', 0 ) ), WP_Stub::$cookies, 'A line without a store stays in the cart on any add, so it does not count.' );

		WP_Stub::$cookies         = array();
		WP_Stub::$wc->cart->items = array( $line( 368 ), $line( 280 ) );
		$service->remember_the_cart_store( true );
		$this->assertSame( 'rwpo_cart_store', WP_Stub::$cookies[0][0] ?? null );
		$this->assertLessThan( time(), WP_Stub::$cookies[0][2] ?? PHP_INT_MAX, 'Two stores, as an add leaves them before WeLaunch empties the cart: not known, so deleted.' );
		$this->assertArrayNotHasKey( 'rwpo_cart_store', $_COOKIE );

		WP_Stub::$cookies         = array();
		WP_Stub::$wc->cart->items = array( $line( 'x' ) );
		$service->remember_the_cart_store( true );
		$this->assertSame( array(), WP_Stub::$cookies, 'Unreadable: not known, and nothing to delete.' );

		WP_Stub::$wc->cart->items = array( array( 'data' => new WC_Product() ) );
		$service->remember_the_cart_store( true );
		$this->assertSame( array( array( 'rwpo_cart_store', '0', 0 ) ), WP_Stub::$cookies, 'No line has a store.' );

		WP_Stub::$cookies = array();
		$service->remember_the_cart_store( false );
		$this->assertLessThan( time(), WP_Stub::$cookies[0][2] ?? PHP_INT_MAX, 'Deleted with WooCommerce\'s cart cookies.' );
		$this->assertArrayNotHasKey( 'rwpo_cart_store', $_COOKIE );

		WP_Stub::$cookies = array();
		WP_Stub::$wc      = null;
		$_COOKIE          = array( 'rwpo_cart_store' => '280' );
		$service->remember_the_cart_store( true );
		$this->assertLessThan( time(), WP_Stub::$cookies[0][2] ?? PHP_INT_MAX, 'No cart to read: not known.' );
	}

	/**
	 * Home delivery is Click & Collect's delivery store, or without Click & Collect WeLaunch's default location.
	 *
	 * Click & Collect draws its delivery row from deliveryInventory, above the
	 * pickup stores, where the default location is one more store: ticking
	 * the default there would tick a branch nobody chose. Hoka has both on 280.
	 *
	 * @return void
	 */
	public function test_delivery_is_the_row_welaunch_draws_for_it(): void {
		$cases = array(
			array( array( 'defaultInventory' => '280' ), 280 ),
			array(
				array(
					'defaultInventory'   => '281',
					'clickCollectEnable' => '1',
					'deliveryInventory'  => '280',
				),
				280,
			),
			array(
				array(
					'defaultInventory'   => '281',
					'clickCollectEnable' => '0',
					'deliveryInventory'  => '280',
				),
				281,
			),
			array(
				array(
					'defaultInventory'   => '281',
					'clickCollectEnable' => 1,
					'deliveryInventory'  => '',
				),
				0,
			),
			array( array( 'defaultInventory' => '28x' ), 0 ),
			array( array( 'defaultInventory' => array( 280 ) ), 0 ),
			array( 'broken', 0 ),
			array( null, 0 ),
		);

		foreach ( $cases as list( $options, $delivery ) ) {
			WP_Stub::$options['woocommerce_multi_inventory_options'] = $options;

			$this->assertSame( $delivery, Store_Choice::delivery_store(), var_export( $options, true ) );
		}

		WP_Stub::$options['rcus_store_choice']                   = 'yes';
		WP_Stub::$options['rcus_store_choice_delivery']          = 'yes';
		WP_Stub::$options['woocommerce_multi_inventory_options'] = $cases[1][0];
		$service = new Store_Choice();
		$service->register();
		$service->add_script();

		$this->assertStringContainsString( 'D="280",', WP_Stub::$inline[0] );

		WP_Stub::$inline                                         = array();
		WP_Stub::$options['woocommerce_multi_inventory_options'] = $cases[3][0];
		$service->add_script();

		$this->assertStringContainsString( 'D=null,', WP_Stub::$inline[0], 'Click & Collect without a delivery store draws no delivery row: nothing to tick.' );
	}

	/**
	 * A simple product's delivery counts as printed, but Click & Collect's delivery radio only with WeLaunch's on-stock class.
	 *
	 * WeLaunch disables a simple product's store with none, and leaves out
	 * one with no number, but prints Click & Collect's delivery radio enabled
	 * whatever its stock, with the row's class saying which. Porto's quick
	 * view runs none of WeLaunch's checks, so 2.8.6 to 2.8.10 ticked
	 * delivery there for a product it did not have.
	 *
	 * @return void
	 */
	public function test_a_simple_products_delivery_counts_as_printed(): void {
		( new Store_Choice() )->add_script();

		$script = WP_Stub::$inline[0];

		$this->assertStringContainsString( 'u=v.length?N[v.val()]:T[e.find(\'[name="add-to-cart"]\').val()];', $script, 'A size by its stores; a simple product by WeLaunch\'s check of delivery, when one ran.' );
		$this->assertStringContainsString( 'if(void 0===u&&!v.length)u=!r.closest(".woocommerce-multi-inventory-inventories-delivery-container").length||0<r.closest(".woocommerce-multi-inventory-inventories-row").find(".woocommerce-multi-inventory-inventories-stock-on-stock").length;', $script, 'Without one, as printed: enabled, and in Click & Collect\'s delivery block on stock.' );
		$this->assertStringContainsString( 'if(!0===u){r.first().prop("checked",!0);', $script, 'A size whose stores are not known yet waits for them.' );
		$this->assertStringContainsString( 'if(D&&(!i||"0"===i)&&P(d,"inventory_id")===D)T[P(d,"product_id")]=!!r.status', $script, 'WeLaunch\'s own check of delivery then decides.' );
	}

	/**
	 * A form that showed locations and posted none is refused before WeLaunch picks its cookie's store.
	 *
	 * @return void
	 */
	public function test_the_server_refuses_an_add_without_a_location(): void {
		self::hoka();
		$service = new Store_Choice();

		$_POST = array( 'rwpo_store_choice' => '1' );
		$this->assertFalse( $service->require_a_store( true ) );
		$this->assertSame( array( 'error: Choose home delivery or a store to pick it up.' ), WP_Stub::$notices );

		$_POST = array(
			'rwpo_store_choice'                     => '1',
			'woocommerce_multi_inventory_inventory' => '281',
		);
		$this->assertTrue( $service->require_a_store( true ), 'A location picked.' );

		$_POST = array();
		$this->assertTrue( $service->require_a_store( true ), 'No locations on that form: a list button, or the script did not run.' );

		$_POST = array(
			'rwpo_store_choice'                     => '1',
			'woocommerce_multi_inventory_inventory' => array( '281' ),
		);
		$this->assertFalse( $service->require_a_store( true ), 'An array is no location.' );

		$_POST = array( 'rwpo_store_choice' => '1' );
		$this->assertFalse( $service->require_a_store( false ), 'Already refused stays refused.' );
		$this->assertSame( 'kept', $service->require_a_store( 'kept' ), 'Whatever another filter returned passes through.' );
	}

	/**
	 * A store with none of the size is refused, which WeLaunch lets through while it files the line there.
	 *
	 * WeLaunch's check validates its default location instead of a posted
	 * store with no units, and its cart data files the line under the posted
	 * one: the checkout then refused it. With some units but fewer than asked
	 * WeLaunch refuses it itself, so nothing is said twice. Arguments as
	 * WooCommerce's form handler passes them (product, quantity, size,
	 * attributes) and as its AJAX add and Porto's do (the size as product).
	 *
	 * @return void
	 */
	public function test_the_server_refuses_a_store_without_the_units(): void {
		self::hoka();
		$service = new Store_Choice();
		self::a_size(
			array(
				280 => '3',
				281 => '1',
				368 => '0',
				999 => '',
			)
		);
		WP_Stub::$terms[368]->name = '<b>Hoka Marina</b>';

		$_POST = array(
			'rwpo_store_choice'                     => '1',
			'woocommerce_multi_inventory_inventory' => '368',
		);
		$this->assertFalse( $service->require_a_store( true, 42666, 1, 42672, array( 'attribute_pa_talla' => '9-us' ) ) );
		$this->assertSame( array( 'error: Rincon 4 - 9 US: 0 left at &lt;b&gt;Hoka Marina&lt;/b&gt;. Choose another location or fewer units.' ), WP_Stub::$notices, 'The store\'s name is text.' );

		WP_Stub::$notices = array();
		$this->assertFalse( $service->require_a_store( true, 42672, 1 ), 'Porto\'s quick view, which posts the size as the product.' );

		WP_Stub::$post_meta[42672]['woocommerce_multi_inventory_inventories_stock'][368] = '';
		$this->assertFalse( $service->require_a_store( true, 42672, 1 ), 'No number counts as none: WeLaunch files the line there all the same, and its checkout refuses it.' );

		WP_Stub::$notices = array();
		$_POST['woocommerce_multi_inventory_inventory'] = '281';
		$this->assertTrue( $service->require_a_store( true, 42672, 1 ), 'Enough units.' );
		$this->assertTrue( $service->require_a_store( true, 42672, 2 ), 'Some, fewer than asked: WeLaunch refuses it with its own message.' );
		$this->assertSame( array(), WP_Stub::$notices );

		WP_Stub::$options['woocommerce_multi_inventory_options']['inventoryAllowEmptyProducts'] = '1';
		$this->assertFalse( $service->require_a_store( true, 42672, 2 ), 'Unless its "allow empty products" skips that check.' );
		$this->assertSame( array( 'error: Rincon 4 - 9 US: 1 left at Hoka Costanera. Choose another location or fewer units.' ), WP_Stub::$notices );
		unset( WP_Stub::$options['woocommerce_multi_inventory_options']['inventoryAllowEmptyProducts'] );

		WP_Stub::$notices = array();
		$_POST['woocommerce_multi_inventory_inventory'] = '368';
		$this->assertTrue( $service->require_a_store( true, 42672, 4 ), 'WeLaunch weighs its default location for a store with none, and refuses fewer units there itself.' );

		WP_Stub::$post_meta[42672]['woocommerce_multi_inventory_inventories_stock'][280] = '0';
		$this->assertFalse( $service->require_a_store( true, 42672, 1 ), 'With none there either, WeLaunch lets it through.' );

		WP_Stub::$options['woocommerce_multi_inventory_options']['inventoryRequired'] = '1';
		$this->assertTrue( $service->require_a_store( true, 42672, 1 ), 'Unless it requires a location: then it refuses it itself.' );
	}

	/**
	 * Adds WeLaunch lets through as it does are left alone: backorders, bundles, sizes not picked, products without numbers.
	 *
	 * @return void
	 */
	public function test_what_welaunch_allows_stays_allowed(): void {
		self::hoka();
		$service = new Store_Choice();
		$size    = self::a_size( array( 368 => '0' ) );
		$_POST   = array( 'woocommerce_multi_inventory_inventory' => '368' );

		$size->backorders = true;
		$this->assertTrue( $service->require_a_store( true, 42672, 1 ), 'A sale on backorder.' );

		$size->backorders   = false;
		$size->on_backorder = true;
		$this->assertTrue( $service->require_a_store( true, 42672, 1 ) );

		$size->on_backorder = false;
		$size->type         = 'bundle';
		$this->assertTrue( $service->require_a_store( true, 42672, 1 ), 'A bundle, which WeLaunch\'s checkout leaves alone.' );

		$size->type = 'variable';
		$this->assertTrue( $service->require_a_store( true, 42672, 1, '' ), 'A variable product without its size, which WooCommerce refuses; the form handler passes an empty string.' );

		$size->type = 'variation';
		$this->assertTrue( $service->require_a_store( true, 42672, 0 ), 'No units asked for.' );
		$this->assertTrue( $service->require_a_store( true, 999, 1 ), 'No such product.' );
		$this->assertTrue( $service->require_a_store( true, 'x', 'y', array() ), 'Junk.' );

		foreach ( array( null, 'broken', array(), array( 368 => '', 280 => ' ' ), array( 368 => array( 2 ) ) ) as $numbers ) {
			WP_Stub::$post_meta[42672]['woocommerce_multi_inventory_inventories_stock'] = $numbers;
			$this->assertTrue( $service->require_a_store( true, 42672, 1 ), 'No store numbers: WeLaunch decides alone. ' . var_export( $numbers, true ) );
		}

		$parented              = self::a_size( array() );
		$parented->stock_owner = 42666;
		WP_Stub::$post_meta[42666]['woocommerce_multi_inventory_inventories_stock'] = array( 368 => '0' );
		$this->assertFalse( $service->require_a_store( true, 42672, 1 ), 'A size whose stock its parent manages counts the parent\'s numbers, as WeLaunch\'s checkout does.' );

		WP_Stub::$options['woocommerce_multi_inventory_options']['orderFlowAlwaysUse'] = '1';
		$this->assertTrue( $service->require_a_store( true, 42672, 1 ), 'WeLaunch\'s checkout then picks every line\'s store itself.' );

		WP_Stub::$options['woocommerce_multi_inventory_options'] = 'broken';
		WP_Stub::$metadata['term'][368]['woocommerce_multi_inventory_frontend'] = array( 'on' );
		$this->assertFalse( $service->require_a_store( true, 42672, 1 ), 'Settings that are not WeLaunch\'s: the store still needs the units.' );
	}

	/**
	 * A store the site does not offer is refused, whatever its stock: the owner hid it from the site.
	 *
	 * A page cached before Parque Arauco was hidden (hoka, 2026-10-06) still
	 * drew it. WeLaunch's default location and Click & Collect's delivery
	 * store are WeLaunch's own, offered whatever their flag says.
	 *
	 * @return void
	 */
	public function test_the_server_refuses_a_store_the_site_does_not_offer(): void {
		self::hoka();
		$service = new Store_Choice();
		self::a_size(
			array(
				280 => '3',
				430 => '5',
			)
		);

		$_POST = array(
			'rwpo_store_choice'                     => '1',
			'woocommerce_multi_inventory_inventory' => '430',
		);
		$this->assertFalse( $service->require_a_store( true, 42672, 1 ) );
		$this->assertSame( array( 'error: Hoka Parque Arauco does not take online orders. Choose home delivery or another store.' ), WP_Stub::$notices );

		WP_Stub::$notices = array();
		$this->assertFalse( $service->require_a_store( true ), 'Whatever the product.' );

		WP_Stub::$metadata['term'][430]['woocommerce_multi_inventory_frontend'] = array( 'ON ' );
		$this->assertTrue( $service->require_a_store( true, 42672, 1 ), 'Shown as the product page\'s query matches it: any case, trailing space.' );

		WP_Stub::$metadata['term'][430]['woocommerce_multi_inventory_frontend'] = array( '', 'on' );
		$this->assertTrue( $service->require_a_store( true, 42672, 1 ), 'Any row.' );

		WP_Stub::$metadata['term'][430]['woocommerce_multi_inventory_frontend'] = array( 'off' );
		unset( WP_Stub::$metadata['term'][280] );
		$_POST['woocommerce_multi_inventory_inventory'] = '280';
		$this->assertTrue( $service->require_a_store( true, 42672, 1 ), 'Delivery, WeLaunch\'s own, whatever its flag says.' );

		$_POST['woocommerce_multi_inventory_inventory'] = '777';
		WP_Stub::$notices = array();
		$this->assertFalse( $service->require_a_store( true, 42672, 1 ) );
		$this->assertSame( array( 'error: Choose home delivery or a store to pick it up.' ), WP_Stub::$notices, 'A store that does not exist: choose again.' );
	}

	/**
	 * An add that posts no store is refused when no store the site offers has any of the size.
	 *
	 * WeLaunch's answer about Parque Arauco, hidden from the site, enabled
	 * the button for a size only that store had; with no radio to tick the
	 * add posted no store, and WeLaunch filed it under delivery, without
	 * units, for its checkout to refuse.
	 *
	 * @return void
	 */
	public function test_an_add_without_a_store_needs_one_the_site_offers(): void {
		self::hoka();
		$service = new Store_Choice();
		self::a_size(
			array(
				280 => '0',
				281 => 0,
				368 => '',
				430 => '5',
			)
		);

		$this->assertFalse( $service->require_a_store( true, 42672, 1 ) );
		$this->assertSame( array( 'error: Rincon 4 - 9 US is not available online.' ), WP_Stub::$notices );

		WP_Stub::$post_meta[42672]['woocommerce_multi_inventory_inventories_stock'][281] = '1';
		$this->assertTrue( $service->require_a_store( true, 42672, 1 ), 'A store on the site has some: WeLaunch decides where the line goes.' );

		WP_Stub::$post_meta[42672]['woocommerce_multi_inventory_inventories_stock'][281] = '0';
		WP_Stub::$options['woocommerce_multi_inventory_options']['inventoryRequired'] = '1';
		$this->assertTrue( $service->require_a_store( true, 42672, 1 ), 'WeLaunch refuses it itself when it requires a location.' );
	}

	/**
	 * WeLaunch's cookie, or a ?inventory= link, naming a store the site does not offer is set aside before WeLaunch reads it.
	 *
	 * The cookie lasts a year, so a shopper who once bought at Parque Arauco
	 * still had their lines filed there - and its stock status, its prices -
	 * after the owner hid it.
	 *
	 * @return void
	 */
	public function test_a_hidden_store_is_never_the_visitors(): void {
		self::hoka();
		$service = new Store_Choice();

		$_COOKIE = array( 'woocommerce_multi_inventory_inventory' => '430' );
		$_GET    = array( 'inventory' => '430' );
		$service->set_aside_a_hidden_store();
		$this->assertSame( array(), $_COOKIE );
		$this->assertSame( array(), $_GET );

		foreach ( array( '281', '280', '"430"', '0', 'x' ) as $kept ) {
			$_COOKIE = array( 'woocommerce_multi_inventory_inventory' => $kept );
			$_GET    = array( 'inventory' => $kept );
			$service->set_aside_a_hidden_store();
			$this->assertSame( array( 'woocommerce_multi_inventory_inventory' => $kept ), $_COOKIE, 'A store the site offers, WeLaunch\'s own, or one WeLaunch reads as none (its intval()): ' . $kept );
			$this->assertSame( array( 'inventory' => $kept ), $_GET );
		}

		$_COOKIE = array( 'woocommerce_multi_inventory_inventory' => array( '430' ) );
		$service->set_aside_a_hidden_store();
		$this->assertSame( array( 'woocommerce_multi_inventory_inventory' => array( '430' ) ), $_COOKIE, 'Not WeLaunch\'s: left alone.' );

		$_COOKIE = array(
			'woocommerce_multi_inventory_inventory' => '430',
			'woocommerce_items_in_cart'             => '1',
		);
		$_GET    = array( 'inventory' => '368' );
		$service->set_aside_a_hidden_store();
		$this->assertSame( array( 'woocommerce_items_in_cart' => '1' ), $_COOKIE, 'Only WeLaunch\'s cookie goes.' );
		$this->assertSame( array( 'inventory' => '368' ), $_GET );
	}
}
