<?php
/**
 * How often WooCommerce looks for unpaid orders.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Unpaid_Orders;

/**
 * Covers RhinnoIT\Customizer\Frontend\Unpaid_Orders.
 */
#[CoversClass( Unpaid_Orders::class )]
final class UnpaidOrdersTest extends TestCase {

	/**
	 * WooCommerce's filter, read wherever it schedules the check.
	 */
	const string FILTER = 'woocommerce_cancel_unpaid_orders_interval_minutes';

	/**
	 * Starts from a site with the defaults.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
	}

	/**
	 * Off, it hooks nothing.
	 *
	 * @return void
	 */
	public function test_nothing_is_hooked_while_the_switch_is_off(): void {
		WP_Stub::$options['rcus_unpaid_orders'] = 'no';

		( new Unpaid_Orders() )->register();

		$this->assertSame( array(), WP_Stub::$hooks );
	}

	/**
	 * The master switch turns it off too.
	 *
	 * @return void
	 */
	public function test_the_master_switch_turns_it_off(): void {
		WP_Stub::$options['rcus_unpaid_orders'] = 'yes';
		WP_Stub::$options['rcus_enabled']       = 'no';

		( new Unpaid_Orders() )->register();

		$this->assertSame( array(), WP_Stub::$hooks );
	}

	/**
	 * On, it hooks WooCommerce's interval filter and nothing else.
	 *
	 * No init hook and no Action Scheduler hook: the must-use file it
	 * replaces queried Action Scheduler on every uncached request.
	 *
	 * @return void
	 */
	public function test_the_switch_hooks_only_woocommerces_interval_filter(): void {
		WP_Stub::$options['rcus_unpaid_orders'] = 'yes';

		( new Unpaid_Orders() )->register();

		$this->assertSame( array( self::FILTER ), WP_Stub::$hooks );
		$this->assertSame( array( self::FILTER . ':shorten_interval' ), WP_Stub::$callbacks );
		$this->assertSame( 10, WP_Stub::$priorities[ self::FILTER . ':shorten_interval' ] );
		$this->assertSame( array( array( self::FILTER, Unpaid_Orders::class, 'shorten_interval', 1 ) ), WP_Stub::$registered );
	}

	/**
	 * Hold stock values WooCommerce passes: absint() of the option, an int.
	 *
	 * @return array<string, array{0: int}>
	 */
	public static function long_holds(): array {
		return array(
			'15 minutes' => array( 15 ),
			'16 minutes' => array( 16 ),
			'30 minutes' => array( 30 ),
			'an hour'    => array( 60 ),
			'a day'      => array( 1440 ),
		);
	}

	/**
	 * With a hold of 15 minutes or more, WooCommerce looks every 2 minutes.
	 *
	 * @param int $hold Hold stock minutes.
	 * @return void
	 */
	#[DataProvider( 'long_holds' )]
	public function test_a_hold_of_15_minutes_or_more_is_checked_every_2_minutes( int $hold ): void {
		$this->assertSame( 2, ( new Unpaid_Orders() )->shorten_interval( $hold ) );
	}

	/**
	 * Holds shorter than Webpay's window plus a margin, and 0.
	 *
	 * @return array<string, array{0: int}>
	 */
	public static function short_holds(): array {
		return array(
			'14 minutes'                   => array( 14 ),
			'10 minutes'                   => array( 10 ),
			'Webpay\'s window'             => array( 9 ),
			'5 minutes'                    => array( 5 ),
			'2 minutes'                    => array( 2 ),
			'1 minute'                     => array( 1 ),
			'0, WooCommerce\'s "no check"' => array( 0 ),
		);
	}

	/**
	 * A shorter hold keeps WooCommerce's own interval, and 0 stays 0.
	 *
	 * A short check against a hold close to Webpay's window would cancel
	 * orders whose payment is still on its way.
	 *
	 * @param int $hold Hold stock minutes.
	 * @return void
	 */
	#[DataProvider( 'short_holds' )]
	public function test_a_shorter_hold_keeps_woocommerces_own_interval( int $hold ): void {
		$this->assertSame( $hold, ( new Unpaid_Orders() )->shorten_interval( $hold ) );
	}

	/**
	 * Whatever another filter hands over can never end the job.
	 *
	 * WooCommerce has already unscheduled the check when it applies the
	 * filter, so a TypeError here would leave none until the 03:00 repair.
	 *
	 * @return void
	 */
	public function test_a_value_core_never_sends_cannot_end_the_job(): void {
		$service = new Unpaid_Orders();

		$this->assertSame( 2, $service->shorten_interval( '60' ) );
		$this->assertSame( '10', $service->shorten_interval( '10' ) );
		$this->assertSame( 'abc', $service->shorten_interval( 'abc' ) );
		$this->assertNull( $service->shorten_interval( null ) );
		$this->assertSame( array( 60 ), $service->shorten_interval( array( 60 ) ) );
		$this->assertTrue( $service->shorten_interval( true ) );
	}

	/**
	 * The interval is never raised, and never dropped below 1.
	 *
	 * @return void
	 */
	public function test_the_interval_is_never_raised_nor_dropped_below_one(): void {
		$service = new Unpaid_Orders();

		for ( $minutes = 1; $minutes <= 2000; $minutes++ ) {
			$interval = $service->shorten_interval( $minutes );

			$this->assertLessThanOrEqual( $minutes, $interval );
			$this->assertGreaterThanOrEqual( 1, $interval );
		}
	}
}
