<?php
/**
 * What the two shipping rules share: the commune match, the message, the stored rates.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Admin\Settings_Page;
use RhinnoIT\Customizer\Frontend\Shipping_Communes;

/**
 * Covers RhinnoIT\Customizer\Frontend\Shipping_Communes.
 */
#[CoversClass( Shipping_Communes::class )]
final class ShippingCommunesTest extends TestCase {

	/**
	 * Starts from a configured shop with an empty cart.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();

		WP_Stub::$wc          = new WooCommerce();
		WP_Stub::$wc->session = new WC_Session_Handler();
	}

	/**
	 * A city matches a chosen commune whatever its accents, capitals and spaces.
	 *
	 * @return void
	 */
	public function test_a_city_matches_whatever_its_accents_capitals_and_spaces(): void {
		$lookup = Shipping_Communes::lookup( array( 'Ñuñoa', 'San José de Maipo' ) );

		$this->assertSame( array( 'nunoa' => true, 'san jose de maipo' => true ), $lookup );

		foreach ( array( 'Ñuñoa', 'nunoa', ' ÑUÑOA ', "San  José\tde Maipo", 'SAN JOSE DE MAIPO' ) as $city ) {
			$this->assertTrue( Shipping_Communes::matches( self::package( $city ), $lookup ), sprintf( '"%s" is a chosen commune.', $city ) );
		}

		foreach ( array( 'Ñuñoa Centro', 'San José', 'Providencia', '' ) as $city ) {
			$this->assertFalse( Shipping_Communes::matches( self::package( $city ), $lookup ), sprintf( '"%s" is not a chosen commune.', $city ) );
		}
	}

	/**
	 * A package that names no city as text matches nothing, and nothing breaks.
	 *
	 * @return void
	 */
	public function test_a_package_without_a_city_matches_nothing(): void {
		$lookup  = Shipping_Communes::lookup( array( 'Paine', '' ) );
		$package = self::package( 'Paine' );

		$this->assertSame( array( 'paine' => true ), $lookup, 'An empty name never becomes a commune everybody matches.' );

		foreach ( array( null, array( 'Paine' ), 42 ) as $city ) {
			$package['destination']['city'] = $city;

			$this->assertFalse( Shipping_Communes::matches( $package, $lookup ), sprintf( 'Matched for %s.', var_export( $city, true ) ) );
		}

		unset( $package['destination']['city'] );

		$this->assertFalse( Shipping_Communes::matches( $package, $lookup ) );
		$this->assertFalse( Shipping_Communes::matches( array( 'destination' => 'Paine' ), $lookup ) );
		$this->assertFalse( Shipping_Communes::matches( 'Paine', $lookup ) );
		$this->assertFalse( Shipping_Communes::matches( null, $lookup ) );
		$this->assertFalse( Shipping_Communes::matches( self::package( '' ), Shipping_Communes::lookup( array( '' ) ) ) );
	}

	/**
	 * The cart's packages are WooCommerce's, by index; anything else reads as none.
	 *
	 * @return void
	 */
	public function test_the_packages_are_woocommerces_by_index(): void {
		WP_Stub::$wc->shipping()->packages = array(
			0 => self::package( 'Paine' ),
			1 => 'not a package',
			2 => self::package( 'Buin' ),
		);

		$this->assertSame( array( 0, 2 ), array_keys( Shipping_Communes::packages() ) );

		WP_Stub::$wc = null;

		$this->assertSame( array(), Shipping_Communes::packages(), 'No WooCommerce object: no package, and no fatal.' );
	}

	/**
	 * Each package comes with the rate the shopper chose, only while its rates hold it.
	 *
	 * @return void
	 */
	public function test_a_chosen_rate_counts_only_while_its_package_holds_it(): void {
		$express  = new WC_Shipping_Rate( 'flat_rate:6', 'Despacho Express', 4990.0, array(), 'flat_rate', 6 );
		$standard = new WC_Shipping_Rate( 'flat_rate:5', 'Despacho estándar', 2990.0, array(), 'flat_rate', 5 );

		WP_Stub::$wc->shipping()->packages = array(
			0 => self::package( 'Paine', array( 'flat_rate:6' => $express ) ),
			1 => self::package( 'Paine', array( 'flat_rate:5' => $standard ) ),
			2 => self::package( 'Paine', array( 'flat_rate:5' => $standard ) ),
		);
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 0 => 'flat_rate:6', 1 => 'flat_rate:6', 2 => array( 'flat_rate:5' ) );

		$pairs = Shipping_Communes::chosen_rates();

		$this->assertCount( 1, $pairs, 'The second package no longer holds the rate chosen for it, the third chose nothing readable.' );
		$this->assertSame( $express, $pairs[0][1] );
		$this->assertSame( 'Paine', $pairs[0][0]['destination']['city'] );

		WP_Stub::$wc->session->data['chosen_shipping_methods'] = 'flat_rate:6';

		$this->assertSame( array(), Shipping_Communes::chosen_rates(), 'Nothing chosen as a list: nothing to check.' );

		WP_Stub::$wc->session = null;

		$this->assertSame( array(), Shipping_Communes::chosen_rates(), 'No session: nothing to check, and no fatal.' );
	}

	/**
	 * The message is printed as its own row, escaped, its line breaks kept.
	 *
	 * @return void
	 */
	public function test_the_message_is_a_row_of_its_own_escaped_with_its_line_breaks(): void {
		ob_start();
		Shipping_Communes::print_notice( "No despachamos a <b>Paine</b>.\nEscríbenos." );
		$html = (string) ob_get_clean();

		$this->assertSame( "<tr class=\"rcus-shipping-notice\"><td colspan=\"2\"><div class=\"woocommerce-info\">No despachamos a &lt;b&gt;Paine&lt;/b&gt;.<br />\nEscríbenos.</div></td></tr>", $html );
		$this->assertSame( 'Por ahora &lt;script&gt;alert(1)&lt;/script&gt;', Shipping_Communes::html( 'Por ahora <script>alert(1)</script>' ) );
	}

	/**
	 * A refused order says the rule's message, or a sentence of its own when that message is empty.
	 *
	 * @return void
	 */
	public function test_a_refusal_says_the_message_or_a_sentence_of_its_own(): void {
		$errors = new WP_Error();

		Shipping_Communes::refuse( $errors, 'rcus-shipping-block', "Sin express en tu comuna.\n<b>Elige otro.</b>" );
		Shipping_Communes::refuse( $errors, 'rcus-shipping-exclusion', '' );

		$this->assertSame( array( "Sin express en tu comuna.<br />\n&lt;b&gt;Elige otro.&lt;/b&gt;" ), $errors->get_error_messages( 'rcus-shipping-block' ) );
		$this->assertSame( array( 'The shipping method you chose is not available for your commune. Please choose another one.' ), $errors->get_error_messages( 'rcus-shipping-exclusion' ), 'A cleared message prints nothing, but a refusal still says why.' );
	}

	/**
	 * A save renews WooCommerce's shipping version, which every stored package's hash includes.
	 *
	 * @return void
	 */
	public function test_a_save_renews_the_version_every_stored_rate_is_keyed_by(): void {
		WP_Stub::$transients['shipping-transient-version'] = '1700000000.000000';

		Shipping_Communes::refresh_rates();

		$this->assertNotSame( '1700000000.000000', WP_Stub::$transients['shipping-transient-version'], 'The rates every shopper\'s session stored would keep the old rule.' );
		$this->assertSame( WP_Stub::$transients['shipping-transient-version'], WC_Cache_Helper::get_transient_version( 'shipping' ), 'The version WooCommerce\'s package hash reads.' );
		$this->assertSame( Settings_Page::SAVED_HOOK, Shipping_Communes::SAVED, 'Every settings screen of the group fires it by the name Settings_Page holds.' );
	}

	/**
	 * A package as WC_Cart::get_shipping_packages() builds it, its rates filled.
	 *
	 * @param string                          $city  The city it ships to.
	 * @param array<string, WC_Shipping_Rate> $rates Its rates.
	 * @return array<string, mixed>
	 */
	private static function package( string $city, array $rates = array() ): array {
		return array(
			'contents'        => array(),
			'contents_cost'   => 29990.0,
			'applied_coupons' => array(),
			'user'            => array( 'ID' => 0 ),
			'destination'     => array(
				'country'   => 'CL',
				'state'     => 'CL-RM',
				'postcode'  => '',
				'city'      => $city,
				'address'   => 'Av. Siempre Viva 742',
				'address_1' => 'Av. Siempre Viva 742',
				'address_2' => '',
			),
			'cart_subtotal'   => 29990.0,
			'rates'           => $rates,
		);
	}
}
