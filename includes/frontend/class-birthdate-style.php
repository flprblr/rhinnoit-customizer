<?php
/**
 * Draws the checkout's birth date field like Porto's other fields.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Gives the birth date field of the classic checkout the height and border of the fields around it.
 *
 * The field, billing_birthdate, is another plugin's, drawn as a date input.
 * Porto 7.10's checkout stylesheet (woopage.css) gives the checkout's text,
 * email, phone and password inputs a 30px line height, 48px tall with its
 * padding and border, and leaves the date input out, so the birth date field
 * came out shorter than the fields around it. What the old
 * rhinnoit-correccion-fecha-nacimiento plugin did, as an inline style with
 * no file, on the checkout only: the field is 48px tall, with Porto's input
 * border (--porto-input-bc) and no native look, and the calendar icon sits
 * at its right, centered, inside the field's wrapper. A checkout without that
 * field matches nothing.
 *
 * Porto only: off Porto the border color has no value, and the field would
 * lose its border.
 */
final class Birthdate_Style {

	/**
	 * Handle of the inline style.
	 */
	const string STYLE = 'rcus-birthdate-style';

	/**
	 * The field, its calendar icon, and the wrapper the icon is placed in.
	 */
	const string CSS = '#billing_birthdate[type="date"]{font-size:inherit;font-family:inherit;padding:12px 16px;width:100%;height:48px;line-height:normal;background:#fff;border:1px solid var(--porto-input-bc);border-radius:0;box-sizing:border-box;-webkit-appearance:none;-moz-appearance:none;appearance:none}'
		. '#billing_birthdate[type="date"]::-webkit-calendar-picker-indicator{position:absolute;right:10px;top:50%;transform:translateY(-50%);cursor:pointer;filter:invert(.5)}'
		. '#billing_birthdate_field .woocommerce-input-wrapper{position:relative;display:block;width:100%}';

	/**
	 * Hooks the style while the switch is on, on Porto.
	 *
	 * @return void
	 */
	public function register(): void {
		// The border is Porto's, and the switch is offered only on Porto, so
		// nothing may run anywhere else. The template is an option: known now.
		if ( ! Settings::feature( 'birthdate_style' ) || 'porto' !== get_template() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'add_style' ) );
	}

	/**
	 * Prints the rules in the page head, as an inline style with no file, on the checkout only.
	 *
	 * @return void
	 */
	public function add_style(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_register_style( self::STYLE, false, array(), RCUS_VERSION );
		wp_enqueue_style( self::STYLE );
		wp_add_inline_style( self::STYLE, self::CSS );
	}
}
