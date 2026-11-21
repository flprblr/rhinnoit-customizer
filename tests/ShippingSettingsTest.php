<?php
/**
 * The rows of the two shipping rules: where they sit, what they offer, what they store.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Admin\Settings_Page;
use RhinnoIT\Customizer\Frontend\Commune_Select;
use RhinnoIT\Customizer\Frontend\Shipping_Block;
use RhinnoIT\Customizer\Install;
use RhinnoIT\Customizer\Settings;

/**
 * Covers the shipping rows of Settings, as the form draws and saves them.
 */
#[CoversClass( Settings::class )]
#[CoversClass( Commune_Select::class )]
final class ShippingSettingsTest extends TestCase {

	/**
	 * Starts from a configured shop with one zone.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();

		WP_Stub::$zones = array(
			1 => array(
				'name'    => 'Santiago',
				'states'  => array( 'CL-RM' ),
				'methods' => array(
					new WC_Shipping_Method( 5, 'flat_rate', 'Despacho estándar' ),
					new WC_Shipping_Method( 6, 'flat_rate', 'Despacho Express' ),
				),
			),
		);
	}

	/**
	 * Each rule's rows sit together under its own label, after Cart and checkout and before Orders.
	 *
	 * @return void
	 */
	public function test_the_rows_sit_between_cart_and_checkout_and_orders(): void {
		$rows = array();

		foreach ( Settings::fields() as $field ) {
			$rows[ $field['id'] ] = $field;
		}

		$ids   = array_keys( $rows );
		$start = array_search( 'rcus_checkout_commune_select', $ids, true );

		$this->assertSame(
			array(
				'rcus_checkout_commune_select',
				'rcus_shipping_block',
				'rcus_shipping_block_methods',
				'rcus_shipping_block_communes',
				'rcus_shipping_block_message',
				'rcus_shipping_exclusion',
				'rcus_shipping_exclusion_communes',
				'rcus_shipping_exclusion_message',
				'rcus_unpaid_orders',
			),
			array_slice( $ids, (int) $start, 9 )
		);

		$expected = array(
			'rcus_shipping_block'              => array( 'checkbox', 'Shipping methods by commune' ),
			'rcus_shipping_block_methods'      => array( 'multiselect', 'Shipping methods by commune' ),
			'rcus_shipping_block_communes'     => array( 'multiselect', 'Shipping methods by commune' ),
			'rcus_shipping_block_message'      => array( 'textarea', 'Shipping methods by commune' ),
			'rcus_shipping_exclusion'          => array( 'checkbox', 'Deliveries by commune' ),
			'rcus_shipping_exclusion_communes' => array( 'multiselect', 'Deliveries by commune' ),
			'rcus_shipping_exclusion_message'  => array( 'textarea', 'Deliveries by commune' ),
		);

		foreach ( $expected as $id => list( $type, $group ) ) {
			$this->assertSame( $type, $rows[ $id ]['type'], $id );
			$this->assertSame( $group, $rows[ $id ]['group'], $id );
			$this->assertSame( Settings::REQUIRES_WOOCOMMERCE, $rows[ $id ]['requires'], $id );
		}

		$this->assertSame( 'yes', $rows['rcus_shipping_block']['default'], 'On by default: inert until both lists hold something.' );
		$this->assertSame( 'yes', $rows['rcus_shipping_exclusion']['default'], 'On by default: inert until its list holds something.' );
	}

	/**
	 * Both commune lists offer Chile's 346 communes, each its own id, grouped by WooCommerce's region codes.
	 *
	 * @return void
	 */
	public function test_the_commune_lists_offer_chiles_communes_by_region(): void {
		foreach ( array( 'rcus_shipping_block_communes', 'rcus_shipping_exclusion_communes' ) as $id ) {
			$field = self::field( $id );

			$this->assertSame( array_keys( Commune_Select::COMMUNES ), array_keys( $field['options'] ), $id );
			$this->assertSame( array( 'Alhué' => 'Alhué', 'Buin' => 'Buin' ), array_slice( $field['options']['CL-RM'], 0, 2 ), $id );
			$this->assertCount( 346, Settings::choice_ids( $field['options'] ), $id );
			$this->assertSame( array( Commune_Select::class, 'region_names' ), $field['group_labels'], $id );
			$this->assertSame( array(), $field['default'], $id );
		}
	}

	/**
	 * The groups take WooCommerce's names for the regions, and their codes without WooCommerce's countries.
	 *
	 * @return void
	 */
	public function test_the_groups_take_woocommerces_region_names(): void {
		$this->assertSame( array(), Commune_Select::region_names(), 'No WooCommerce object: the form shows the codes.' );

		WP_Stub::$wc            = new WooCommerce();
		WP_Stub::$wc->countries = new WC_Countries();

		$names = Commune_Select::region_names();

		$this->assertSame( 'Región Metropolitana de Santiago', $names['CL-RM'] );
		$this->assertSame( array(), array_diff( array_keys( Commune_Select::COMMUNES ), array_keys( $names ) ), 'Every group the lists draw has WooCommerce\'s name.' );

		$html = self::draw( 'rcus_shipping_exclusion_communes' );

		$this->assertStringContainsString( '<optgroup label="Región Metropolitana de Santiago">', $html );
		$this->assertStringContainsString( '<option value="Ñuñoa">Ñuñoa</option>', $html );
		$this->assertStringContainsString( '<optgroup label="Libertador General Bernardo O&#039;Higgins">', $html );
		$this->assertSame( 16, substr_count( $html, '<optgroup' ) );
	}

	/**
	 * The methods list asks its callback on the screen, and is read by its id format everywhere else.
	 *
	 * @return void
	 */
	public function test_the_methods_list_comes_from_its_callback(): void {
		$field = self::field( 'rcus_shipping_block_methods' );

		$this->assertSame( array( Shipping_Block::class, 'method_choices' ), $field['options_callback'] );
		$this->assertSame( Settings::DIGITS, $field['id_format'] );
		$this->assertArrayNotHasKey( 'options', $field );

		WP_Stub::$options['rcus_shipping_block_methods'] = array( '6', '99', 'Express' );

		$this->assertSame( array( '6', '99' ), Settings::chosen( 'shipping_block_methods' ), 'A storefront request keeps every id of the right form, a deleted method\'s included: no rate carries it.' );
		$this->assertSame( 0, WP_Stub::$zone_listings, 'Reading the row lists no zone.' );

		$html = self::draw( 'rcus_shipping_block_methods' );

		$this->assertSame( 1, WP_Stub::$zone_listings, 'Drawing it lists them once.' );
		$this->assertStringContainsString( '<option value="5">Santiago: Despacho estándar</option><option value="6" selected="selected">Santiago: Despacho Express</option>', $html );
		$this->assertStringContainsString( 'data-placeholder="Choose shipping methods…"', $html );
	}

	/**
	 * A save keeps only the communes and the methods the screen offers.
	 *
	 * @return void
	 */
	public function test_a_save_keeps_only_what_the_screen_offers(): void {
		$fields = array( self::field( 'rcus_shipping_block_methods' ), self::field( 'rcus_shipping_block_communes' ), self::field( 'rcus_shipping_exclusion_message' ) );

		$values = Settings_Page::sanitize_submission(
			$fields,
			array(
				'rcus_shipping_block_methods'     => array( '6', '8', '6' ),
				'rcus_shipping_block_communes'    => array( 'Paine', 'O\'Higgins', 'Gotham', 'CL-RM', array( 'Buin' ) ),
				'rcus_shipping_exclusion_message' => "  Sin despacho a <b>Penco</b>.\nEscríbenos.  ",
			)
		);

		$this->assertSame( array( '6' ), $values['rcus_shipping_block_methods'], 'No zone offers 8.' );
		$this->assertSame( array( 'Paine', 'O\'Higgins' ), $values['rcus_shipping_block_communes'], 'Only Chile\'s communes, by their names.' );
		$this->assertSame( "Sin despacho a Penco.\nEscríbenos.", $values['rcus_shipping_exclusion_message'] );
	}

	/**
	 * A message's default is never seeded: it is a translated sentence, read where it is printed.
	 *
	 * @return void
	 */
	public function test_a_message_default_is_never_seeded(): void {
		$this->assertArrayNotHasKey( 'rcus_shipping_block_message', Settings::defaults() );
		$this->assertArrayNotHasKey( 'rcus_shipping_exclusion_message', Settings::defaults() );

		Install::activate();

		$this->assertArrayNotHasKey( 'rcus_shipping_block_message', WP_Stub::$options, 'Seeded, the English of the first read would stay in the row on every shop.' );
		$this->assertArrayNotHasKey( 'rcus_shipping_exclusion_message', WP_Stub::$options );
		$this->assertSame( array(), WP_Stub::$options['rcus_shipping_exclusion_communes'], 'The lists are seeded, empty.' );

		WP_Stub::$translations = array( 'We cannot deliver to your commune for the time being. Please check your address, or contact us if you need help.' => 'Por ahora no podemos despachar a tu comuna. Revisa tu dirección o contáctanos si necesitas ayuda.' );

		$this->assertSame( 'Por ahora no podemos despachar a tu comuna. Revisa tu dirección o contáctanos si necesitas ayuda.', Settings::text( 'shipping_exclusion_message' ), 'Unseeded, the default follows the shop\'s language.' );
		$this->assertStringContainsString( '>Por ahora no podemos despachar a tu comuna.', self::draw( 'rcus_shipping_exclusion_message' ), 'And the box shows it, in the admin\'s language, until the screen is saved.' );
	}

	/**
	 * Draws one row as the form does and returns the HTML.
	 *
	 * @param string $id Option id.
	 * @return string
	 */
	private static function draw( string $id ): string {
		ob_start();
		( new Settings_Page() )->render_fields( array( self::field( $id ) ) );

		return (string) ob_get_clean();
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
