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
	'rcus_rut_validation',
	'rcus_shipping_block',
	'rcus_shipping_block_methods',
	'rcus_shipping_block_communes',
	'rcus_shipping_block_message',
	'rcus_shipping_exclusion',
	'rcus_shipping_exclusion_communes',
	'rcus_shipping_exclusion_message',
	'rcus_unpaid_orders',
	'rcus_fintoc_session_expiry',
	'rcus_widget_thumb_srcset',
	'rcus_widget_sale_price',
	'rcus_birthdate_style',
	'rcus_inventory_quick_view',
	'rcus_inventory_links',
	'rcus_store_choice',
	'rcus_store_choice_delivery',
	'rcus_pickup_stores_in_stock',
	'rcus_size_stores_at_once',
	'rcus_store_stock_hold',
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

/*
 * Left on purpose: the order meta _rwpo_store_units_taken, which
 * Store_Stock_Hold writes on a failed order whose store units WeLaunch kept.
 * It is order data and must survive a reinstall: without it, those orders
 * would count the same units twice. The rwpo_cart_store cookie lives only in
 * browsers.
 */
