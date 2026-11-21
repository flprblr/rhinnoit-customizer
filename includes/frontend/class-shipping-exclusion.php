<?php
/**
 * Stops deliveries to chosen communes, keeping store pickup.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Leaves only store pickup to an address in the communes picked on the screen.
 *
 * For when the courier stops delivering somewhere - a disaster zone, a road
 * cut - and every delivery method there has to go at once, whatever zone or
 * courier offers it, while the shopper can still pick the order up.
 *
 * The rates go on woocommerce_package_rates, last, after the couriers'
 * plugins add theirs: every rate but WooCommerce's pickup ones leaves the
 * cart and both checkouts. With pickup left, the classic cart and checkout
 * show the message as a row under the shipping options; with nothing left,
 * the message replaces WooCommerce's own "no shipping options" text in that
 * package instead, and no row is printed, so it never shows twice. An empty
 * message prints nothing and leaves WooCommerce's text; the methods still go.
 * The rates WooCommerce stored before the rule changed can still hold a
 * delivery the shopper chose, so the classic checkout's submit refuses it.
 *
 * Inert until its list holds something.
 */
final class Shipping_Exclusion {

	/**
	 * Method ids of the rates that stay: WooCommerce's local pickup, and the pickup locations of its block checkout.
	 *
	 * @var array<int, string>
	 */
	const array PICKUP = array( 'local_pickup', 'pickup_location' );

	/**
	 * The communes not delivered to, as Shipping_Communes::lookup() makes them.
	 *
	 * @var array<string, bool>
	 */
	private array $communes = array();

	/**
	 * The message, once read: see message().
	 *
	 * @var string|null
	 */
	private ?string $message = null;

	/**
	 * Hooks the save of the screen, and the rule while it is on and its list holds something.
	 *
	 * @return void
	 */
	public function register(): void {
		/*
		 * Whatever the switches say: see Shipping_Communes::refresh_rates().
		 * Shipping_Block hooks the same static callback, which WordPress keeps
		 * once, so a save bumps the version once.
		 */
		add_action( Shipping_Communes::SAVED, array( Shipping_Communes::class, 'refresh_rates' ) );

		if ( ! Settings::feature( 'shipping_exclusion' ) ) {
			return;
		}

		$this->communes = Shipping_Communes::lookup( Settings::chosen( 'shipping_exclusion_communes' ) );

		if ( array() === $this->communes ) {
			return;
		}

		// Last, after the couriers' plugins add their rates.
		add_filter( 'woocommerce_package_rates', array( $this, 'keep_pickup_only' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_cart_totals_after_shipping', array( $this, 'print_notice' ) );
		add_action( 'woocommerce_review_order_after_shipping', array( $this, 'print_notice' ) );
		add_filter( 'woocommerce_no_shipping_available_html', array( $this, 'replace_no_shipping_text' ) );
		add_filter( 'woocommerce_cart_no_shipping_available_html', array( $this, 'replace_no_shipping_text' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'refuse_delivery' ), 10, 2 );
	}

	/**
	 * Takes every rate but pickup away from a package shipping to one of the communes.
	 *
	 * @param mixed $rates   Rate id => WC_Shipping_Rate.
	 * @param mixed $package The package they were calculated for.
	 * @return mixed
	 */
	public function keep_pickup_only( mixed $rates, mixed $package = null ): mixed {
		if ( ! is_array( $rates ) || ! Shipping_Communes::matches( $package, $this->communes ) ) {
			return $rates;
		}

		foreach ( $rates as $key => $rate ) {
			if ( ! self::is_pickup( $rate ) ) {
				unset( $rates[ $key ] );
			}
		}

		return $rates;
	}

	/**
	 * Prints the message under the shipping options, once, while a package ships to one of the communes with pickup left.
	 *
	 * A package left with nothing gets the message in place of WooCommerce's
	 * own text (replace_no_shipping_text()), so then there is no row.
	 *
	 * @return void
	 */
	public function print_notice(): void {
		$message = $this->message();

		if ( '' === $message ) {
			return;
		}

		$matched = false;

		foreach ( Shipping_Communes::packages() as $package ) {
			if ( ! Shipping_Communes::matches( $package, $this->communes ) ) {
				continue;
			}

			if ( ! self::has_rates( $package ) ) {
				return;
			}

			$matched = true;
		}

		if ( $matched ) {
			Shipping_Communes::print_notice( $message );
		}
	}

	/**
	 * Puts the message in place of WooCommerce's text when a package shipping to one of the communes has nothing left.
	 *
	 * WooCommerce prints that text through wp_kses_post(), and only for a
	 * package without rates: the classic checkout's filter passes the text
	 * alone, the cart's the text and the address it formatted.
	 *
	 * @param mixed $html WooCommerce's text.
	 * @return mixed
	 */
	public function replace_no_shipping_text( mixed $html ): mixed {
		$message = $this->message();

		if ( '' === $message ) {
			return $html;
		}

		foreach ( Shipping_Communes::packages() as $package ) {
			if ( Shipping_Communes::matches( $package, $this->communes ) && ! self::has_rates( $package ) ) {
				return Shipping_Communes::html( $message );
			}
		}

		return $html;
	}

	/**
	 * Refuses an order that chose a delivery for a package shipping to one of the communes.
	 *
	 * @param mixed $data   Posted checkout data.
	 * @param mixed $errors The checkout's WP_Error.
	 * @return void
	 */
	public function refuse_delivery( mixed $data, mixed $errors = null ): void {
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}

		foreach ( Shipping_Communes::chosen_rates() as list( $package, $rate ) ) {
			if ( ! self::is_pickup( $rate ) && Shipping_Communes::matches( $package, $this->communes ) ) {
				Shipping_Communes::refuse( $errors, 'rcus-shipping-exclusion', $this->message() );

				return;
			}
		}
	}

	/**
	 * Whether a rate is one of WooCommerce's pickup ones.
	 *
	 * @param mixed $rate A rate.
	 * @return bool
	 */
	private static function is_pickup( mixed $rate ): bool {
		return $rate instanceof \WC_Shipping_Rate && in_array( $rate->get_method_id(), self::PICKUP, true );
	}

	/**
	 * Whether a package has any rate left to choose.
	 *
	 * @param array<mixed> $package A shipping package.
	 * @return bool
	 */
	private static function has_rates( array $package ): bool {
		return is_array( $package['rates'] ?? null ) && array() !== $package['rates'];
	}

	/**
	 * The message, read the first time it is printed.
	 *
	 * Not in register(): its declared default is a translated sentence, and
	 * the translations load on init, after the services register on
	 * plugins_loaded. Read there, a row never saved would print in English on
	 * every shop.
	 *
	 * @return string
	 */
	private function message(): string {
		$this->message ??= Settings::text( 'shipping_exclusion_message' );

		return $this->message;
	}
}
