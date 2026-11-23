<?php
/**
 * Constants and theme functions for the static analyser.
 *
 * The constants are defined at run time in the main plugin file, which
 * PHPStan cannot resolve across files; the functions belong to the Porto
 * theme, which it never reads. This file is never loaded by WordPress.
 *
 * @package RhinnoIT\Customizer
 */

define( 'RCUS_VERSION', '2.0.0' );
define( 'RCUS_FILE', __FILE__ );
define( 'RCUS_PATH', __DIR__ . '/' );
define( 'RCUS_URL', 'https://example.test/wp-content/plugins/rhinnoit-customizer/' );
define( 'RCUS_BASENAME', 'rhinnoit-customizer/rhinnoit-customizer.php' );
define( 'RCUS_MENU_PARENT_SLUG', 'rhinnoit-tools' );
define( 'RCUS_MENU_PARENT_TITLE', 'RHINNO IT' );
define( 'RCUS_MENU_TITLE', 'Customizer' );

/**
 * Porto's srcset trim (inc/functions/general.php), which Widget_Thumbnail sets aside and hooks back.
 *
 * @param array<int, array<string, mixed>> $sources    Candidates.
 * @param array<int, int>                  $size_array Box.
 * @return array<int, array<string, mixed>>
 */
function porto_image_srcset_filter_sizes( array $sources, array $size_array ): array {
	return $sources;
}
