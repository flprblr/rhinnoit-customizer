<?php
/**
 * The widget thumbnail correction on a theme that is not Porto.
 *
 * Its own process: tests/bootstrap.php loads the Porto stubs unless
 * RCUS_TESTS_PORTO is 'none', and the child inherits the environment.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Widget_Thumbnail;

/**
 * Covers RhinnoIT\Customizer\Frontend\Widget_Thumbnail without Porto.
 */
#[CoversClass( Widget_Thumbnail::class )]
final class WidgetThumbnailWithoutPortoTest extends TestCase {

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
	 * Hooked before the theme loads, it acts once Porto's widget exists.
	 *
	 * Plugins boot on plugins_loaded, before the theme's functions.php: 2.7.10
	 * asked for Porto in register() and hooked nothing on any Porto shop.
	 * Without Porto at the moment of use, the tag is left as it came.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_it_acts_once_the_theme_has_loaded_and_never_without_porto(): void {
		$this->assertFalse( function_exists( 'porto_widget_product_thumbnail' ), 'The child has to boot without Porto or this case means nothing.' );

		WP_Stub::reset();
		WP_Stub::$srcsets = array( 501 => 'https://shop.test/a-85x85.jpg 85w, https://shop.test/a-400x400.jpg 400w' );

		$service = new Widget_Thumbnail();
		$service->register();
		$this->assertSame( array( 'wp_get_attachment_image_src:remember_thumbnail', 'porto_lazy_load_images:add_srcset' ), WP_Stub::$callbacks, 'Hooked at plugins_loaded, before any theme.' );

		$service->remember_thumbnail( array( 'https://shop.test/a-85x85.jpg', 85, 85, true ), 501, 'widget-thumb-medium' );
		$tag = '<img src="https://shop.test/a-85x85.jpg" alt="" width="85" height="85" />';
		$this->assertSame( $tag, $service->add_srcset( $tag ), 'No Porto, nothing changed.' );

		// The theme's functions.php, read after the plugins.
		eval( 'function porto_widget_product_thumbnail() {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Defines the theme function after boot, as WordPress does.

		$this->assertStringContainsString( 'sizes="85px"', $service->add_srcset( $tag ) );
	}
}
