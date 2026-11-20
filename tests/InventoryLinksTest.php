<?php
/**
 * Keeping WeLaunch Multi Inventory's store out of links and cached pages.
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
use RhinnoIT\Customizer\Frontend\Inventory_Links;

/**
 * Covers RhinnoIT\Customizer\Frontend\Inventory_Links.
 */
#[CoversClass( Inventory_Links::class )]
#[RunTestsInSeparateProcesses]
final class InventoryLinksTest extends TestCase {

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
	 * A returning shopper on a category: the store cookie WeLaunch set on their last add.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$options['rcus_inventory_links'] = 'yes';
		WP_Stub::$wc_pages                        = array(
			'cart'      => 11,
			'checkout'  => 12,
			'myaccount' => 13,
		);
		WP_Stub::$page_uris                       = array(
			11 => 'carrito',
			12 => 'finalizar-compra',
			13 => 'mi-cuenta',
		);
		WP_Stub::$is_admin                        = false;
		$_SERVER['REQUEST_URI']                   = '/c/zapatillas-mujer/';
		$_COOKIE['woocommerce_multi_inventory_inventory'] = '430';
	}

	/**
	 * Runs the server half as WordPress does on init.
	 *
	 * @return bool Whether WeLaunch would still see the store cookie.
	 */
	private static function welaunch_sees_the_store(): bool {
		( new Inventory_Links() )->build_cacheable_pages_for_the_default_store();

		return isset( $_COOKIE['woocommerce_multi_inventory_inventory'] );
	}

	/**
	 * Both halves hook where WeLaunch cannot get ahead of them.
	 *
	 * @return void
	 */
	public function test_it_hooks_before_welaunch_reads_and_after_it_enqueues(): void {
		( new Inventory_Links() )->register();

		$this->assertSame( array( 'init:build_cacheable_pages_for_the_default_store', 'wp_enqueue_scripts:keep_links_clean' ), WP_Stub::$callbacks );
		$this->assertSame( 0, WP_Stub::$priorities['init:build_cacheable_pages_for_the_default_store'], 'WeLaunch reads the cookie at 10.' );
		$this->assertSame( 99, WP_Stub::$priorities['wp_enqueue_scripts:keep_links_clean'], 'WeLaunch enqueues at 10.' );

		WP_Stub::reset();
		WP_Stub::$options['rcus_inventory_links'] = 'no';
		( new Inventory_Links() )->register();
		$this->assertSame( array(), WP_Stub::$callbacks, 'Off, it hooks nothing.' );
	}

	/**
	 * WeLaunch's script is told not to rewrite links, through its own option.
	 *
	 * Once the cookie exists it appends ?inventory= to every link, and Kinsta
	 * caches no such URL: every page a returning shopper opened was a render.
	 *
	 * @return void
	 */
	public function test_links_stay_clean(): void {
		( new Inventory_Links() )->keep_links_clean();

		$this->assertSame( 'window.woocommerce_multi_inventory_options&&(woocommerce_multi_inventory_options.disableStateReplace="1");', WP_Stub::$inline[0] );
		$this->assertSame( array( 'woocommerce-multi-inventory-public', 'before' ), WP_Stub::$inline_at[0], 'After the options object, before WeLaunch reads it.' );
	}

	/**
	 * The online store stays ticked unless the shopper picks another, or the cart belongs to one.
	 *
	 * The owner's rule: every store is offered, the default is the online
	 * one. WeLaunch's script ticked the store its cookie remembered - a pickup
	 * store for anyone who once bought for pickup - and, on a size change,
	 * dropped the store the shopper had just picked. With a cart the cart's
	 * store stays: WeLaunch empties the cart of other stores' lines on the
	 * next add (measured live).
	 *
	 * @return void
	 */
	public function test_the_default_store_stays_ticked(): void {
		( new Inventory_Links() )->keep_links_clean();

		$this->assertSame( array( 'woocommerce-multi-inventory-public', 'after' ), WP_Stub::$inline_at[1], 'After WeLaunch\'s script, which binds its handlers.' );

		$script = WP_Stub::$inline[1];

		$this->assertStringStartsWith( 'window.jQuery&&', $script, 'Nothing runs without jQuery.' );
		$this->assertStringContainsString( 'o.defaultInventory?String(o.defaultInventory):null', $script, 'The default is WeLaunch\'s own setting, never a number in the code.' );
		$this->assertStringContainsString( 'if(e.originalEvent&&this.checked)m=this.value', $script, 'Only a store the shopper clicked counts as their pick.' );
		$this->assertStringContainsString( 'w=p||(k()?n:f)', $script, 'Their pick, else the cart\'s store, else the default.' );
		$this->assertStringContainsString( '/(?:^|;\\s*)woocommerce_items_in_cart=/.test(document.cookie)', $script );
		$this->assertStringContainsString( 'if(a&&a!==w&&2>c){c++;', $script, 'WeLaunch re-checks only a store it did not check, at most twice per size pick.' );
		$this->assertStringContainsString( 'on("show_variation",function(){c=0})', $script );
	}

	/**
	 * While Store_Choice decides the ticked store, the default-store script stays out.
	 *
	 * It ticks the default even where that store has none of the size, and
	 * each script would run WeLaunch's check again for the store the other
	 * then unticks.
	 *
	 * @return void
	 */
	public function test_the_default_store_script_steps_aside_for_store_choice(): void {
		WP_Stub::$options['rcus_store_choice'] = 'yes';
		$service                               = new Inventory_Links();
		$service->register();
		$service->keep_links_clean();

		$this->assertSame( array( array( 'woocommerce-multi-inventory-public', 'before' ) ), WP_Stub::$inline_at, 'Links stay clean; the ticking is Store_Choice\'s.' );

		WP_Stub::$inline                       = array();
		WP_Stub::$inline_at                    = array();
		WP_Stub::$options['rcus_store_choice'] = 'no';
		$service                               = new Inventory_Links();
		$service->register();
		$service->keep_links_clean();

		$this->assertSame( Inventory_Links::DEFAULT_STORE_SCRIPT, WP_Stub::$inline[1] ?? '', 'Without it, the default store stays ticked as before.' );
	}

	/**
	 * A page the cache may keep is built for the default store.
	 *
	 * Rendered with the cookie, it would carry one shopper's store - "out of
	 * stock" on sizes the online store has - to everyone the cache serves.
	 *
	 * @return void
	 */
	public function test_a_cacheable_page_is_built_for_the_default_store(): void {
		foreach ( array( '/c/zapatillas-mujer/', '/', '/p/hoka-clifton-10/?utm_source=mail', '/carrito-de-compras/', '/tienda/page/2/' ) as $uri ) {
			$_SERVER['REQUEST_URI']                           = $uri;
			$_COOKIE['woocommerce_multi_inventory_inventory'] = '430';

			$this->assertFalse( self::welaunch_sees_the_store(), $uri );
		}

		$_SERVER['REQUEST_METHOD']                        = 'HEAD';
		$_COOKIE['woocommerce_multi_inventory_inventory'] = '430';
		$this->assertFalse( self::welaunch_sees_the_store(), 'HEAD is cached like GET.' );
	}

	/**
	 * Every request the cache never keeps, or that needs the store, still sees it.
	 *
	 * @return void
	 */
	public function test_requests_that_need_the_store_keep_it(): void {
		$cases = array(
			'a cart'            => function (): void {
				$_COOKIE['woocommerce_items_in_cart'] = '1';
			},
			'a POST'            => function (): void {
				$_SERVER['REQUEST_METHOD'] = 'POST';
			},
			'a logged-in user'  => function (): void {
				WP_Stub::$logged_in = true;
			},
			'admin'             => function (): void {
				WP_Stub::$is_admin = true;
			},
			'admin-ajax'        => function (): void {
				WP_Stub::$doing_ajax = true;
			},
			'cron'              => function (): void {
				WP_Stub::$doing_cron = true;
			},
			'a store asked for' => function (): void {
				$_GET['inventory'] = '430';
			},
			'wc-ajax'           => function (): void {
				$_GET['wc-ajax'] = 'get_variation';
			},
			'the REST API'      => function (): void {
				$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';
			},
			'plain REST'        => function (): void {
				$_GET['rest_route'] = '/wc/store/v1/cart';
			},
			'a link that adds'  => function (): void {
				$_GET['add-to-cart']     = '42845';
				$_REQUEST['add-to-cart'] = '42845';
			},
			'the cart'          => function (): void {
				$_SERVER['REQUEST_URI']                    = '/carrito/';
				$_COOKIE['wp_woocommerce_session_abc123'] = 't_1|2|3|h';
			},
			'checkout'          => function (): void {
				$_SERVER['REQUEST_URI']                    = '/finalizar-compra/order-received/123/?key=wc_order_x';
				$_COOKIE['wp_woocommerce_session_abc123'] = 't_1|2|3|h';
			},
			'the account'       => function (): void {
				$_SERVER['REQUEST_URI']                    = '/mi-cuenta/lost-password/';
				$_COOKIE['wp_woocommerce_session_abc123'] = 't_1|2|3|h';
			},
			'no request URI'    => function (): void {
				unset( $_SERVER['REQUEST_URI'] );
			},
			'a method array'    => function (): void {
				$_SERVER['REQUEST_METHOD'] = array( 'GET' );
			},
		);

		foreach ( $cases as $case => $arrange ) {
			$this->setUp();
			$arrange();

			$this->assertTrue( self::welaunch_sees_the_store(), $case );
		}
	}

	/**
	 * Without a session the cart, checkout and account pages hold no cart, and Kinsta caches them.
	 *
	 * Before the switch a pickup shopper's cart link carried ?inventory=,
	 * which Kinsta bypasses; clean, a render with their store would be kept
	 * and served to everyone.
	 *
	 * @return void
	 */
	public function test_the_shops_pages_without_a_session_are_built_for_the_default_store(): void {
		foreach ( array( '/carrito/', '/finalizar-compra/', '/mi-cuenta/' ) as $uri ) {
			$_SERVER['REQUEST_URI']                           = $uri;
			$_COOKIE['woocommerce_multi_inventory_inventory'] = '430';

			$this->assertFalse( self::welaunch_sees_the_store(), $uri );
		}
	}

	/**
	 * In a subdirectory install the shop's pages are matched under it, and nothing outside it is touched.
	 *
	 * @return void
	 */
	public function test_a_subdirectory_install_matches_its_own_paths(): void {
		WP_Stub::$home_url                        = 'https://shop.test/cl/';
		$_COOKIE['wp_woocommerce_session_abc123'] = 't_1|2|3|h';

		$_SERVER['REQUEST_URI'] = '/cl/carrito/';
		$this->assertTrue( self::welaunch_sees_the_store() );

		$_SERVER['REQUEST_URI'] = '/otro-sitio/';
		$this->assertTrue( self::welaunch_sees_the_store(), 'Not this site\'s path.' );

		$_SERVER['REQUEST_URI'] = '/cl/c/zapatillas-mujer/';
		$this->assertFalse( self::welaunch_sees_the_store() );
	}

	/**
	 * Without the cookie there is nothing to set aside.
	 *
	 * @return void
	 */
	public function test_a_first_visit_is_left_alone(): void {
		unset( $_COOKIE['woocommerce_multi_inventory_inventory'] );

		$this->assertFalse( self::welaunch_sees_the_store() );
		$this->assertSame( array(), array_keys( $_COOKIE ) );
	}
}
