<?php
/**
 * Runs when the plugin is deleted from WordPress.
 *
 * On multisite, loop over the sites with get_sites() if the options are stored
 * per site.
 *
 * @package RhinnoIT\Customizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$rcus_options = array(
	'rcus_version',
	'rcus_enabled',
	'rcus_scope_woocommerce',
	'rcus_scope_wordpress',
	// A switch retired by a split, or removed outright, stays listed here: the
	// update keeps its row so a rollback still finds the choice made there.
	// Settings::retired_option_ids() names them, and a test holds this to it.
);

foreach ( $rcus_options as $rcus_option ) {
	delete_option( $rcus_option );
}

/*
 * One throttle transient per asset bundle. A plugin that adds an entry point
 * adds its key here too: Assets appends the entry point name to the prefix.
 */
delete_transient( 'rcus_broken_build_frontend' );
delete_transient( 'rcus_broken_build_admin' );
