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
use RhinnoIT\Customizer\Frontend\Unpaid_Orders;

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
	 * @return void
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
	}

	/**
	 * Declares compatibility with the WooCommerce features.
	 *
	 * Blocks stay declared compatible although Checkout_Focus and
	 * Commune_Select act on the classic (shortcode) checkout only: on the block
	 * checkout they do nothing, and they break nothing. Set
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
			( new Express_Checkout() )->register();
			( new Checkout_Focus() )->register();
			( new Commune_Select() )->register();
			( new Cart_Sale_Price() )->register();
			( new Coupon_Split() )->register();
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
