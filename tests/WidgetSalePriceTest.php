<?php
/**
 * The sale price color in Porto's product widgets.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Widget_Sale_Price;

/**
 * Covers RhinnoIT\Customizer\Frontend\Widget_Sale_Price.
 */
#[CoversClass( Widget_Sale_Price::class )]
final class WidgetSalePriceTest extends TestCase {

	/**
	 * Starts with the switch at its default and Porto's stubs loaded.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
	}

	/**
	 * It ships on, by the owner's decision, and its switch and the master switch turn it off.
	 *
	 * @return void
	 */
	public function test_it_ships_on_and_the_switches_turn_it_off(): void {
		( new Widget_Sale_Price() )->register();
		$this->assertSame( array( 'wp_enqueue_scripts:add_style' ), WP_Stub::$callbacks );

		WP_Stub::reset();
		WP_Stub::$options['rcus_widget_sale_price'] = 'no';
		( new Widget_Sale_Price() )->register();
		$this->assertSame( array(), WP_Stub::$hooks );

		WP_Stub::reset();
		WP_Stub::$options['rcus_enabled'] = 'no';
		( new Widget_Sale_Price() )->register();
		$this->assertSame( array(), WP_Stub::$hooks );
	}

	/**
	 * One inline rule, no file, that sets no color of its own.
	 *
	 * The rule beats Porto's .product_list_widget .product-details .amount
	 * (three classes) with three classes and an element, and takes the
	 * color from the ins: hoka's own #DC3545, measured on its product page.
	 *
	 * @return void
	 */
	public function test_the_amount_inherits_the_ins_color(): void {
		( new Widget_Sale_Price() )->add_style();

		$this->assertSame( array( 'register:rcus-widget-sale-price:false', 'enqueue:rcus-widget-sale-price' ), WP_Stub::$styles );
		$this->assertSame( ':where(.product_list_widget .product-details ins){color:var(--porto-color-price)}.product_list_widget .product-details ins .woocommerce-Price-amount.amount{color:inherit}', WP_Stub::$inline_styles['rcus-widget-sale-price'], 'Without an ins rule of the shop\'s, the ins keeps Porto\'s price color.' );
		$this->assertStringNotContainsString( '#', WP_Stub::$inline_styles['rcus-widget-sale-price'], 'The red is the shop\'s own ins rule.' );
	}
}
