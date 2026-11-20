<?php
/**
 * Fintoc's payment page ends with the stock hold.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Fintoc_Session_Expiry;

/**
 * Covers RhinnoIT\Customizer\Frontend\Fintoc_Session_Expiry.
 */
#[CoversClass( Fintoc_Session_Expiry::class )]
final class FintocSessionExpiryTest extends TestCase {

	/**
	 * Fintoc's filter.
	 */
	const string FILTER = 'woocommerce_fintoc_checkout_session_payload';

	/**
	 * A shop managing stock with hoka's 30-minute hold.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$options['woocommerce_manage_stock']       = 'yes';
		WP_Stub::$options['woocommerce_hold_stock_minutes'] = '30';
	}

	/**
	 * The body Fintoc for WooCommerce 1.2.4 builds (class-wc-fintoc-gateway.php:287-302): no expires_at.
	 *
	 * @return array<string, mixed>
	 */
	private static function payload(): array {
		return array(
			'amount'         => 74990,
			'currency'       => 'CLP',
			'customer_email' => 'b@shop.test',
			'success_url'    => 'https://shop.test/?wc-api=wc_fintoc_return&result=success',
			'cancel_url'     => 'https://shop.test/?wc-api=wc_fintoc_return&result=cancel',
			'metadata'       => array(
				'woocommerce_order_id' => '900',
				'order_key'            => 'wc_order_abc',
				'source'               => 'woocommerce',
			),
		);
	}

	/**
	 * An order created the given number of minutes ago.
	 *
	 * @param int $minutes_ago Minutes since the order was created.
	 * @return WC_Order
	 */
	private static function order( int $minutes_ago ): WC_Order {
		$order               = new WC_Order( 'pending', array(), 900 );
		$order->date_created = new DateTimeImmutable( '@' . ( time() - $minutes_ago * 60 ) );

		return $order;
	}

	/**
	 * The expiry set, as a timestamp.
	 *
	 * @param mixed $payload The filtered payload.
	 * @return int
	 */
	private static function expiry( mixed $payload ): int {
		self::assertIsArray( $payload );

		return ( new DateTimeImmutable( $payload['expires_at'] ) )->getTimestamp();
	}

	/**
	 * Off it hooks nothing; on, it hooks Fintoc's payload filter with both arguments.
	 *
	 * @return void
	 */
	public function test_the_switch_hooks_fintocs_payload_filter(): void {
		WP_Stub::$options['rcus_fintoc_session_expiry'] = 'no';
		( new Fintoc_Session_Expiry() )->register();
		$this->assertSame( array(), WP_Stub::$hooks );

		WP_Stub::$options['rcus_fintoc_session_expiry'] = 'yes';
		( new Fintoc_Session_Expiry() )->register();

		$this->assertSame( array( self::FILTER . ':end_with_hold' ), WP_Stub::$callbacks );
		$this->assertSame( array( self::FILTER, Fintoc_Session_Expiry::class, 'end_with_hold', 2 ), WP_Stub::$registered[0] );
	}

	/**
	 * A fresh checkout's session ends when WooCommerce's reservation does: creation plus Hold stock.
	 *
	 * @return void
	 */
	public function test_a_fresh_session_ends_with_the_hold(): void {
		$payload = ( new Fintoc_Session_Expiry() )->end_with_hold( self::payload(), self::order( 0 ) );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $payload['expires_at'], 'ISO 8601 in UTC, as Fintoc documents it.' );
		$this->assertEqualsWithDelta( time() + 30 * 60, self::expiry( $payload ), 2 );

		unset( $payload['expires_at'] );
		$this->assertSame( self::payload(), $payload, 'Nothing else in the body changes.' );
	}

	/**
	 * A retry late in the hold still gets the 11 minutes Fintoc needs, never less.
	 *
	 * @return void
	 */
	public function test_a_late_retry_gets_at_least_eleven_minutes(): void {
		$service = new Fintoc_Session_Expiry();

		$this->assertEqualsWithDelta( time() + 22 * 60, self::expiry( $service->end_with_hold( self::payload(), self::order( 8 ) ) ), 2, 'Eight minutes in: the hold still has 22.' );
		$this->assertEqualsWithDelta( time() + 11 * 60, self::expiry( $service->end_with_hold( self::payload(), self::order( 25 ) ) ), 2 );
		$this->assertEqualsWithDelta( time() + 11 * 60, self::expiry( $service->end_with_hold( self::payload(), self::order( 600 ) ) ), 2 );
	}

	/**
	 * Without a hold to follow, or with an expiry already set, the body is left as it came.
	 *
	 * @return void
	 */
	public function test_it_steps_aside_without_a_hold(): void {
		$service = new Fintoc_Session_Expiry();

		$set               = self::payload();
		$set['expires_at'] = '2026-10-07T03:00:00Z';
		$this->assertSame( $set, $service->end_with_hold( $set, self::order( 0 ) ), 'Another filter set one.' );

		WP_Stub::$options['woocommerce_hold_stock_minutes'] = '0';
		$this->assertSame( self::payload(), $service->end_with_hold( self::payload(), self::order( 0 ) ), 'Hold stock off.' );

		WP_Stub::$options['woocommerce_hold_stock_minutes'] = '30';
		WP_Stub::$options['woocommerce_manage_stock']       = 'no';
		$this->assertSame( self::payload(), $service->end_with_hold( self::payload(), self::order( 0 ) ), 'Stock not managed.' );
	}

	/**
	 * Whatever another filter hands over passes through.
	 *
	 * @return void
	 */
	public function test_values_it_cannot_read_pass_through(): void {
		$service = new Fintoc_Session_Expiry();

		$this->assertSame( 'body', $service->end_with_hold( 'body', self::order( 0 ) ) );
		$this->assertSame( self::payload(), $service->end_with_hold( self::payload() ), 'Applied with one argument.' );
		$this->assertSame( self::payload(), $service->end_with_hold( self::payload(), 900 ), 'An id, not an order.' );

		$undated = new WC_Order( 'pending', array(), 900 );
		$this->assertEqualsWithDelta( time() + 30 * 60, self::expiry( $service->end_with_hold( self::payload(), $undated ) ), 2, 'No creation date: counted from now.' );
	}
}
