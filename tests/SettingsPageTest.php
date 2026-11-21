<?php
/**
 * The settings form: what it draws and what it agrees to store.
 *
 * The form draws itself, so the guarantees WC_Admin_Settings would give for
 * free have to be earned here: an undeclared key is never stored, a structural
 * row is never stored, and a value read back from the database is escaped
 * before it reaches the page.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Admin\Settings_Page;
use RhinnoIT\Customizer\Settings;

/**
 * Covers RhinnoIT\Customizer\Admin\Settings_Page.
 */
#[CoversClass( Settings_Page::class )]
final class SettingsPageTest extends TestCase {

	/**
	 * Starts from a configured site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
	}

	/**
	 * Draws the form and returns the HTML.
	 *
	 * @return string
	 */
	private function form(): string {
		ob_start();
		( new Settings_Page() )->render();

		return (string) ob_get_clean();
	}

	/**
	 * A key nobody declared is never stored, whatever the post carries.
	 *
	 * @return void
	 */
	public function test_an_undeclared_key_is_never_stored(): void {
		$values = Settings_Page::sanitize_submission(
			Settings::fields(),
			array(
				'rcus_enabled'  => 'yes',
				'rcus_is_admin' => 'yes',
				'siteurl'       => 'https://attacker.test',
			)
		);

		$this->assertArrayNotHasKey( 'rcus_is_admin', $values );
		$this->assertArrayNotHasKey( 'siteurl', $values, 'A crafted post must not reach core options.' );
		$this->assertSame( 'yes', $values['rcus_enabled'] );
	}

	/**
	 * A section heading is form structure and is never stored as an option.
	 *
	 * @return void
	 */
	public function test_a_structural_row_is_never_stored(): void {
		$values = Settings_Page::sanitize_submission(
			Settings::fields(),
			array(
				'rcus_general'     => 'yes',
				'rcus_woocommerce' => 'yes',
			)
		);

		$this->assertArrayNotHasKey( 'rcus_general', $values );
		$this->assertArrayNotHasKey( 'rcus_woocommerce', $values );

		// The type already keeps a title out. This isolates the flag itself: a
		// row marked structural is skipped even when its type could be stored.
		$flagged = Settings_Page::sanitize_submission(
			array(
				array(
					'id'        => 'rcus_structural',
					'type'      => 'checkbox',
					'is_option' => false,
				),
			),
			array( 'rcus_structural' => 'yes' )
		);

		$this->assertSame( array(), $flagged );
	}

	/**
	 * A checkbox is yes only for the exact value, and no when absent.
	 *
	 * Browsers send nothing for an unchecked box, so absence is the "off" signal.
	 * Anything but the literal the form prints is off too: an array, a "1", a
	 * "true" all mean somebody built the post by hand.
	 *
	 * @return void
	 */
	public function test_a_checkbox_is_yes_only_for_the_exact_value(): void {
		$this->assertSame( 'yes', Settings_Page::sanitize_submission( Settings::fields(), array( 'rcus_enabled' => 'yes' ) )['rcus_enabled'] );
		$this->assertSame( 'no', Settings_Page::sanitize_submission( Settings::fields(), array() )['rcus_enabled'], 'An absent box was unchecked.' );

		foreach ( array( '1', 'true', 'on', array( 'yes' ) ) as $posted ) {
			$this->assertSame(
				'no',
				Settings_Page::sanitize_submission( Settings::fields(), array( 'rcus_enabled' => $posted ) )['rcus_enabled'],
				sprintf( 'Stored as on for %s.', var_export( $posted, true ) )
			);
		}
	}

	/**
	 * The form shows one card per thing the shop runs, in order.
	 *
	 * The shared process runs Porto and no WeLaunch Multi Inventory.
	 *
	 * @return void
	 */
	public function test_a_shop_sees_a_card_per_domain(): void {
		$html = $this->form();

		$this->assertStringContainsString( '<h2 class="rcus-card__title">WooCommerce</h2>', $html );
		$this->assertStringContainsString( '<h2 class="rcus-card__title">Porto</h2>', $html );
		$this->assertStringNotContainsString( '>WeLaunch Multi Inventory</h2>', $html, 'Not installed here.' );
		$this->assertStringNotContainsString( '>WordPress</h2>', $html, 'Nothing this plugin does works without WooCommerce.' );
		$this->assertLessThan( strpos( $html, '>WooCommerce</h2>' ), strpos( $html, 'for="rcus_enabled"' ), 'The master switch above every card.' );
		$this->assertLessThan( strpos( $html, '>Porto</h2>' ), strpos( $html, '>WooCommerce</h2>' ), 'The platform before the theme.' );
		$this->assertSame( 3, substr_count( $html, '<section class="rcus-card">' ), 'The master switch, then one card per domain.' );
		$this->assertSame( 2, substr_count( $html, '<h2' ), 'The master switch has no heading of its own.' );
		$this->assertSame( substr_count( $html, '<section' ), substr_count( $html, '</section>' ), 'Every card closes what it opened.' );
		$this->assertSame( substr_count( $html, '<div' ), substr_count( $html, '</div>' ), 'Every row closes what it opened.' );
	}

	/**
	 * Every checkbox is drawn as an on/off switch, and its row label toggles it.
	 *
	 * Still a real checkbox, so the save logic above does not change; the role
	 * tells a screen reader it is a switch, and the class is what the admin
	 * stylesheet draws.
	 *
	 * @return void
	 */
	public function test_every_checkbox_is_drawn_as_a_switch(): void {
		$html       = $this->form();
		$checkboxes = substr_count( $html, 'type="checkbox"' );

		$this->assertSame( 15, $checkboxes, 'One switch per feature that can apply, plus the master switch: not WeLaunch\'s seven, nor the Fintoc one on a site without them.' );
		$this->assertStringNotContainsString( 'type="number"', $html, 'The revision limit belongs to wp-config.php, which always wins.' );
		$this->assertSame( $checkboxes, substr_count( $html, 'type="checkbox" role="switch" class="rcus-switch"' ) );
		$this->assertStringContainsString( '<label class="rcus-row__label" for="rcus_enabled">Enable</label>', $html );
		$this->assertStringContainsString( 'aria-describedby="rcus_enabled-desc"', $html );
		$this->assertStringContainsString( '<p class="rcus-row__desc" id="rcus_enabled-desc">', $html );
	}

	/**
	 * A group label is printed once, above the first switch of its run.
	 *
	 * @return void
	 */
	public function test_a_group_label_heads_its_run_once(): void {
		$html = $this->form();

		$this->assertSame( 1, substr_count( $html, '<h3 class="rcus-card__group">Cart and checkout</h3>' ) );
		$this->assertLessThan( strpos( $html, 'for="rcus_express_checkout"' ), strpos( $html, '>Cart and checkout</h3>' ) );
		$this->assertGreaterThan( strpos( $html, 'for="rcus_rut_validation"' ), strpos( $html, '>Shipping methods by commune</h3>' ) );
		$this->assertGreaterThan( strpos( $html, 'for="rcus_checkout_commune_select"' ), strpos( $html, 'for="rcus_rut_validation"' ), 'The RUT check closes Cart and checkout.' );
		$this->assertLessThan( strpos( $html, 'for="rcus_shipping_block"' ), strpos( $html, '>Shipping methods by commune</h3>' ) );
		$this->assertSame( 1, substr_count( $html, '>Shipping methods by commune</h3>' ), 'Above the switch, its two lists and its message, once.' );
		$this->assertGreaterThan( strpos( $html, 'for="rcus_shipping_block_message"' ), strpos( $html, '>Deliveries by commune</h3>' ) );
		$this->assertLessThan( strpos( $html, 'for="rcus_shipping_exclusion"' ), strpos( $html, '>Deliveries by commune</h3>' ) );
		$this->assertGreaterThan( strpos( $html, 'for="rcus_shipping_exclusion_message"' ), strpos( $html, '>Orders</h3>' ) );
		$this->assertLessThan( strpos( $html, 'for="rcus_widget_thumb_srcset"' ), strpos( $html, '>Product widgets</h3>' ) );
		$this->assertGreaterThan( strpos( $html, 'for="rcus_widget_sale_price"' ), strpos( $html, '<h3 class="rcus-card__group">Checkout</h3>' ) );
		$this->assertLessThan( strpos( $html, 'for="rcus_birthdate_style"' ), strpos( $html, '<h3 class="rcus-card__group">Checkout</h3>' ) );
		$this->assertSame( 6, substr_count( $html, '<h3 class="rcus-card__group">' ), 'Cart and checkout, the two shipping rules, Orders, then Porto\'s Product widgets and Checkout, each once.' );
	}

	/**
	 * A value read back from the database never reaches the page.
	 *
	 * The screen prints only whether a box is on, never what the row holds, so
	 * markup written into an option cannot surface here in any form.
	 *
	 * @return void
	 */
	public function test_a_stored_value_cannot_reach_the_page(): void {
		WP_Stub::$options['rcus_enabled'] = '"><script>alert(1)</script>';

		$html = $this->form();

		$this->assertDoesNotMatchRegularExpression( '/<script\b/i', $html );
		$this->assertStringNotContainsString( 'alert(', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="rcus_enabled" value="yes"[^>]* checked/', $html, 'And it reads as off.' );
	}

	/**
	 * A box is drawn checked exactly when its option is yes.
	 *
	 * @return void
	 */
	public function test_a_box_is_checked_exactly_when_its_option_is_yes(): void {
		WP_Stub::$options['rcus_enabled'] = 'yes';

		$this->assertMatchesRegularExpression( '/name="rcus_enabled" value="yes"[^>]* checked="checked"/', $this->form() );

		// Settings reads anything but 'yes' as off, so the screen must not draw
		// any of these as on.
		foreach ( array( 'no', '1', 'on', '', array( 'yes' ), 42, true ) as $stored ) {
			WP_Stub::$options['rcus_enabled'] = $stored;

			$this->assertDoesNotMatchRegularExpression(
				'/name="rcus_enabled" value="yes"[^>]* checked/',
				$this->form(),
				sprintf( 'Drawn on for %s, which the plugin reads as off.', var_export( $stored, true ) )
			);
		}
	}

	/**
	 * Nothing has been retired, so a form this version drew is never turned away.
	 *
	 * @return void
	 */
	public function test_a_current_form_is_not_taken_for_an_older_one(): void {
		$this->assertFalse( Settings_Page::drawn_by_an_older_version( array( 'rcus_drawn' => Settings::option_ids() ) ) );
		$this->assertFalse( Settings_Page::drawn_by_an_older_version( array( 'rcus_drawn' => array( 'rcus_enabled' ) ) ) );
	}

	/**
	 * A form that lists nothing it drew is refused, not saved as nothing.
	 *
	 * Every form this version draws lists the master switch. One that lists
	 * nothing was drawn before the list existed - WC_Admin_Settings drew the
	 * fields then - and would store nothing while the redirect said it had.
	 *
	 * @return void
	 */
	public function test_a_form_that_lists_nothing_it_drew_is_refused(): void {
		WP_Stub::$options['rcus_enabled'] = 'yes';

		foreach ( array( null, 'rcus_enabled', array(), array( array( 'rcus_enabled' ) ) ) as $drawn ) {
			$this->assertTrue( Settings_Page::drawn_by_an_older_version( null === $drawn ? array() : array( 'rcus_drawn' => $drawn ) ) );
		}

		// What the form posted before it drew itself.
		$_POST = array(
			'rcus_enabled' => '1',
		);

		try {
			( new Settings_Page() )->save();
		} catch ( WP_Halt $e ) {
			unset( $e );
		}

		$this->assertStringContainsString( 'outdated=1', (string) WP_Stub::$redirect, 'The screen asks for a reload.' );
		$this->assertStringNotContainsString( 'updated=1', (string) WP_Stub::$redirect, 'Nothing was saved, so it must not say so.' );
		$this->assertSame( array(), WP_Stub::$fired, 'Nor tell the group it was saved.' );
		$this->assertSame( 'yes', WP_Stub::$options['rcus_enabled'], 'Saved, the old form\'s "1" would have switched the plugin off.' );
	}

	/**
	 * The screen explains a refused save instead of claiming it worked.
	 *
	 * @return void
	 */
	public function test_a_refused_save_says_so(): void {
		$_GET['outdated'] = '1';

		$html = $this->form();

		$this->assertStringContainsString( 'nothing was saved', $html );
		$this->assertStringNotContainsString( 'Settings saved.', $html );
	}

	/**
	 * A field that was not on the screen is not stored as unchecked.
	 *
	 * The field list is rebuilt at save time. If WooCommerce became available
	 * while the form was open, its section was never drawn, and storing its
	 * absent boxes as "no" would switch off the struck-through price that
	 * nobody touched.
	 *
	 * @return void
	 */
	public function test_a_field_that_was_not_drawn_is_left_alone(): void {
		$fields = Settings_Page::fields_to_save(
			Settings::fields(),
			array( 'rcus_drawn' => array( 'rcus_enabled' ) )
		);

		$this->assertSame( array( 'rcus_enabled' ), array_column( $fields, 'id' ) );
		$this->assertArrayNotHasKey(
			'rcus_cart_sale_price',
			Settings_Page::sanitize_submission( $fields, array() ),
			'Undrawn, so its stored value survives.'
		);
	}

	/**
	 * The drawn list can narrow the save but never widen it.
	 *
	 * @return void
	 */
	public function test_the_drawn_list_cannot_name_a_field_this_site_lacks(): void {
		$wordpress_only = array_values(
			array_filter(
				Settings::fields(),
				static fn ( array $field ): bool => 'woocommerce' !== ( $field['requires'] ?? '' )
			)
		);

		$fields = Settings_Page::fields_to_save(
			$wordpress_only,
			array( 'rcus_drawn' => array( 'rcus_checkout_coupon_prompt', 'siteurl', array( 'x' ) ) )
		);

		$this->assertSame( array(), $fields, 'A crafted marker is intersected with what the site can use.' );
		$this->assertSame( array(), Settings_Page::fields_to_save( $wordpress_only, array( 'rcus_drawn' => 'not a list' ) ) );
	}

	/**
	 * Every option the form draws carries its marker, once.
	 *
	 * @return void
	 */
	public function test_every_drawn_option_carries_its_marker(): void {
		$html = $this->form();

		foreach ( array( 'rcus_enabled', 'rcus_cart_sale_price', 'rcus_unpaid_orders' ) as $id ) {
			$this->assertSame( 1, substr_count( $html, sprintf( 'name="rcus_drawn[]" value="%s"', $id ) ), sprintf( '%s is drawn once, so it is marked once.', $id ) );
		}

		$this->assertStringNotContainsString( 'value="rcus_general"', $html, 'A section heading is not an option and has no marker.' );
	}
}
