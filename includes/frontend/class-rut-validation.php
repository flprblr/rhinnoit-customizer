<?php
/**
 * Checks and formats the Chilean RUT on the classic checkout.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Formats the RUT field of the classic checkout, and refuses an order whose RUT is missing or not valid.
 *
 * The field, billing_rut, and the Chilean/foreigner choice beside it,
 * billing_is_chilean, are another plugin's: this one adds neither, and does
 * nothing on a checkout without the RUT field. A RUT is required of a
 * Chilean customer and never of a foreigner, so the requirement stops being
 * WooCommerce's: at woocommerce_checkout_fields 20, after the plugin that
 * adds the field, it is no longer required in WooCommerce's sense - neither
 * its required flag nor the validate-required class WooCommerce's script
 * checks - and everything else that plugin set stays. With no choice made,
 * or no radio at all, the flag the field had before decides, which the input
 * carries for the script as data-rcus-required.
 *
 * The radio's value is normalized as the old rhinnoit-validacion-rut-cl
 * plugin did, accents and case aside: chilean, chilena, chileno, 1, true, si,
 * or anything containing "chile" is a Chilean; foreigner, extranjero,
 * extranjera, 0, false, no, or anything containing "extra" a foreigner.
 *
 * On the submit, woocommerce_checkout_posted_data empties a foreigner's RUT,
 * and woocommerce_after_checkout_validation refuses a missing RUT where one
 * is required and one that is not valid (is_valid()), with the field's id,
 * so WooCommerce links the notice to the field and marks it. A script
 * printed after wc-checkout formats the field as 12.345.678-9 while it is
 * typed, checks it when it loses focus and on checkout_place_order, which it
 * stops with a message under the field, WooCommerce's own inline error,
 * disables and empties the field for a foreigner and shows the required mark
 * while it is required (WooCommerce draws it as optional now). It runs again
 * on updated_checkout. Without the script the submit still refuses what the
 * server refuses.
 *
 * Classic checkout only: the block checkout draws its own fields and runs
 * none of these hooks, and the script finds no field there.
 */
final class Rut_Validation {

	/**
	 * The RUT field another plugin adds to the billing fields.
	 */
	const string FIELD = 'billing_rut';

	/**
	 * The radio another plugin adds for the customer to say whether they are Chilean.
	 */
	const string CHOICE = 'billing_is_chilean';

	/**
	 * What choice() answers for a Chilean customer.
	 */
	const string CHILEAN = 'chilean';

	/**
	 * What choice() answers for a foreigner.
	 */
	const string FOREIGNER = 'foreigner';

	/**
	 * The attribute that carries, on the input, whether the field was required before this feature made it optional.
	 */
	const string REQUIRED_ATTRIBUTE = 'data-rcus-required';

	/**
	 * WooCommerce's classic checkout script, which the script follows.
	 */
	const string HANDLE = 'wc-checkout';

	/**
	 * Handle of the inline style.
	 */
	const string STYLE = 'rcus-rut-validation';

	/**
	 * The field in error, whether this plugin's script or WooCommerce's notice marked it, and the field while it is turned off.
	 *
	 * WooCommerce's own red where its stylesheet loads, plain red where the
	 * theme drops it (Porto does, and styles no invalid field).
	 */
	const string CSS = '#billing_rut[aria-invalid=true]{border-color:var(--wc-red,red)!important;background-color:#ffe6e6!important}'
		. '#billing_rut_field .checkout-inline-error-message{color:var(--wc-red,red)}'
		. '#billing_rut:disabled{background-color:#f5f5f5!important;color:#999!important;cursor:not-allowed}';

	/**
	 * The script; __MESSAGES__ is the two messages, required and invalid, as JSON.
	 *
	 * Its functions: n() normalizes a radio value as choice() does (c, f or
	 * empty) and c() reads the ticked one; q() answers whether the field is
	 * required now; k() strips a RUT to its digits and K, v() is is_valid()
	 * and t() the 12.345.678-9 format; r() clears the field's error and b()
	 * marks it as WooCommerce marks one from a notice; h() checks the field
	 * and answers whether it may be sent; s() turns the field off for a
	 * foreigner and shows whether it is required; o() binds the submit check,
	 * which a failure stops before any later handler can let it through. The
	 * field and its label are looked up every time, and every handler steps
	 * aside without them.
	 */
	const string SCRIPT = 'window.jQuery&&(function($){var M=__MESSAGES__,F="#billing_rut",C="input[name=billing_is_chilean]",D="billing_rut_description";'
		. 'function n(v){v=String(null==v?"":v).trim().toLowerCase();if(v.normalize)v=v.normalize("NFD").replace(/[^ -~]/g,"");'
		. 'return/^(chilean|chilena|chileno|1|true|si)$/.test(v)||-1<v.indexOf("chile")?"c":/^(foreigner|extranjero|extranjera|0|false|no)$/.test(v)||-1<v.indexOf("extra")?"f":""}'
		. 'function c(){return n($(C).filter(":checked").val())}'
		. 'function q(f){var x=c();return"c"===x||"f"!==x&&"yes"===f.attr("data-rcus-required")}'
		. 'function k(v){return String(v||"").toUpperCase().replace(/[^0-9K]/g,"")}'
		. 'function v(r){var m=/^(\d{7,8})([0-9K])$/.exec(r),s=0,w=2,i;if(!m||/^0+$/.test(m[1])||/^(\d)\1{7}$/.test(m[1]))return!1;'
		. 'for(i=m[1].length-1;0<=i;i--){s+=m[1].charAt(i)*w;w=7===w?2:w+1}s=11-s%11;return m[2]===(11===s?"0":10===s?"K":String(s))}'
		. 'function t(x){var r=k(x).slice(0,9),b=r.slice(0,-1),o="";if(2>r.length)return r;while(3<b.length){o="."+b.slice(-3)+o;b=b.slice(0,-3)}return b+o+"-"+r.slice(-1)}'
		. 'function r(f){f.removeAttr("aria-invalid");if(D===f.attr("aria-describedby"))f.removeAttr("aria-describedby");f.closest(".form-row").removeClass("woocommerce-invalid").find("#"+D).remove()}'
		. 'function b(f,m){r(f);f.attr({"aria-invalid":"true","aria-describedby":D}).closest(".form-row").addClass("woocommerce-invalid").append($("<p></p>").addClass("checkout-inline-error-message").attr("id",D).text(m))}'
		. 'function h(f){var x;if(!f.length)return!0;if(f.prop("disabled")){r(f);return!0}x=k(f.val());if(""===x?!q(f):v(x)){r(f);return!0}b(f,""===x?M.required:M.invalid);return!1}'
		. 'function s(){var f=$(F),x,y,l,a,e;if(!f.length)return;x="f"===c();y=!x&&q(f);f.prop("disabled",x);if(x){f.val("");r(f)}'
		. 'if(y)f.attr("aria-required","true");else f.removeAttr("aria-required");'
		. 'l=$("label[for=billing_rut]");a=l.children(".optional");e=l.children(".required");'
		. 'if(y&&!e.length)e=$("<span></span>").addClass("required").attr("aria-hidden","true").text("*").appendTo(l);a.toggle(!y);e.toggle(y)}'
		. 'function o(){$("form.checkout").off("checkout_place_order.rcusRut").on("checkout_place_order.rcusRut",function(e){var f=$(F);try{if(h(f))return}catch(x){return}'
		. 'e.stopImmediatePropagation();f.trigger("focus");return!1});try{s()}catch(x){}}'
		. '$(document.body).on("input",F,function(){var f=$(this),x=f.val(),y=t(x),p=this.selectionStart,i=0,j=0,g;r(f);if(y===x)return;'
		. 'g="number"===typeof p?k(x.slice(0,p)).length:y.length;f.val(y);for(;i<y.length&&j<g;i++)if(/[0-9K]/.test(y.charAt(i)))j++;try{this.setSelectionRange(i,i)}catch(e){}})'
		. '.on("focusout",F,function(){h($(this))}).on("change",C,function(){r($(F));s()}).on("updated_checkout",o);$(o)})(jQuery);';

	/**
	 * Whether the RUT field was required before this feature made it optional, or null while no RUT field was seen.
	 *
	 * Set by relax_field(): WooCommerce builds the checkout fields once per
	 * request, before it reads the posted data, so the submit's check knows it
	 * by then.
	 *
	 * @var bool|null
	 */
	private ?bool $required = null;

	/**
	 * Hooks the field, the submit and the script while the switch is on.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'rut_validation' ) ) {
			return;
		}

		// After the plugin that adds the field, at 10.
		add_filter( 'woocommerce_checkout_fields', array( $this, 'relax_field' ), 20 );
		add_filter( 'woocommerce_checkout_posted_data', array( $this, 'clear_for_foreigner' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'check_rut' ), 10, 2 );
		// After WooCommerce enqueues wc-checkout, at 10.
		add_action( 'wp_enqueue_scripts', array( $this, 'add_script' ), 100 );
	}

	/**
	 * Takes the requirement away from WooCommerce, which would ask a foreigner for a RUT too.
	 *
	 * Only the required flag and the validate-required class go; what the
	 * flag said stays on the input, for the script, and in this request, for
	 * the submit's check. A field that already carries it was relaxed before,
	 * by another pass of this filter, and keeps what it said then.
	 *
	 * @param mixed $fields Fieldset => field key => field, as WooCommerce builds them.
	 * @return mixed
	 */
	public function relax_field( mixed $fields ): mixed {
		if ( ! is_array( $fields ) || ! is_array( $fields['billing'] ?? null ) || ! is_array( $fields['billing'][ self::FIELD ] ?? null ) ) {
			return $fields;
		}

		$field      = $fields['billing'][ self::FIELD ];
		$attributes = is_array( $field['custom_attributes'] ?? null ) ? $field['custom_attributes'] : array();
		$relaxed    = $attributes[ self::REQUIRED_ATTRIBUTE ] ?? null;

		$this->required = is_string( $relaxed ) ? 'yes' === $relaxed : ! empty( $field['required'] );

		$attributes[ self::REQUIRED_ATTRIBUTE ] = $this->required ? 'yes' : 'no';

		$field['required']          = false;
		$field['custom_attributes'] = $attributes;

		if ( is_array( $field['class'] ?? null ) ) {
			$field['class'] = array_values( array_filter( $field['class'], static fn ( mixed $name ): bool => 'validate-required' !== $name ) );
		}

		$fields['billing'][ self::FIELD ] = $field;

		return $fields;
	}

	/**
	 * Empties a foreigner's RUT, which the script turns off but a page without it still sends.
	 *
	 * @param mixed $data Posted checkout data, by field key.
	 * @return mixed
	 */
	public function clear_for_foreigner( mixed $data ): mixed {
		if ( is_array( $data ) && array_key_exists( self::FIELD, $data ) && self::FOREIGNER === self::choice( $data[ self::CHOICE ] ?? null ) ) {
			$data[ self::FIELD ] = '';
		}

		return $data;
	}

	/**
	 * Refuses a missing RUT where one is required, and one that is not valid.
	 *
	 * Required of a Chilean, never of a foreigner, and with no choice as the
	 * field was before relax_field(). The errors carry the field's id, which
	 * WooCommerce puts on the notice so its script links and marks the field.
	 *
	 * @param mixed $data   Posted checkout data, after woocommerce_checkout_posted_data.
	 * @param mixed $errors The checkout's WP_Error.
	 * @return void
	 */
	public function check_rut( mixed $data, mixed $errors = null ): void {
		if ( null === $this->required || ! is_array( $data ) || ! $errors instanceof \WP_Error ) {
			return;
		}

		$choice = self::choice( $data[ self::CHOICE ] ?? null );

		if ( self::FOREIGNER === $choice ) {
			return;
		}

		$rut = is_string( $data[ self::FIELD ] ?? null ) ? trim( $data[ self::FIELD ] ) : '';

		if ( '' === $rut ) {
			if ( self::CHILEAN === $choice || $this->required ) {
				$errors->add( 'rcus-rut-required', esc_html( self::message( 'required' ) ), array( 'id' => self::FIELD ) );
			}

			return;
		}

		if ( ! self::is_valid( $rut ) ) {
			$errors->add( 'rcus-rut-invalid', esc_html( self::message( 'invalid' ) ), array( 'id' => self::FIELD ) );
		}
	}

	/**
	 * Prints the script after WooCommerce's checkout script, and the style it marks the field with, on the checkout only.
	 *
	 * @return void
	 */
	public function add_script(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$messages = wp_json_encode(
			array(
				'required' => self::message( 'required' ),
				'invalid'  => self::message( 'invalid' ),
			),
			JSON_UNESCAPED_UNICODE
		);

		// Without the script nothing would carry the style's states.
		if ( false === $messages ) {
			return;
		}

		wp_add_inline_script( self::HANDLE, str_replace( '__MESSAGES__', $messages, self::SCRIPT ), 'after' );

		wp_register_style( self::STYLE, false, array(), RCUS_VERSION );
		wp_enqueue_style( self::STYLE );
		wp_add_inline_style( self::STYLE, self::CSS );
	}

	/**
	 * Whether a RUT is valid: Chile's modulo 11 check digit, on a body of seven or eight digits.
	 *
	 * Stripped to its digits and K - dots, dash and spaces go, a lowercase k
	 * counts - it must be 8 or 9 characters, a body of digits and a check
	 * digit. A body of zeros or of eight repeated digits is refused although
	 * its check digit may add up. The check digit is 11 minus the sum of the
	 * body's digits, weighted 2 to 7 from the right, modulo 11: 11 is 0 and
	 * 10 is K.
	 *
	 * Pure, so the cases can hand it any RUT.
	 *
	 * @param string $rut A RUT as typed: 12.345.678-5, 12345678-5 or 123456785.
	 * @return bool
	 */
	public static function is_valid( string $rut ): bool {
		$rut = (string) preg_replace( '/[^0-9K]/', '', strtoupper( $rut ) );

		if ( 1 !== preg_match( '/\A(\d{7,8})([0-9K])\z/', $rut, $parts ) ) {
			return false;
		}

		list( , $body, $digit ) = $parts;

		if ( 1 === preg_match( '/\A0+\z/', $body ) || 1 === preg_match( '/\A(\d)\1{7}\z/', $body ) ) {
			return false;
		}

		$sum    = 0;
		$weight = 2;

		for ( $i = strlen( $body ) - 1; 0 <= $i; $i-- ) {
			$sum   += (int) $body[ $i ] * $weight;
			$weight = 7 === $weight ? 2 : $weight + 1;
		}

		$remainder = 11 - $sum % 11;
		$expected  = match ( $remainder ) {
			11      => '0',
			10      => 'K',
			default => (string) $remainder,
		};

		return $expected === $digit;
	}

	/**
	 * What the radio says, normalized: CHILEAN, FOREIGNER, or an empty string for neither.
	 *
	 * Accents and case aside, as the old plugin read it. Anything but a
	 * scalar - an array a crafted post can send - says neither.
	 *
	 * @param mixed $value The radio's posted value.
	 * @return string
	 */
	public static function choice( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = strtolower( trim( remove_accents( (string) $value ) ) );

		if ( '' === $value ) {
			return '';
		}

		if ( in_array( $value, array( 'chilean', 'chilena', 'chileno', '1', 'true', 'si' ), true ) || str_contains( $value, 'chile' ) ) {
			return self::CHILEAN;
		}

		if ( in_array( $value, array( 'foreigner', 'extranjero', 'extranjera', '0', 'false', 'no' ), true ) || str_contains( $value, 'extra' ) ) {
			return self::FOREIGNER;
		}

		return '';
	}

	/**
	 * One of the two messages, the same in the notice and under the field.
	 *
	 * @param string $which required or invalid.
	 * @return string
	 */
	private static function message( string $which ): string {
		return 'required' === $which
			? __( 'Please enter your RUT.', 'rhinnoit-customizer' )
			: __( 'The RUT you entered is not valid. Please check it.', 'rhinnoit-customizer' );
	}
}
