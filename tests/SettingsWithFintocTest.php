<?php
/**
 * The settings screen of a shop running Fintoc for WooCommerce.
 *
 * Its own process: Fintoc's main file defines FINTOC_WC_VERSION when it loads,
 * and the rest of the suite must not see it.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Settings;

/**
 * Covers Settings::available_fields() with Fintoc.
 */
#[CoversClass( Settings::class )]
final class SettingsWithFintocTest extends TestCase {

	/**
	 * The Fintoc switch is offered where Fintoc is installed.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_the_fintoc_switch_is_on_the_screen(): void {
		define( 'FINTOC_WC_VERSION', '1.2.4' );

		WP_Stub::reset();

		$this->assertContains( 'rcus_fintoc_session_expiry', array_column( Settings::available_fields(), 'id' ) );
	}
}
