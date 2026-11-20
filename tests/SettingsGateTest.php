<?php
/**
 * The master switch and the per-feature switches read through it.
 *
 * Every feature reads its own switch through Settings::feature() in its
 * register(), so a mistake here is silent: the feature simply never hooks, or
 * hooks with the plugin switched off.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Settings;

#[CoversClass( Settings::class )]
final class SettingsGateTest extends TestCase {

	/**
	 * Starts every test from an unconfigured site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::$options = array();
	}

	/**
	 * A feature with no stored value falls back to its declared default.
	 *
	 * @return void
	 */
	public function test_an_unconfigured_feature_uses_its_declared_default(): void {
		$this->assertTrue( Settings::feature( 'cart_sale_price' ), 'The struck-through price ships on.' );
		$this->assertFalse( Settings::feature( 'express_checkout' ), 'A journey change ships off.' );
		$this->assertFalse( Settings::feature( 'express_checkout_all' ), 'A journey change ships off.' );
		$this->assertFalse( Settings::feature( 'checkout_coupon_prompt' ), 'A journey change ships off.' );
		$this->assertFalse( Settings::feature( 'checkout_login_prompt' ), 'A journey change ships off.' );
	}

	/**
	 * The master switch takes every feature down with it.
	 *
	 * @return void
	 */
	public function test_the_master_switch_overrides_every_feature(): void {
		WP_Stub::$options['rcus_enabled']   = 'no';
		WP_Stub::$options['rcus_feature_a'] = 'yes';

		$this->assertFalse( Settings::feature( 'feature_a' ) );

		unset( WP_Stub::$options['rcus_enabled'] );

		$ships_on = 'yes' === ( Settings::defaults()['rcus_enabled'] ?? null );

		$this->assertSame( $ships_on, Settings::is_enabled(), 'A master switch never saved reads as its declared default.' );
		$this->assertSame( $ships_on, Settings::feature( 'feature_a' ), 'And so does every feature behind it.' );
	}

	/**
	 * A feature switched off stays off while the master switch is on.
	 *
	 * @return void
	 */
	public function test_one_feature_can_be_switched_off_alone(): void {
		WP_Stub::$options['rcus_enabled']   = 'yes';
		WP_Stub::$options['rcus_feature_a'] = 'no';
		WP_Stub::$options['rcus_feature_b'] = 'yes';

		$this->assertFalse( Settings::feature( 'feature_a' ) );
		$this->assertTrue( Settings::feature( 'feature_b' ), 'The others are untouched.' );
	}

	/**
	 * An unknown key is refused rather than silently enabled.
	 *
	 * @return void
	 */
	public function test_an_unknown_feature_is_off(): void {
		WP_Stub::$options['rcus_enabled'] = 'yes';

		$this->assertFalse( Settings::feature( 'no_such_thing' ) );
	}

	/**
	 * A row holding anything but 'yes' reads as off.
	 *
	 * Read on every storefront request, so a corrupt row must neither fatal
	 * nor turn the feature on.
	 *
	 * @return void
	 */
	public function test_a_corrupt_row_reads_as_off(): void {
		WP_Stub::$options['rcus_enabled'] = 'yes';

		foreach ( array( '1', 'on', '', array( 'yes' ), 42, true ) as $stored ) {
			WP_Stub::$options['rcus_feature_a'] = $stored;

			$this->assertFalse( Settings::feature( 'feature_a' ), sprintf( 'Read as on for %s.', var_export( $stored, true ) ) );
		}
	}
}
