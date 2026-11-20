<?php
/**
 * The widget sale price color on a theme that is not Porto.
 *
 * Its own process: tests/bootstrap.php loads the Porto stubs unless
 * RCUS_TESTS_PORTO is 'none', and the child inherits the environment.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Widget_Sale_Price;

/**
 * Covers RhinnoIT\Customizer\Frontend\Widget_Sale_Price without Porto.
 */
#[CoversClass( Widget_Sale_Price::class )]
final class WidgetSalePriceWithoutPortoTest extends TestCase {

	/**
	 * Boots the child without Porto.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		putenv( 'RCUS_TESTS_PORTO=none' );
	}

	/**
	 * Leaves the environment as the rest of the suite expects it.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		putenv( 'RCUS_TESTS_PORTO' );
	}

	/**
	 * Hooked before the theme loads, it adds the rule once Porto's widget exists.
	 *
	 * Plugins boot on plugins_loaded, before the theme's functions.php: 2.7.10
	 * asked for Porto in register() and hooked nothing on any Porto shop.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_it_acts_once_the_theme_has_loaded_and_never_without_porto(): void {
		$this->assertFalse( function_exists( 'porto_widget_product_thumbnail' ), 'The child has to boot without Porto or this case means nothing.' );

		WP_Stub::reset();
		$service = new Widget_Sale_Price();
		$service->register();
		$this->assertSame( array( 'wp_enqueue_scripts:add_style' ), WP_Stub::$callbacks, 'Hooked at plugins_loaded, before any theme.' );

		$service->add_style();
		$this->assertSame( array(), WP_Stub::$inline_styles, 'No Porto, no rule of Porto\'s to correct.' );

		// The theme's functions.php, read after the plugins.
		eval( 'function porto_widget_product_thumbnail() {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Defines the theme function after boot, as WordPress does.

		$service->add_style();
		$this->assertArrayHasKey( 'rcus-widget-sale-price', WP_Stub::$inline_styles );
	}
}
