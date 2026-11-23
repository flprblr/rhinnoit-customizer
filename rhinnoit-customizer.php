<?php
/**
 * Plugin Name:          RHINNO IT - Customizer
 * Description:          Adjusts the WooCommerce cart, checkout, orders and store pickup.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         8.3
 * Author:               RHINNO IT
 * Author URI:           mailto:dev@rhinnoit.cloud
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          rhinnoit-customizer
 * Domain Path:          /languages
 * Update URI:           false
 * WC requires at least: 8.0
 * WC tested up to:      11.0
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer;

defined( 'ABSPATH' ) || exit;

define( 'RCUS_VERSION', '1.0.0' );
define( 'RCUS_FILE', __FILE__ );
define( 'RCUS_PATH', plugin_dir_path( __FILE__ ) );
define( 'RCUS_URL', plugin_dir_url( __FILE__ ) );
define( 'RCUS_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Admin menu.
 *
 * Every plugin in the group shares the parent node: keep the same slug and
 * title in all of them and change only RCUS_MENU_TITLE. The first one to load
 * creates the node and the rest hang off it.
 */
define( 'RCUS_MENU_PARENT_SLUG', 'rhinnoit-tools' );
define( 'RCUS_MENU_PARENT_TITLE', 'RHINNO IT' );
define( 'RCUS_MENU_TITLE', 'Customizer' );

require_once RCUS_PATH . 'includes/autoload.php';

register_activation_hook( RCUS_FILE, array( Install::class, 'activate' ) );
register_deactivation_hook( RCUS_FILE, array( Install::class, 'deactivate' ) );

// Feature flags must be declared before WooCommerce boots.
add_action( 'before_woocommerce_init', array( Plugin::class, 'declare_woocommerce_compatibility' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
