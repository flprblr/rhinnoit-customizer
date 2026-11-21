<?php
/**
 * What the two shipping rules share: the commune they match, the message, the rates WooCommerce keeps.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Answers for Shipping_Block and Shipping_Exclusion, which ask the same questions of a package.
 *
 * A package matches when the city it ships to equals one of the communes
 * chosen on the screen once both are normalized: accents removed, lowercase,
 * trimmed, inner whitespace collapsed. With Commune_Select on the city is the
 * commune's official name; with it off it is what the shopper typed, so
 * "nunoa" and " ÑUÑOA " match Ñuñoa while "Santiago Centro" matches nothing.
 * The region is not compared: the commune alone decides.
 *
 * WooCommerce keeps each package's rates in the shopper's session under a
 * hash that includes its 'shipping' transient version, and recalculates them
 * only when the package changes. A list changed on the screen would wait
 * until then, so both services hook refresh_rates() to every save of the
 * screen, as WooCommerce bumps that version when its own zones are saved.
 *
 * Not a service: nothing here is registered by Plugin.
 */
final class Shipping_Communes {

	/**
	 * Group hook fired after every save of a settings screen of the group.
	 *
	 * Admin\Settings_Page::SAVED_HOOK, written out: the services register on
	 * every storefront request, and reading the constant would load the admin
	 * class there. A test holds the two equal.
	 */
	const string SAVED = 'rhinnoit_settings_saved';

	/**
	 * A name as both sides are compared: accents removed, lowercase, trimmed, inner whitespace collapsed.
	 *
	 * @param string $name A commune's name, or what a shopper typed as the city.
	 * @return string
	 */
	public static function normalize( string $name ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', strtolower( remove_accents( $name ) ) ) );
	}

	/**
	 * Chosen communes as a lookup of their normalized names.
	 *
	 * Built once, in register(), so matching a package costs one normalization.
	 *
	 * @param array<int, string> $communes Communes' names, as Settings::chosen() reads them.
	 * @return array<string, bool> Normalized name => true.
	 */
	public static function lookup( array $communes ): array {
		$lookup = array();

		foreach ( $communes as $commune ) {
			$lookup[ self::normalize( $commune ) ] = true;
		}

		unset( $lookup[''] );

		return $lookup;
	}

	/**
	 * Whether a package ships to one of these communes.
	 *
	 * @param mixed               $package A shipping package, as WooCommerce builds it.
	 * @param array<string, bool> $lookup  What lookup() made of the chosen communes.
	 * @return bool
	 */
	public static function matches( mixed $package, array $lookup ): bool {
		$destination = is_array( $package ) ? ( $package['destination'] ?? null ) : null;
		$city        = is_array( $destination ) ? ( $destination['city'] ?? null ) : null;

		return is_string( $city ) && isset( $lookup[ self::normalize( $city ) ] );
	}

	/**
	 * The packages WooCommerce last shipped the cart as, by index, each with its destination and rates.
	 *
	 * @return array<int|string, array<mixed>>
	 */
	public static function packages(): array {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$shipping    = $woocommerce instanceof \WooCommerce ? $woocommerce->shipping() : null;
		$packages    = $shipping instanceof \WC_Shipping ? $shipping->get_packages() : null;

		return is_array( $packages ) ? array_filter( $packages, 'is_array' ) : array();
	}

	/**
	 * Each package with the rate the shopper chose for it, where its rates still hold that rate.
	 *
	 * A rate a rule took away is gone from its package, and WooCommerce
	 * refuses that order itself. What is left to check is a chosen rate still
	 * in the rates WooCommerce stored before the rule changed.
	 *
	 * @return array<int, array{0: array<mixed>, 1: \WC_Shipping_Rate}>
	 */
	public static function chosen_rates(): array {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$session     = $woocommerce instanceof \WooCommerce ? $woocommerce->session : null;
		$chosen      = is_object( $session ) && method_exists( $session, 'get' ) ? $session->get( 'chosen_shipping_methods' ) : null;

		if ( ! is_array( $chosen ) ) {
			return array();
		}

		$pairs = array();

		foreach ( self::packages() as $index => $package ) {
			$id    = $chosen[ $index ] ?? null;
			$rates = $package['rates'] ?? null;
			$rate  = is_string( $id ) && is_array( $rates ) ? ( $rates[ $id ] ?? null ) : null;

			if ( $rate instanceof \WC_Shipping_Rate ) {
				$pairs[] = array( $package, $rate );
			}
		}

		return $pairs;
	}

	/**
	 * Prints a rule's message as its own row, under the shipping row of the cart totals or the order review.
	 *
	 * @param string $message What the shopper reads; never empty here.
	 * @return void
	 */
	public static function print_notice( string $message ): void {
		printf(
			'<tr class="rcus-shipping-notice"><td colspan="2"><div class="woocommerce-info">%s</div></td></tr>',
			nl2br( esc_html( $message ) )
		);
	}

	/**
	 * A rule's message as HTML: escaped, its line breaks kept.
	 *
	 * @param string $message What the shopper reads.
	 * @return string
	 */
	public static function html( string $message ): string {
		return nl2br( esc_html( $message ) );
	}

	/**
	 * Refuses the order with a rule's message, or with a sentence of this plugin's while that message is empty.
	 *
	 * An empty message prints nothing beside the shipping options, but a
	 * refused order still has to say why.
	 *
	 * @param \WP_Error $errors  The checkout's errors.
	 * @param string    $code    The rule's error code.
	 * @param string    $message The rule's message.
	 * @return void
	 */
	public static function refuse( \WP_Error $errors, string $code, string $message ): void {
		if ( '' === $message ) {
			$message = __( 'The shipping method you chose is not available for your commune. Please choose another one.', 'rhinnoit-customizer' );
		}

		$errors->add( $code, self::html( $message ) );
	}

	/**
	 * Bumps WooCommerce's shipping version, so every shopper's stored rates are calculated again.
	 *
	 * Hooked to every save of the screen whatever the switches say: the save
	 * that turns a rule off has to free the rates it hid, and the one that
	 * turns it on runs with the old values. WooCommerce stores the version as
	 * a transient and adds it to each package's hash, so this costs one write,
	 * and each shopper's next cart or checkout recalculates.
	 *
	 * @return void
	 */
	public static function refresh_rates(): void {
		if ( class_exists( \WC_Cache_Helper::class ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}
}
