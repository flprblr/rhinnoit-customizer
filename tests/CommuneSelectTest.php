<?php
/**
 * The commune dropdown on the classic checkout.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Commune_Select;

/**
 * Covers RhinnoIT\Customizer\Frontend\Commune_Select.
 */
#[CoversClass( Commune_Select::class )]
final class CommuneSelectTest extends TestCase {

	/**
	 * Starts every case on the checkout with the switch on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$options['rcus_checkout_commune_select'] = 'yes';
		WP_Stub::$page_type                               = 'checkout';
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
	 * On the checkout, the script follows WooCommerce's checkout script with the list and both placeholders.
	 *
	 * @return void
	 */
	public function test_prints_the_script_after_the_checkout_script(): void {
		( new Commune_Select() )->register();

		$this->assertContains( 'wp_enqueue_scripts:add_script', WP_Stub::$callbacks );

		( new Commune_Select() )->add_script();

		$this->assertSame( array( array( 'wc-checkout', 'after' ) ), WP_Stub::$inline_at );

		$script = WP_Stub::$inline[0];

		$this->assertStringNotContainsString( '__COMMUNES__', $script );
		$this->assertStringNotContainsString( '__CHOOSE__', $script );
		$this->assertStringNotContainsString( '__REGION__', $script );
		$this->assertStringContainsString( '"CL-RM":["Alhu', $script );
		$this->assertStringContainsString( 'Choose a commune', $script );
		$this->assertStringContainsString( 'Choose the region first', $script );
	}

	/**
	 * Every one of WooCommerce's 16 Chilean regions has its communes, 346 in all, none twice in a region.
	 *
	 * @return void
	 */
	public function test_lists_every_region_and_commune(): void {
		$regions = array( 'CL-AI', 'CL-AN', 'CL-AP', 'CL-AR', 'CL-AT', 'CL-BI', 'CL-CO', 'CL-LI', 'CL-LL', 'CL-LR', 'CL-MA', 'CL-ML', 'CL-NB', 'CL-RM', 'CL-TA', 'CL-VS' );
		$listed  = array_keys( Commune_Select::COMMUNES );

		sort( $listed );

		$this->assertSame( $regions, $listed );
		$this->assertSame( 346, array_sum( array_map( 'count', Commune_Select::COMMUNES ) ) );

		foreach ( Commune_Select::COMMUNES as $region => $communes ) {
			$this->assertSame( $communes, array_values( array_unique( $communes ) ), $region );
		}
	}

	/**
	 * Off the checkout nothing is printed.
	 *
	 * @return void
	 */
	public function test_prints_nothing_elsewhere(): void {
		WP_Stub::$page_type = 'shop';

		( new Commune_Select() )->add_script();

		$this->assertSame( array(), WP_Stub::$inline );
	}

	/**
	 * A failed encode prints nothing rather than a broken script.
	 *
	 * @return void
	 */
	public function test_prints_nothing_when_the_list_cannot_be_encoded(): void {
		WP_Stub::$json_fails = true;

		( new Commune_Select() )->add_script();

		$this->assertSame( array(), WP_Stub::$inline );
	}

	/**
	 * With the switch off nothing is hooked.
	 *
	 * @return void
	 */
	public function test_hooks_nothing_when_off(): void {
		WP_Stub::$options['rcus_checkout_commune_select'] = 'no';

		( new Commune_Select() )->register();

		$this->assertNotContains( 'wp_enqueue_scripts:add_script', WP_Stub::$callbacks );
	}
}
