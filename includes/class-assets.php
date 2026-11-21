<?php
/**
 * Enqueueing of the scripts and styles built by @wordpress/scripts.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer;

use RhinnoIT\Customizer\Admin\Menu;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the build/ bundles only where they are needed, and WooCommerce's selectWoo beside the admin one.
 */
final class Assets {

	/**
	 * Transient that throttles the broken build log entry, one per bundle.
	 *
	 * The entry point name is appended. A single shared key meant the first
	 * admin page view of the hour silenced the storefront for the rest of it,
	 * so a shop deployed without a build logged nothing about the shop.
	 */
	const string BROKEN_BUILD_FLAG = 'rcus_broken_build_';

	/**
	 * WooCommerce's script that turns a select with the wc-enhanced-select class into selectWoo's searchable box.
	 */
	const string SELECT_SCRIPT = 'wc-enhanced-select';

	/**
	 * WooCommerce's admin stylesheet, which draws that box.
	 */
	const string SELECT_STYLE = 'woocommerce_admin_styles';

	/**
	 * Hooks the enqueueing.
	 *
	 * @return void
	 */
	public function register(): void {
		// At 20: WooCommerce registers its admin stylesheet from a callback at
		// 10 that it adds on init, after this one, so at 10 this ran first and
		// found nothing registered.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ), 20 );
	}

	/**
	 * Admin assets, only on the plugin page.
	 *
	 * @return void
	 */
	public function enqueue_admin(): void {
		if ( ! Menu::is_current_page() ) {
			return;
		}

		if ( Plugin::woocommerce_is_supported() ) {
			$this->enqueue_woocommerce_select();
		}

		$this->enqueue( 'admin' );
	}

	/**
	 * WooCommerce's own selectWoo, for the multiselects of the settings form.
	 *
	 * The script and stylesheet WooCommerce's settings screens enhance a
	 * multiselect with, so a list on this screen is the box of WooCommerce's
	 * "Sell to specific countries" setting: searchable, its choices kept as
	 * chips with an x. Each is enqueued only if WooCommerce registered it; a
	 * WooCommerce that renamed one leaves a native multiple select, which
	 * posts the same. Only where WooCommerce is supported, since everywhere
	 * else the form draws the master switch alone, and before the bundle, so
	 * this plugin's stylesheet comes after WooCommerce's.
	 *
	 * @return void
	 */
	private function enqueue_woocommerce_select(): void {
		if ( wp_script_is( self::SELECT_SCRIPT, 'registered' ) ) {
			wp_enqueue_script( self::SELECT_SCRIPT );
		}

		if ( wp_style_is( self::SELECT_STYLE, 'registered' ) ) {
			wp_enqueue_style( self::SELECT_STYLE );
		}
	}

	/**
	 * Enqueues the bundle and its stylesheet when they exist.
	 *
	 * @param string $name Entry point name (build/{$name}.js).
	 * @return string Registered handle, or an empty string when the build is missing.
	 */
	private function enqueue( string $name ): string {
		$asset = $this->read_manifest( $name );

		if ( null === $asset ) {
			return '';
		}

		$handle = 'rcus-' . $name;

		wp_enqueue_script(
			$handle,
			RCUS_URL . 'build/' . $name . '.js',
			$asset['dependencies'],
			$asset['version'],
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Only bundles that import @wordpress/i18n get wp-i18n from the build.
		if ( in_array( 'wp-i18n', $asset['dependencies'], true ) ) {
			wp_set_script_translations( $handle, 'rhinnoit-customizer', RCUS_PATH . 'languages' );
		}

		if ( is_readable( RCUS_PATH . 'build/' . $name . '.css' ) ) {
			wp_enqueue_style( $handle, RCUS_URL . 'build/' . $name . '.css', array(), $asset['version'] );

			// The build emits build/*-rtl.css; WordPress uses it on RTL locales.
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}

		return $handle;
	}

	/**
	 * Reads and validates build/{$name}.asset.php.
	 *
	 * A truncated or half-uploaded build would otherwise reach in_array() with
	 * a non-array and raise a fatal error on every view of the settings screen.
	 *
	 * @param string $name Entry point name.
	 * @return array{dependencies: list<string>, version: string}|null Null when the manifest is unusable.
	 */
	private function read_manifest( string $name ): ?array {
		$file = RCUS_PATH . 'build/' . $name . '.asset.php';

		if ( ! is_readable( $file ) ) {
			$this->log_broken_build( $name, $file );

			return null;
		}

		try {
			$asset = require $file;
		} catch ( \Throwable $e ) {
			// A file truncated mid-upload raises a ParseError, which would
			// otherwise be fatal on every request that enqueues this bundle.
			$this->log_broken_build( $name, $file );

			return null;
		}

		if ( ! is_array( $asset )
			|| ! isset( $asset['dependencies'], $asset['version'] )
			|| ! is_array( $asset['dependencies'] )
			|| ! is_string( $asset['version'] ) ) {
			$this->log_broken_build( $name, $file );

			return null;
		}

		return array(
			'dependencies' => array_values( array_filter( $asset['dependencies'], 'is_string' ) ),
			'version'      => $asset['version'],
		);
	}

	/**
	 * Logs a broken build once an hour at most, per bundle.
	 *
	 * Per bundle because bundles fail independently: an entry point added next
	 * to the admin one gets a window of its own, instead of being hidden by the
	 * admin screen's for the rest of the hour.
	 *
	 * @param string $name Entry point name.
	 * @param string $file Manifest path.
	 * @return void
	 */
	private function log_broken_build( string $name, string $file ): void {
		Logger::error_once(
			self::BROKEN_BUILD_FLAG . $name,
			sprintf( '%s is missing or invalid. Run "npm run build".', $file )
		);
	}
}
