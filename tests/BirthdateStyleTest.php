<?php
/**
 * The birth date field of Porto's checkout.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Birthdate_Style;
use RhinnoIT\Customizer\Settings;

/**
 * Covers RhinnoIT\Customizer\Frontend\Birthdate_Style.
 */
#[CoversClass( Birthdate_Style::class )]
final class BirthdateStyleTest extends TestCase {

	/**
	 * Starts on the checkout with the switch at its default and Porto's stubs loaded.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$page_type = 'checkout';
	}

	/**
	 * Leaves the stubs as the rest of the suite expects them.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		WP_Stub::$page_type = '';
	}

	/**
	 * It ships on, and its switch and the master switch turn it off.
	 *
	 * @return void
	 */
	public function test_it_ships_on_and_the_switches_turn_it_off(): void {
		$this->assertSame( 'porto', get_template(), 'The shared process runs Porto, or this case means nothing.' );

		( new Birthdate_Style() )->register();
		$this->assertSame( array( 'wp_enqueue_scripts:add_style' ), WP_Stub::$callbacks );

		WP_Stub::reset();
		WP_Stub::$options['rcus_birthdate_style'] = 'no';
		( new Birthdate_Style() )->register();
		$this->assertSame( array(), WP_Stub::$hooks );

		WP_Stub::reset();
		WP_Stub::$options['rcus_enabled'] = 'no';
		( new Birthdate_Style() )->register();
		$this->assertSame( array(), WP_Stub::$hooks );
	}

	/**
	 * Its switch heads a Checkout group of its own in Porto's card, on by default.
	 *
	 * @return void
	 */
	public function test_its_row_sits_in_portos_card(): void {
		$ids   = array_column( Settings::fields(), 'id' );
		$index = array_search( 'rcus_birthdate_style', $ids, true );
		$row   = Settings::fields()[ $index ];

		$this->assertSame( 'rcus_widget_sale_price', $ids[ $index - 1 ] );
		$this->assertSame( 'rcus_porto', $ids[ $index + 1 ], 'Last in the card.' );
		$this->assertSame( 'checkbox', $row['type'] );
		$this->assertSame( 'Checkout', $row['group'] );
		$this->assertSame( Settings::REQUIRES_PORTO, $row['requires'], 'Its border is Porto\'s.' );
		$this->assertSame( 'yes', $row['default'] );
	}

	/**
	 * On the checkout, the old plugin's rules as an inline style with no file: the field, its calendar icon and the wrapper the icon sits in.
	 *
	 * @return void
	 */
	public function test_the_field_matches_portos_on_the_checkout(): void {
		( new Birthdate_Style() )->add_style();

		$this->assertSame( array( 'register:rcus-birthdate-style:false', 'enqueue:rcus-birthdate-style' ), WP_Stub::$styles );

		$css = WP_Stub::$inline_styles['rcus-birthdate-style'];

		$this->assertStringStartsWith( '#billing_birthdate[type="date"]{', $css, 'Only a date input: a text field the other plugin draws instead is left as it is.' );
		$this->assertStringContainsString( 'height:48px;', $css, 'Porto\'s text inputs: a 30px line, 8px of padding each side and the border.' );
		$this->assertStringContainsString( 'border:1px solid var(--porto-input-bc);', $css );
		$this->assertStringContainsString( '-webkit-appearance:none;-moz-appearance:none;appearance:none}', $css, 'The standard property last.' );
		$this->assertStringContainsString( '#billing_birthdate[type="date"]::-webkit-calendar-picker-indicator{position:absolute;right:10px;top:50%;transform:translateY(-50%);', $css );
		$this->assertStringContainsString( '#billing_birthdate_field .woocommerce-input-wrapper{position:relative;', $css, 'Where the icon is placed from.' );
		$this->assertSame( substr_count( $css, '{' ), substr_count( $css, '}' ), 'Every rule closes what it opened.' );
	}

	/**
	 * Off the checkout nothing is printed.
	 *
	 * @return void
	 */
	public function test_nothing_is_printed_off_the_checkout(): void {
		foreach ( array( 'cart', 'product', 'account', 'page' ) as $page ) {
			WP_Stub::$page_type = $page;

			( new Birthdate_Style() )->add_style();

			$this->assertSame( array(), WP_Stub::$styles, $page );
			$this->assertSame( array(), WP_Stub::$inline_styles, $page );
		}
	}
}
