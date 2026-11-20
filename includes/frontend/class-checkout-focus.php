<?php
/**
 * Removes the prompts that lead away from a checkout with nothing to ship.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Drops the coupon and login prompts that WooCommerce prints above the form,
 * each behind its own switch.
 *
 * Both are links out of the checkout at the moment of paying: one sends the
 * shopper to look for a code they may not have, the other to a login form. On
 * a low-value impulse purchase that is where the journey ends.
 *
 * Scoped to a cart that needs no shipping, the same condition Express_Checkout
 * uses. Someone buying parts keeps both prompts: a returning customer has an
 * account worth signing into, and a coupon they were given is worth applying.
 *
 * Removing the coupon FORM does not disable coupons. A campaign link carrying
 * a code still applies it, and the field comes back for any cart that ships.
 *
 * Classic checkout only: the block checkout draws its own coupon and login
 * prompts and never fires woocommerce_before_checkout_form.
 */
final class Checkout_Focus {

	/**
	 * Whether the coupon prompt goes.
	 *
	 * @var bool
	 */
	private bool $coupon = false;

	/**
	 * Whether the login prompt goes.
	 *
	 * @var bool
	 */
	private bool $login = false;

	/**
	 * Hooks the removal when either prompt is switched off.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->coupon = Settings::feature( 'checkout_coupon_prompt' );
		$this->login  = Settings::feature( 'checkout_login_prompt' );

		if ( ! $this->coupon && ! $this->login ) {
			return;
		}

		add_action( 'template_redirect', array( $this, 'drop_the_prompts' ) );
	}

	/**
	 * Unhooks the chosen prompts when the checkout has nothing to ship.
	 *
	 * Runs on `template_redirect`, late enough that the cart is loaded and
	 * early enough that nothing has been rendered.
	 *
	 * @return void
	 */
	public function drop_the_prompts(): void {
		if ( ! $this->is_checkout_without_shipping() ) {
			return;
		}

		if ( $this->coupon ) {
			remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
		}

		if ( $this->login ) {
			remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_login_form', 10 );
		}
	}

	/**
	 * Whether this request renders a checkout whose cart needs no shipping.
	 *
	 * @return bool
	 */
	private function is_checkout_without_shipping(): bool {
		if ( ! function_exists( 'is_checkout' ) || ! function_exists( 'WC' ) || ! is_checkout() ) {
			return false;
		}

		$wc = WC();

		if ( ! $wc instanceof \WooCommerce || ! $wc->cart instanceof \WC_Cart ) {
			return false;
		}

		return Cart_State::nothing_to_ship( $wc->cart );
	}
}
