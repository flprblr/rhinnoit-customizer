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
 * plugins add theirs: the cart and both checkouts lose them. The message is
 * for the shopper whose choice the rule took: once it took the rate they had
 * selected and WooCommerce picked another in its place, and while a package
 * ships to one of the communes and its zone has one of the methods enabled,
 * the classic cart and checkout show it as a row under the shipping options.
 * A shopper who never had one of the methods selected sees nothing: in the
 * communes alone the message reads as if there were no delivery at all. An
 * empty message shows nothing, and the methods still go. The rates
 * WooCommerce stored before the rule changed can still hold a hidden method
 * the shopper chose, and a checkout drawn before can still post one that is
 * gone from the rates, so the classic checkout's submit refuses both.
 *
 * Inert until both lists hold something.
 */
final class Shipping_Block {

	/**
	 * Session key under which the shopper's session keeps that the rule took the rate they had chosen: see note_taken_choice().
	 *
	 * It holds the shipping version WooCommerce keyed the rates by when the
	 * rule took the choice, and counts only while that version is current.
	 */
	const string TAKEN_CHOICE = 'rcus_shipping_block_taken_choice';

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
	 * Rate ids hide_rates() took away in this request, for the submit and the shopper's choice: see takes() and note_taken_choice().
	 *
	 * @var array<string, bool>
	 */
	private array $taken = array();

	/**
	 * The rates the session held as chosen when the cart loaded, by package index: see remember_chosen_rates().
	 *
	 * @var array<int|string, string>
	 */
	private array $chosen_before = array();

	/**
	 * Hooks the save of the screen and any plugin's activation and deactivation, and the rule while it is on and both lists hold something.
	 *
	 * @return void
	 */
	public function register(): void {
		/*
		 * Whatever the switches say: see Shipping_Communes::refresh_rates().
		 * Shipping_Exclusion hooks the same static callback, which WordPress
		 * keeps once per hook, so each bumps the version once.
		 */
		add_action( Shipping_Communes::SAVED, array( Shipping_Communes::class, 'refresh_rates' ) );
		add_action( 'activated_plugin', array( Shipping_Communes::class, 'refresh_rates' ) );
		add_action( 'deactivated_plugin', array( Shipping_Communes::class, 'refresh_rates' ) );

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
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'remember_chosen_rates' ) );
		add_filter( 'woocommerce_shipping_chosen_method', array( $this, 'note_taken_choice' ), 10, 3 );
		add_action( 'woocommerce_cart_updated', array( $this, 'forget_taken_choice' ) );
		add_action( 'woocommerce_cart_emptied', array( $this, 'forget_taken_choice' ) );
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
				$this->taken[ (string) $key ] = true;

				unset( $rates[ $key ] );
			}
		}

		return $rates;
	}

	/**
	 * Keeps the rates the session holds as chosen when the cart loads, before this request can wipe them.
	 *
	 * The classic cart's shipping calculator unsets every chosen rate
	 * (WC_Shipping::reset_shipping()) before it works out the rates for the
	 * address it was given, so WooCommerce then tells
	 * woocommerce_shipping_chosen_method that nothing was chosen.
	 * note_taken_choice() reads these instead, in that request.
	 *
	 * @return void
	 */
	public function remember_chosen_rates(): void {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$session     = $woocommerce instanceof \WooCommerce ? $woocommerce->session : null;
		$chosen      = is_object( $session ) && method_exists( $session, 'get' ) ? $session->get( 'chosen_shipping_methods' ) : null;

		$this->chosen_before = is_array( $chosen ) ? array_filter( $chosen, 'is_string' ) : array();
	}

	/**
	 * Keeps in the shopper's session that the rule took the rate they had chosen, when WooCommerce picks another in its place.
	 *
	 * WooCommerce asks this filter for a package's default rate whenever the
	 * chosen one is gone from its rates, or the rates changed, and passes the
	 * rate that was chosen - selected by the shopper, or by WooCommerce as the
	 * first rate - or false. False also comes after the cart's shipping
	 * calculator unset the choice (woocommerce_calculated_shipping fired): the
	 * rates chosen when the cart loaded, for the packages being rated, stand
	 * in for it then. A rate counts only when hide_rates() took that very id
	 * in this request and it is gone from this package's rates: then the rule
	 * took the shopper's choice, which is what the message explains. Kept in
	 * the session because the classic checkout draws its review again at
	 * every change of an address field, and the message has to outlive that;
	 * print_notice() and forget_taken_choice() say when it goes. WooCommerce
	 * asks this on both checkouts, so the block checkout keeps the answer
	 * too, though only the classic cart and checkout print the message.
	 *
	 * @param mixed $rate_id The rate WooCommerce picks in its place.
	 * @param mixed $rates   The package's rates, rate id => WC_Shipping_Rate.
	 * @param mixed $chosen  The rate that was chosen, or false.
	 * @return mixed The rate WooCommerce picks, untouched.
	 */
	public function note_taken_choice( mixed $rate_id, mixed $rates = null, mixed $chosen = null ): mixed {
		if ( is_string( $chosen ) ) {
			$candidates = array( $chosen );
		} elseif ( did_action( 'woocommerce_calculated_shipping' ) ) {
			// Only the packages being rated now: the session keeps the choice of a package the cart no longer has.
			$candidates = array_intersect_key( $this->chosen_before, Shipping_Communes::packages() );
		} else {
			$candidates = array();
		}

		foreach ( $candidates as $id ) {
			if ( isset( $this->taken[ $id ] ) && is_array( $rates ) && ! isset( $rates[ $id ] ) ) {
				self::keep_taken_choice( self::rates_version() );

				break;
			}
		}

		return $rate_id;
	}

	/**
	 * Forgets that the rule took the shopper's choice once the cart has nothing to ship: emptied, its last line removed or dropped, the order placed.
	 *
	 * Hooked to woocommerce_cart_emptied, which empty_cart() fires when an
	 * order is placed, and to woocommerce_cart_updated, which WooCommerce
	 * fires each time it stores the cart: where it removes a line, and where
	 * it drops one on loading the cart. WooCommerce 11 forgets its own chosen
	 * rates there too once nothing in the cart ships.
	 *
	 * @return void
	 */
	public function forget_taken_choice(): void {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$session     = $woocommerce instanceof \WooCommerce ? $woocommerce->session : null;
		$cart        = $woocommerce instanceof \WooCommerce ? $woocommerce->cart : null;

		if ( ! is_object( $session ) || ! method_exists( $session, 'get' ) || null === $session->get( self::TAKEN_CHOICE ) ) {
			return;
		}

		if ( ! $cart instanceof \WC_Cart || ! $cart->needs_shipping() ) {
			self::keep_taken_choice( null );
		}
	}

	/**
	 * Prints the message under the shipping options, once, while the rule has taken the shopper's choice and a package's zone loses one of the methods.
	 *
	 * The zone is asked because a commune listed here can lie in a zone that
	 * never offered the method: there the message would only puzzle. Shipping
	 * options drawn with no package in the communes mean the address left
	 * them, so what the rule took there is forgotten: the message is for a
	 * choice the rule takes from now on.
	 *
	 * @return void
	 */
	public function print_notice(): void {
		$message = $this->message();

		if ( '' === $message || ! self::taken_choice() ) {
			return;
		}

		$in_communes = false;

		foreach ( Shipping_Communes::packages() as $package ) {
			if ( ! Shipping_Communes::matches( $package, $this->communes ) ) {
				continue;
			}

			if ( $this->zone_offers_one( $package ) ) {
				Shipping_Communes::print_notice( $message );

				return;
			}

			$in_communes = true;
		}

		if ( ! $in_communes ) {
			self::keep_taken_choice( null );
		}
	}

	/**
	 * Refuses an order whose chosen method this rule hides for its commune.
	 *
	 * Either the hidden method is still in the rates WooCommerce stored, and
	 * chosen, or the submit posted it and it is gone from the rates, where
	 * WooCommerce would have moved the package to another method. The second
	 * time the review is drawn again, so the shopper sees what is left. A
	 * package with no method left is WooCommerce's to refuse, and it does: a
	 * second notice would only ask for another method where there is none.
	 * The order is refused once, however many packages lose the method.
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

		foreach ( Shipping_Communes::posted_rates_gone( $data ) as list( $package, $id ) ) {
			if ( ! Shipping_Communes::matches( $package, $this->communes ) ) {
				continue;
			}

			// WooCommerce refuses a package with nothing left on its own: the
			// review is only drawn again, so it stops offering what is gone.
			if ( ! Shipping_Communes::has_rates( $package ) ) {
				Shipping_Communes::redraw_review();

				continue;
			}

			// takes() last: it may read the instance's zone row.
			if ( $this->takes( $id ) ) {
				Shipping_Communes::refuse( $errors, 'rcus-shipping-block', $this->message() );
				Shipping_Communes::redraw_review();

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
	 * Whether this rule takes a rate the submit posted by its id: one of a chosen instance's.
	 *
	 * Read from the id itself, because the rates may have been worked out
	 * again by an earlier request, where this request's filter never saw
	 * them. A zone method's rate id is its method id, its instance id and
	 * maybe a suffix of its own, joined by colons
	 * (WC_Shipping_Method::get_rate_id()). A method outside every zone joins
	 * its id and a number of its own the same way - the block checkout's
	 * pickup_location:N, N a location - so a chosen instance in second place
	 * counts only behind that instance's own method id, which WooCommerce is
	 * asked here, at the submit, and never on a page load. Any other id
	 * counts only when hide_rates() took that very id in this request.
	 *
	 * @param string $id A posted rate id.
	 * @return bool
	 */
	private function takes( string $id ): bool {
		if ( isset( $this->taken[ $id ] ) ) {
			return true;
		}

		$segments = explode( ':', $id );
		$instance = $segments[1] ?? '';

		if ( ! in_array( $instance, $this->methods, true ) ) {
			return false;
		}

		return self::method_id_of( absint( $instance ) ) === $segments[0];
	}

	/**
	 * The method id an instance was added with, read from its zone's row: '' without one.
	 *
	 * Read from WooCommerce's shipping zone data store, not through
	 * WC_Shipping_Zones::get_shipping_method(), which builds the method's
	 * class and is a fatal when a shipping plugin registered a class it never
	 * defines. One query, made only at the submit.
	 *
	 * @param int $instance Instance id.
	 * @return string
	 */
	private static function method_id_of( int $instance ): string {
		if ( ! class_exists( \WC_Shipping_Zone_Data_Store::class ) ) {
			return '';
		}

		$row = ( new \WC_Shipping_Zone_Data_Store() )->get_method( $instance );

		return is_object( $row ) && isset( $row->method_id ) && is_string( $row->method_id ) ? $row->method_id : '';
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
	 * Whether the shopper's session keeps that the rule took the rate they had chosen, under the shipping version still current.
	 *
	 * Every save of the screen renews that version (Shipping_Communes::refresh_rates()),
	 * so a choice taken before the rule was switched off and on, its lists
	 * changed or its message filled in counts for nothing, like the rates it
	 * was read from. Whatever else renews it drops the answer as well - a
	 * save of another screen of the group, a plugin activated or deactivated,
	 * a shipping zone saved, the version lost from the object cache - and
	 * the message does not come back for that choice: it fails towards
	 * silence. The session is read from the database: anything but the
	 * current version is no answer.
	 *
	 * @return bool
	 */
	private static function taken_choice(): bool {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$session     = $woocommerce instanceof \WooCommerce ? $woocommerce->session : null;
		$stored      = is_object( $session ) && method_exists( $session, 'get' ) ? $session->get( self::TAKEN_CHOICE ) : null;

		return is_string( $stored ) && '' !== $stored && self::rates_version() === $stored;
	}

	/**
	 * Writes into the shopper's session the shipping version the rule took their choice under, or forgets it with null.
	 *
	 * @param string|null $version The current shipping version, or null to forget.
	 * @return void
	 */
	private static function keep_taken_choice( ?string $version ): void {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$session     = $woocommerce instanceof \WooCommerce ? $woocommerce->session : null;

		if ( is_object( $session ) && method_exists( $session, 'set' ) ) {
			$session->set( self::TAKEN_CHOICE, '' === $version ? null : $version );
		}
	}

	/**
	 * The version WooCommerce keys every shopper's stored rates by: '' without its cache helper.
	 *
	 * @return string
	 */
	private static function rates_version(): string {
		if ( ! class_exists( \WC_Cache_Helper::class ) ) {
			return '';
		}

		$version = \WC_Cache_Helper::get_transient_version( 'shipping' );

		return is_scalar( $version ) ? (string) $version : '';
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
