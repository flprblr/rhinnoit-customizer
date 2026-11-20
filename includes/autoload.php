<?php
/**
 * Autoloader mapping the plugin namespace to the WordPress file convention.
 *
 * RhinnoIT\Customizer\Assets              -> includes/class-assets.php
 * RhinnoIT\Customizer\Admin\Settings_Page -> includes/admin/class-settings-page.php
 *
 * @package RhinnoIT\Customizer
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'RhinnoIT\\Customizer\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$parts     = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$file_name = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';
		$sub_dir   = empty( $parts ) ? '' : strtolower( implode( '/', $parts ) ) . '/';
		$path      = RCUS_PATH . 'includes/' . $sub_dir . $file_name;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
