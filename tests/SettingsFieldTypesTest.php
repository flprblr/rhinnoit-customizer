<?php
/**
 * The multiselect and textarea types: what the form draws, what it stores, what the plugin reads back.
 *
 * No option uses either type yet, so the cases hand the form and the readers
 * fields of their own, as SettingsNeedsTest hands Settings::fields_for_marks()
 * its own: communes grouped by WooCommerce's region codes, shipping methods
 * by instance id, whose choices live in the database and only a callback
 * lists, and a message for the shopper.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Admin\Settings_Page;
use RhinnoIT\Customizer\Settings;

/**
 * Covers the multiselect and textarea types of Settings and Admin\Settings_Page.
 */
#[CoversClass( Settings::class )]
#[CoversClass( Settings_Page::class )]
final class SettingsFieldTypesTest extends TestCase {

	/**
	 * How many times the shipping methods' callback ran.
	 *
	 * @var int
	 */
	private int $calls = 0;

	/**
	 * Starts from a configured site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();

		$this->calls = 0;
	}

	/**
	 * A multiselect is a native multiple select, posted as a list, that WooCommerce's selectWoo enhances.
	 *
	 * @return void
	 */
	public function test_a_multiselect_is_a_native_select_that_selectwoo_enhances(): void {
		$html = self::draw( array( self::communes() ) );

		$this->assertStringContainsString( '<div class="rcus-row rcus-row--wide"><div class="rcus-row__text"><label class="rcus-row__label" for="rcus_communes">Communes</label><p class="rcus-row__desc" id="rcus_communes-desc">Where it applies.</p></div><div class="rcus-row__control">', $html, 'Its text above it, so the box can take the row\'s width.' );
		$this->assertStringContainsString( '<select multiple="multiple" class="wc-enhanced-select rcus-select" id="rcus_communes" name="rcus_communes[]" data-placeholder="Choose communes" aria-describedby="rcus_communes-desc">', $html );
		$this->assertSame( 1, substr_count( $html, 'name="rcus_drawn[]" value="rcus_communes"' ), 'Drawn, so marked once: a selection left empty posts nothing else.' );
		$this->assertSame( 1, substr_count( $html, '</select>' ) );
		$this->assertSame( substr_count( $html, '<div' ), substr_count( $html, '</div>' ), 'The row closes what it opened.' );
	}

	/**
	 * Grouped choices are optgroups, named by the field's callback or else by their key.
	 *
	 * @return void
	 */
	public function test_grouped_choices_are_drawn_as_optgroups(): void {
		$html = self::draw( array( self::communes() ) );

		$this->assertStringContainsString( '<optgroup label="Región Metropolitana de Santiago"><option value="Buin">Buin</option><option value="Ñuñoa">Ñuñoa</option></optgroup>', $html );
		$this->assertStringContainsString( '<optgroup label="CL-BI"><option value="Penco">Penco</option><option value="Tomé">Tomé</option></optgroup>', $html, 'A group the callback does not name shows its key.' );

		$flat            = self::communes();
		$flat['options'] = array(
			'Buin'  => 'Buin',
			'Paine' => 'Paine',
		);

		$html = self::draw( array( $flat ) );

		$this->assertStringNotContainsString( '<optgroup', $html, 'Choices without a group are plain options.' );
		$this->assertStringContainsString( '><option value="Buin">Buin</option><option value="Paine">Paine</option></select>', $html );
	}

	/**
	 * The ids the row holds are drawn selected, and nothing else: what the plugin reads.
	 *
	 * @return void
	 */
	public function test_the_stored_ids_are_drawn_selected(): void {
		WP_Stub::$options['rcus_communes'] = array( 'Ñuñoa', 'Penco' );

		$html = self::draw( array( self::communes() ) );

		$this->assertStringContainsString( '<option value="Ñuñoa" selected="selected">Ñuñoa</option>', $html );
		$this->assertStringContainsString( '<option value="Penco" selected="selected">Penco</option>', $html );
		$this->assertSame( 2, substr_count( $html, ' selected="selected"' ) );

		// A row that is not a list, or holds an id as something else, selects nothing.
		foreach ( array( 'Buin', array( array( 'Buin' ) ), array( 1 ) ) as $stored ) {
			WP_Stub::$options['rcus_communes'] = $stored;

			$this->assertStringNotContainsString( 'selected', self::draw( array( self::communes() ) ), sprintf( 'Drawn selected for %s.', var_export( $stored, true ) ) );
		}

		// Unless the field declares a default, which the plugin then reads.
		WP_Stub::$options['rcus_communes'] = 'Penco';

		$this->assertStringContainsString( '<option value="Buin" selected="selected">', self::draw( array( self::communes( array( 'Buin' ) ) ) ), 'A row that is not a list reads as the default.' );
		$this->assertStringNotContainsString( '<option value="Penco" selected', self::draw( array( self::communes( array( 'Buin' ) ) ) ) );

		unset( WP_Stub::$options['rcus_communes'] );

		$this->assertStringContainsString( '<option value="Buin" selected="selected">', self::draw( array( self::communes( array( 'Buin' ) ) ) ), 'So does a row never saved.' );
	}

	/**
	 * A textarea is drawn holding the text the plugin reads from its row.
	 *
	 * @return void
	 */
	public function test_a_textarea_holds_the_text_the_plugin_reads(): void {
		$html = self::draw( array( self::message() ) );

		$this->assertStringContainsString( '<div class="rcus-row rcus-row--wide"><div class="rcus-row__text"><label class="rcus-row__label" for="rcus_message">Message</label><p class="rcus-row__desc" id="rcus_message-desc">What the shopper reads.</p></div><div class="rcus-row__control">', $html );
		$this->assertStringContainsString( '<textarea rows="3" class="large-text" id="rcus_message" name="rcus_message" aria-describedby="rcus_message-desc">We do not deliver there.</textarea>', $html, 'A row never saved holds the declared default.' );
		$this->assertSame( 1, substr_count( $html, 'name="rcus_drawn[]" value="rcus_message"' ) );
		$this->assertSame( substr_count( $html, '<div' ), substr_count( $html, '</div>' ) );

		WP_Stub::$options['rcus_message'] = "No despachamos a Penco.\nEscríbenos.";

		$this->assertStringContainsString( ">No despachamos a Penco.\nEscríbenos.</textarea>", self::draw( array( self::message() ) ) );

		WP_Stub::$options['rcus_message'] = '';

		$this->assertStringContainsString( 'aria-describedby="rcus_message-desc"></textarea>', self::draw( array( self::message() ) ), 'A cleared box stays cleared.' );

		WP_Stub::$options['rcus_message'] = array( 'No despachamos.' );

		$this->assertStringContainsString( '>We do not deliver there.</textarea>', self::draw( array( self::message() ) ), 'A row that holds no text holds what the plugin reads instead.' );
	}

	/**
	 * Nothing read back from the database, or offered by a callback, reaches the page as markup.
	 *
	 * @return void
	 */
	public function test_a_stored_value_cannot_reach_the_page(): void {
		WP_Stub::$options['rcus_communes'] = array( '"><script>alert(1)</script>', 'Buin' );
		WP_Stub::$options['rcus_message']  = '</textarea><script>alert(2)</script>';

		$communes                 = self::communes();
		$communes['group_labels'] = static fn (): array => array( 'CL-RM' => '"><b>Metropolitana</b>' );
		$methods                  = $this->methods();
		$methods['options_callback'] = static fn (): array => array( '9"><b>x</b>' => 'Standard <b>2 days</b>' );

		$html = self::draw( array( $communes, $methods, self::message() ) );

		$this->assertDoesNotMatchRegularExpression( '/<(script|b)\b/i', $html );
		$this->assertStringNotContainsString( 'alert(1)', $html, 'A list prints only which of its choices the row holds.' );
		$this->assertStringContainsString( '<option value="Buin" selected="selected">', $html, 'The known id beside it still reads as selected.' );
		$this->assertStringContainsString( '<optgroup label="&quot;&gt;&lt;b&gt;Metropolitana&lt;/b&gt;">', $html, 'A group\'s name from elsewhere is escaped.' );
		$this->assertStringContainsString( '<option value="9&quot;&gt;&lt;b&gt;x&lt;/b&gt;">Standard &lt;b&gt;2 days&lt;/b&gt;</option>', $html, 'A callback\'s choices come from the database, id and label.' );
		$this->assertStringContainsString( '>&lt;/textarea&gt;&lt;script&gt;alert(2)&lt;/script&gt;</textarea>', $html, 'The text shows as text, and cannot close its box.' );
		$this->assertSame( 1, substr_count( $html, '</textarea>' ) );
	}

	/**
	 * Choices that live in the database are asked for only when the screen is drawn or saved.
	 *
	 * The storefront reads the row through Settings::chosen(), on its own
	 * requests, so the callback must never run there.
	 *
	 * @return void
	 */
	public function test_the_callback_runs_only_when_the_screen_is_drawn_or_saved(): void {
		$field = $this->methods();

		$this->assertSame( array( '6' ), Settings::chosen_from( $field, array( '6' ) ) );
		$this->assertCount( 3, Settings::fields_for_marks( self::card( array( $field ) ), array( '' => true ) ) );
		$this->assertSame( 0, $this->calls, 'Reading the row, or deciding the field can be shown, asks nothing.' );

		$html = self::draw( array( $field ) );

		$this->assertSame( 1, $this->calls, 'Drawing the screen asks once.' );
		$this->assertStringContainsString( '<option value="6">Express</option>', $html );

		$values = Settings_Page::sanitize_submission( array( $field ), array( 'rcus_methods' => array( '7' ) ) );

		$this->assertSame( 2, $this->calls, 'Saving it asks once more.' );
		$this->assertSame( array( 'rcus_methods' => array( '7' ) ), $values );
	}

	/**
	 * A drawn multiselect with nothing selected posts nothing, and stores the empty list.
	 *
	 * @return void
	 */
	public function test_a_drawn_multiselect_left_empty_stores_the_empty_list(): void {
		$posted = array( Settings_Page::DRAWN_FIELD => array( 'rcus_communes' ) );
		$fields = Settings_Page::fields_to_save( self::card( array( self::communes(), $this->methods() ) ), $posted );

		$this->assertSame( array( 'rcus_communes' => array() ), Settings_Page::sanitize_submission( $fields, $posted ), 'The shipping methods were not drawn, so they keep what they had.' );
	}

	/**
	 * A multiselect keeps only the strings its field offers when it is saved, each once.
	 *
	 * @return void
	 */
	public function test_a_multiselect_keeps_only_the_ids_its_field_offers(): void {
		$values = Settings_Page::sanitize_submission(
			array( self::communes(), $this->methods() ),
			array(
				'rcus_communes' => array( 'Buin', 'Providencia', 'Buin', array( 'Penco' ), 7, 'CL-RM', 'Penco' ),
				'rcus_methods'  => array( '6', '8', 7, 'Express' ),
			)
		);

		$this->assertSame( array( 'Buin', 'Penco' ), $values['rcus_communes'], 'An id it does not offer, a duplicate, a nested list, a number and a group\'s key all go.' );
		$this->assertSame( array( '6' ), $values['rcus_methods'], 'Only what the callback offers now, as strings: its 7 came in as a number.' );
		$this->assertSame( array( 'rcus_communes' => array() ), Settings_Page::sanitize_submission( array( self::communes() ), array( 'rcus_communes' => 'Buin' ) ), 'Not a list: not a form this plugin drew.' );
	}

	/**
	 * A textarea is stored as plain text, its line breaks kept.
	 *
	 * @return void
	 */
	public function test_a_textarea_is_stored_as_plain_text(): void {
		$fields = array( self::message() );

		$this->assertSame(
			array( 'rcus_message' => "No despachamos a Penco.\nEscríbenos." ),
			Settings_Page::sanitize_submission( $fields, array( 'rcus_message' => "  No despachamos a <b>Penco</b>.\nEscríbenos.  " ) )
		);
		$this->assertSame( array( 'rcus_message' => '' ), Settings_Page::sanitize_submission( $fields, array( 'rcus_message' => array( 'No despachamos.' ) ) ), 'Not a text: not a form this plugin drew.' );
		$this->assertSame( array( 'rcus_message' => '' ), Settings_Page::sanitize_submission( $fields, array() ) );
	}

	/**
	 * A list row that is not an array reads as the declared default; an empty one is a choice.
	 *
	 * @return void
	 */
	public function test_a_list_row_that_is_not_an_array_reads_as_the_declared_default(): void {
		$field = self::communes( array( 'Buin' ) );

		foreach ( array( null, 'Penco', 42, true ) as $stored ) {
			$this->assertSame( array( 'Buin' ), Settings::chosen_from( $field, $stored ), sprintf( 'Read for %s.', var_export( $stored, true ) ) );
		}

		$this->assertSame( array(), Settings::chosen_from( $field, array() ), 'Nothing selected is a choice, not a missing row.' );
		$this->assertSame( array(), Settings::chosen_from( self::communes(), 'Penco' ), 'A field that declares nothing reads as nothing.' );
	}

	/**
	 * Every element of a list is checked: a string, and one of its field's ids.
	 *
	 * @return void
	 */
	public function test_a_list_keeps_only_the_strings_its_field_offers(): void {
		$this->assertSame(
			array( 'Ñuñoa', 'Tomé' ),
			Settings::chosen_from( self::communes(), array( 'Ñuñoa', array( 'Buin' ), 7, null, 'Providencia', 'CL-RM', 'Ñuñoa', 'Tomé' ) )
		);
	}

	/**
	 * A list whose choices only a callback knows is read by its id format: strings of digits.
	 *
	 * @return void
	 */
	public function test_a_callback_list_is_read_by_its_id_format(): void {
		$this->assertSame(
			array( '6', '12' ),
			Settings::chosen_from( $this->methods(), array( '6', '12', 'abc', '6 ', "6\n", '-1', '1.5', '', array( '7' ), 7, '6' ) )
		);
		$this->assertSame( 0, $this->calls, 'Never by asking the callback.' );

		$unformatted = $this->methods();
		unset( $unformatted['id_format'] );

		$this->assertSame( array(), Settings::chosen_from( $unformatted, array( '6' ) ), 'Without a format nothing can be checked, so nothing passes.' );
	}

	/**
	 * A text row reads as its string, or as the declared default while it holds anything else.
	 *
	 * @return void
	 */
	public function test_a_text_row_that_is_not_a_string_reads_as_the_declared_default(): void {
		$this->assertSame( 'No despachamos.', Settings::text_from( self::message(), 'No despachamos.' ) );
		$this->assertSame( '', Settings::text_from( self::message(), '' ), 'A cleared box is a choice.' );

		foreach ( array( null, array( 'No despachamos.' ), 42, false ) as $stored ) {
			$this->assertSame( 'We do not deliver there.', Settings::text_from( self::message(), $stored ), sprintf( 'Read for %s.', var_export( $stored, true ) ) );
		}

		$this->assertSame( '', Settings::text_from( array( 'default' => array( 'x' ) ), null ), 'A default that is no text reads as none.' );
	}

	/**
	 * The readers take the key without its prefix, and the default from the field declared under it.
	 *
	 * No option of either type exists yet, so the master switch's row stands in.
	 *
	 * @return void
	 */
	public function test_the_readers_find_the_row_and_the_field_by_key(): void {
		WP_Stub::$options['rcus_enabled'] = 'no';

		$this->assertSame( 'no', Settings::text( 'enabled' ) );

		WP_Stub::$options['rcus_enabled'] = array( 'no' );

		$this->assertSame( 'yes', Settings::text( 'enabled' ), 'The declared default of the master switch.' );
		$this->assertSame( array(), Settings::chosen( 'enabled' ), 'A field with no choices keeps nothing.' );
		$this->assertSame( '', Settings::text( 'nothing_by_this_name' ) );
		$this->assertSame( array(), Settings::chosen( 'nothing_by_this_name' ) );
	}

	/**
	 * Draws these rows in a card of their own, as the form would, and returns the HTML.
	 *
	 * @param array<int, array<string, mixed>> $rows Field definitions.
	 * @return string
	 */
	private static function draw( array $rows ): string {
		ob_start();
		( new Settings_Page() )->render_fields( self::card( $rows ) );

		return (string) ob_get_clean();
	}

	/**
	 * A card: its title, its rows, its end.
	 *
	 * @param array<int, array<string, mixed>> $rows Option rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function card( array $rows ): array {
		return array_merge(
			array(
				array(
					'id'        => 'rcus_card',
					'type'      => 'title',
					'title'     => 'Card',
					'is_option' => false,
				),
			),
			$rows,
			array(
				array(
					'id'        => 'rcus_card',
					'type'      => 'sectionend',
					'is_option' => false,
				),
			)
		);
	}

	/**
	 * Communes by WooCommerce's region code, with one region named for the form and one not.
	 *
	 * @param array<int, string> $default The declared default.
	 * @return array<string, mixed>
	 */
	private static function communes( array $default = array() ): array {
		return array(
			'id'           => 'rcus_communes',
			'type'         => 'multiselect',
			'title'        => 'Communes',
			'desc'         => 'Where it applies.',
			'placeholder'  => 'Choose communes',
			'options'      => array(
				'CL-RM' => array(
					'Buin'  => 'Buin',
					'Ñuñoa' => 'Ñuñoa',
				),
				'CL-BI' => array(
					'Penco' => 'Penco',
					'Tomé'  => 'Tomé',
				),
			),
			'group_labels' => static fn (): array => array( 'CL-RM' => 'Región Metropolitana de Santiago' ),
			'default'      => $default,
		);
	}

	/**
	 * Shipping methods by instance id, which only a callback lists; it counts its calls.
	 *
	 * @return array<string, mixed>
	 */
	private function methods(): array {
		return array(
			'id'               => 'rcus_methods',
			'type'             => 'multiselect',
			'title'            => 'Shipping methods',
			'desc'             => 'The ones it applies to.',
			'placeholder'      => 'Choose shipping methods',
			'options_callback' => function (): array {
				++$this->calls;

				// Integer keys: PHP makes one of every numeric id.
				return array(
					6 => 'Express',
					7 => 'Standard',
				);
			},
			'id_format'        => Settings::DIGITS,
			'default'          => array(),
		);
	}

	/**
	 * A message for the shopper.
	 *
	 * @return array<string, mixed>
	 */
	private static function message(): array {
		return array(
			'id'      => 'rcus_message',
			'type'    => 'textarea',
			'title'   => 'Message',
			'desc'    => 'What the shopper reads.',
			'default' => 'We do not deliver there.',
		);
	}
}
