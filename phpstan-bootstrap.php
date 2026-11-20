<?php
/**
 * Constants for the static analyser.
 *
 * They are defined at run time in the main plugin file, which PHPStan cannot
 * resolve across files. This file is never loaded by WordPress.
 *
 * @package RhinnoIT\Customizer
 */

define( 'RCUS_VERSION', '1.0.0' );
define( 'RCUS_FILE', __FILE__ );
define( 'RCUS_PATH', __DIR__ . '/' );
define( 'RCUS_URL', 'https://example.test/wp-content/plugins/rhinnoit-customizer/' );
define( 'RCUS_BASENAME', 'rhinnoit-customizer/rhinnoit-customizer.php' );
define( 'RCUS_MENU_PARENT_SLUG', 'rhinnoit-tools' );
define( 'RCUS_MENU_PARENT_TITLE', 'RHINNO IT' );
define( 'RCUS_MENU_TITLE', 'Customizer' );
