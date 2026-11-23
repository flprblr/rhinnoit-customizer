<?php
/**
 * Stopping deliveries to chosen communes, store pickup kept.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Admin\Settings_Page;
use RhinnoIT\Customizer\Frontend\Shipping_Communes;
use RhinnoIT\Customizer\Frontend\Shipping_Exclusion;

/**
 * Covers RhinnoIT\Customizer\Frontend\Shipping_Exclusion.
 */
#[CoversClass( Shipping_Exclusion::class )]
final class ShippingExclusionTest extends TestCase {

	/**
	 * The message a row never saved prints.
	 */
	private const string DEFAULT_MESSAGE = 'We cannot deliver to your commune for the time being. Please check your address, or contact us if you need help.';

	/**
	 * WooCommerce's text for a package without rates on the classic checkout (templates/cart/cart-shipping.php, 11.2).
	 */
	private const string CHECKOUT_TEXT = 'There are no shipping options available. Please ensure that your address has been entered correctly, or contact us if you need any help.';

	/**
	 * WooCommerce's text for one in the cart, the address it formatted included.
	 */
	private const string CART_TEXT = 'No shipping options were found for <strong>Penco, Biobío</strong>. ';

	/**
	 * The courier stopped delivering to two communes of the Biobío after the fires, as regiones-excluidas-envio covered.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();

		WP_Stub::$options['rcus_shipping_exclusion_communes'] = array( 'Penco', 'Tomé' );

		WP_Stub::$wc          = new WooCommerce();
		WP_Stub::$wc->session = new WC_Session_Handler();
	}

	/**
	 * In a chosen commune only WooCommerce's pickup rates stay, whatever the city's accents and capitals.
	 *
	 * @return void
	 */
	public function test_only_pickup_stays_in_a_chosen_commune(): void {
		$service = $this->service();

		foreach ( array( 'Penco', 'PENCO', 'Tomé', ' tome ' ) as $city ) {
			$this->assertSame(
				array( 'local_pickup:7', 'pickup_location:0' ),
				array_keys( $service->keep_pickup_only( self::rates(), self::package( $city ) ) ),
				sprintf( 'Every delivery goes in "%s", and only delivery.', $city )
			);
		}
	}

	/**
	 * Any other commune keeps every rate.
	 *
	 * @return void
	 */
	public function test_a_commune_not_chosen_keeps_every_rate(): void {
		$service = $this->service();
		$rates   = self::rates();

		foreach ( array( 'Concepción', 'Penco Alto', '' ) as $city ) {
			$this->assertSame( $rates, $service->keep_pickup_only( $rates, self::package( $city ) ), sprintf( 'Rates changed for "%s".', $city ) );
		}

		$this->assertSame( 'not rates', $service->keep_pickup_only( 'not rates', self::package( 'Penco' ) ), 'What another plugin left in place of the rates is handed back.' );
	}

	/**
	 * The rule runs last, after the couriers' plugins add their rates, with the package WooCommerce passes.
	 *
	 * @return void
	 */
	public function test_it_runs_after_every_courier(): void {
		$this->service();

		$this->assertSame( PHP_INT_MAX, WP_Stub::$priorities['woocommerce_package_rates:keep_pickup_only'] );
		$this->assertContains( array( 'woocommerce_package_rates', Shipping_Exclusion::class, 'keep_pickup_only', 2 ), WP_Stub::$registered );
		$this->assertContains( array( 'woocommerce_after_checkout_validation', Shipping_Exclusion::class, 'refuse_delivery', 2 ), WP_Stub::$registered );
	}

	/**
	 * Ways the rule has nothing to do.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function inertSettings(): array {
		return array(
			'no commune chosen' => array( array( 'rcus_shipping_exclusion_communes' => array() ) ),
			'a list unreadable' => array( array( 'rcus_shipping_exclusion_communes' => 'Penco' ) ),
			'its switch off'    => array( array( 'rcus_shipping_exclusion' => 'no' ) ),
			'the master off'    => array( array( 'rcus_enabled' => 'no' ) ),
		);
	}

	/**
	 * Without its list, its switch or the master switch, nothing is hooked but the save of the screen and the plugins' activation and deactivation.
	 *
	 * @param array<string, mixed> $options Rows that make it inert.
	 * @return void
	 */
	#[DataProvider( 'inertSettings' )]
	public function test_it_hooks_only_the_save_and_the_plugin_changes_while_it_has_nothing_to_do( array $options ): void {
		WP_Stub::$options = array_merge( WP_Stub::$options, $options );

		$this->service();

		$this->assertSame( array( Settings_Page::SAVED_HOOK . ':refresh_rates', 'activated_plugin:refresh_rates', 'deactivated_plugin:refresh_rates' ), WP_Stub::$callbacks, 'The save that turns the rule off still has to free the rates it hid, and so does the deactivation of the old exclusion plugin, which took pickup too.' );

		foreach ( array( Settings_Page::SAVED_HOOK, 'activated_plugin', 'deactivated_plugin' ) as $hook ) {
			$this->assertContains( array( $hook, Shipping_Communes::class, 'refresh_rates', 1 ), WP_Stub::$registered, sprintf( '%s reaches the static bump, which WordPress keeps once for both rules.', $hook ) );
		}
	}

	/**
	 * With pickup left, the message is a row of its own under the shipping options, once.
	 *
	 * @return void
	 */
	public function test_the_message_prints_once_while_pickup_is_left(): void {
		WP_Stub::$wc->shipping()->packages = array(
			self::package( 'Penco', self::pickup() ),
			self::package( 'Penco', self::pickup() ),
		);

		$service = $this->service();

		$this->assertContains( 'woocommerce_cart_totals_after_shipping:print_notice', WP_Stub::$callbacks );
		$this->assertContains( 'woocommerce_review_order_after_shipping:print_notice', WP_Stub::$callbacks );
		$this->assertSame( '<tr class="rcus-shipping-notice"><td colspan="2"><div class="woocommerce-info">' . self::DEFAULT_MESSAGE . '</div></td></tr>', self::printed( $service ), 'Two packages, one message.' );
		$this->assertSame( self::CHECKOUT_TEXT, $service->replace_no_shipping_text( self::CHECKOUT_TEXT ), 'Pickup is left, so WooCommerce has no text to replace.' );
	}

	/**
	 * With nothing left, the message takes the place of WooCommerce's own text, and no row repeats it.
	 *
	 * @return void
	 */
	public function test_with_nothing_left_the_message_replaces_woocommerces_text(): void {
		WP_Stub::$wc->shipping()->packages = array( self::package( 'Penco' ) );

		$service = $this->service();

		$this->assertContains( 'woocommerce_no_shipping_available_html:replace_no_shipping_text', WP_Stub::$callbacks );
		$this->assertContains( 'woocommerce_cart_no_shipping_available_html:replace_no_shipping_text', WP_Stub::$callbacks );
		$this->assertSame( self::DEFAULT_MESSAGE, $service->replace_no_shipping_text( self::CHECKOUT_TEXT ), 'The classic checkout.' );
		$this->assertSame( self::DEFAULT_MESSAGE, $service->replace_no_shipping_text( self::CART_TEXT ), 'The cart.' );
		$this->assertSame( '', self::printed( $service ), 'Said once, in place of WooCommerce\'s text.' );

		WP_Stub::$wc->shipping()->packages = array( self::package( 'Penco', self::pickup() ), self::package( 'Penco' ) );

		$this->assertSame( '', self::printed( $service ), 'One package left with nothing says it there, so no row repeats it.' );
	}

	/**
	 * The message takes WooCommerce's place in a package left with nothing that is not the first one of the cart.
	 *
	 * @return void
	 */
	public function test_the_message_replaces_woocommerces_text_for_a_package_that_comes_second(): void {
		WP_Stub::$wc->shipping()->packages = array(
			0 => self::package( 'Concepción', self::rates() ),
			1 => self::package( 'Penco' ),
		);

		$this->assertSame( self::DEFAULT_MESSAGE, $this->service()->replace_no_shipping_text( self::CHECKOUT_TEXT ), 'A cart WooCommerce ships as two packages, the second to Penco.' );
	}

	/**
	 * WooCommerce's text stays for a package that has nothing for another reason.
	 *
	 * @return void
	 */
	public function test_woocommerces_text_stays_for_a_commune_not_chosen(): void {
		WP_Stub::$wc->shipping()->packages = array( self::package( 'Concepción' ) );

		$service = $this->service();

		$this->assertSame( self::CHECKOUT_TEXT, $service->replace_no_shipping_text( self::CHECKOUT_TEXT ) );
		$this->assertSame( self::CART_TEXT, $service->replace_no_shipping_text( self::CART_TEXT ) );
		$this->assertSame( '', self::printed( $service ) );
	}

	/**
	 * The message is escaped, its line breaks kept, in the row and in WooCommerce's place.
	 *
	 * @return void
	 */
	public function test_the_message_is_escaped_with_its_line_breaks(): void {
		WP_Stub::$options['rcus_shipping_exclusion_message'] = "Sin despacho por los <script>alert(1)</script>.\nEscríbenos.";
		WP_Stub::$wc->shipping()->packages                   = array( self::package( 'Penco', self::pickup() ) );

		$service  = $this->service();
		$expected = "Sin despacho por los &lt;script&gt;alert(1)&lt;/script&gt;.<br />\nEscríbenos.";

		$this->assertStringContainsString( $expected, self::printed( $service ) );
		$this->assertStringNotContainsString( '<script', self::printed( $service ) );

		WP_Stub::$wc->shipping()->packages = array( self::package( 'Penco' ) );

		$this->assertSame( $expected, $service->replace_no_shipping_text( self::CHECKOUT_TEXT ) );
	}

	/**
	 * An empty message prints nothing and leaves WooCommerce's text; the deliveries still go.
	 *
	 * @return void
	 */
	public function test_an_empty_message_prints_nothing(): void {
		WP_Stub::$options['rcus_shipping_exclusion_message'] = '';
		WP_Stub::$wc->shipping()->packages                   = array( self::package( 'Penco', self::pickup() ) );

		$service = $this->service();

		$this->assertSame( '', self::printed( $service ) );

		WP_Stub::$wc->shipping()->packages = array( self::package( 'Penco' ) );

		$this->assertSame( self::CHECKOUT_TEXT, $service->replace_no_shipping_text( self::CHECKOUT_TEXT ), 'A blank cell would explain less than WooCommerce\'s text.' );
		$this->assertSame( array( 'local_pickup:7', 'pickup_location:0' ), array_keys( $service->keep_pickup_only( self::rates(), self::package( 'Penco' ) ) ) );
	}

	/**
	 * A message never saved prints in the shop's language, though the service registers before translations load.
	 *
	 * @return void
	 */
	public function test_the_message_is_read_once_translations_have_loaded(): void {
		WP_Stub::$wc->shipping()->packages = array( self::package( 'Penco' ) );

		$service = $this->service();

		WP_Stub::$translations = array( self::DEFAULT_MESSAGE => 'Por ahora no podemos despachar a tu comuna. Revisa tu dirección o contáctanos si necesitas ayuda.' );

		$this->assertSame( 'Por ahora no podemos despachar a tu comuna. Revisa tu dirección o contáctanos si necesitas ayuda.', $service->replace_no_shipping_text( self::CHECKOUT_TEXT ), 'Read in register(), a message never saved prints in English on every shop.' );
	}

	/**
	 * A delivery still in the rates WooCommerce stored, and chosen, is refused at the submit; pickup is not.
	 *
	 * @return void
	 */
	public function test_a_delivery_still_chosen_is_refused(): void {
		$service = $this->service();

		WP_Stub::$wc->shipping()->packages                     = array( self::package( 'Penco', self::rates() ) );
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'starken:express' );

		$errors = new WP_Error();
		$service->refuse_delivery( array( 'billing_city' => 'Penco' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages( 'rcus-shipping-exclusion' ) );

		// What another plugin firing the action could pass in place of the errors: no fatal.
		$service->refuse_delivery( array(), null );

		foreach ( array( 'local_pickup:7', 'pickup_location:0' ) as $pickup ) {
			WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( $pickup );

			$errors = new WP_Error();
			$service->refuse_delivery( array(), $errors );

			$this->assertSame( array(), $errors->get_error_messages(), sprintf( '%s is a pickup.', $pickup ) );
		}

		WP_Stub::$wc->shipping()->packages                     = array( self::package( 'Concepción', self::rates() ) );
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'flat_rate:5' );

		$errors = new WP_Error();
		$service->refuse_delivery( array(), $errors );
		$service->refuse_delivery( array(), null );

		$this->assertSame( array(), $errors->get_error_messages(), 'Another commune is delivered to.' );
	}

	/**
	 * A delivery still stored and chosen is refused when its package is not the first one of the cart.
	 *
	 * @return void
	 */
	public function test_a_delivery_chosen_for_a_package_that_comes_second_is_refused(): void {
		$service = $this->service();

		WP_Stub::$wc->shipping()->packages = array(
			0 => self::package( 'Concepción', self::rates() ),
			1 => self::package( 'Penco', self::rates() ),
		);
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'flat_rate:5', 'flat_rate:5' );

		$errors = new WP_Error();
		$service->refuse_delivery( self::posted( 'flat_rate:5', 'flat_rate:5' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages( 'rcus-shipping-exclusion' ) );
	}

	/**
	 * A delivery the submit still posts is refused once the rates no longer hold it, and the review is drawn again.
	 *
	 * A checkout drawn before the rule covered Penco still offers home
	 * delivery. WooCommerce works the rates out again on the submit, finds it
	 * gone and moves the package to the first rate left - a store the shopper
	 * never picked - which is what the session holds by the time the submit is
	 * validated: on its own it would place the order as a pickup at $0. Once
	 * with the rule's filter running in the submit's own request, once with
	 * the rates an earlier request already worked out.
	 *
	 * @return void
	 */
	public function test_a_delivery_posted_after_the_rates_lost_it_is_refused(): void {
		// The submit's request: update_session() calculates the rates, the filter keeps pickup only.
		$service = $this->service();
		$rates   = $service->keep_pickup_only( self::rates(), self::package( 'Penco' ) );

		WP_Stub::$wc->shipping()->packages                     = array( self::package( 'Penco', $rates ) );
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'local_pickup:7' );

		$errors = new WP_Error();
		$service->refuse_delivery( self::posted( 'flat_rate:5' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages( 'rcus-shipping-exclusion' ), 'WooCommerce chose the store in its place, and would place the order on it.' );
		$this->assertTrue( WP_Stub::$wc->session->get( 'refresh_totals' ), 'The review is drawn again, pickup only.' );

		// A later submit: the rates were worked out by an earlier request, and this one reuses them.
		WP_Stub::$wc->session = new WC_Session_Handler();
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'local_pickup:7' );

		$service = $this->service();
		$errors  = new WP_Error();
		$service->refuse_delivery( self::posted( 'starken:express' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages( 'rcus-shipping-exclusion' ), 'Read from the posted id: a courier\'s rate is a delivery too.' );
		$this->assertTrue( WP_Stub::$wc->session->get( 'refresh_totals' ) );

		// The same, with Penco second.
		WP_Stub::$wc->shipping()->packages = array(
			0 => self::package( 'Concepción', self::rates() ),
			1 => self::package( 'Penco', self::pickup() ),
		);
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'flat_rate:5', 'local_pickup:7' );

		$errors = new WP_Error();
		$service->refuse_delivery( self::posted( 'flat_rate:5', 'flat_rate:5' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages( 'rcus-shipping-exclusion' ), 'Each posted id is read against its own package.' );
	}

	/**
	 * A posted pickup goes through, still offered or not, and so does a post that holds no rate id as text.
	 *
	 * @return void
	 */
	public function test_a_posted_pickup_goes_through(): void {
		$service = $this->service();

		WP_Stub::$wc->shipping()->packages                     = array( self::package( 'Penco', self::pickup() ) );
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'local_pickup:7' );

		$errors = new WP_Error();

		foreach ( array( 'local_pickup:7', 'pickup_location:0', 'local_pickup:8', 'pickup_location:3', '' ) as $id ) {
			$service->refuse_delivery( self::posted( $id ), $errors );
		}

		$this->assertSame( array(), $errors->get_error_messages(), 'Store pickup stays, and one gone from the rates leaves WooCommerce another store: all that is left.' );

		foreach ( array( array( 'shipping_method' => 'flat_rate:5' ), array( 'shipping_method' => array( array( 'flat_rate:5' ) ) ), array( 'shipping_method' => array( 1 => 'flat_rate:5' ) ), array(), null ) as $data ) {
			$service->refuse_delivery( $data, $errors );
		}

		$this->assertSame( array(), $errors->get_error_messages(), 'No posted id as text, by its package\'s index: nothing to read.' );

		WP_Stub::$wc->shipping()->packages = array( self::package( 'Concepción', self::pickup() ) );

		$service->refuse_delivery( self::posted( 'flat_rate:5' ), $errors );

		$this->assertSame( array(), $errors->get_error_messages(), 'A delivery gone for another reason in a commune not chosen.' );
		$this->assertNull( WP_Stub::$wc->session->get( 'refresh_totals' ), 'Nothing refused, nothing to draw again.' );
	}

	/**
	 * A pickup another plugin registers under a method id of its own goes like any delivery: in the rates, chosen and posted.
	 *
	 * Only the ids in Shipping_Exclusion::PICKUP stay, matched whole, so one
	 * that merely begins like WooCommerce's local pickup is not one of them.
	 *
	 * @return void
	 */
	public function test_a_pickup_of_another_plugin_goes_like_any_delivery(): void {
		$plus    = array( 'local_pickup_plus:3' => new WC_Shipping_Rate( 'local_pickup_plus:3', 'Retiro en sucursal', 0.0, array(), 'local_pickup_plus', 0 ) );
		$service = $this->service();

		$this->assertSame( array( 'local_pickup:7', 'pickup_location:0' ), array_keys( $service->keep_pickup_only( self::rates() + $plus, self::package( 'Penco' ) ) ), 'Its rate goes.' );

		WP_Stub::$wc->shipping()->packages                     = array( self::package( 'Penco', self::rates() + $plus ) );
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'local_pickup_plus:3' );

		$errors = new WP_Error();
		$service->refuse_delivery( self::posted( 'local_pickup_plus:3' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages(), 'Still in the rates WooCommerce stored, and chosen.' );

		WP_Stub::$wc->shipping()->packages                     = array( self::package( 'Penco', self::pickup() ) );
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'local_pickup:7' );

		$errors = new WP_Error();
		$service->refuse_delivery( self::posted( 'local_pickup_plus:3' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages(), 'Posted after the rates lost it.' );
		$this->assertTrue( WP_Stub::$wc->session->get( 'refresh_totals' ) );
	}

	/**
	 * A package left with nothing is WooCommerce's to refuse: the rule adds no notice of its own, and reads on.
	 *
	 * Where the zone has no pickup, a checkout drawn before the rule covered
	 * Penco still posts home delivery. WooCommerce finds no rate chosen among
	 * the package's, none being left, and refuses the order with "No shipping
	 * method has been selected"; the rule's message would only say it twice.
	 *
	 * @return void
	 */
	public function test_a_package_with_nothing_left_is_left_to_woocommerce(): void {
		$service = $this->service();

		WP_Stub::$wc->shipping()->packages                     = array( self::package( 'Penco' ) );
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( '' );

		$errors = new WP_Error();
		$service->refuse_delivery( self::posted( 'flat_rate:5' ), $errors );

		$this->assertSame( array(), $errors->get_error_messages(), 'WooCommerce refuses it on its own, once.' );
		$this->assertNull( WP_Stub::$wc->session->get( 'refresh_totals' ) );

		WP_Stub::$wc->shipping()->packages = array(
			0 => self::package( 'Penco' ),
			1 => self::package( 'Tomé', self::pickup() ),
		);
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( '', 'local_pickup:7' );

		$service->refuse_delivery( self::posted( 'flat_rate:5', 'flat_rate:5' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages(), 'The next package, pickup left, is still the rule\'s.' );
	}

	/**
	 * Every package is read until one is refused, and the order is refused once, however many ship to the communes.
	 *
	 * @return void
	 */
	public function test_each_package_is_read_and_the_order_refused_once(): void {
		$service = $this->service();

		WP_Stub::$wc->shipping()->packages = array(
			0 => self::package( 'Penco', self::pickup() ),
			1 => self::package( 'Tomé', self::pickup() ),
		);
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'local_pickup:7', 'local_pickup:7' );

		$errors = new WP_Error();
		$service->refuse_delivery( self::posted( 'local_pickup:8', 'flat_rate:5' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages(), 'The second package\'s delivery, behind a store gone for another reason.' );

		$errors = new WP_Error();
		$service->refuse_delivery( self::posted( 'flat_rate:5', 'starken:express' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages(), 'Both packages posted a delivery: one notice.' );

		WP_Stub::$wc->shipping()->packages = array(
			0 => self::package( 'Penco', self::rates() ),
			1 => self::package( 'Tomé', self::rates() ),
		);
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'flat_rate:5', 'starken:express' );

		$errors = new WP_Error();
		$service->refuse_delivery( self::posted( 'flat_rate:5', 'starken:express' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages(), 'Both packages chose a delivery from the stored rates: one notice.' );

		WP_Stub::$wc->shipping()->packages = array(
			0 => self::package( 'Penco', self::rates() ),
			1 => self::package( 'Tomé', self::pickup() ),
		);
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'flat_rate:5', 'local_pickup:7' );

		$errors = new WP_Error();
		$service->refuse_delivery( self::posted( 'flat_rate:5', 'flat_rate:5' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages(), 'One chose it from the stored rates, the other posted it after they lost it: one notice.' );
	}

	/**
	 * A registered service, as Plugin::__construct() leaves it.
	 *
	 * @return Shipping_Exclusion
	 */
	private function service(): Shipping_Exclusion {
		WP_Stub::$hooks      = array();
		WP_Stub::$callbacks  = array();
		WP_Stub::$priorities = array();
		WP_Stub::$registered = array();

		$service = new Shipping_Exclusion();
		$service->register();

		return $service;
	}

	/**
	 * What the cart totals print after the shipping row, through the service.
	 *
	 * @param Shipping_Exclusion $service The service.
	 * @return string
	 */
	private static function printed( Shipping_Exclusion $service ): string {
		ob_start();
		$service->print_notice();

		return (string) ob_get_clean();
	}

	/**
	 * The rates a Biobío address gets, by rate id, as WooCommerce hands them to woocommerce_package_rates.
	 *
	 * @return array<string, WC_Shipping_Rate>
	 */
	private static function rates(): array {
		return array(
			'flat_rate:5'       => new WC_Shipping_Rate( 'flat_rate:5', 'Despacho a domicilio', 3990.0, array(), 'flat_rate', 5 ),
			'free_shipping:9'   => new WC_Shipping_Rate( 'free_shipping:9', 'Envío gratis', 0.0, array(), 'free_shipping', 9 ),
			'starken:express'   => new WC_Shipping_Rate( 'starken:express', 'Starken Express', 5990.0, array(), 'starken', 0 ),
			'local_pickup:7'    => new WC_Shipping_Rate( 'local_pickup:7', 'Retiro en tienda', 0.0, array(), 'local_pickup', 7 ),
			'pickup_location:0' => new WC_Shipping_Rate( 'pickup_location:0', 'Tienda Concepción', 0.0, array(), 'pickup_location', 0 ),
		);
	}

	/**
	 * What a chosen commune is left with: the pickup rates.
	 *
	 * @return array<string, WC_Shipping_Rate>
	 */
	private static function pickup(): array {
		return array_intersect_key( self::rates(), array_flip( array( 'local_pickup:7', 'pickup_location:0' ) ) );
	}

	/**
	 * A classic checkout's submit as WC_Checkout::get_posted_data() hands it to woocommerce_after_checkout_validation: the rate ids by package index.
	 *
	 * @param string ...$ids The rate id posted for each package, in order.
	 * @return array<string, mixed>
	 */
	private static function posted( string ...$ids ): array {
		return array(
			'billing_city'    => 'Penco',
			'payment_method'  => 'bacs',
			'shipping_method' => $ids,
		);
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
				'state'     => 'CL-BI',
				'postcode'  => '',
				'city'      => $city,
				'address'   => 'Freire 120',
				'address_1' => 'Freire 120',
				'address_2' => '',
			),
			'cart_subtotal'   => 29990.0,
			'rates'           => $rates,
		);
	}
}
