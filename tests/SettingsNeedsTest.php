<?php
/**
 * A field that cannot act on this site is not on its screen.
 *
 * Settings::available_fields() hides a field whose 'requires' or 'needs' names
 * a mark this site lacks, and Settings::conditions() is the one place that
 * answers each mark. A mark it does not answer counts as missing, so a typo
 * hides its field on every site with no error anywhere.
 *
 * The rules themselves are pinned on Settings::fields_for_marks() with fields
 * and marks of the cases' own. Each NEEDS_* mark brings its own cases here
 * too: its field hidden where the mark is missing, drawn where it is there,
 * and its stored value kept either way.
 *
 * The shared process is a shop without Fintoc, on a WooCommerce whose
 * WC_Discounts hands a coupon's items over. The cases that need Fintoc or an
 * older WooCommerce run in their own process: SettingsWithFintocTest and
 * CouponSplitBeforeItemsFilterTest.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Admin\Settings_Page;
use RhinnoIT\Customizer\Frontend\Coupon_Split;
use RhinnoIT\Customizer\Settings;

/**
 * Covers the marks of Settings::available_fields().
 */
#[CoversClass( Settings::class )]
#[CoversClass( Settings_Page::class )]
final class SettingsNeedsTest extends TestCase {

	/**
	 * Starts from a configured site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
	}

	/**
	 * Ids of the fields this site's screen draws.
	 *
	 * @return array<int, string>
	 */
	private static function drawn(): array {
		return array_column( Settings::available_fields(), 'id' );
	}

	/**
	 * Without Fintoc its switch is not offered; the coupon split, which this WooCommerce feeds, is.
	 *
	 * @return void
	 */
	public function test_a_switch_that_cannot_act_here_is_not_on_the_screen(): void {
		$this->assertFalse( defined( 'FINTOC_WC_VERSION' ), 'The process has Fintoc, or this case means nothing.' );
		$this->assertTrue( Coupon_Split::can_split(), 'The process cannot split coupons, or this case means nothing.' );

		$ids = self::drawn();

		$this->assertNotContains( 'rcus_fintoc_session_expiry', $ids, 'rcus_fintoc_session_expiry does nothing on this site.' );

		foreach ( array( 'rcus_woocommerce', 'rcus_cart_coupon_split', 'rcus_unpaid_orders' ) as $id ) {
			$this->assertContains( $id, $ids, sprintf( '%s can act here.', $id ) );
		}
	}

	/**
	 * A post naming a hidden switch cannot write it, and its value is kept.
	 *
	 * @return void
	 */
	public function test_a_hidden_switch_keeps_its_value_whatever_the_post_says(): void {
		$posted = array(
			'rcus_fintoc_session_expiry' => 'yes',
			'rcus_unpaid_orders'         => 'yes',
			Settings_Page::DRAWN_FIELD   => array( 'rcus_fintoc_session_expiry', 'rcus_unpaid_orders' ),
		);

		$values = Settings_Page::sanitize_submission( Settings_Page::fields_to_save( Settings::available_fields(), $posted ), $posted );

		$this->assertSame( array( 'rcus_unpaid_orders' => 'yes' ), $values );
	}

	/**
	 * On a WooCommerce that hands no items over, the coupon split is off the screen and its value stays.
	 *
	 * The form of a site that offered it, posted with the box unchecked: were
	 * the switch still saved, its absence would store it as off.
	 *
	 * @return void
	 */
	public function test_a_coupon_split_this_woocommerce_cannot_run_keeps_its_value(): void {
		$fields = Settings::fields_for_marks( Settings::fields(), array( Settings::NEEDS_COUPON_ITEMS => false ) + Settings::conditions() );
		$posted = array(
			'rcus_cart_sale_price'     => 'yes',
			Settings_Page::DRAWN_FIELD => array( 'rcus_cart_coupon_split', 'rcus_cart_sale_price' ),
		);

		$this->assertNotContains( 'rcus_cart_coupon_split', array_column( $fields, 'id' ) );
		$this->assertContains( 'rcus_cart_sale_price', array_column( $fields, 'id' ), 'The rest of the card stays.' );
		$this->assertSame( array( 'rcus_cart_sale_price' => 'yes' ), Settings_Page::sanitize_submission( Settings_Page::fields_to_save( $fields, $posted ), $posted ) );
	}

	/**
	 * Hiding a switch never changes what it does: a hidden one still reads its row.
	 *
	 * @return void
	 */
	public function test_hiding_is_only_the_screen(): void {
		WP_Stub::$options['rcus_fintoc_session_expiry'] = 'no';

		$this->assertNotContains( 'rcus_fintoc_session_expiry', self::drawn() );
		$this->assertFalse( Settings::feature( 'fintoc_session_expiry' ) );
		$this->assertContains( 'rcus_fintoc_session_expiry', Settings::option_ids(), 'Still an option: seeded and uninstalled.' );
	}

	/**
	 * Every mark a field names is one the screen reads, and only options carry 'needs'.
	 *
	 * A typo would hide its field on every site, with no error anywhere.
	 *
	 * @return void
	 */
	public function test_every_mark_is_a_condition_the_screen_reads(): void {
		$conditions = Settings::conditions();
		$marked     = 0;

		foreach ( Settings::fields() as $field ) {
			$id = (string) $field['id'];

			$this->assertArrayHasKey( $field['requires'] ?? '', $conditions, sprintf( '%s requires a mark nobody reads.', $id ) );

			$marked += '' !== ( $field['requires'] ?? '' ) ? 1 : 0;

			if ( ! isset( $field['needs'] ) ) {
				continue;
			}

			++$marked;

			$this->assertNotFalse( $field['is_option'] ?? true, sprintf( '%s: a card is hidden through its section mark, not through needs.', $id ) );
			$this->assertIsArray( $field['needs'] );
			$this->assertNotEmpty( $field['needs'] );

			foreach ( $field['needs'] as $mark ) {
				$this->assertIsString( $mark );
				$this->assertArrayHasKey( $mark, $conditions, sprintf( '%s needs a mark nobody reads.', $id ) );
			}
		}

		$this->assertGreaterThan( 0, $marked, 'No field carries a mark, so this guard checks nothing.' );
	}

	/**
	 * The screen reads this site's own marks.
	 *
	 * @return void
	 */
	public function test_the_screen_filters_with_this_sites_marks(): void {
		$this->assertSame( Settings::fields_for_marks( Settings::fields(), Settings::conditions() ), Settings::available_fields() );
	}

	/**
	 * An option whose 'needs' the site lacks is not offered; the rest of its card is.
	 *
	 * @return void
	 */
	public function test_an_option_whose_mark_is_missing_is_left_off(): void {
		$ids = self::ids_for(
			array(
				'' => true,
				'a' => true,
				'b' => false,
			),
			self::card( 'rcus_card', array( self::option( 'rcus_one', array( 'a' ) ), self::option( 'rcus_two', array( 'a', 'b' ) ), self::option( 'rcus_three' ) ) )
		);

		$this->assertSame( array( 'rcus_card', 'rcus_one', 'rcus_three', 'rcus_card' ), $ids, 'Every mark must hold; one missing is enough to hide it.' );
	}

	/**
	 * A mark nobody answers hides its option on every site.
	 *
	 * @return void
	 */
	public function test_a_mark_nobody_answers_counts_as_missing(): void {
		$ids = self::ids_for(
			array( '' => true ),
			self::card( 'rcus_card', array( self::option( 'rcus_typo', array( 'not_a_mark' ) ), self::option( 'rcus_kept' ) ) )
		);

		$this->assertSame( array( 'rcus_card', 'rcus_kept', 'rcus_card' ), $ids );
	}

	/**
	 * A card left with nothing to switch is not drawn at all.
	 *
	 * @return void
	 */
	public function test_a_card_left_with_no_option_goes_whole(): void {
		$ids = self::ids_for(
			array(
				'' => true,
				'a' => false,
			),
			array_merge(
				self::card( 'rcus_general', array( self::option( 'rcus_enabled' ) ) ),
				self::card( 'rcus_emptied', array( self::option( 'rcus_hidden', array( 'a' ) ) ) ),
				self::card( 'rcus_empty', array() ),
				self::card( 'rcus_kept', array( self::option( 'rcus_shown' ) ) )
			)
		);

		$this->assertSame( array( 'rcus_general', 'rcus_enabled', 'rcus_general', 'rcus_kept', 'rcus_shown', 'rcus_kept' ), $ids );
	}

	/**
	 * A section mark takes the whole card, title and end included.
	 *
	 * @return void
	 */
	public function test_a_section_mark_takes_the_whole_card(): void {
		$rows = array_map(
			static fn ( array $field ): array => $field + array( 'requires' => 'shop' ),
			self::card( 'rcus_shop', array( self::option( 'rcus_cart' ) ) )
		);

		$this->assertSame( array(), self::ids_for( array( '' => true, 'shop' => false ), $rows ) );
		$this->assertSame( array( 'rcus_shop', 'rcus_cart', 'rcus_shop' ), self::ids_for( array( '' => true, 'shop' => true ), $rows ) );
	}

	/**
	 * Ids of the fields a site with these marks would be offered.
	 *
	 * @param array<string, bool>              $present Mark => whether the site has it.
	 * @param array<int, array<string, mixed>> $fields  Field definitions.
	 * @return array<int, string>
	 */
	private static function ids_for( array $present, array $fields ): array {
		return array_map( static fn ( array $field ): string => (string) $field['id'], Settings::fields_for_marks( $fields, $present ) );
	}

	/**
	 * A card: its title, its rows, its end.
	 *
	 * @param string                           $id   Section id.
	 * @param array<int, array<string, mixed>> $rows Option rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function card( string $id, array $rows ): array {
		return array_merge(
			array(
				array(
					'id'        => $id,
					'type'      => 'title',
					'is_option' => false,
				),
			),
			$rows,
			array(
				array(
					'id'        => $id,
					'type'      => 'sectionend',
					'is_option' => false,
				),
			)
		);
	}

	/**
	 * A checkbox option, with its marks.
	 *
	 * @param string             $id    Option id.
	 * @param array<int, string> $needs Its 'needs' marks.
	 * @return array<string, mixed>
	 */
	private static function option( string $id, array $needs = array() ): array {
		$field = array(
			'id'   => $id,
			'type' => 'checkbox',
		);

		if ( array() !== $needs ) {
			$field['needs'] = $needs;
		}

		return $field;
	}
}
