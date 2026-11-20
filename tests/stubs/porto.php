<?php
/**
 * The slice of the Porto theme the plugin touches.
 *
 * Required by tests/bootstrap.php unless RCUS_TESTS_PORTO is 'none'.
 *
 * @package RhinnoIT\Customizer
 */

/**
 * Porto's product widget thumbnail: it exists, and the correction asks no more of it.
 *
 * @return void
 */
function porto_widget_product_thumbnail() {}

/**
 * Porto's srcset trim: it exists, and the bootstrap reproduces what it does.
 *
 * @param mixed $sources    Candidates.
 * @param mixed $size_array Box.
 * @return mixed
 */
function porto_image_srcset_filter_sizes( $sources, $size_array ) {
	return $sources;
}
