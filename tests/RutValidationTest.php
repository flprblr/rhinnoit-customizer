<?php
/**
 * The RUT on the classic checkout: the field, the submit, the script.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Rut_Validation;
use RhinnoIT\Customizer\Settings;

/**
 * Covers RhinnoIT\Customizer\Frontend\Rut_Validation.
 */
#[CoversClass( Rut_Validation::class )]
final class RutValidationTest extends TestCase {

	/**
	 * The message for a missing RUT.
	 */
	private const string REQUIRED = 'Please enter your RUT.';

	/**
	 * The message for a RUT that is not valid.
	 */
	private const string INVALID = 'The RUT you entered is not valid. Please check it.';

	/**
	 * Starts every case on the checkout with the switch at its default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$page_type = 'checkout';
	}

	/**
	 * Leaves the stubs as the rest of the suite expects them.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		WP_Stub::$page_type = '';
	}

	/**
	 * It ships on, after the plugin that adds the field and after WooCommerce's checkout script, and its switches turn it off.
	 *
	 * @return void
	 */
	public function test_it_ships_on_and_the_switches_turn_it_off(): void {
		( new Rut_Validation() )->register();

		$this->assertSame(
			array(
				'woocommerce_checkout_fields:relax_field',
				'woocommerce_checkout_posted_data:clear_for_foreigner',
				'woocommerce_after_checkout_validation:check_rut',
				'wp_enqueue_scripts:add_script',
			),
			WP_Stub::$callbacks
		);
		$this->assertSame( 20, WP_Stub::$priorities['woocommerce_checkout_fields:relax_field'], 'After the plugin that adds the field.' );
		$this->assertSame( 100, WP_Stub::$priorities['wp_enqueue_scripts:add_script'], 'After WooCommerce enqueues wc-checkout.' );
		$this->assertContains( array( 'woocommerce_after_checkout_validation', Rut_Validation::class, 'check_rut', 2 ), WP_Stub::$registered, 'WooCommerce passes the data and the errors.' );

		WP_Stub::reset();
		WP_Stub::$options['rcus_rut_validation'] = 'no';
		( new Rut_Validation() )->register();
		$this->assertSame( array(), WP_Stub::$hooks );

		WP_Stub::reset();
		WP_Stub::$options['rcus_enabled'] = 'no';
		( new Rut_Validation() )->register();
		$this->assertSame( array(), WP_Stub::$hooks );
	}

	/**
	 * Its switch sits last in Cart and checkout, on by default.
	 *
	 * @return void
	 */
	public function test_its_row_sits_in_cart_and_checkout(): void {
		$ids = array_column( Settings::fields(), 'id' );
		$row = Settings::fields()[ array_search( 'rcus_rut_validation', $ids, true ) ];

		$this->assertSame( 'rcus_checkout_commune_select', $ids[ array_search( 'rcus_rut_validation', $ids, true ) - 1 ] );
		$this->assertSame( 'rcus_shipping_block', $ids[ array_search( 'rcus_rut_validation', $ids, true ) + 1 ] );
		$this->assertSame( 'checkbox', $row['type'] );
		$this->assertSame( 'Cart and checkout', $row['group'] );
		$this->assertSame( Settings::REQUIRES_WOOCOMMERCE, $row['requires'] );
		$this->assertSame( 'yes', $row['default'] );
		$this->assertArrayNotHasKey( 'needs', $row, 'Whether the checkout has a RUT field is another plugin\'s setting: never a mark.' );
	}

	/**
	 * The field stops being required in WooCommerce's sense, keeps everything else, and says on the input what it was.
	 *
	 * @return void
	 */
	public function test_the_field_stops_being_required_in_woocommerces_sense(): void {
		$service = new Rut_Validation();
		$fields  = $service->relax_field( self::fields( true ) );
		$field   = $fields['billing']['billing_rut'];

		$this->assertFalse( $field['required'], 'WooCommerce would ask a foreigner for it too.' );
		$this->assertSame( array( 'form-row-wide', 'rut' ), $field['class'], 'WooCommerce\'s script checks the class.' );
		$this->assertSame( 'RUT', $field['label'] );
		$this->assertSame( 25, $field['priority'] );
		$this->assertSame( array( 'maxlength' => '12', 'data-rcus-required' => 'yes' ), $field['custom_attributes'] );
		$this->assertSame( self::fields( true )['billing']['billing_is_chilean'], $fields['billing']['billing_is_chilean'], 'The radio is left alone.' );
		$this->assertSame( self::fields( true )['billing']['billing_first_name'], $fields['billing']['billing_first_name'] );

		$optional = ( new Rut_Validation() )->relax_field( self::fields( false ) );

		$this->assertSame( 'no', $optional['billing']['billing_rut']['custom_attributes']['data-rcus-required'] );
	}

	/**
	 * A second pass of the filter keeps what the field said before the first.
	 *
	 * @return void
	 */
	public function test_a_second_pass_keeps_what_the_field_said(): void {
		$service = new Rut_Validation();
		$fields  = $service->relax_field( $service->relax_field( self::fields( true ) ) );

		$this->assertSame( 'yes', $fields['billing']['billing_rut']['custom_attributes']['data-rcus-required'] );

		$errors = new WP_Error();
		$service->check_rut( array( 'billing_rut' => '' ), $errors );

		$this->assertSame( array( self::REQUIRED ), $errors->get_error_messages(), 'Read again from the relaxed field, the flag would say optional.' );
	}

	/**
	 * Without the RUT field the fields go through unchanged, and the submit is not checked.
	 *
	 * @return void
	 */
	public function test_a_checkout_without_the_field_is_left_alone(): void {
		$service = new Rut_Validation();
		$fields  = self::fields( true );

		unset( $fields['billing']['billing_rut'] );

		$this->assertSame( $fields, $service->relax_field( $fields ) );
		$this->assertSame( 'not fields', $service->relax_field( 'not fields' ) );
		$this->assertSame( array( 'billing' => 'x' ), $service->relax_field( array( 'billing' => 'x' ) ) );

		$errors = new WP_Error();
		$service->check_rut( array( 'billing_rut' => 'garbage' ), $errors );

		$this->assertSame( array(), $errors->get_error_messages(), 'No RUT field on this checkout: nothing to ask.' );
	}

	/**
	 * RUTs Chile's check digit accepts, as shoppers type them.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function validRuts(): array {
		return array(
			'dotted and dashed'      => array( '12.345.678-5' ),
			'dashed'                 => array( '12345678-5' ),
			'bare'                   => array( '123456785' ),
			'seven digits'           => array( '7.654.321-6' ),
			'ending in K'            => array( '16.000.004-K' ),
			'ending in lowercase k'  => array( '16.000.004-k' ),
			'bare with lowercase k'  => array( '1000005k' ),
			'ending in 0'            => array( '16.000.009-0' ),
			'bare ending in 0'       => array( '160000120' ),
			'with spaces'            => array( ' 12 345 678 5 ' ),
			'leading zero'           => array( '07.654.321-6' ),
		);
	}

	/**
	 * Each one passes.
	 *
	 * @param string $rut A RUT as typed.
	 * @return void
	 */
	#[DataProvider( 'validRuts' )]
	public function test_a_valid_rut_passes( string $rut ): void {
		$this->assertTrue( Rut_Validation::is_valid( $rut ) );
	}

	/**
	 * RUTs refused, one reason each.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function invalidRuts(): array {
		return array(
			'wrong check digit'         => array( '12.345.678-9' ),
			'K where 5 belongs'         => array( '12.345.678-K' ),
			'0 where K belongs'         => array( '16.000.004-0' ),
			'seven characters'          => array( '1.234.567' ),
			'six digits that add up'    => array( '123.456-0' ),
			'ten characters'            => array( '123.456.789-0' ),
			'nine digits that add up'   => array( '123.456.789-2' ),
			'a K in the body'           => array( '1234K67-8' ),
			'all zeros'                 => array( '0.000.000-0' ),
			'eight zeros'               => array( '00.000.000-0' ),
			'eight repeated digits'     => array( '11.111.111-1' ),
			'eight repeated digits too' => array( '22.222.222-2' ),
			'empty'                     => array( '' ),
			'no digits'                 => array( 'abc' ),
		);
	}

	/**
	 * Each one is refused, though all zeros and eight repeated digits add up to their check digit.
	 *
	 * @param string $rut A RUT as typed.
	 * @return void
	 */
	#[DataProvider( 'invalidRuts' )]
	public function test_an_invalid_rut_is_refused( string $rut ): void {
		$this->assertFalse( Rut_Validation::is_valid( $rut ) );
	}

	/**
	 * Radio values, and what they say.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function choices(): array {
		return array(
			'chilean'            => array( 'chilean', Rut_Validation::CHILEAN ),
			'Chilena'            => array( 'Chilena', Rut_Validation::CHILEAN ),
			'CHILENO'            => array( 'CHILENO', Rut_Validation::CHILEAN ),
			'1'                  => array( '1', Rut_Validation::CHILEAN ),
			'true'               => array( 'true', Rut_Validation::CHILEAN ),
			'si'                 => array( 'si', Rut_Validation::CHILEAN ),
			'Sí, accented'       => array( 'Sí', Rut_Validation::CHILEAN ),
			'containing chile'   => array( ' soy de Chile ', Rut_Validation::CHILEAN ),
			'foreigner'          => array( 'foreigner', Rut_Validation::FOREIGNER ),
			'Extranjero'         => array( 'Extranjero', Rut_Validation::FOREIGNER ),
			'extranjera'         => array( 'extranjera', Rut_Validation::FOREIGNER ),
			'0'                  => array( '0', Rut_Validation::FOREIGNER ),
			'false'              => array( 'false', Rut_Validation::FOREIGNER ),
			'No'                 => array( 'No', Rut_Validation::FOREIGNER ),
			'containing extra'   => array( 'EXTRANJERO/A', Rut_Validation::FOREIGNER ),
			'nothing chosen'     => array( '', '' ),
			'another value'      => array( 'otro', '' ),
			'no radio'           => array( null, '' ),
			'an array'           => array( array( 'chileno' ), '' ),
			'an integer, as one' => array( 1, Rut_Validation::CHILEAN ),
		);
	}

	/**
	 * The radio is read as the old plugin read it, accents and case aside.
	 *
	 * @param mixed  $value    The radio's posted value.
	 * @param string $expected What it says.
	 * @return void
	 */
	#[DataProvider( 'choices' )]
	public function test_the_radio_is_read_as_the_old_plugin_read_it( mixed $value, string $expected ): void {
		$this->assertSame( $expected, Rut_Validation::choice( $value ) );
	}

	/**
	 * A foreigner's RUT is emptied before WooCommerce stores it; anyone else's is kept.
	 *
	 * @return void
	 */
	public function test_a_foreigners_rut_is_emptied(): void {
		$service = new Rut_Validation();
		$posted  = array(
			'billing_first_name' => 'Ana',
			'billing_is_chilean' => 'Extranjero',
			'billing_rut'        => '12.345.678-5',
		);

		$this->assertSame( array_merge( $posted, array( 'billing_rut' => '' ) ), $service->clear_for_foreigner( $posted ) );
		$this->assertSame( array_merge( $posted, array( 'billing_is_chilean' => 'Chileno' ) ), $service->clear_for_foreigner( array_merge( $posted, array( 'billing_is_chilean' => 'Chileno' ) ) ) );

		unset( $posted['billing_rut'] );

		$this->assertSame( $posted, $service->clear_for_foreigner( $posted ), 'No RUT field: nothing to store, so no row is added.' );
		$this->assertSame( 'not data', $service->clear_for_foreigner( 'not data' ) );
	}

	/**
	 * A foreigner is never asked for a RUT, whatever the field said or the page sent.
	 *
	 * @return void
	 */
	public function test_a_foreigner_is_never_asked(): void {
		$service = new Rut_Validation();
		$service->relax_field( self::fields( true ) );

		$posted = $service->clear_for_foreigner(
			array(
				'billing_is_chilean' => 'extranjero',
				'billing_rut'        => 'garbage',
			)
		);

		$errors = new WP_Error();
		$service->check_rut( $posted, $errors );

		$this->assertSame( '', $posted['billing_rut'] );
		$this->assertSame( array(), $errors->get_error_messages(), 'Required by the field, but not of a foreigner.' );
	}

	/**
	 * Who must give a RUT, and when an empty one passes.
	 *
	 * @return array<string, array{0: bool, 1: mixed, 2: bool}>
	 */
	public static function requirements(): array {
		return array(
			'a Chilean, the field required'         => array( true, 'Chileno', true ),
			'a Chilean, the field optional'         => array( false, 'chilena', true ),
			'no choice, the field required'         => array( true, '', true ),
			'no choice, the field optional'         => array( false, '', false ),
			'no radio, the field required'          => array( true, null, true ),
			'no radio, the field optional'          => array( false, null, false ),
			'another value, the field required'     => array( true, 'otro', true ),
			'another value, the field optional'     => array( false, 'otro', false ),
			'a crafted choice, the field optional'  => array( false, array( 'chileno' ), false ),
			'a foreigner, the field required'       => array( true, 'Extranjero', false ),
		);
	}

	/**
	 * A missing RUT is refused where it is required: always of a Chilean, never of a foreigner, otherwise as the field was.
	 *
	 * @param bool  $field_required Whether the plugin that adds the field made it required.
	 * @param mixed $choice         The radio's posted value; null for no radio.
	 * @param bool  $refused        Whether an empty RUT is refused.
	 * @return void
	 */
	#[DataProvider( 'requirements' )]
	public function test_a_missing_rut_is_refused_where_required( bool $field_required, mixed $choice, bool $refused ): void {
		$service = new Rut_Validation();
		$service->relax_field( self::fields( $field_required ) );

		$posted = array( 'billing_rut' => '' );

		if ( null !== $choice ) {
			$posted['billing_is_chilean'] = $choice;
		}

		$errors = new WP_Error();
		$service->check_rut( $posted, $errors );

		if ( $refused ) {
			$this->assertSame( array( 'rcus-rut-required' ), $errors->get_error_codes() );
			$this->assertSame( array( self::REQUIRED ), $errors->get_error_messages() );
			$this->assertSame( array( 'id' => 'billing_rut' ), $errors->get_error_data( 'rcus-rut-required' ), 'WooCommerce links the notice to the field by its id.' );
		} else {
			$this->assertSame( array(), $errors->get_error_messages() );
		}
	}

	/**
	 * A RUT that is not valid is refused, required or not, with the field's id; a valid one passes.
	 *
	 * @return void
	 */
	public function test_an_invalid_rut_is_refused_with_the_field_id(): void {
		foreach ( array( true, false ) as $field_required ) {
			$service = new Rut_Validation();
			$service->relax_field( self::fields( $field_required ) );

			$errors = new WP_Error();
			$service->check_rut( array( 'billing_rut' => '12.345.678-9' ), $errors );

			$this->assertSame( array( self::INVALID ), $errors->get_error_messages( 'rcus-rut-invalid' ), 'Typed, it is checked even where it is optional.' );
			$this->assertSame( array( 'id' => 'billing_rut' ), $errors->get_error_data( 'rcus-rut-invalid' ) );

			$errors = new WP_Error();
			$service->check_rut(
				array(
					'billing_is_chilean' => 'Chileno',
					'billing_rut'        => '12.345.678-5',
				),
				$errors
			);

			$this->assertSame( array(), $errors->get_error_messages() );
		}
	}

	/**
	 * What another plugin firing the action, or a crafted post, can pass: no fatal, nothing let through.
	 *
	 * @return void
	 */
	public function test_the_check_takes_whatever_it_is_handed(): void {
		$service = new Rut_Validation();
		$service->relax_field( self::fields( true ) );

		$service->check_rut( array( 'billing_rut' => '' ), null );
		$service->check_rut( array( 'billing_rut' => '' ), new stdClass() );
		$service->check_rut( 'not data', new WP_Error() );

		$errors = new WP_Error();
		$service->check_rut( array( 'billing_rut' => array( '12.345.678-5' ) ), $errors );

		$this->assertSame( array( self::REQUIRED ), $errors->get_error_messages(), 'A RUT that is not text is no RUT.' );
	}

	/**
	 * The messages are read when they are used, after the translations load, not in register().
	 *
	 * @return void
	 */
	public function test_the_messages_follow_the_shops_language(): void {
		$service = new Rut_Validation();
		$service->register();
		$service->relax_field( self::fields( true ) );

		WP_Stub::$translations = array(
			self::REQUIRED => 'Por favor, ingresa tu RUT.',
			self::INVALID  => 'El RUT que ingresaste no es válido. Por favor, revísalo.',
		);

		$errors = new WP_Error();
		$service->check_rut( array( 'billing_rut' => '' ), $errors );
		$this->assertSame( array( 'Por favor, ingresa tu RUT.' ), $errors->get_error_messages() );

		$service->add_script();
		$this->assertStringContainsString( json_encode( 'El RUT que ingresaste no es válido. Por favor, revísalo.' ), WP_Stub::$inline[0] );
	}

	/**
	 * On the checkout the script follows WooCommerce's checkout script with both messages, and the style marks the field.
	 *
	 * @return void
	 */
	public function test_the_script_follows_the_checkout_script(): void {
		( new Rut_Validation() )->add_script();

		$this->assertSame( array( array( 'wc-checkout', 'after' ) ), WP_Stub::$inline_at );

		$script = WP_Stub::$inline[0];

		$this->assertStringNotContainsString( '__MESSAGES__', $script );
		$this->assertStringContainsString( 'var M={"required":"' . self::REQUIRED . '","invalid":"' . self::INVALID . '"}', $script );
		$this->assertStringContainsString( '"yes"===f.attr("data-rcus-required")', $script, 'The field\'s own flag, which relax_field() writes.' );
		$this->assertStringContainsString( 'checkout_place_order.rcusRut', $script );
		$this->assertStringContainsString( '.on("updated_checkout",o)', $script );
		$this->assertStringContainsString( 'if(!f.length)return', $script, 'The field may not exist.' );

		$this->assertSame( array( 'register:rcus-rut-validation:false', 'enqueue:rcus-rut-validation' ), WP_Stub::$styles, 'A style with no file.' );
		$this->assertStringStartsWith( '#billing_rut[aria-invalid=true]{', WP_Stub::$inline_styles['rcus-rut-validation'] );
		$this->assertStringContainsString( '#billing_rut:disabled{', WP_Stub::$inline_styles['rcus-rut-validation'] );
	}

	/**
	 * Off the checkout nothing is printed.
	 *
	 * @return void
	 */
	public function test_nothing_is_printed_off_the_checkout(): void {
		foreach ( array( 'cart', 'product', 'shop', 'page' ) as $page ) {
			WP_Stub::$page_type = $page;

			( new Rut_Validation() )->add_script();

			$this->assertSame( array(), WP_Stub::$inline, $page );
			$this->assertSame( array(), WP_Stub::$styles, $page );
		}
	}

	/**
	 * A failed encode prints neither a broken script nor the style it would carry.
	 *
	 * @return void
	 */
	public function test_a_failed_encode_prints_nothing(): void {
		WP_Stub::$json_fails = true;

		( new Rut_Validation() )->add_script();

		$this->assertSame( array(), WP_Stub::$inline, '"var M=;" would break every script after it.' );
		$this->assertSame( array(), WP_Stub::$styles );
		$this->assertSame( array(), WP_Stub::$inline_styles );
	}

	/**
	 * The checkout fields as WooCommerce builds them with the other plugin's two, the RUT field required or not.
	 *
	 * @param bool $required Whether that plugin made the RUT field required.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private static function fields( bool $required ): array {
		return array(
			'billing'  => array(
				'billing_first_name' => array(
					'label'    => 'Nombre',
					'required' => true,
					'class'    => array( 'form-row-first' ),
					'priority' => 10,
				),
				'billing_is_chilean' => array(
					'type'     => 'radio',
					'label'    => 'Nacionalidad',
					'options'  => array(
						'Chileno'    => 'Chileno',
						'Extranjero' => 'Extranjero',
					),
					'required' => true,
					'class'    => array( 'form-row-wide', 'validate-required' ),
					'priority' => 24,
				),
				'billing_rut'        => array(
					'type'              => 'text',
					'label'             => 'RUT',
					'required'          => $required,
					'class'             => $required ? array( 'form-row-wide', 'validate-required', 'rut' ) : array( 'form-row-wide', 'rut' ),
					'priority'          => 25,
					'custom_attributes' => array( 'maxlength' => '12' ),
				),
			),
			'shipping' => array(),
			'account'  => array(),
			'order'    => array(),
		);
	}
}
