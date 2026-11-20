<?php
/**
 * Shortens the journey by skipping the cart page.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the shopper to checkout instead of the cart, behind two switches.
 *
 * "Virtual products" decides from the cart itself, never from a product ID or
 * a category: every line has to be a virtual product. There is nothing on the
 * cart page for such an order - no shipping method to pick, no address to
 * preview - while a cart with anything physical keeps the normal flow,
 * because someone assembling an order of parts needs the cart to keep adding
 * to it. "Every product" skips the cart page for every cart, physical ones
 * included, for a shop that has decided its customers do not need it.
 *
 * The decision is made after the add, with the real cart, never predicted
 * from the page. An add reaches the shopper two ways:
 *
 * - An add that navigates (a form post, a ?add-to-cart= link): WooCommerce
 *   asks woocommerce_add_to_cart_redirect where to go next.
 * - An AJAX add - WooCommerce's listing buttons, and Porto's product page,
 *   quick view and variation forms, which all intercept the click - fires
 *   woocommerce_ajax_added_to_cart once the product is in the cart. Both
 *   scripts send the shopper to product_url when the response carries it
 *   with error set, the path they already use to show a failed add, so that
 *   is the answer given here when the cart goes to checkout. Like
 *   WooCommerce's own redirect-to-cart option, the page is left rather than
 *   updated, so no added_to_cart event fires.
 *
 * Nothing is printed into the page, so nothing depends on which visitor a
 * cached page was built for, and no button is changed.
 */
final class Express_Checkout {

	/**
	 * Whether a cart of virtual products skips the cart page.
	 *
	 * @var bool
	 */
	private bool $virtual = false;

	/**
	 * Whether every cart skips the cart page.
	 *
	 * @var bool
	 */
	private bool $every = false;

	/**
	 * Hooks the two redirects when either switch is on.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->virtual = Settings::feature( 'express_checkout' );
		$this->every   = Settings::feature( 'express_checkout_all' );

		if ( ! $this->virtual && ! $this->every ) {
			return;
		}

		add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'redirect_to_checkout' ), 10, 2 );

		// Last, so whatever else listens to a successful add has run first.
		add_action( 'woocommerce_ajax_added_to_cart', array( $this, 'send_ajax_add_to_checkout' ), PHP_INT_MAX );
	}

	/**
	 * Points the redirect after a navigating add at checkout.
	 *
	 * WooCommerce also runs this filter with no product, to build the cart link
	 * its scripts print on every page ("View cart" after an AJAX add). That
	 * call is not an add and gets its URL back: pointing it at checkout would
	 * send a shopper who just added something physical past the cart.
	 *
	 * @param mixed $url     Where WooCommerce was going to send the shopper.
	 * @param mixed $product The product just added, or null outside an add.
	 * @return mixed
	 */
	public function redirect_to_checkout( mixed $url, mixed $product = null ): mixed {
		if ( null === $product || ! $this->cart_goes_to_checkout() ) {
			return $url;
		}

		return $this->checkout_url() ?? $url;
	}

	/**
	 * Answers an AJAX add with checkout as the next page.
	 *
	 * @return void
	 */
	public function send_ajax_add_to_checkout(): void {
		if ( ! $this->cart_goes_to_checkout() ) {
			return;
		}

		$checkout = $this->checkout_url();

		if ( null === $checkout ) {
			return;
		}

		wp_send_json(
			array(
				'error'       => true,
				'product_url' => $checkout,
			)
		);
	}

	/**
	 * Whether the cart as it is now should skip the cart page.
	 *
	 * @return bool
	 */
	private function cart_goes_to_checkout(): bool {
		if ( $this->every ) {
			return true;
		}

		return $this->virtual && $this->cart_is_ready() && Cart_State::nothing_to_ship( WC()->cart );
	}

	/**
	 * The checkout URL, or null when the shop has no usable one.
	 *
	 * An empty string comes back from wc_get_checkout_url() when the page was
	 * deleted or never assigned, and sending the shopper there would strand
	 * them.
	 *
	 * @return string|null
	 */
	private function checkout_url(): ?string {
		if ( ! function_exists( 'wc_get_checkout_url' ) ) {
			return null;
		}

		$checkout = wc_get_checkout_url();

		return is_string( $checkout ) && '' !== $checkout ? $checkout : null;
	}

	/**
	 * Whether the cart object is loaded and usable.
	 *
	 * `WC()->cart` is null before `wp_loaded` and on requests where WooCommerce
	 * decided not to build a session, so it cannot be taken for granted even
	 * inside a hook that normally runs late.
	 *
	 * @return bool
	 */
	private function cart_is_ready(): bool {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		$wc = WC();

		return $wc instanceof \WooCommerce && $wc->cart instanceof \WC_Cart;
	}
}
