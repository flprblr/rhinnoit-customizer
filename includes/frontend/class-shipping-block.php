<?php
/**
 * Hides chosen shipping methods for an address in chosen communes.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Takes the shipping methods picked on the screen away from an address in the communes picked there.
 *
 * Built for express delivery: a same-day promise the courier keeps in a
 * city's central communes and not in its outskirts. A method is one instance
 * of one shipping zone, picked by its instance id, so renaming it changes
 * nothing and the same kind of method in another zone stays.
 *
 * The rates go on woocommerce_package_rates, last, after the couriers'
 * plugins add theirs: the cart and both checkouts lose them. While a package
 * ships to one of the communes and its zone has one of the methods enabled,
 * the classic cart and checkout show the message as a row under the shipping
 * options; an empty message shows nothing, and the methods still go. The
 * rates WooCommerce stored before the rule changed can still hold a hidden
 * method the shopper chose, so the classic checkout's submit refuses it.
 *
 * Inert until both lists hold something.
 */
final class Shipping_Block {

	/**
	 * Instance ids of the methods to hide.
	 *
	 * @var array<int, string>
	 */
	private array $methods = array();

	/**
	 * The communes they are hidden in, as Shipping_Communes::lookup() makes them.
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
	 * Hooks the save of the screen, and the rule while it is on and both lists hold something.
	 *
	 * @return void
	 */
	public function register(): void {
		/*
		 * Whatever the switches say: see Shipping_Communes::refresh_rates().
		 * Shipping_Exclusion hooks the same static callback, which WordPress
		 * keeps once, so a save bumps the version once.
		 */
		add_action( Shipping_Communes::SAVED, array( Shipping_Communes::class, 'refresh_rates' ) );

		if ( ! Settings::feature( 'shipping_block' ) ) {
			return;
		}

		$this->methods  = Settings::chosen( 'shipping_block_methods' );
		$this->communes = Shipping_Communes::lookup( Settings::chosen( 'shipping_block_communes' ) );

		if ( array() === $this->methods || array() === $this->communes ) {
			return;
		}

		// Last, after the couriers' plugins add their rates.
		add_filter( 'woocommerce_package_rates', array( $this, 'hide_rates' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_cart_totals_after_shipping', array( $this, 'print_notice' ) );
		add_action( 'woocommerce_review_order_after_shipping', array( $this, 'print_notice' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'refuse_hidden_method' ), 10, 2 );
	}

	/**
	 * Takes the chosen methods' rates away from a package shipping to one of the communes.
	 *
	 * @param mixed $rates   Rate id => WC_Shipping_Rate.
	 * @param mixed $package The package they were calculated for.
	 * @return mixed
	 */
	public function hide_rates( mixed $rates, mixed $package = null ): mixed {
		if ( ! is_array( $rates ) || ! Shipping_Communes::matches( $package, $this->communes ) ) {
			return $rates;
		}

		foreach ( $rates as $key => $rate ) {
			if ( $rate instanceof \WC_Shipping_Rate && $this->hides( $rate ) ) {
				unset( $rates[ $key ] );
			}
		}

		return $rates;
	}

	/**
	 * Prints the message under the shipping options, once, while a package's zone loses one of the methods.
	 *
	 * The zone is asked because a commune listed here can lie in a zone that
	 * never offered the method: there the message would only puzzle.
	 *
	 * @return void
	 */
	public function print_notice(): void {
		$message = $this->message();

		if ( '' === $message ) {
			return;
		}

		foreach ( Shipping_Communes::packages() as $package ) {
			if ( Shipping_Communes::matches( $package, $this->communes ) && $this->zone_offers_one( $package ) ) {
				Shipping_Communes::print_notice( $message );

				return;
			}
		}
	}

	/**
	 * Refuses an order whose chosen method this rule hides for its commune.
	 *
	 * @param mixed $data   Posted checkout data.
	 * @param mixed $errors The checkout's WP_Error.
	 * @return void
	 */
	public function refuse_hidden_method( mixed $data, mixed $errors = null ): void {
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}

		foreach ( Shipping_Communes::chosen_rates() as list( $package, $rate ) ) {
			if ( $this->hides( $rate ) && Shipping_Communes::matches( $package, $this->communes ) ) {
				Shipping_Communes::refuse( $errors, 'rcus-shipping-block', $this->message() );

				return;
			}
		}
	}

	/**
	 * Every method of every shipping zone, for the screen: instance id => "Zone: method".
	 *
	 * The methods list's options_callback, which only the form calls, when
	 * the screen is drawn or saved: it reads every zone from the database.
	 * The zone WooCommerce keeps for the locations no other zone covers comes
	 * last, as WooCommerce lists it, and a disabled method says so.
	 *
	 * @return array<int, string>
	 */
	public static function method_choices(): array {
		if ( ! class_exists( \WC_Shipping_Zones::class ) || ! class_exists( \WC_Shipping_Zone::class ) ) {
			return array();
		}

		$zones = array();

		foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
			if ( is_array( $zone ) ) {
				$zones[] = array( $zone['zone_name'] ?? '', $zone['shipping_methods'] ?? array() );
			}
		}

		$rest    = new \WC_Shipping_Zone( 0 );
		$zones[] = array( $rest->get_zone_name(), $rest->get_shipping_methods() );

		$choices = array();

		foreach ( $zones as list( $name, $methods ) ) {
			foreach ( is_array( $methods ) ? $methods : array() as $method ) {
				// A method outside every zone has no instance, and no rate of it carries one.
				if ( ! $method instanceof \WC_Shipping_Method || 0 === absint( $method->get_instance_id() ) ) {
					continue;
				}

				$id    = absint( $method->get_instance_id() );
				$title = $method->get_title();
				$label = sprintf( '%1$s: %2$s', is_string( $name ) ? $name : '', is_string( $title ) ? $title : '' );

				if ( ! $method->is_enabled() ) {
					/* translators: %s: a shipping zone and one of its methods, as "Zone: method". */
					$label = sprintf( __( '%s (disabled)', 'rhinnoit-customizer' ), $label );
				}

				$choices[ $id ] = $label;
			}
		}

		return $choices;
	}

	/**
	 * Whether this rule hides a rate: one of the chosen instance ids.
	 *
	 * @param \WC_Shipping_Rate $rate A rate.
	 * @return bool
	 */
	private function hides( \WC_Shipping_Rate $rate ): bool {
		$id = $rate->get_instance_id();

		return ( is_int( $id ) || is_string( $id ) ) && in_array( (string) $id, $this->methods, true );
	}

	/**
	 * Whether the zone a package ships through has one of the chosen methods enabled.
	 *
	 * @param array<mixed> $package A shipping package.
	 * @return bool
	 */
	private function zone_offers_one( array $package ): bool {
		if ( ! class_exists( \WC_Shipping_Zones::class ) ) {
			return false;
		}

		foreach ( \WC_Shipping_Zones::get_zone_matching_package( $package )->get_shipping_methods( true ) as $method ) {
			if ( $method instanceof \WC_Shipping_Method && in_array( (string) absint( $method->get_instance_id() ), $this->methods, true ) ) {
				return true;
			}
		}

		return false;
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
		$this->message ??= Settings::text( 'shipping_block_message' );

		return $this->message;
	}
}
