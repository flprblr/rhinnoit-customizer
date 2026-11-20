<?php
/**
 * Porto's product widget thumbnails get a srcset.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Widget_Thumbnail;

/**
 * Covers RhinnoIT\Customizer\Frontend\Widget_Thumbnail.
 */
#[CoversClass( Widget_Thumbnail::class )]
final class WidgetThumbnailTest extends TestCase {

	/**
	 * A thumbnail's 85-pixel copy, as on hoka's product page sidebar.
	 */
	const string THUMB = 'https://cl.hoka.com/wp-content/uploads/2026/08/HOKA_HK1164930EYL_VIEW1-85x85.jpg';

	/**
	 * Its hover image.
	 */
	const string HOVER = 'https://cl.hoka.com/wp-content/uploads/2026/08/HOKA_HK1164930EYL_VIEW2-85x85.jpg';

	/**
	 * WordPress's srcset for the first, the same-shaped copies the upload holds.
	 */
	const string SRCSET = 'https://cl.hoka.com/wp-content/uploads/2026/08/HOKA_HK1164930EYL_VIEW1-85x85.jpg 85w, https://cl.hoka.com/wp-content/uploads/2026/08/HOKA_HK1164930EYL_VIEW1-150x150.jpg 150w, https://cl.hoka.com/wp-content/uploads/2026/08/HOKA_HK1164930EYL_VIEW1-400x400.jpg 400w';

	/**
	 * What the tag gets: the copies at least 1.5 times the 85-pixel box.
	 */
	const string SHARP = 'https://cl.hoka.com/wp-content/uploads/2026/08/HOKA_HK1164930EYL_VIEW1-150x150.jpg 150w, https://cl.hoka.com/wp-content/uploads/2026/08/HOKA_HK1164930EYL_VIEW1-400x400.jpg 400w';

	/**
	 * Starts with the switch at its default and Porto's stubs loaded.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$srcsets = array(
			501 => self::SRCSET,
			502 => str_replace( 'VIEW1', 'VIEW2', self::SRCSET ),
		);
	}

	/**
	 * The two tags porto_widget_product_thumbnail() prints, byte for byte as served.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function porto_tags(): array {
		return array(
			'<img src="' . self::THUMB . '" alt="" width="85" height="85" />',
			'<img src="' . self::HOVER . '" alt="" width="85" height="85" class="hover-image" />',
		);
	}

	/**
	 * A service that has seen both lookups, as Porto makes them.
	 *
	 * @return Widget_Thumbnail
	 */
	private static function after_portos_lookups(): Widget_Thumbnail {
		$service = new Widget_Thumbnail();
		$service->remember_thumbnail( array( self::HOVER, 85, 85, true ), 502, 'widget-thumb-medium' );
		$service->remember_thumbnail( array( self::THUMB, 85, 85, true ), 501, 'widget-thumb-medium' );

		return $service;
	}

	/**
	 * It ships on and hooks both halves, the second before Porto's own lazy load.
	 *
	 * @return void
	 */
	public function test_it_ships_on_and_hooks_both_halves(): void {
		( new Widget_Thumbnail() )->register();

		$this->assertSame( array( 'wp_get_attachment_image_src:remember_thumbnail', 'porto_lazy_load_images:add_srcset' ), WP_Stub::$callbacks );
		$this->assertSame( 5, WP_Stub::$priorities['porto_lazy_load_images:add_srcset'], 'Porto\'s lazy load hooks the same filter at 9999.' );
		$this->assertSame( 3, WP_Stub::$registered[0][3], 'Core passes the size third.' );
	}

	/**
	 * Its switch and the master switch turn it off.
	 *
	 * @return void
	 */
	public function test_the_switches_turn_it_off(): void {
		WP_Stub::$options['rcus_widget_thumb_srcset'] = 'no';
		( new Widget_Thumbnail() )->register();
		$this->assertSame( array(), WP_Stub::$hooks );

		WP_Stub::reset();
		WP_Stub::$options['rcus_enabled'] = 'no';
		( new Widget_Thumbnail() )->register();
		$this->assertSame( array(), WP_Stub::$hooks );
	}

	/**
	 * Both of Porto's tags get WordPress's srcset and the box's width.
	 *
	 * @return void
	 */
	public function test_portos_thumbnails_get_a_srcset_and_sizes(): void {
		$service = self::after_portos_lookups();
		list( $thumb, $hover ) = self::porto_tags();

		$html = $service->add_srcset( $thumb );
		$this->assertStringContainsString( 'srcset="' . self::SHARP . '"', $html, 'The 85-pixel copy looked soft even on a 1x screen: the smallest kept is the 150.' );
		$this->assertStringContainsString( 'sizes="85px"', $html );
		$this->assertStringContainsString( 'src="' . self::THUMB . '"', $html, 'A normal screen keeps the 85-pixel file.' );

		$html = $service->add_srcset( $hover );
		$this->assertStringContainsString( 'VIEW2-400x400.jpg 400w', $html );
		$this->assertStringContainsString( 'class="hover-image"', $html );
	}

	/**
	 * Porto's srcset trim leaves nothing for an 85-pixel box, so it is off for this call only.
	 *
	 * The bootstrap's wp_get_attachment_image_srcset() returns false while
	 * the trim is hooked, as core does on a Porto site: 2.7.11 added nothing.
	 *
	 * @return void
	 */
	public function test_portos_srcset_trim_is_set_aside_for_this_call_only(): void {
		$this->assertSame( 10, has_filter( 'wp_calculate_image_srcset', 'porto_image_srcset_filter_sizes' ), 'Porto\'s trim is hooked, as on every Porto request.' );
		$this->assertFalse( wp_get_attachment_image_srcset( 501, 'widget-thumb-medium' ), 'With the trim, core has nothing to offer.' );

		$html = self::after_portos_lookups()->add_srcset( self::porto_tags()[0] );

		$this->assertStringContainsString( 'srcset="' . self::SHARP . '"', $html );
		$this->assertSame( 10, has_filter( 'wp_calculate_image_srcset', 'porto_image_srcset_filter_sizes' ), 'Put back at its own priority for every other image.' );
	}

	/**
	 * Images it did not see looked up, or that already have a srcset, are left as they came.
	 *
	 * @return void
	 */
	public function test_other_images_are_left_alone(): void {
		$service = self::after_portos_lookups();

		$other = '<img src="https://cl.hoka.com/wp-content/uploads/2026/08/other-85x85.jpg" alt="" width="85" height="85" />';
		$this->assertSame( $other, $service->add_srcset( $other ), 'Not a widget-thumb-medium lookup.' );

		$done = '<img src="' . self::THUMB . '" srcset="x 85w" width="85" height="85" />';
		$this->assertSame( $done, $service->add_srcset( $done ) );

		$service->remember_thumbnail( array( self::THUMB, 85, 85, true ), 501, 'thumbnail' );
		$fresh = new Widget_Thumbnail();
		$fresh->remember_thumbnail( array( self::THUMB, 85, 85, true ), 501, 'thumbnail' );
		$this->assertSame( self::porto_tags()[0], $fresh->add_srcset( self::porto_tags()[0] ), 'Another size is never remembered.' );

		WP_Stub::$srcsets = array();
		$this->assertSame( self::porto_tags()[0], $service->add_srcset( self::porto_tags()[0] ), 'An upload with no other copy.' );
	}

	/**
	 * Whatever another filter hands over passes through, without an error.
	 *
	 * @return void
	 */
	public function test_values_it_cannot_read_pass_through(): void {
		$service = self::after_portos_lookups();

		$this->assertSame( '', $service->add_srcset( '' ), 'Porto prints an empty hover image when there is none.' );
		$this->assertNull( $service->add_srcset( null ) );
		$this->assertFalse( $service->remember_thumbnail( false, 501, 'widget-thumb-medium' ) );
		$this->assertSame( array( 1 ), $service->remember_thumbnail( array( 1 ), 501, 'widget-thumb-medium' ) );
		$this->assertSame( array( self::THUMB ), $service->remember_thumbnail( array( self::THUMB ) ), 'Applied with one argument.' );

		WP_Stub::$srcsets = array( 501 => 'https://cl.hoka.com/wp-content/uploads/2026/08/HOKA_HK1164930EYL_VIEW1-85x85.jpg 85w, https://cl.hoka.com/wp-content/uploads/2026/08/HOKA_HK1164930EYL_VIEW1-32x32.jpg 32w' );
		$this->assertSame( self::porto_tags()[0], $service->add_srcset( self::porto_tags()[0] ), 'Only copies too small to help: left as it came.' );

		$no_width = '<img src="' . self::THUMB . '" alt="" />';
		$this->assertSame( $no_width, $service->add_srcset( $no_width ), 'No width, no sizes to give.' );
	}

	/**
	 * At most REMEMBER URLs are kept in one request.
	 *
	 * @return void
	 */
	public function test_it_remembers_a_bounded_number_of_urls(): void {
		$service = new Widget_Thumbnail();

		for ( $i = 0; $i < Widget_Thumbnail::REMEMBER + 50; $i++ ) {
			$service->remember_thumbnail( array( 'https://shop.test/' . $i . '-85x85.jpg', 85, 85, true ), $i + 1, 'widget-thumb-medium' );
		}

		$last = '<img src="https://shop.test/' . ( Widget_Thumbnail::REMEMBER + 10 ) . '-85x85.jpg" width="85" height="85" />';
		$this->assertSame( $last, $service->add_srcset( $last ) );
	}
}
