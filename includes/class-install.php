<?php
/**
 * Installation and upgrade tasks.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer;

use RhinnoIT\Customizer\Frontend\Shipping_Communes;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the stored options in step with the shipped version.
 */
final class Install {

	/**
	 * Option holding the version the options were last seeded for.
	 */
	const string VERSION_OPTION = Settings::OPTION_PREFIX . 'version';

	/**
	 * Option prefix of the plugin this one replaces, RHINNO IT - WP Optimizer.
	 *
	 * Written out rather than derived: it names what existed before, so it
	 * must NOT follow a rename. Renamed to this plugin's own prefix, the copy
	 * would read every option back into itself and carry nothing over.
	 * Plugin::LEGACY_CONSTANT and the four keys Store_Choice, Store_Stock_Hold
	 * and Cart_Sale_Price use in place keep WP Optimizer's spelling for the
	 * same reason.
	 */
	const string LEGACY_PREFIX = 'rwpo_';

	/**
	 * Group hook that reports a change making cached pages stale.
	 *
	 * No plugin prefix, like the menu constants: every plugin of the group
	 * fires this same hook, with a reason and, for one product, its id, so
	 * whichever plugin clears the page cache hears all of them. With no
	 * listener it does nothing. Must hold the same value in every plugin of
	 * the group. The clearing plugin takes the reasons content, site, stock,
	 * sale and product, and drops any other without a word.
	 */
	const string DIRTY_HOOK = 'rhinnoit_page_cache_dirty';

	/**
	 * Runs on activation.
	 *
	 * Seeds the site it runs on. A network activation on multisite seeds only
	 * the current site; the others get their defaults and migration the first
	 * time their dashboard loads, through check_version(). uninstall.php makes
	 * the same single-site assumption.
	 *
	 * It runs while WP Optimizer is still active too: the guard in
	 * Plugin::boot() holds back the services, not this. The copy has to be
	 * made before WP Optimizer is deleted, because its uninstaller deletes the
	 * rows the copy reads.
	 *
	 * Then it bumps WooCommerce's shipping version, so the shipping rules a
	 * reactivated plugin finds saved act on every shopper's stored rates at
	 * once. The services that hook the same bump to core's activated_plugin
	 * are not registered in the request that activates this plugin.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::seed_defaults();

		Shipping_Communes::refresh_rates();
	}

	/**
	 * Runs on deactivation.
	 *
	 * Deactivating the plugin turns both shipping rules off, and the rates
	 * they took away stay in every shopper's stored rates until WooCommerce's
	 * shipping version changes, which a deactivation does not do by itself.
	 * So it bumps that version, whatever the switches say: what turns a rule
	 * off has to free the rates it hid. Nothing without WooCommerce.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Shipping_Communes::refresh_rates();
	}

	/**
	 * Seeds defaults for options added since the stored version.
	 *
	 * Deploying by copying the plugin folder over an existing install does not
	 * re-run the activation hook, so without this a new option would never get
	 * its default written.
	 *
	 * @return void
	 */
	public static function check_version(): void {
		if ( RCUS_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		self::seed_defaults();

		/**
		 * Reports that every page may print differently now.
		 *
		 * New code can change any page, and a build copied over the folder
		 * fires no upgrader hook for a page cache to see.
		 *
		 * @since 1.0.0
		 * @param string $reason A change to every page.
		 */
		do_action( self::DIRTY_HOOK, 'site' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- A group hook, unprefixed on purpose: see DIRTY_HOOK.
	}

	/**
	 * Writes any missing option and records the version.
	 *
	 * Existing values are left untouched: add_option() only writes when absent.
	 *
	 * @return void
	 */
	private static function seed_defaults(): void {
		self::migrate_legacy_options();
		self::inherit_split_options();

		foreach ( Settings::defaults() as $option => $value ) {
			add_option( $option, $value );
		}

		update_option( self::VERSION_OPTION, RCUS_VERSION );
	}

	/**
	 * Carries the settings of the plugin this one replaces.
	 *
	 * This plugin took over the shop half of RHINNO IT - WP Optimizer, with
	 * the rwpo_ prefix, when it was split in two. A new slug means WordPress
	 * sees a new plugin, so without this every site would start over from the
	 * defaults. It copies, it never moves: the old plugin keeps its own
	 * options until its own uninstaller removes them, which is also what
	 * makes deleting it from the admin safe once this one is activated - this
	 * plugin's prefix is different, so that uninstaller cannot reach these.
	 *
	 * Only this plugin's own options are looked up there. WP Optimizer's other
	 * switches belong to RHINNO IT - Optimizer, and its version row is its
	 * own: this plugin records its version itself.
	 *
	 * A value somebody set here is never overwritten by an older one from
	 * there, and it is add_option() that guarantees it: it writes nothing when
	 * the option already exists. That is also why this runs before the
	 * defaults are written, not after.
	 *
	 * @return void
	 */
	private static function migrate_legacy_options(): void {
		$missing = new \stdClass();

		foreach ( Settings::option_ids() as $option ) {
			$legacy = self::LEGACY_PREFIX . substr( $option, strlen( Settings::OPTION_PREFIX ) );
			$value  = get_option( $legacy, $missing );

			if ( $value === $missing ) {
				continue;
			}

			add_option( $option, $value );
		}
	}

	/**
	 * Hands the value of a retired wide switch down to the switches split from it.
	 *
	 * The pieces of a split switch are new options, so without this they would
	 * get their defaults and a site that had turned the wide one off would find
	 * them back on after the update. add_option() keeps a value already set
	 * here, and Settings::feature() makes the same lookup until this has run.
	 *
	 * The retired rows are left in place. Nothing reads them while this version
	 * runs, but a rollback does, and without them it would reseed those
	 * switches from their defaults. uninstall.php removes them.
	 *
	 * @return void
	 */
	private static function inherit_split_options(): void {
		$missing = new \stdClass();

		foreach ( Settings::inherits() as $option => $old ) {
			$value = get_option( Settings::OPTION_PREFIX . $old, $missing );

			if ( $value !== $missing ) {
				add_option( $option, $value );
			}
		}
	}
}
