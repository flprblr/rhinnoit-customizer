<?php
/**
 * Gives Porto's product widget thumbnails a srcset.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lets Retina screens and phones fetch a sharper copy of a widget thumbnail.
 *
 * Porto's porto_widget_product_thumbnail() - the products beside a product
 * page, and any other product widget - builds its <img> by hand from
 * wp_get_attachment_image_src( $id, 'widget-thumb-medium' ): an 85-pixel
 * square, with no srcset. A screen with two or three pixels per CSS pixel
 * stretches those 85 pixels over 170 or 255 and the shoe looks blurred
 * (seen on hoka's product pages). The larger square copies already exist -
 * 150, 400 and up, about 5 KB for the 400 in WebP - but nothing offers them.
 *
 * WordPress's own wp_get_attachment_image_srcset() for that size lists them,
 * and sizes="85px" tells the browser the box, so a normal screen keeps the
 * 85-pixel file and a Retina one picks the first copy that is sharp enough.
 * Porto trims every srcset on wp_calculate_image_srcset to the copies from
 * 320 pixels up to the box's width (porto_image_srcset_filter_sizes): for an
 * 85-pixel box that leaves nothing, core then returns false, and 2.7.11 added
 * nothing. So for this one call the trim is taken off and put back at its own
 * priority, whatever happens; every other srcset on the page keeps it.
 *
 * The 85-pixel copy itself is what looked soft on a 1x screen too: the CDN
 * serves it as a 664-byte WebP and the browser shrinks it to the 84-pixel box
 * (the owner's 1920x1080 monitor, Chrome and Safari, 2026-10-05). So copies
 * narrower than 1.5 times the box leave the srcset: a 1x screen takes the 150
 * (1.4 KB in WebP) and shrinks it, a 2x or 3x one the 400.
 * Porto's markup carries the URL but not the attachment, so the URL each
 * widget-thumb-medium lookup returns is remembered with its attachment, and
 * the srcset is added where Porto passes the tag through porto_lazy_load_images,
 * before its own lazy load (hooked there at 9999 when it is on) rewrites it.
 * An image whose URL was not looked up here, or that has a srcset already,
 * is left as it came. Without Porto it does nothing. Porto is asked for at
 * the moment of use, never in register(): plugins boot on plugins_loaded,
 * before the theme's functions.php is read, and 2.7.10 asked there and hooked
 * nothing on any Porto shop.
 */
final class Widget_Thumbnail {

	/**
	 * The size Porto's widget asks for.
	 */
	const string SIZE = 'widget-thumb-medium';

	/**
	 * Narrowest copy kept, as a multiple of the box: below it a copy looks soft even at 1x.
	 */
	const float MIN_DENSITY = 1.5;

	/**
	 * Most URLs remembered in one request.
	 */
	const int REMEMBER = 200;

	/**
	 * Attachment id by the URL a widget-thumb-medium lookup returned.
	 *
	 * @var array<string, int>
	 */
	private array $seen = array();

	/**
	 * Hooks both halves while the switch is on.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'widget_thumb_srcset' ) ) {
			return;
		}

		// Last, so the URL kept is the one Porto prints after any rewriting filter.
		add_filter( 'wp_get_attachment_image_src', array( $this, 'remember_thumbnail' ), 999, 3 );
		add_filter( 'porto_lazy_load_images', array( $this, 'add_srcset' ), 5 );
	}

	/**
	 * Remembers which attachment a widget-thumb-medium URL belongs to.
	 *
	 * @param mixed $image         URL, width, height and whether it is a resized copy, or false.
	 * @param mixed $attachment_id The attachment.
	 * @param mixed $size          The size asked for.
	 * @return mixed The image, unchanged.
	 */
	public function remember_thumbnail( mixed $image, mixed $attachment_id = 0, mixed $size = '' ): mixed {
		if ( self::SIZE !== $size || ! is_array( $image ) || ! isset( $image[0] ) || ! is_string( $image[0] ) || ! is_numeric( $attachment_id ) || 0 >= (int) $attachment_id ) {
			return $image;
		}

		if ( isset( $this->seen[ $image[0] ] ) || self::REMEMBER > count( $this->seen ) ) {
			$this->seen[ $image[0] ] = (int) $attachment_id;
		}

		return $image;
	}

	/**
	 * Adds WordPress's srcset and the box's width to each remembered thumbnail.
	 *
	 * @param mixed $html The <img> tags Porto built.
	 * @return mixed
	 */
	public function add_srcset( mixed $html ): mixed {
		if ( ! is_string( $html ) || array() === $this->seen || ! str_contains( $html, '<img' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) || ! function_exists( 'porto_widget_product_thumbnail' ) ) {
			return $html;
		}

		$tags    = new \WP_HTML_Tag_Processor( $html );
		$changed = false;

		while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
			$src   = $tags->get_attribute( 'src' );
			$width = $tags->get_attribute( 'width' );

			if ( ! is_string( $src ) || ! isset( $this->seen[ $src ] ) || null !== $tags->get_attribute( 'srcset' ) || ! is_string( $width ) || ! ctype_digit( $width ) ) {
				continue;
			}

			$srcset = self::sharp_enough( self::srcset( $this->seen[ $src ] ), (int) $width );

			if ( '' === $srcset ) {
				continue;
			}

			$tags->set_attribute( 'srcset', $srcset );
			$tags->set_attribute( 'sizes', $width . 'px' );
			$changed = true;
		}

		return $changed ? $tags->get_updated_html() : $html;
	}

	/**
	 * WordPress's srcset for the widget size, without Porto's trim.
	 *
	 * @param int $attachment_id The attachment.
	 * @return string|false
	 */
	private static function srcset( int $attachment_id ): string|false {
		// An int only when Porto hooked it, so the function exists to hook back.
		$porto = has_filter( 'wp_calculate_image_srcset', 'porto_image_srcset_filter_sizes' );

		if ( is_int( $porto ) ) {
			remove_filter( 'wp_calculate_image_srcset', 'porto_image_srcset_filter_sizes', $porto );
		}

		try {
			return wp_get_attachment_image_srcset( $attachment_id, self::SIZE );
		} finally {
			if ( is_int( $porto ) ) {
				add_filter( 'wp_calculate_image_srcset', 'porto_image_srcset_filter_sizes', $porto, 2 );
			}
		}
	}

	/**
	 * The candidates at least MIN_DENSITY times the box, or '' when fewer than one is left.
	 *
	 * @param string|false $srcset WordPress's srcset.
	 * @param int          $box    The box's width in CSS pixels.
	 * @return string
	 */
	private static function sharp_enough( string|false $srcset, int $box ): string {
		if ( ! is_string( $srcset ) || 0 >= $box ) {
			return '';
		}

		$kept = array();

		foreach ( explode( ',', $srcset ) as $candidate ) {
			$candidate = trim( $candidate );

			if ( 1 === preg_match( '/\s(\d+)w$/', $candidate, $width ) && (int) $width[1] >= $box * self::MIN_DENSITY ) {
				$kept[] = $candidate;
			}
		}

		return implode( ', ', $kept );
	}
}
