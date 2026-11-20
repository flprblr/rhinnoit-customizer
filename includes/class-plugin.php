<?php
/**
 * Plugin container.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use RhinnoIT\Customizer\Admin\Menu;
use RhinnoIT\Customizer\Admin\Plugin_Links;
use RhinnoIT\Customizer\Frontend\Cart_Sale_Price;
use RhinnoIT\Customizer\Frontend\Checkout_Focus;
use RhinnoIT\Customizer\Frontend\Commune_Select;
use RhinnoIT\Customizer\Frontend\Coupon_Split;
use RhinnoIT\Customizer\Frontend\Express_Checkout;
use RhinnoIT\Customizer\Frontend\Fintoc_Session_Expiry;
use RhinnoIT\Customizer\Frontend\Inventory_Links;
use RhinnoIT\Customizer\Frontend\Pickup_Stores;
use RhinnoIT\Customizer\Frontend\Quick_View_Store;
use RhinnoIT\Customizer\Frontend\Size_Stores;
use RhinnoIT\Customizer\Frontend\Store_Choice;
use RhinnoIT\Customizer\Frontend\Store_Stock_Hold;
use RhinnoIT\Customizer\Frontend\Unpaid_Orders;
use RhinnoIT\Customizer\Frontend\Widget_Sale_Price;
use RhinnoIT\Customizer\Frontend\Widget_Thumbnail;

defined( 'ABSPATH' ) || exit;

/**
 * Boots and wires every service of the plugin.
 */
final class Plugin {

	/**
	 * Lowest supported WooCommerce version.
	 */
	const string MIN_WC_VERSION = '8.0';

	/**
	 * Constant that RHINNO IT - WP Optimizer, the plugin this one replaces, defines while it is active.
	 *
	 * Written out rather than derived, like Install::LEGACY_PREFIX: it names
	 * what existed before, so it must NOT follow a rename. Renamed to this
	 * plugin's own version constant, which is always defined, the guard in
	 * boot() would hold on every request and the plugin would never run.
	 */
	const string LEGACY_CONSTANT = 'RWPO_VERSION';

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Boots the plugin once.
	 *
	 * Returns nothing on purpose: it runs as an action callback, and WordPress
	 * discards whatever an action returns.
	 *
	 * While WP Optimizer is still active it boots nothing but a notice: that
	 * plugin runs the same features under its own switches, so with both
	 * booted the shop would get each change twice. The activation copy of its
	 * settings is not booted from here, so it still runs. The question is
	 * asked here, on plugins_loaded, and never at file scope: WordPress loads
	 * the active plugins in the order of their folder names, and this one's
	 * comes before WP Optimizer's, so at file scope its constant is not
	 * defined yet.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( defined( self::LEGACY_CONSTANT ) ) {
			add_action( 'admin_notices', array( self::class, 'render_waiting_notice' ) );

			return;
		}

		if ( null === self::$instance ) {
			self::$instance = new self();
		}
	}

	/**
	 * Declares compatibility with the WooCommerce features.
	 *
	 * Blocks stay declared compatible although Checkout_Focus, Commune_Select
	 * and Store_Stock_Hold act on the classic (shortcode) checkout only: on the
	 * block checkout they do nothing, and they break nothing. Set
	 * `cart_checkout_blocks` to false only for a service that would break the
	 * block checkout.
	 *
	 * @return void
	 */
	public static function declare_woocommerce_compatibility(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}

		FeaturesUtil::declare_compatibility( 'custom_order_tables', RCUS_FILE, true );
		FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', RCUS_FILE, true );
	}

	/**
	 * Registers the services.
	 *
	 * Two halves, and only one of them needs WooCommerce. The WordPress half is
	 * the admin bundle alone, so the screen works on any site; every service
	 * acts on the shop and registers only on a WooCommerce this plugin
	 * supports. A shop running an older WooCommerce gets a notice saying why
	 * the plugin does nothing, rather than silence.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		( new Assets() )->register();

		if ( self::woocommerce_is_supported() ) {
			// A service whose job needs WooCommerce registers here and nowhere
			// else, and lives in includes/frontend/. The WordPress half above
			// touches WooCommerce only behind a guard.
			( new Widget_Thumbnail() )->register();
			( new Widget_Sale_Price() )->register();
			( new Express_Checkout() )->register();
			( new Checkout_Focus() )->register();
			( new Commune_Select() )->register();
			( new Cart_Sale_Price() )->register();
			( new Coupon_Split() )->register();
			// The WeLaunch scripts go on WeLaunch's own handle: the three at 99
			// print in the order they register here, then Store_Choice's at 100,
			// which ticks from the radios Size_Stores' script sets.
			( new Inventory_Links() )->register();
			( new Quick_View_Store() )->register();
			( new Size_Stores() )->register();
			( new Store_Choice() )->register();
			( new Pickup_Stores() )->register();
			( new Store_Stock_Hold() )->register();
			( new Unpaid_Orders() )->register();
			( new Fintoc_Session_Expiry() )->register();
		} elseif ( self::woocommerce_is_active() ) {
			add_action( 'admin_notices', array( $this, 'render_unsupported_notice' ) );
		}

		if ( is_admin() ) {
			add_action( 'admin_init', array( Install::class, 'check_version' ) );

			( new Menu() )->register();
			( new Plugin_Links() )->register();
		}
	}

	/**
	 * Loads the plugin translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'rhinnoit-customizer', false, dirname( RCUS_BASENAME ) . '/languages' );
	}

	/**
	 * Shows the notice when WooCommerce is present but too old for this plugin.
	 *
	 * @return void
	 */
	public function render_unsupported_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: minimum WooCommerce version. */
					__( 'Customizer needs WooCommerce %s or higher, and does nothing until then.', 'rhinnoit-customizer' ),
					self::MIN_WC_VERSION
				)
			)
		);
	}

	/**
	 * Shows the notice while WP Optimizer is still active.
	 *
	 * Static, because nothing is booted while it shows: the instance would
	 * register the services. For the same reason it loads the translations
	 * itself, since load_textdomain() is hooked by the constructor.
	 *
	 * @return void
	 */
	public static function render_waiting_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		load_plugin_textdomain( 'rhinnoit-customizer', false, dirname( RCUS_BASENAME ) . '/languages' );

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Customizer waits until WP Optimizer is deactivated, and does nothing until then.', 'rhinnoit-customizer' )
		);
	}

	/**
	 * Whether WooCommerce is loaded at all, whatever its version.
	 *
	 * @return bool
	 */
	public static function woocommerce_is_active(): bool {
		return defined( 'WC_VERSION' );
	}

	/**
	 * Whether WooCommerce is loaded on a version the WooCommerce half supports.
	 *
	 * Public because the settings screen asks the same question to decide
	 * whether to show the WooCommerce section: one answer, read from one place.
	 *
	 * @return bool
	 */
	public static function woocommerce_is_supported(): bool {
		return self::woocommerce_is_active() && version_compare( (string) WC_VERSION, self::MIN_WC_VERSION, '>=' );
	}
}
