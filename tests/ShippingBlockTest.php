<?php
/**
 * Hiding chosen shipping methods for an address in chosen communes.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Admin\Settings_Page;
use RhinnoIT\Customizer\Frontend\Shipping_Block;
use RhinnoIT\Customizer\Frontend\Shipping_Communes;
use RhinnoIT\Customizer\Settings;

/**
 * Covers RhinnoIT\Customizer\Frontend\Shipping_Block.
 */
#[CoversClass( Shipping_Block::class )]
final class ShippingBlockTest extends TestCase {

	/**
	 * The message a row never saved prints.
	 */
	private const string DEFAULT_MESSAGE = 'Express delivery is not available for your commune. Please choose another shipping method.';

	/**
	 * Express hidden in two outlying communes of Santiago, as YNK's shop hid it; Santiago's zone offers it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();

		WP_Stub::$options['rcus_shipping_block_methods']  = array( '6' );
		WP_Stub::$options['rcus_shipping_block_communes'] = array( 'Paine', 'Ñuñoa' );

		WP_Stub::$zones = array(
			1 => array(
				'name'    => 'Santiago',
				'states'  => array( 'CL-RM' ),
				'methods' => array(
					new WC_Shipping_Method( 5, 'flat_rate', 'Despacho estándar' ),
					new WC_Shipping_Method( 6, 'flat_rate', 'Despacho Express' ),
					new WC_Shipping_Method( 7, 'local_pickup', 'Retiro en tienda' ),
				),
			),
			2 => array(
				'name'    => 'Regiones',
				'states'  => array( 'CL-VS', 'CL-BI' ),
				'methods' => array(
					new WC_Shipping_Method( 8, 'flat_rate', 'Starken' ),
				),
			),
		);

		WP_Stub::$wc          = new WooCommerce();
		WP_Stub::$wc->session = new WC_Session_Handler();
	}

	/**
	 * In a chosen commune only the chosen instances go, whatever the city's accents and capitals.
	 *
	 * @return void
	 */
	public function test_only_the_chosen_instances_go_in_a_chosen_commune(): void {
		$service = $this->service();

		foreach ( array( 'Paine', 'paine', 'Ñuñoa', ' NUNOA ' ) as $city ) {
			$this->assertSame(
				array( 'flat_rate:5', 'local_pickup:7', 'starken:express' ),
				array_keys( $service->hide_rates( self::rates(), self::package( $city ) ) ),
				sprintf( 'Express is hidden in "%s", and nothing else.', $city )
			);
		}
	}

	/**
	 * Any other commune keeps every rate, express included.
	 *
	 * @return void
	 */
	public function test_a_commune_not_chosen_keeps_every_rate(): void {
		$service = $this->service();
		$rates   = self::rates();

		foreach ( array( 'Providencia', 'Paine Centro', '' ) as $city ) {
			$this->assertSame( $rates, $service->hide_rates( $rates, self::package( $city ) ), sprintf( 'Rates changed for "%s".', $city ) );
		}

		$this->assertSame( 'not rates', $service->hide_rates( 'not rates', self::package( 'Paine' ) ), 'What another plugin left in place of the rates is handed back.' );
	}

	/**
	 * The rule runs last, after the couriers' plugins add their rates, with the package WooCommerce passes.
	 *
	 * @return void
	 */
	public function test_it_runs_after_every_courier(): void {
		$this->service();

		$this->assertSame( PHP_INT_MAX, WP_Stub::$priorities['woocommerce_package_rates:hide_rates'] );
		$this->assertContains( array( 'woocommerce_package_rates', Shipping_Block::class, 'hide_rates', 2 ), WP_Stub::$registered );
		$this->assertContains( array( 'woocommerce_after_checkout_validation', Shipping_Block::class, 'refuse_hidden_method', 2 ), WP_Stub::$registered );
	}

	/**
	 * Ways the rule has nothing to do.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function inertSettings(): array {
		return array(
			'no method chosen'   => array( array( 'rcus_shipping_block_methods' => array() ) ),
			'no commune chosen'  => array( array( 'rcus_shipping_block_communes' => array() ) ),
			'a list unreadable'  => array( array( 'rcus_shipping_block_communes' => 'Paine' ) ),
			'its switch off'     => array( array( 'rcus_shipping_block' => 'no' ) ),
			'the master off'     => array( array( 'rcus_enabled' => 'no' ) ),
		);
	}

	/**
	 * Without both lists, its switch or the master switch, nothing is hooked but the save of the screen and the plugins' activation and deactivation.
	 *
	 * Each is heard whatever the switches say: the save that turns the rule
	 * off has to free the rates it hid from every shopper's session, and so
	 * does the deactivation of a plugin that filtered rates - the old express
	 * plugin among them - since WooCommerce bumps nothing for it.
	 *
	 * @param array<string, mixed> $options Rows that make it inert.
	 * @return void
	 */
	#[DataProvider( 'inertSettings' )]
	public function test_it_hooks_only_the_save_and_the_plugin_changes_while_it_has_nothing_to_do( array $options ): void {
		WP_Stub::$options = array_merge( WP_Stub::$options, $options );

		$this->service();

		$this->assertSame( array( Settings_Page::SAVED_HOOK . ':refresh_rates', 'activated_plugin:refresh_rates', 'deactivated_plugin:refresh_rates' ), WP_Stub::$callbacks );

		foreach ( array( Settings_Page::SAVED_HOOK, 'activated_plugin', 'deactivated_plugin' ) as $hook ) {
			$this->assertContains( array( $hook, Shipping_Communes::class, 'refresh_rates', 1 ), WP_Stub::$registered, sprintf( '%s reaches the static bump, which WordPress keeps once for both rules.', $hook ) );
		}
	}

	/**
	 * The message is a row of its own under the shipping options, once, in the cart and the checkout.
	 *
	 * @return void
	 */
	public function test_the_message_prints_once_while_a_package_loses_a_method(): void {
		WP_Stub::$wc->shipping()->packages = array(
			self::package( 'Paine' ),
			self::package( 'Paine' ),
		);

		$service = $this->service();

		$this->assertContains( 'woocommerce_cart_totals_after_shipping:print_notice', WP_Stub::$callbacks );
		$this->assertContains( 'woocommerce_review_order_after_shipping:print_notice', WP_Stub::$callbacks );
		$this->assertSame( '<tr class="rcus-shipping-notice"><td colspan="2"><div class="woocommerce-info">' . self::DEFAULT_MESSAGE . '</div></td></tr>', self::printed( $service ), 'Two packages, one message.' );
	}

	/**
	 * No row for a commune not chosen, or a zone that does not offer the method.
	 *
	 * @return void
	 */
	public function test_no_message_where_nothing_is_lost(): void {
		$service = $this->service();

		WP_Stub::$wc->shipping()->packages = array( self::package( 'Providencia' ) );

		$this->assertSame( '', self::printed( $service ), 'A commune not chosen.' );

		WP_Stub::$wc->shipping()->packages = array( self::package( 'Penco', 'CL-BI' ) );
		WP_Stub::$options['rcus_shipping_block_communes'] = array( 'Penco' );
		$service = $this->service();

		$this->assertSame( '', self::printed( $service ), 'A chosen commune whose zone never offered express: the message would only puzzle.' );

		WP_Stub::$wc->shipping()->packages = array( self::package( 'Paine' ) );
		WP_Stub::$zones[1]['methods'][1]->enabled = 'no';
		WP_Stub::$options['rcus_shipping_block_communes'] = array( 'Paine' );
		$service = $this->service();

		$this->assertSame( '', self::printed( $service ), 'Express disabled in the zone: nothing to explain.' );

		WP_Stub::$wc->shipping()->packages = array( self::package( 'Paine', 'CL-AP' ) );
		WP_Stub::$zones[0] = array( 'methods' => array( new WC_Shipping_Method( 6, 'flat_rate', 'Despacho Express' ) ) );

		$this->assertStringContainsString( self::DEFAULT_MESSAGE, self::printed( $service ), 'The zone for everywhere else counts like any other.' );
	}

	/**
	 * The message prints when the package that loses the method is not the first one of the cart.
	 *
	 * @return void
	 */
	public function test_the_message_prints_for_a_package_that_comes_second(): void {
		WP_Stub::$wc->shipping()->packages = array(
			0 => self::package( 'Providencia' ),
			1 => self::package( 'Paine' ),
		);

		$this->assertSame( '<tr class="rcus-shipping-notice"><td colspan="2"><div class="woocommerce-info">' . self::DEFAULT_MESSAGE . '</div></td></tr>', self::printed( $this->service() ), 'A cart WooCommerce ships as two packages, the second to Paine.' );
	}

	/**
	 * The message is escaped, its line breaks kept; an empty one prints nothing and the method still goes.
	 *
	 * @return void
	 */
	public function test_the_message_is_escaped_and_an_empty_one_prints_nothing(): void {
		WP_Stub::$wc->shipping()->packages = array( self::package( 'Paine' ) );
		WP_Stub::$options['rcus_shipping_block_message'] = "Sin express en <script>alert(1)</script>.\nEscríbenos.";

		$html = self::printed( $this->service() );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( "Sin express en &lt;script&gt;alert(1)&lt;/script&gt;.<br />\nEscríbenos.", $html );

		WP_Stub::$options['rcus_shipping_block_message'] = '';
		$service = $this->service();

		$this->assertSame( '', self::printed( $service ) );
		$this->assertArrayNotHasKey( 'flat_rate:6', $service->hide_rates( self::rates(), self::package( 'Paine' ) ), 'A cleared message still hides the method.' );
	}

	/**
	 * A message never saved prints in the shop's language, though the service registers before translations load.
	 *
	 * @return void
	 */
	public function test_the_message_is_read_once_translations_have_loaded(): void {
		WP_Stub::$wc->shipping()->packages = array( self::package( 'Paine' ) );

		// plugins_loaded: the services register, the translations are not loaded yet.
		$service = $this->service();

		// init: the plugin loads its translations.
		WP_Stub::$translations = array( self::DEFAULT_MESSAGE => 'El despacho express no está disponible para tu comuna. Por favor, elige otro método de envío.' );

		$this->assertStringContainsString( 'El despacho express no está disponible', self::printed( $service ), 'Read in register(), a message never saved prints in English on every shop.' );
	}

	/**
	 * A hidden method still in the rates WooCommerce stored, and chosen, is refused at the submit.
	 *
	 * @return void
	 */
	public function test_a_hidden_method_still_chosen_is_refused(): void {
		$service = $this->service();
		$errors  = new WP_Error();

		WP_Stub::$wc->shipping()->packages                     = array( self::package( 'Paine', 'CL-RM', self::rates() ) );
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'flat_rate:6' );

		$service->refuse_hidden_method( array( 'billing_city' => 'Paine' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages( 'rcus-shipping-block' ) );

		// What another plugin firing the action could pass in place of the errors: no fatal.
		$service->refuse_hidden_method( array(), null );

		WP_Stub::$options['rcus_shipping_block_message'] = '';
		$service = $this->service();
		$errors  = new WP_Error();

		$service->refuse_hidden_method( array(), $errors );

		$this->assertSame( array( 'The shipping method you chose is not available for your commune. Please choose another one.' ), $errors->get_error_messages(), 'An empty message still refuses, with a sentence of its own.' );
	}

	/**
	 * Another method, another commune, or no error object: the order goes through.
	 *
	 * @return void
	 */
	public function test_the_submit_refuses_nothing_else(): void {
		$service = $this->service();
		$errors  = new WP_Error();

		WP_Stub::$wc->shipping()->packages                     = array( self::package( 'Paine', 'CL-RM', self::rates() ) );
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'flat_rate:5' );

		$service->refuse_hidden_method( array(), $errors );

		WP_Stub::$wc->shipping()->packages                     = array( self::package( 'Providencia', 'CL-RM', self::rates() ) );
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'flat_rate:6' );

		$service->refuse_hidden_method( array(), $errors );
		$service->refuse_hidden_method( array(), null );

		$this->assertSame( array(), $errors->get_error_messages() );
	}

	/**
	 * A hidden method still stored and chosen is refused when its package is not the first one of the cart.
	 *
	 * @return void
	 */
	public function test_a_hidden_method_chosen_for_a_package_that_comes_second_is_refused(): void {
		$service = $this->service();
		$errors  = new WP_Error();

		WP_Stub::$wc->shipping()->packages = array(
			0 => self::package( 'Providencia', 'CL-RM', self::only( 'flat_rate:5' ) ),
			1 => self::package( 'Paine', 'CL-RM', self::only( 'flat_rate:5', 'flat_rate:6' ) ),
		);
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'flat_rate:5', 'flat_rate:6' );

		$service->refuse_hidden_method( array( 'shipping_method' => array( 'flat_rate:5', 'flat_rate:6' ) ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages( 'rcus-shipping-block' ) );
	}

	/**
	 * A hidden method the submit still posts is refused once the rates no longer hold it, and the review is drawn again.
	 *
	 * A checkout drawn before the rule covered Paine still offers express.
	 * WooCommerce works the rates out again on the submit, finds express gone
	 * and moves the package to the first rate left, which is what the session
	 * holds by the time the submit is validated: on its own it would place
	 * the order with standard delivery. Once with the rule's filter running in
	 * the submit's own request, once with the rates an earlier request already
	 * worked out - another tab, the cart page - so the filter never ran here.
	 *
	 * @return void
	 */
	public function test_a_hidden_method_posted_after_the_rates_lost_it_is_refused(): void {
		// The submit's request: update_session() calculates the rates, the filter takes express.
		$service = $this->service();
		$rates   = $service->hide_rates( self::rates(), self::package( 'Paine' ) );

		$this->assertArrayNotHasKey( 'flat_rate:6', $rates );

		WP_Stub::$wc->shipping()->packages                     = array( self::package( 'Paine', 'CL-RM', $rates ) );
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'flat_rate:5' );

		$errors = new WP_Error();
		$service->refuse_hidden_method( self::posted( 'flat_rate:6' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages( 'rcus-shipping-block' ), 'WooCommerce chose standard delivery in its place, and would place the order on it.' );
		$this->assertTrue( WP_Stub::$wc->session->get( 'refresh_totals' ), 'The review is drawn again, without express.' );

		// A later submit: the rates were worked out by an earlier request, and this one reuses them.
		WP_Stub::$wc->session = new WC_Session_Handler();
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'flat_rate:5' );

		$service = $this->service();
		$errors  = new WP_Error();
		$service->refuse_hidden_method( self::posted( 'flat_rate:6' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages( 'rcus-shipping-block' ), 'Read from the posted id, whose instance is a chosen one.' );
		$this->assertTrue( WP_Stub::$wc->session->get( 'refresh_totals' ) );

		// The same, with the matching package second.
		WP_Stub::$wc->shipping()->packages = array(
			0 => self::package( 'Providencia', 'CL-RM', self::rates() ),
			1 => self::package( 'Paine', 'CL-RM', $rates ),
		);
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'flat_rate:5', 'flat_rate:5' );

		$errors = new WP_Error();
		$service->refuse_hidden_method( self::posted( 'flat_rate:5', 'flat_rate:6' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages( 'rcus-shipping-block' ), 'Each posted id is read against its own package.' );
	}

	/**
	 * A posted id that names no instance counts only when the filter took that very id in the same request.
	 *
	 * A courier's rate can carry a chosen instance and an id of its own shape;
	 * the id alone then says nothing, so a submit whose rates an earlier
	 * request worked out is left to WooCommerce.
	 *
	 * @return void
	 */
	public function test_a_posted_id_of_another_shape_counts_only_when_the_filter_took_it(): void {
		$courier = new WC_Shipping_Rate( 'courier-sameday-6', 'Courier same day', 5990.0, array(), 'courier', 6 );
		$service = $this->service();
		$rates   = $service->hide_rates( self::rates() + array( 'courier-sameday-6' => $courier ), self::package( 'Paine' ) );

		$this->assertSame( array( 'flat_rate:5', 'local_pickup:7', 'starken:express' ), array_keys( $rates ), 'Its instance is the chosen one.' );

		WP_Stub::$wc->shipping()->packages                     = array( self::package( 'Paine', 'CL-RM', $rates ) );
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'flat_rate:5' );

		$errors = new WP_Error();
		$service->refuse_hidden_method( self::posted( 'courier-sameday-6' ), $errors );

		$this->assertSame( array( self::DEFAULT_MESSAGE ), $errors->get_error_messages( 'rcus-shipping-block' ), 'Taken by the filter in this request.' );

		$service = $this->service();
		$errors  = new WP_Error();
		$service->refuse_hidden_method( self::posted( 'courier-sameday-6' ), $errors );

		$this->assertSame( array(), $errors->get_error_messages(), 'Worked out by an earlier request: nothing in the id says this rule took it.' );
	}

	/**
	 * A posted rate this rule does not take, or a post that holds no rate id as text, goes through.
	 *
	 * @return void
	 */
	public function test_the_submit_refuses_no_other_posted_rate(): void {
		$service = $this->service();
		$rates   = $service->hide_rates( self::rates(), self::package( 'Paine' ) );

		WP_Stub::$wc->shipping()->packages                     = array( self::package( 'Paine', 'CL-RM', $rates ) );
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'flat_rate:5' );

		$errors = new WP_Error();

		foreach ( array( self::posted( 'flat_rate:5' ), self::posted( 'local_pickup:7' ), self::posted( 'flat_rate:9' ), self::posted( 'flat_rate:60' ), self::posted( 'starken:6x' ), self::posted( '' ) ) as $data ) {
			$service->refuse_hidden_method( $data, $errors );
		}

		$this->assertSame( array(), $errors->get_error_messages(), 'Standard delivery, pickup, a method gone for another reason, an instance or a suffix that only begins like a chosen instance, or nothing.' );

		// What a crafted post or another plugin firing the action can hand over.
		foreach ( array( array( 'shipping_method' => 'flat_rate:6' ), array( 'shipping_method' => array( array( 'flat_rate:6' ) ) ), array( 'shipping_method' => array( 1 => 'flat_rate:6' ) ), array(), null, 'flat_rate:6' ) as $data ) {
			$service->refuse_hidden_method( $data, $errors );
		}

		$this->assertSame( array(), $errors->get_error_messages(), 'No posted id as text, by its package\'s index: nothing to read.' );

		WP_Stub::$wc->shipping()->packages = array( self::package( 'Providencia', 'CL-RM', $rates ) );

		$service->refuse_hidden_method( self::posted( 'flat_rate:6' ), $errors );

		$this->assertSame( array(), $errors->get_error_messages(), 'Express is gone for another reason in a commune not chosen.' );
		$this->assertNull( WP_Stub::$wc->session->get( 'refresh_totals' ), 'Nothing refused, nothing to draw again.' );
	}

	/**
	 * The screen lists every method of every zone as "Zone: method", the zone for everywhere else last.
	 *
	 * @return void
	 */
	public function test_the_choices_are_every_method_of_every_zone(): void {
		WP_Stub::$zones[2]['methods'][0]->enabled = 'no';
		WP_Stub::$zones[0] = array( 'methods' => array( new WC_Shipping_Method( 11, 'flat_rate', 'Internacional' ), new WC_Shipping_Method( 0, 'legacy_flat_rate', 'Sin zona' ) ) );

		$this->assertSame(
			array(
				5  => 'Santiago: Despacho estándar',
				6  => 'Santiago: Despacho Express',
				7  => 'Santiago: Retiro en tienda',
				8  => 'Regiones: Starken (disabled)',
				11 => 'Locations not covered by your other zones: Internacional',
			),
			Shipping_Block::method_choices()
		);
	}

	/**
	 * A zone's name and a method's title come from the database, so the screen escapes them.
	 *
	 * @return void
	 */
	public function test_the_choices_reach_the_screen_escaped(): void {
		WP_Stub::$zones[1]['name'] = '"><script>alert(1)</script>';
		WP_Stub::$zones[1]['methods'][1]->title = 'Express <b>hoy</b>';

		ob_start();
		( new Settings_Page() )->render_fields( array( self::field( 'rcus_shipping_block_methods' ) ) );
		$html = (string) ob_get_clean();

		$this->assertDoesNotMatchRegularExpression( '/<(script|b)\b/i', $html );
		$this->assertStringContainsString( '<option value="6" selected="selected">&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;: Express &lt;b&gt;hoy&lt;/b&gt;</option>', $html );
	}

	/**
	 * A storefront request never lists the zones: the rule reads ids, and the message row asks one zone.
	 *
	 * @return void
	 */
	public function test_the_choices_are_never_listed_on_a_storefront_request(): void {
		WP_Stub::$wc->shipping()->packages                     = array( self::package( 'Paine', 'CL-RM', self::rates() ) );
		WP_Stub::$wc->session->data['chosen_shipping_methods'] = array( 'flat_rate:6' );

		$service = $this->service();
		$service->hide_rates( self::rates(), self::package( 'Paine' ) );
		self::printed( $service );
		$service->refuse_hidden_method( self::posted( 'flat_rate:6' ), new WP_Error() );

		WP_Stub::$wc->shipping()->packages = array( self::package( 'Paine', 'CL-RM', self::only( 'flat_rate:5' ) ) );
		$service->refuse_hidden_method( self::posted( 'flat_rate:6' ), new WP_Error() );

		$this->assertSame( 0, WP_Stub::$zone_listings );
	}

	/**
	 * A registered service, as Plugin::__construct() leaves it.
	 *
	 * @return Shipping_Block
	 */
	private function service(): Shipping_Block {
		WP_Stub::$hooks      = array();
		WP_Stub::$callbacks  = array();
		WP_Stub::$priorities = array();
		WP_Stub::$registered = array();

		$service = new Shipping_Block();
		$service->register();

		return $service;
	}

	/**
	 * What the cart totals print after the shipping row, through the service.
	 *
	 * @param Shipping_Block $service The service.
	 * @return string
	 */
	private static function printed( Shipping_Block $service ): string {
		ob_start();
		$service->print_notice();

		return (string) ob_get_clean();
	}

	/**
	 * The rates a Santiago address gets, by rate id, as WooCommerce hands them to woocommerce_package_rates.
	 *
	 * @return array<string, WC_Shipping_Rate>
	 */
	private static function rates(): array {
		return array(
			'flat_rate:5'     => new WC_Shipping_Rate( 'flat_rate:5', 'Despacho estándar', 2990.0, array(), 'flat_rate', 5 ),
			'flat_rate:6'     => new WC_Shipping_Rate( 'flat_rate:6', 'Despacho Express', 4990.0, array(), 'flat_rate', 6 ),
			'local_pickup:7'  => new WC_Shipping_Rate( 'local_pickup:7', 'Retiro en tienda', 0.0, array(), 'local_pickup', 7 ),
			'starken:express' => new WC_Shipping_Rate( 'starken:express', 'Starken Express', 5990.0, array(), 'starken', 0 ),
		);
	}

	/**
	 * Some of those rates, in the same order.
	 *
	 * @param string ...$ids Rate ids.
	 * @return array<string, WC_Shipping_Rate>
	 */
	private static function only( string ...$ids ): array {
		return array_intersect_key( self::rates(), array_flip( $ids ) );
	}

	/**
	 * A classic checkout's submit as WC_Checkout::get_posted_data() hands it to woocommerce_after_checkout_validation: the rate ids by package index.
	 *
	 * @param string ...$ids The rate id posted for each package, in order.
	 * @return array<string, mixed>
	 */
	private static function posted( string ...$ids ): array {
		return array(
			'billing_city'    => 'Paine',
			'payment_method'  => 'bacs',
			'shipping_method' => $ids,
		);
	}

	/**
	 * A package as WC_Cart::get_shipping_packages() builds it, its rates filled.
	 *
	 * @param string                          $city  The city it ships to.
	 * @param string                          $state Its region.
	 * @param array<string, WC_Shipping_Rate> $rates Its rates.
	 * @return array<string, mixed>
	 */
	private static function package( string $city, string $state = 'CL-RM', array $rates = array() ): array {
		return array(
			'contents'        => array(),
			'contents_cost'   => 29990.0,
			'applied_coupons' => array(),
			'user'            => array( 'ID' => 0 ),
			'destination'     => array(
				'country'   => 'CL',
				'state'     => $state,
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

	/**
	 * One row of the field list, by id.
	 *
	 * @param string $id Option id.
	 * @return array<string, mixed>
	 */
	private static function field( string $id ): array {
		foreach ( Settings::fields() as $field ) {
			if ( $id === $field['id'] ) {
				return $field;
			}
		}

		return array();
	}
}
