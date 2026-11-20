<?php
/**
 * Ends a Fintoc payment page when the order's stock hold ends.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Gives each Fintoc Checkout Session an expiry at the end of Hold stock.
 *
 * Fintoc for WooCommerce (1.2.4) creates a Checkout Session for every payment
 * attempt without an expires_at, and Fintoc then keeps it payable for 24
 * hours. WooCommerce holds the order's units for Hold stock minutes only (30
 * on hoka), and cancels the unpaid order around then. A shopper who pays on a
 * session still open after that is completed anyway: Fintoc calls
 * payment_complete(), which turns a cancelled or failed order into
 * processing, and the units - by then free, perhaps sold to someone else -
 * are taken a second time. Nothing at the shop sees that payment coming: it
 * happens on Fintoc's page, with no request to the shop.
 *
 * Fintoc's own filter, woocommerce_fintoc_checkout_session_payload, receives
 * the request body and the order; here it gets an expires_at at the order's
 * creation plus Hold stock, the moment WooCommerce's reservation of a fresh
 * checkout ends. Fintoc refuses an expiry less than 10 minutes away, so a
 * session opened late - a retry from the order's payment page - gets at least
 * FLOOR minutes. When a session expires unpaid, Fintoc cancels the order
 * itself, about when WooCommerce would. It cancels on the expiry of any of the
 * order's sessions, so a retry's session can stay payable for up to FLOOR
 * minutes after the first one cancelled the order; only Fintoc can close
 * that. A payload that already carries an expiry, a shop that does not manage
 * stock, or a Hold stock of 0 is left as it came.
 */
final class Fintoc_Session_Expiry {

	/**
	 * Fewest minutes a session stays open: Fintoc's minimum is 10, plus one for clock skew.
	 */
	const int FLOOR = 11;

	/**
	 * Hooks Fintoc's payload filter while the switch is on.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'fintoc_session_expiry' ) ) {
			return;
		}

		add_filter( 'woocommerce_fintoc_checkout_session_payload', array( $this, 'end_with_hold' ), 10, 2 );
	}

	/**
	 * Sets the session's expiry to the end of the order's stock hold.
	 *
	 * @param mixed $payload The Checkout Session request body.
	 * @param mixed $order   The order being paid.
	 * @return mixed
	 */
	public function end_with_hold( mixed $payload, mixed $order = null ): mixed {
		if ( ! is_array( $payload ) || ! empty( $payload['expires_at'] ) || ! $order instanceof \WC_Order || 'yes' !== get_option( 'woocommerce_manage_stock' ) ) {
			return $payload;
		}

		$hold = get_option( 'woocommerce_hold_stock_minutes' );

		if ( ! is_numeric( $hold ) || 0 >= (int) $hold ) {
			return $payload;
		}

		$created = $order->get_date_created();
		$start   = $created instanceof \DateTimeInterface ? $created->getTimestamp() : time();
		$expires = max( $start + (int) $hold * MINUTE_IN_SECONDS, time() + self::FLOOR * MINUTE_IN_SECONDS );

		$payload['expires_at'] = gmdate( 'Y-m-d\TH:i:s\Z', $expires );

		return $payload;
	}
}
