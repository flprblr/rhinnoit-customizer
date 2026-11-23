<?php
/**
 * Tests for the options contract and the upgrade path.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Install;
use RhinnoIT\Customizer\Settings;

#[CoversClass( Settings::class )]
#[CoversClass( Install::class )]
final class PluginTest extends TestCase {

	protected function setUp(): void {
		WP_Stub::reset();
	}

	public function test_every_option_the_plugin_creates_is_removed_on_uninstall(): void {
		$declared   = array_merge( Settings::option_ids(), Settings::retired_option_ids() );
		$declared[] = Install::VERSION_OPTION;

		$uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );

		foreach ( $declared as $option ) {
			$this->assertStringContainsString(
				"'" . $option . "'",
				$uninstall,
				sprintf( '%s is written by the plugin but never deleted.', $option )
			);
		}

		foreach ( array( 'admin' ) as $bundle ) {
			$this->assertStringContainsString(
				"delete_transient( 'rcus_broken_build_" . $bundle . "' )",
				$uninstall,
				sprintf( 'The %s bundle leaves a throttle transient behind.', $bundle )
			);
		}

		// A log window opened under a literal key is a transient of its own.
		$windows = array();
		$sources = array_merge( glob( dirname( __DIR__ ) . '/includes/*.php' ) ?: array(), glob( dirname( __DIR__ ) . '/includes/*/*.php' ) ?: array() );

		foreach ( $sources as $file ) {
			preg_match_all( "/error_once\(\s*'(rcus_[a-z0-9_]+)'/", (string) file_get_contents( $file ), $found );
			array_push( $windows, ...$found[1] );
		}

		$this->assertContains( 'rcus_coupon_split_table', $windows, 'The scan found none of the windows it knows of, so it checks nothing.' );

		foreach ( $windows as $window ) {
			$this->assertStringContainsString(
				"delete_transient( '" . $window . "' )",
				$uninstall,
				sprintf( 'The %s log window is written by the plugin but never deleted.', $window )
			);
		}
	}

	public function test_defaults_come_from_the_field_definition(): void {
		$this->assertSame(
			array(
				'rcus_enabled'                     => 'yes',
				'rcus_express_checkout'            => 'no',
				'rcus_express_checkout_all'        => 'no',
				'rcus_cart_sale_price'             => 'yes',
				'rcus_cart_coupon_split'           => 'yes',
				'rcus_checkout_coupon_prompt'      => 'no',
				'rcus_checkout_login_prompt'       => 'no',
				'rcus_checkout_commune_select'     => 'yes',
				'rcus_rut_validation'              => 'yes',
				'rcus_shipping_block'              => 'yes',
				'rcus_shipping_block_methods'      => array(),
				'rcus_shipping_block_communes'     => array(),
				// No message, here or below: a translated default is never seeded.
				'rcus_shipping_exclusion'          => 'yes',
				'rcus_shipping_exclusion_communes' => array(),
				'rcus_unpaid_orders'               => 'yes',
				'rcus_fintoc_session_expiry'       => 'yes',
				'rcus_widget_thumb_srcset'         => 'yes',
				'rcus_widget_sale_price'           => 'yes',
				'rcus_birthdate_style'             => 'yes',
				'rcus_inventory_quick_view'        => 'yes',
				'rcus_inventory_links'             => 'yes',
				'rcus_store_choice'                => 'yes',
				'rcus_store_choice_delivery'       => 'yes',
				'rcus_pickup_stores_in_stock'      => 'yes',
				'rcus_size_stores_at_once'         => 'yes',
				'rcus_store_stock_hold'            => 'yes',
			),
			Settings::defaults()
		);
	}

	public function test_every_structural_field_is_flagged_so_it_cannot_become_an_option(): void {
		$structural = 0;

		foreach ( Settings::fields() as $field ) {
			if ( ! in_array( $field['type'] ?? '', array( 'title', 'sectionend', 'info' ), true ) ) {
				continue;
			}

			++$structural;

			$this->assertFalse(
				$field['is_option'] ?? true,
				sprintf(
					'%s is form structure. Without \'is_option\' => false, a crafted post makes '
					. 'Settings_Page::sanitize_submission() store the section id as an option nothing reads.',
					(string) ( $field['id'] ?? '?' )
				)
			);
		}

		$this->assertGreaterThan( 0, $structural, 'The field definition lost its section, so this guard checks nothing.' );
	}

	public function test_each_conditional_section_is_marked_row_by_row(): void {
		// available_fields() drops rows by this flag, one by one. A row of a
		// conditional card without it shows up on a site that lacks what it
		// needs; a WordPress row with one disappears from plain sites. A plugin
		// that adds a conditional card adds its title id and mark here.
		$marks   = array(
			'rcus_woocommerce'     => Settings::REQUIRES_WOOCOMMERCE,
			'rcus_porto'           => Settings::REQUIRES_PORTO,
			'rcus_multi_inventory' => Settings::REQUIRES_MULTI_INVENTORY,
		);
		$current = '';
		$seen    = array();

		foreach ( Settings::fields() as $field ) {
			if ( 'title' === $field['type'] && isset( $marks[ $field['id'] ] ) ) {
				$current = $marks[ $field['id'] ];
				$seen[]  = $field['id'];
			}

			$this->assertSame(
				$current,
				$field['requires'] ?? '',
				sprintf( '%s (%s) carries the wrong section mark.', $field['id'], $field['type'] )
			);

			if ( 'sectionend' === $field['type'] && isset( $marks[ $field['id'] ] ) ) {
				$current = '';
			}
		}

		$this->assertSame( array_keys( $marks ), $seen, 'WooCommerce, then Porto, then WeLaunch Multi Inventory.' );
	}

	public function test_activation_seeds_defaults_without_overwriting_existing_values(): void {
		// The opposite of the declared default, or a reseed would look the same.
		WP_Stub::$options = array( 'rcus_enabled' => 'no' );

		Install::activate();

		$this->assertSame( 'no', WP_Stub::$options['rcus_enabled'], 'An existing choice must survive reactivation.' );
		$this->assertSame( 'no', WP_Stub::$options['rcus_express_checkout'], 'A journey change never switches itself on.' );
		$this->assertSame( RCUS_VERSION, WP_Stub::$options[ Install::VERSION_OPTION ] );
	}

	/**
	 * Activating and deactivating the plugin renew WooCommerce's shipping version, whatever the switches say.
	 *
	 * Deactivated, the plugin's shipping rules stop, and the rates they took
	 * away would stay in every session that stored them; reactivated, the
	 * lists saved before act at once. WooCommerce bumps nothing for either.
	 *
	 * @return void
	 */
	public function test_activation_and_deactivation_renew_the_shipping_version(): void {
		WP_Stub::$transients['shipping-transient-version'] = '1700000000.000000';

		Install::activate();

		$this->assertNotSame( '1700000000.000000', WP_Stub::$transients['shipping-transient-version'], 'Reactivated with lists saved: they act on the stored rates at once.' );

		WP_Stub::$transients['shipping-transient-version'] = '1700000000.000000';
		WP_Stub::$options['rcus_enabled']                  = 'no';
		WP_Stub::$options['rcus_shipping_exclusion']       = 'no';

		Install::deactivate();

		$this->assertNotSame( '1700000000.000000', WP_Stub::$transients['shipping-transient-version'], 'Deactivated: the rates the rules took away come back to every session.' );
	}

	public function test_a_stale_stored_version_reseeds_missing_options(): void {
		WP_Stub::$options = array( Install::VERSION_OPTION => '0.9.0' );

		Install::check_version();

		$this->assertSame( 'yes', WP_Stub::$options['rcus_enabled'] );
		$this->assertSame( RCUS_VERSION, WP_Stub::$options[ Install::VERSION_OPTION ] );
		$this->assertContains( array( Install::DIRTY_HOOK, 'site' ), WP_Stub::$fired, 'New code can print every page differently, and a copied build fires no upgrader hook.' );
	}

	public function test_a_current_version_does_no_work(): void {
		WP_Stub::$options = array( Install::VERSION_OPTION => RCUS_VERSION );

		Install::check_version();

		$this->assertArrayNotHasKey( 'rcus_enabled', WP_Stub::$options, 'Nothing to do when the version matches.' );
		$this->assertSame( array(), WP_Stub::$fired, 'No update, so no page is stale: it runs on every admin page.' );
	}

	/**
	 * Values a corrupt option row can hold.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function unusableEnabledValues(): array {
		return array(
			'empty string' => array( '' ),
			'array'        => array( array( 'a' => 1 ) ),
			'integer'      => array( 42 ),
			'boolean'      => array( true ),
		);
	}

	/**
	 * The switch is read on every storefront request, so it takes whatever the
	 * row holds without fataling and without turning the plugin on. A cast or
	 * wc_string_to_bool() would do neither: an array reads as true, and
	 * strtolower() on one is a TypeError on every page of the shop.
	 *
	 * @param mixed $value Stored option value.
	 * @return void
	 */
	#[DataProvider( 'unusableEnabledValues' )]
	public function test_a_corrupt_switch_leaves_the_plugin_off( $value ): void {
		WP_Stub::$options['rcus_enabled'] = $value;

		$this->assertFalse( Settings::is_enabled() );
	}

	public function test_the_settings_of_wp_optimizer_are_carried_over(): void {
		// A shop that ran RHINNO IT - WP Optimizer, whose shop half moved here.
		// A new slug is a new plugin to WordPress, so without the migration
		// every site would start over from the defaults.
		WP_Stub::$options = array(
			'rwpo_cart_sale_price'  => 'no',
			'rwpo_express_checkout' => 'yes',
			'rwpo_enabled'          => 'no',
			'rwpo_srcset_prune'     => 'no',
		);

		Install::activate();

		$this->assertSame( 'no', WP_Stub::$options['rcus_cart_sale_price'], 'A correction switched off there stays off here.' );
		$this->assertSame( 'yes', WP_Stub::$options['rcus_express_checkout'], 'A journey change switched on there stays on here.' );
		$this->assertSame( 'no', WP_Stub::$options['rcus_enabled'], 'A shop that had switched WP Optimizer off starts with this one off.' );
		$this->assertSame( 'yes', WP_Stub::$options['rcus_cart_coupon_split'], 'An option WP Optimizer never stored gets its default.' );
		$this->assertArrayNotHasKey( 'rcus_srcset_prune', WP_Stub::$options, 'Only this plugin\'s own options come over: that switch is the Optimizer\'s.' );
	}

	public function test_the_migration_never_overwrites_a_value_set_here(): void {
		WP_Stub::$options = array(
			'rwpo_cart_sale_price' => 'no',
			'rcus_cart_sale_price' => 'yes',
		);

		Install::activate();

		$this->assertSame( 'yes', WP_Stub::$options['rcus_cart_sale_price'], 'An older value from the old plugin must not win.' );
	}

	public function test_the_migration_copies_and_never_deletes(): void {
		WP_Stub::$options = array( 'rwpo_cart_sale_price' => 'no' );

		Install::activate();

		// The old plugin may still be active; its own uninstaller owns these.
		$this->assertSame( 'no', WP_Stub::$options['rwpo_cart_sale_price'] );
	}

	public function test_a_site_seeded_from_its_dashboard_gets_the_migration_too(): void {
		// The other sites of a network activation are seeded by check_version()
		// the first time their dashboard loads, so the copy has to come with it.
		WP_Stub::$options = array( 'rwpo_cart_sale_price' => 'no' );

		Install::check_version();

		$this->assertSame( 'no', WP_Stub::$options['rcus_cart_sale_price'] );
	}
}
