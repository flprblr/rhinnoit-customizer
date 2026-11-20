<?php
/**
 * The keys WP Optimizer's pages and orders carry keep its spelling here.
 *
 * WP Optimizer 2.8 wrote them on every shop this plugin replaces it on:
 * pages it cached, or that are still open, post the marker and read the
 * cart's store cookie, and the failed orders it marked carry the meta. Each
 * is read in place, never copied, so the code has to spell them as it did.
 * The cases of Store_Choice and Store_Stock_Hold assert them too, but a
 * rename pass over the identity would rewrite those along with the code and
 * stay green; this one builds the old prefix so that no such pass can.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Store_Choice;
use RhinnoIT\Customizer\Frontend\Store_Stock_Hold;
use RhinnoIT\Customizer\Settings;

/**
 * Covers the three keys Store_Choice and Store_Stock_Hold read from WP Optimizer's time.
 */
#[CoversClass( Store_Choice::class )]
#[CoversClass( Store_Stock_Hold::class )]
final class KeptKeysTest extends TestCase {

	/**
	 * WP Optimizer's prefix, written in two pieces on purpose: whole, a rename pass would rewrite it.
	 */
	const string LEGACY = 'rw' . 'po';

	/**
	 * The marker, the cookie and the order meta are WP Optimizer's own.
	 *
	 * @return void
	 */
	public function test_the_keys_older_pages_and_orders_carry_keep_their_spelling(): void {
		$this->assertSame( self::LEGACY . '_store_choice', Store_Choice::MARKER, 'Pages cached or open from before the switch post it; renamed, an add from one with no store ticked would go in under WeLaunch\'s cookie store.' );
		$this->assertSame( self::LEGACY . '_cart_store', Store_Choice::CART_STORE_COOKIE, 'The script on those pages reads it to tick home delivery.' );
		$this->assertSame( '_' . self::LEGACY . '_store_units_taken', Store_Stock_Hold::UNITS_TAKEN, 'Renamed, the failed orders WP Optimizer marked would hold their units against other shoppers again, and refuse their own retries.' );
	}

	/**
	 * The option the marker shares its name with is this plugin's own, like every other.
	 *
	 * @return void
	 */
	public function test_the_option_beside_the_marker_is_renamed(): void {
		$this->assertContains( Settings::OPTION_PREFIX . 'store_choice', Settings::option_ids() );
		$this->assertNotContains( Store_Choice::MARKER, Settings::option_ids(), 'The marker is a posted field, not a setting: the same string, another key.' );
	}

	/**
	 * Deleting the plugin leaves the order meta: it is order data, not a setting.
	 *
	 * @return void
	 */
	public function test_uninstall_leaves_the_order_meta(): void {
		$uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );

		$this->assertStringNotContainsString( "'" . Store_Stock_Hold::UNITS_TAKEN . "'", $uninstall, 'A reinstall must find the marked orders still counted as their own.' );
	}
}
