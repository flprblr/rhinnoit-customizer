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
	'rcus_express_checkout',
	'rcus_express_checkout_all',
	'rcus_cart_sale_price',
	'rcus_cart_coupon_split',
	'rcus_checkout_coupon_prompt',
	'rcus_checkout_login_prompt',
	'rcus_checkout_commune_select',
	'rcus_unpaid_orders',
	'rcus_fintoc_session_expiry',
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
delete_transient( 'rcus_broken_build_admin' );

/*
 * One log window per way the coupon split can step aside, kept apart so
 * neither can silence the other.
 */
delete_transient( 'rcus_coupon_split_filtered' );
delete_transient( 'rcus_coupon_split_table' );
