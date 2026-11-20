<?php
/**
 * Cancels unpaid orders close to WooCommerce's hold stock time.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Makes WooCommerce look for unpaid orders every few minutes.
 *
 * WooCommerce runs wc_cancel_unpaid_orders() once per Hold stock period N
 * and cancels the checkout orders still pending whose last update is older
 * than N, so an unpaid order can stay pending for almost 2N. Its own filter
 * sets how often it looks, and all three places that schedule the check read
 * it: after every run, the daily repair Action Scheduler fires at 03:00, and
 * a save of Products > Inventory. Below N = 15 it steps aside: Webpay's
 * payment window is about 9-10 minutes, and a payment confirmed after the
 * cancellation reopens the order. Fintoc's sessions stay payable for 24 hours
 * and Mercado Pago's preferences never expire, so with them a late payment can
 * reach an order already cancelled whenever that happens; Fintoc_Session_Expiry
 * bounds Fintoc's.
 *
 * On the classic checkout stock and coupons are not what changes: an order
 * still pending has taken neither, and WooCommerce's stock reservation and
 * coupon hold expire at N whether or not it is cancelled. The block checkout
 * moves its order from checkout-draft to pending, which counts a coupon as
 * used until the order is cancelled. What comes sooner on either is the
 * Cancelled status, the admin's email and the end of the Pay button in My
 * Account.
 *
 * This replaces a must-use file the shops ran before. It asked Action
 * Scheduler on every uncached request whether the job existed, ran the
 * cancellation twice per check, and its five-minute job outlived the file.
 * Here nothing runs per request and nothing is scheduled: WooCommerce keeps
 * its own job, so turning the switch off needs no cleanup. The next check
 * WooCommerce schedules is at N again. The old file's five-minute job
 * repeats after every run, because Action Scheduler schedules the next one
 * once the callback has cancelled what was pending; it only stops when
 * WooCommerce's own check runs first. With the switch on and a hold of 15 or
 * more that is the next check, two minutes out; otherwise a save of
 * Products > Inventory cancels it.
 */
final class Unpaid_Orders {

	/**
	 * Hold stock minutes below which WooCommerce's interval is left as it is.
	 */
	const int MIN_HOLD = 15;

	/**
	 * Minutes between two checks.
	 *
	 * Action Scheduler's async runner starts at most once a minute.
	 */
	const int INTERVAL = 2;

	/**
	 * Hooks WooCommerce's interval filter while the switch is on.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'unpaid_orders' ) ) {
			return;
		}

		add_filter( 'woocommerce_cancel_unpaid_orders_interval_minutes', array( $this, 'shorten_interval' ) );
	}

	/**
	 * Shortens the interval when the hold is long enough.
	 *
	 * Untyped on purpose: WooCommerce has already unscheduled the job when it
	 * applies this filter, so a TypeError here would leave no next check
	 * until the 03:00 repair. The value passed is the one used, never the
	 * stored option: a Products > Inventory save passes the new Hold stock
	 * before it is stored. It never raises a value and never turns one of 1
	 * or more into 0, WooCommerce's "do not reschedule".
	 *
	 * @param mixed $minutes Interval in minutes; WooCommerce passes absint() of Hold stock.
	 * @return mixed
	 */
	public function shorten_interval( mixed $minutes ): mixed {
		if ( ! is_numeric( $minutes ) || (int) $minutes < self::MIN_HOLD ) {
			return $minutes;
		}

		return self::INTERVAL;
	}
}
