<?php
/**
 * Tests for the asset loading of the admin bundle, the only one shipped.
 *
 * The manifest cases were real fatal errors before it was validated, and the
 * helper they exercise is the one every bundle goes through; the rest pin
 * where the bundle is allowed to load, and WooCommerce's selectWoo with it.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Admin\Menu;
use RhinnoIT\Customizer\Assets;
use RhinnoIT\Customizer\Plugin;

#[CoversClass( Assets::class )]
final class AssetsTest extends TestCase {

	protected function setUp(): void {
		WP_Stub::reset();
	}

	/**
	 * Writes build/{$name}.asset.php with an arbitrary payload.
	 *
	 * @param string $php  Literal PHP the manifest returns.
	 * @param string $name Entry point name.
	 * @return void
	 */
	private function manifest( string $php, string $name = 'admin' ): void {
		file_put_contents( RCUS_PATH . 'build/' . $name . '.asset.php', '<?php return ' . $php . ';' );
	}

	/**
	 * Manifests that must never reach wp_enqueue_script().
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function brokenManifests(): array {
		return array(
			'empty file'          => array( '' ),
			'returns a string'    => array( '"oops"' ),
			'returns null'        => array( 'null' ),
			'no dependencies key' => array( "array( 'version' => 'abc' )" ),
			'dependencies string' => array( "array( 'dependencies' => 'jquery', 'version' => 'abc' )" ),
			'version is an array' => array( "array( 'dependencies' => array(), 'version' => array( 'x' ) )" ),
		);
	}

	/**
	 * Broken manifests must not reach wp_enqueue_script().
	 *
	 * @param string $payload Manifest body.
	 * @return void
	 */
	#[DataProvider( 'brokenManifests' )]
	public function test_broken_manifest_degrades_instead_of_fataling( string $payload ): void {
		$_GET = array( 'page' => Menu::PAGE_SLUG );

		if ( '' === $payload ) {
			file_put_contents( RCUS_PATH . 'build/admin.asset.php', "<?php\n" );
		} else {
			$this->manifest( $payload );
		}

		( new Assets() )->enqueue_admin();

		$this->assertSame( array(), WP_Stub::$enqueued, 'Nothing may be enqueued from an unusable manifest.' );
		$this->assertCount( 1, WP_Stub::$logs );
	}

	public function test_truncated_manifest_does_not_escape_as_a_parse_error(): void {
		$_GET = array( 'page' => Menu::PAGE_SLUG );

		file_put_contents( RCUS_PATH . 'build/admin.asset.php', "<?php return array( 'dependencies' =>" );

		( new Assets() )->enqueue_admin();

		$this->assertSame( array(), WP_Stub::$enqueued );
		$this->assertCount( 1, WP_Stub::$logs );
	}

	public function test_missing_build_directory_is_survivable(): void {
		$_GET = array( 'page' => Menu::PAGE_SLUG );

		( new Assets() )->enqueue_admin();

		$this->assertSame( array(), WP_Stub::$enqueued );
	}

	public function test_broken_build_is_logged_once_per_window(): void {
		$_GET = array( 'page' => Menu::PAGE_SLUG );

		for ( $i = 0; $i < 5; $i++ ) {
			( new Assets() )->enqueue_admin();
		}

		$this->assertCount( 1, WP_Stub::$logs, 'A repeated code path must not flood the log.' );
	}

	public function test_the_admin_bundle_loads_only_on_the_page_of_this_plugin(): void {
		$this->manifest( "array( 'dependencies' => array(), 'version' => 'abc' )", 'admin' );

		( new Assets() )->enqueue_admin();

		$this->assertSame( array(), WP_Stub::$enqueued, 'Nothing of this plugin is on screen.' );

		$_GET = array( 'page' => Menu::PAGE_SLUG );

		( new Assets() )->enqueue_admin();

		$this->assertSame( 'rcus-admin', WP_Stub::$enqueued[0]['handle'] ?? '' );
	}

	/**
	 * Nothing loads on another admin screen.
	 *
	 * admin_enqueue_scripts fires on every admin screen, so this is the guard
	 * that keeps the bundle off the rest of wp-admin.
	 *
	 * @return void
	 */
	public function test_nothing_loads_on_another_admin_screen(): void {
		$this->manifest( "array( 'dependencies' => array(), 'version' => 'abc' )", 'admin' );

		foreach ( array( 'edit.php', 'wc-settings', '' ) as $page ) {
			WP_Stub::$enqueued = array();
			$_GET['page']      = $page;

			( new Assets() )->enqueue_admin();

			$this->assertSame( array(), WP_Stub::$enqueued, sprintf( 'Loaded on "%s".', $page ) );
		}
	}

	public function test_the_manifest_version_reaches_the_script(): void {
		$_GET = array( 'page' => Menu::PAGE_SLUG );

		$this->manifest( "array( 'dependencies' => array(), 'version' => 'abc' )" );

		( new Assets() )->enqueue_admin();

		$this->assertSame( 'abc', WP_Stub::$enqueued[0]['ver'] ?? null, 'The build hash is what keeps a browser from running the previous bundle.' );
	}

	public function test_non_string_dependencies_are_filtered_out(): void {
		$_GET = array( 'page' => Menu::PAGE_SLUG );

		$this->manifest( "array( 'dependencies' => array( 'wp-i18n', 42, null ), 'version' => 'abc' )" );

		( new Assets() )->enqueue_admin();

		$this->assertSame( array( 'wp-i18n' ), WP_Stub::$enqueued[0]['deps'] );
	}

	public function test_translations_are_registered_only_for_bundles_that_use_i18n(): void {
		$_GET = array( 'page' => Menu::PAGE_SLUG );

		$this->manifest( "array( 'dependencies' => array( 'jquery' ), 'version' => 'abc' )" );

		( new Assets() )->enqueue_admin();

		$handles = array_column( WP_Stub::$enqueued, 'handle' );
		$this->assertNotContains( 'rcus-admin:i18n', $handles );
	}

	/**
	 * WooCommerce's selectWoo loads on this screen, before the bundle.
	 *
	 * The handles WooCommerce's settings screens enhance a multiselect with, so
	 * this plugin's stylesheet, printed after WooCommerce's, has the last word.
	 *
	 * @return void
	 */
	public function test_woocommerces_select_loads_on_this_screen_before_the_bundle(): void {
		$this->assertTrue( Plugin::woocommerce_is_supported(), 'The shared process runs a supported WooCommerce, or this case means nothing.' );

		$_GET                        = array( 'page' => Menu::PAGE_SLUG );
		WP_Stub::$registered_scripts = array( 'wc-enhanced-select' );
		WP_Stub::$registered_styles  = array( 'woocommerce_admin_styles' );

		$this->manifest( "array( 'dependencies' => array(), 'version' => 'abc' )" );
		file_put_contents( RCUS_PATH . 'build/admin.css', '' );

		( new Assets() )->enqueue_admin();

		$this->assertSame( array( 'wc-enhanced-select', 'rcus-admin' ), array_column( WP_Stub::$enqueued, 'handle' ) );
		$this->assertSame( array( 'enqueue:woocommerce_admin_styles', 'enqueue:rcus-admin' ), WP_Stub::$styles );
	}

	/**
	 * Nowhere else: admin_enqueue_scripts fires on every admin screen, WooCommerce's own included.
	 *
	 * @return void
	 */
	public function test_woocommerces_select_loads_on_no_other_screen(): void {
		WP_Stub::$registered_scripts = array( 'wc-enhanced-select' );
		WP_Stub::$registered_styles  = array( 'woocommerce_admin_styles' );

		foreach ( array( 'wc-settings', 'edit.php', '' ) as $page ) {
			$_GET['page'] = $page;

			( new Assets() )->enqueue_admin();
		}

		$this->assertSame( array(), WP_Stub::$enqueued );
		$this->assertSame( array(), WP_Stub::$styles );
	}

	/**
	 * A handle WooCommerce did not register is left out, and the select stays a native one.
	 *
	 * @return void
	 */
	public function test_a_handle_woocommerce_did_not_register_is_left_out(): void {
		$_GET = array( 'page' => Menu::PAGE_SLUG );

		WP_Stub::$registered_scripts = array( 'wc-enhanced-select' );

		( new Assets() )->enqueue_admin();

		$this->assertSame( array( 'wc-enhanced-select' ), array_column( WP_Stub::$enqueued, 'handle' ) );
		$this->assertSame( array(), WP_Stub::$styles, 'No stylesheet registered, none enqueued.' );

		WP_Stub::$enqueued           = array();
		WP_Stub::$registered_scripts = array();
		WP_Stub::$registered_styles  = array( 'woocommerce_admin_styles' );

		( new Assets() )->enqueue_admin();

		$this->assertSame( array(), WP_Stub::$enqueued, 'No script registered, none enqueued.' );
		$this->assertSame( array( 'enqueue:woocommerce_admin_styles' ), WP_Stub::$styles );
	}

	/**
	 * The screen's assets are enqueued once WooCommerce has registered its own.
	 *
	 * WooCommerce registers woocommerce_admin_styles from an
	 * admin_enqueue_scripts callback at 10 that it adds on init, after this
	 * plugin's, so at 10 the check ran first, found nothing registered, and
	 * the box was never drawn as WooCommerce's.
	 *
	 * @return void
	 */
	public function test_the_screen_enqueues_after_woocommerce_registers(): void {
		( new Assets() )->register();

		$this->assertGreaterThan( 10, WP_Stub::$priorities['admin_enqueue_scripts:enqueue_admin'] ?? 10 );
	}
}
