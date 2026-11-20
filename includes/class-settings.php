<?php
/**
 * Definition and reading of the plugin options.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer;

use RhinnoIT\Customizer\Frontend\Coupon_Split;

defined( 'ABSPATH' ) || exit;

/**
 * Single place where the options are declared. The UI lives in Admin\Settings_Page.
 */
final class Settings {

	/**
	 * Prefix of the options in wp_options.
	 */
	const string OPTION_PREFIX = 'rcus_';

	/**
	 * Value of 'requires' on every row of the WooCommerce section.
	 */
	const string REQUIRES_WOOCOMMERCE = 'woocommerce';

	/**
	 * Marks the rows of the Porto section: WooCommerce features on Porto's own markup.
	 *
	 * Also a need of the one WeLaunch switch that acts on Porto's quick view.
	 */
	const string REQUIRES_PORTO = 'porto';

	/**
	 * Marks the rows of the WeLaunch Multi Inventory section.
	 */
	const string REQUIRES_MULTI_INVENTORY = 'multi_inventory';

	/**
	 * A switch that needs Fintoc for WooCommerce: it hooks Fintoc's own filter.
	 */
	const string NEEDS_FINTOC = 'fintoc';

	/**
	 * A switch that needs WooCommerce 8.8+, whose WC_Discounts hands itself to woocommerce_coupon_get_items_to_apply.
	 */
	const string NEEDS_COUPON_ITEMS = 'coupon_items';

	/**
	 * Keys of the switches removed outright, without the prefix.
	 *
	 * A switch removed with nothing to inherit it goes here, so its row stays
	 * listed for uninstall.php and a form drawn before the update is refused.
	 *
	 * @var array<int, string>
	 */
	const array REMOVED = array();

	/**
	 * Field definition.
	 *
	 * The master switch alone in an untitled card, then WooCommerce; Porto, for
	 * features on the theme's own WooCommerce markup; and WeLaunch Multi
	 * Inventory. There is no WordPress card: nothing this plugin does works
	 * without WooCommerce. Every row of the last three, its title and
	 * sectionend included, carries 'requires' with its section's mark, and
	 * available_fields() drops a section whole on a site without what it
	 * needs. A feature that works on Porto's or WeLaunch's own output goes in
	 * their section, not in WooCommerce's.
	 *
	 * An option that can only act with something its section does not promise
	 * - a plugin, the host, the kind of theme, a core version - also carries
	 * 'needs', a list of marks, and is left off the screen where any of them is
	 * missing, so the screen only offers what can apply on the site. The mark
	 * is the condition the feature itself stops at, read from what is
	 * installed, never from another plugin's settings. See conditions().
	 *
	 * Every switch ships on except the four that change the shopper's journey:
	 * skipping the cart and hiding the checkout's coupon and login prompts
	 * (owner's decision, 2026-10-16).
	 *
	 * 'group' is the small label the screen prints above a run of related
	 * switches inside a section; consecutive fields share one label.
	 *
	 * The structural rows carry 'is_option' => false, which is the flag
	 * Settings_Page::sanitize_submission() checks. Without it a crafted post
	 * stores the section id in wp_options, where nothing reads it and
	 * uninstall.php never deletes it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			array(
				'id'        => self::OPTION_PREFIX . 'general',
				'type'      => 'title',
				'is_option' => false,
			),
			array(
				'id'      => self::OPTION_PREFIX . 'enabled',
				'type'    => 'checkbox',
				'title'   => __( 'Enable', 'rhinnoit-customizer' ),
				'desc'    => __( 'If you turn it off, everything below stops, without deactivating the plugin.', 'rhinnoit-customizer' ),
				'default' => 'yes',
			),
			array(
				'id'        => self::OPTION_PREFIX . 'general',
				'type'      => 'sectionend',
				'is_option' => false,
			),

			array(
				'id'        => self::OPTION_PREFIX . 'woocommerce',
				'type'      => 'title',
				'title'     => __( 'WooCommerce', 'rhinnoit-customizer' ),
				'is_option' => false,
				'requires'  => self::REQUIRES_WOOCOMMERCE,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'express_checkout',
				'type'     => 'checkbox',
				'group'    => __( 'Cart and checkout', 'rhinnoit-customizer' ),
				'title'    => __( 'Skip the cart for virtual products', 'rhinnoit-customizer' ),
				'desc'     => __( 'After adding a product, shoppers go straight to checkout if the cart holds only virtual products.', 'rhinnoit-customizer' ),
				'default'  => 'no',
				'requires' => self::REQUIRES_WOOCOMMERCE,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'express_checkout_all',
				'type'     => 'checkbox',
				'group'    => __( 'Cart and checkout', 'rhinnoit-customizer' ),
				'title'    => __( 'Skip the cart for any product', 'rhinnoit-customizer' ),
				'desc'     => __( 'After adding any product, physical ones included, shoppers go straight to checkout.', 'rhinnoit-customizer' ),
				'default'  => 'no',
				'requires' => self::REQUIRES_WOOCOMMERCE,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'cart_sale_price',
				'type'     => 'checkbox',
				'group'    => __( 'Cart and checkout', 'rhinnoit-customizer' ),
				'title'    => __( 'Show the regular price struck through', 'rhinnoit-customizer' ),
				'desc'     => __( 'In the mini cart, cart and checkout, products on sale keep showing their discount. Check all three on a phone.', 'rhinnoit-customizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_WOOCOMMERCE,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'cart_coupon_split',
				'type'     => 'checkbox',
				'group'    => __( 'Cart and checkout', 'rhinnoit-customizer' ),
				'title'    => __( 'Split cart coupons by price', 'rhinnoit-customizer' ),
				'desc'     => __( 'A fixed cart discount is shared out by each product\'s price, so a cheap product never ends up free.', 'rhinnoit-customizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_WOOCOMMERCE,
				'needs'    => array( self::NEEDS_COUPON_ITEMS ),
			),
			array(
				'id'       => self::OPTION_PREFIX . 'checkout_coupon_prompt',
				'type'     => 'checkbox',
				'group'    => __( 'Cart and checkout', 'rhinnoit-customizer' ),
				'title'    => __( 'Hide the coupon prompt', 'rhinnoit-customizer' ),
				'desc'     => __( 'If the cart holds only virtual products, the classic checkout does not show the coupon field.', 'rhinnoit-customizer' ),
				'default'  => 'no',
				'requires' => self::REQUIRES_WOOCOMMERCE,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'checkout_login_prompt',
				'type'     => 'checkbox',
				'group'    => __( 'Cart and checkout', 'rhinnoit-customizer' ),
				'title'    => __( 'Hide the login prompt', 'rhinnoit-customizer' ),
				'desc'     => __( 'If the cart holds only virtual products, the classic checkout does not invite shoppers to log in.', 'rhinnoit-customizer' ),
				'default'  => 'no',
				'requires' => self::REQUIRES_WOOCOMMERCE,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'checkout_commune_select',
				'type'     => 'checkbox',
				'group'    => __( 'Cart and checkout', 'rhinnoit-customizer' ),
				'title'    => __( 'Pick the commune from a list', 'rhinnoit-customizer' ),
				'desc'     => __( 'For an address in Chile, the classic checkout offers the chosen region\'s communes in a dropdown, like the region. Check the checkout on a phone.', 'rhinnoit-customizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_WOOCOMMERCE,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'unpaid_orders',
				'type'     => 'checkbox',
				'group'    => __( 'Orders', 'rhinnoit-customizer' ),
				'title'    => __( 'Cancel unpaid orders on time', 'rhinnoit-customizer' ),
				'desc'     => __( 'With a stock hold of 15 minutes or more, unpaid orders are cancelled soon after it ends.', 'rhinnoit-customizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_WOOCOMMERCE,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'fintoc_session_expiry',
				'type'     => 'checkbox',
				'group'    => __( 'Orders', 'rhinnoit-customizer' ),
				'title'    => __( 'Avoid late Fintoc payments', 'rhinnoit-customizer' ),
				'desc'     => __( 'The time to pay with Fintoc ends with the stock hold, instead of lasting 24 hours.', 'rhinnoit-customizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_WOOCOMMERCE,
				'needs'    => array( self::NEEDS_FINTOC ),
			),
			array(
				'id'        => self::OPTION_PREFIX . 'woocommerce',
				'type'      => 'sectionend',
				'is_option' => false,
				'requires'  => self::REQUIRES_WOOCOMMERCE,
			),
			array(
				'id'        => self::OPTION_PREFIX . 'porto',
				'type'      => 'title',
				'title'     => __( 'Porto', 'rhinnoit-customizer' ),
				'is_option' => false,
				'requires'  => self::REQUIRES_PORTO,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'widget_thumb_srcset',
				'type'     => 'checkbox',
				'group'    => __( 'Product widgets', 'rhinnoit-customizer' ),
				'title'    => __( 'Show sharp thumbnails', 'rhinnoit-customizer' ),
				'desc'     => __( 'Thumbnails in widgets load at a higher resolution and stop looking blurry.', 'rhinnoit-customizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_PORTO,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'widget_sale_price',
				'type'     => 'checkbox',
				'group'    => __( 'Product widgets', 'rhinnoit-customizer' ),
				'title'    => __( 'Match the sale price color', 'rhinnoit-customizer' ),
				'desc'     => __( 'The sale price in widgets uses the same color as on shop and category pages.', 'rhinnoit-customizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_PORTO,
			),
			array(
				'id'        => self::OPTION_PREFIX . 'porto',
				'type'      => 'sectionend',
				'is_option' => false,
				'requires'  => self::REQUIRES_PORTO,
			),
			array(
				'id'        => self::OPTION_PREFIX . 'multi_inventory',
				'type'      => 'title',
				'title'     => __( 'WeLaunch Multi Inventory', 'rhinnoit-customizer' ),
				'is_option' => false,
				'requires'  => self::REQUIRES_MULTI_INVENTORY,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'inventory_quick_view',
				'type'     => 'checkbox',
				'group'    => __( 'Store picked', 'rhinnoit-customizer' ),
				'title'    => __( 'Keep the quick view\'s store choice', 'rhinnoit-customizer' ),
				'desc'     => __( 'Adding from the quick view keeps the store the shopper picked.', 'rhinnoit-customizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_MULTI_INVENTORY,
				'needs'    => array( self::REQUIRES_PORTO ),
			),
			array(
				'id'       => self::OPTION_PREFIX . 'inventory_links',
				'type'     => 'checkbox',
				'group'    => __( 'Store picked', 'rhinnoit-customizer' ),
				'title'    => __( 'Remove the store from links', 'rhinnoit-customizer' ),
				'desc'     => __( 'Links do not carry the last store used, so pages can keep loading from the cache.', 'rhinnoit-customizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_MULTI_INVENTORY,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'store_choice',
				'type'     => 'checkbox',
				'group'    => __( 'Store picked', 'rhinnoit-customizer' ),
				'title'    => __( 'Let the shopper choose', 'rhinnoit-customizer' ),
				'desc'     => __( 'Pickup stores never come ticked, and adding to the cart needs home delivery or a store ticked.', 'rhinnoit-customizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_MULTI_INVENTORY,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'store_choice_delivery',
				'type'     => 'checkbox',
				'group'    => __( 'Store picked', 'rhinnoit-customizer' ),
				'title'    => __( 'Tick home delivery when in stock', 'rhinnoit-customizer' ),
				'desc'     => __( 'Home delivery comes ticked when it has the size picked and the cart is empty or for home delivery. Requires "Let the shopper choose".', 'rhinnoit-customizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_MULTI_INVENTORY,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'pickup_stores_in_stock',
				'type'     => 'checkbox',
				'group'    => __( 'Store picked', 'rhinnoit-customizer' ),
				'title'    => __( 'Hide stores without the size', 'rhinnoit-customizer' ),
				'desc'     => __( 'Pickup stores show only if they have the size picked, and home delivery always does. Check the product page.', 'rhinnoit-customizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_MULTI_INVENTORY,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'size_stores_at_once',
				'type'     => 'checkbox',
				'group'    => __( 'Store picked', 'rhinnoit-customizer' ),
				'title'    => __( 'Show each size\'s stores instantly', 'rhinnoit-customizer' ),
				'desc'     => __( 'Shows which stores have the size as soon as it is picked, without waiting.', 'rhinnoit-customizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_MULTI_INVENTORY,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'store_stock_hold',
				'type'     => 'checkbox',
				'group'    => __( 'Stock', 'rhinnoit-customizer' ),
				'title'    => __( 'Hold each store\'s stock', 'rhinnoit-customizer' ),
				'desc'     => __( 'Keeps two shoppers from paying for a store\'s last unit at the same time.', 'rhinnoit-customizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_MULTI_INVENTORY,
			),
			array(
				'id'        => self::OPTION_PREFIX . 'multi_inventory',
				'type'      => 'sectionend',
				'is_option' => false,
				'requires'  => self::REQUIRES_MULTI_INVENTORY,
			),
		);
	}

	/**
	 * The fields this site can use: fields() minus what it lacks.
	 *
	 * The form draws and saves this list, so on a site without WooCommerce,
	 * Porto or WeLaunch Multi Inventory that section is neither shown nor
	 * writable, and neither is an option whose 'needs' the site does not meet
	 * - both keep whatever value they had, ready for the day what they need is
	 * installed. See fields_for_marks() for the rules.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function available_fields(): array {
		return self::fields_for_marks( self::fields(), self::conditions() );
	}

	/**
	 * The fields a site with these marks can use, in order.
	 *
	 * A field goes when its 'requires' or any of its 'needs' names a mark the
	 * site lacks, and a mark nobody declared counts as missing. Then a section
	 * left with no option goes whole, its title and sectionend included: a
	 * card with nothing to switch is only noise on the screen, and the options
	 * it would hold keep their rows anyway.
	 *
	 * Pure, so the cases can hand it fields and marks no plugin ships.
	 *
	 * @param array<int, array<string, mixed>> $fields  Field definitions, in order.
	 * @param array<string, bool>              $present Mark => whether the site has it.
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields_for_marks( array $fields, array $present ): array {
		$kept    = array();
		$section = array();
		$options = 0;

		foreach ( $fields as $field ) {
			if ( ! self::has_marks( $field, $present ) ) {
				continue;
			}

			$type = $field['type'] ?? '';

			if ( 'title' === $type ) {
				// A section nobody closed keeps what it holds rather than losing it.
				if ( 0 < $options ) {
					array_push( $kept, ...$section );
				}

				$section = array( $field );
				$options = 0;

				continue;
			}

			if ( array() === $section ) {
				$kept[] = $field;

				continue;
			}

			$section[] = $field;

			if ( false !== ( $field['is_option'] ?? true ) ) {
				++$options;
			}

			if ( 'sectionend' === $type ) {
				if ( 0 < $options ) {
					array_push( $kept, ...$section );
				}

				$section = array();
				$options = 0;
			}
		}

		if ( 0 < $options ) {
			array_push( $kept, ...$section );
		}

		return $kept;
	}

	/**
	 * Whether the site has every mark a field names.
	 *
	 * @param array<string, mixed> $field   One field definition.
	 * @param array<string, bool>  $present Mark => whether the site has it.
	 * @return bool
	 */
	private static function has_marks( array $field, array $present ): bool {
		$marks   = is_array( $field['needs'] ?? null ) ? $field['needs'] : array();
		$marks[] = $field['requires'] ?? '';

		foreach ( $marks as $mark ) {
			if ( ! is_string( $mark ) || ! ( $present[ $mark ] ?? false ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Mark => whether this site has it, for every 'requires' and 'needs' mark.
	 *
	 * Read when the screen is drawn or saved, long after the theme and every
	 * plugin loaded, and from what is installed: a plugin's constant, the host,
	 * the theme's kind, a core function, WooCommerce's version and the class a
	 * feature writes into. Each answer is the one the feature itself stops at,
	 * so a field is hidden exactly where it would do nothing.
	 * The empty mark is the one a field without 'requires' carries.
	 *
	 * Each 'needs' mark is a NEEDS_* constant, or a section mark such as
	 * REQUIRES_PORTO, answered here in the same change as the first field
	 * that names one: a mark this list does not answer hides its field on
	 * every site. Porto's and WeLaunch's sections are WooCommerce features, so
	 * they need WooCommerce too.
	 *
	 * @return array<string, bool>
	 */
	public static function conditions(): array {
		$woocommerce = Plugin::woocommerce_is_supported();

		return array(
			''                             => true,
			self::REQUIRES_WOOCOMMERCE     => $woocommerce,
			self::REQUIRES_PORTO           => $woocommerce && 'porto' === get_template(),
			self::REQUIRES_MULTI_INVENTORY => $woocommerce && function_exists( 'welaunch_woocommerce_multi_inventory_run' ),
			// Defined when Fintoc's main file loads; its filter fires only from its gateway.
			self::NEEDS_FINTOC             => defined( 'FINTOC_WC_VERSION' ),
			self::NEEDS_COUPON_ITEMS       => $woocommerce && Coupon_Split::can_split(),
		);
	}

	/**
	 * Ids of every option the settings form can write.
	 *
	 * Read from the declaration, not from the defaults: a field without a
	 * 'default' is still an option, so deriving the list from defaults() left
	 * one invisible to the uninstall guard.
	 *
	 * @return array<int, string>
	 */
	public static function option_ids(): array {
		$ids = array();

		foreach ( self::fields() as $field ) {
			// The same rows Settings_Page::sanitize_submission() skips.
			if ( ! isset( $field['id'], $field['type'] ) || false === ( $field['is_option'] ?? true ) ) {
				continue;
			}

			$ids[] = (string) $field['id'];
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Ids of the options an older version had and this one does not.
	 *
	 * Every 'inherits' key that is no longer an option of its own, plus the
	 * switches removed outright. Nothing deletes them on the update, so a
	 * rollback still finds the choice made there; uninstall.php lists them, and
	 * a test holds it to that.
	 *
	 * @return array<int, string>
	 */
	public static function retired_option_ids(): array {
		$current = self::option_ids();
		$retired = array_map(
			static fn ( string $key ): string => self::OPTION_PREFIX . $key,
			self::REMOVED
		);

		foreach ( self::fields() as $field ) {
			if ( ! isset( $field['inherits'] ) || ! is_string( $field['inherits'] ) ) {
				continue;
			}

			$id = self::OPTION_PREFIX . $field['inherits'];

			if ( ! in_array( $id, $current, true ) ) {
				$retired[] = $id;
			}
		}

		return array_values( array_unique( $retired ) );
	}

	/**
	 * Default values derived from the field definition.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		// fields() is a literal, so this never changes within a request, and
		// feature() asks for it once per feature on every storefront hit.
		static $defaults = null;

		if ( null !== $defaults ) {
			return $defaults;
		}

		$defaults = array();

		foreach ( self::fields() as $field ) {
			if ( isset( $field['id'], $field['default'] ) ) {
				$defaults[ $field['id'] ] = $field['default'];
			}
		}

		return $defaults;
	}

	/**
	 * Reads one of the plugin options.
	 *
	 * @param string $key           Key without the prefix.
	 * @param mixed  $default_value Value returned when the option does not exist.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default_value = '' ): mixed {
		return get_option( self::OPTION_PREFIX . $key, $default_value );
	}

	/**
	 * Tells whether the plugin should apply its changes.
	 *
	 * A row never saved reads as the master switch's declared default, the
	 * same fallback feature() makes for every other switch: a plugin that
	 * ships it on runs before anyone saves the screen, and one that ships it
	 * off does not. Only the field says which, so this file stays the same in
	 * every plugin of the group.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return 'yes' === self::get( 'enabled', self::defaults()[ self::OPTION_PREFIX . 'enabled' ] ?? 'no' );
	}

	/**
	 * Tells whether one feature is switched on.
	 *
	 * Every feature answers no while the master switch is off, so a caller
	 * never has to test both.
	 *
	 * @param string $feature Key without the prefix.
	 * @return bool
	 */
	public static function feature( string $feature ): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$missing = self::missing();
		$value   = self::get( $feature, $missing );

		if ( $missing === $value ) {
			$value = self::inherited( $feature );
		}

		if ( $missing === $value ) {
			$defaults = self::defaults();
			$value    = $defaults[ self::OPTION_PREFIX . $feature ] ?? 'no';
		}

		return 'yes' === $value;
	}

	/**
	 * The stored value of the wide switch a split one came from, if any.
	 *
	 * Install hands that value down, but only when it runs: on activation and
	 * on the first admin page after an update. Until then the new row does not
	 * exist, and falling back to the declared default would briefly undo what
	 * the site had chosen, on every storefront page. So the read makes the same
	 * lookup Install makes.
	 *
	 * @param string $feature Key without the prefix.
	 * @return mixed The stored value, or the missing marker.
	 */
	private static function inherited( string $feature ): mixed {
		$old = self::inherits()[ self::OPTION_PREFIX . $feature ] ?? '';

		if ( '' === $old ) {
			return self::missing();
		}

		return get_option( self::OPTION_PREFIX . $old, self::missing() );
	}

	/**
	 * Option id => the key of the wide switch it was split from.
	 *
	 * @return array<string, string>
	 */
	public static function inherits(): array {
		static $inherits = null;

		if ( null !== $inherits ) {
			return $inherits;
		}

		$inherits = array();

		foreach ( self::fields() as $field ) {
			if ( isset( $field['id'], $field['inherits'] ) && is_string( $field['inherits'] ) ) {
				$inherits[ (string) $field['id'] ] = $field['inherits'];
			}
		}

		return $inherits;
	}

	/**
	 * Marker for "no such row", distinct from anything an option can hold.
	 *
	 * @return object
	 */
	private static function missing(): object {
		static $missing = null;

		if ( null === $missing ) {
			$missing = new \stdClass();
		}

		return $missing;
	}
}
