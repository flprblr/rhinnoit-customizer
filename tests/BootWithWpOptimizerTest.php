<?php
/**
 * Booting while WP Optimizer, the plugin this one replaces, is still active.
 *
 * Its own process: RCUS_TESTS_WP_OPTIMIZER makes tests/bootstrap.php define
 * the constant WP Optimizer defines when it loads, the rest of the suite must
 * not see it, and Plugin::boot() is a singleton. WeLaunch runs here too, so
 * every service would hook something if the guard let it through.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Install;
use RhinnoIT\Customizer\Plugin;
use RhinnoIT\Customizer\Settings;

#[CoversClass( Plugin::class )]
#[CoversClass( Install::class )]
final class BootWithWpOptimizerTest extends TestCase {

	public static function setUpBeforeClass(): void {
		putenv( 'RCUS_TESTS_WP_OPTIMIZER=1' );
		putenv( 'RCUS_TESTS_WELAUNCH=1' );
	}

	public static function tearDownAfterClass(): void {
		putenv( 'RCUS_TESTS_WP_OPTIMIZER' );
		putenv( 'RCUS_TESTS_WELAUNCH' );
	}

	#[RunInSeparateProcess]
	public function test_only_the_notice_is_hooked_while_wp_optimizer_runs(): void {
		$this->assertTrue( defined( 'RWPO_VERSION' ), 'The child has to boot beside WP Optimizer or this case means nothing.' );

		WP_Stub::reset();

		foreach ( Settings::option_ids() as $option ) {
			WP_Stub::$options[ $option ] = 'yes';
		}

		$options = WP_Stub::$options;

		Plugin::boot();

		$this->assertSame( array( 'admin_notices:render_waiting_notice' ), WP_Stub::$callbacks, 'No service, no menu, no check_version: WP Optimizer runs the same features, and both would change the shop twice.' );
		$this->assertSame( array(), WP_Stub::$fired, 'Nothing is reported either.' );
		$this->assertSame( $options, WP_Stub::$options, 'Nothing is written: the copy is the activation\'s job.' );
		$this->assertSame( 0, ( new ReflectionMethod( Plugin::class, 'render_waiting_notice' ) )->getNumberOfRequiredParameters(), 'admin_notices passes nothing.' );
	}

	#[RunInSeparateProcess]
	public function test_activation_beside_wp_optimizer_copies_its_settings(): void {
		// The hand-over activates this plugin while WP Optimizer still runs:
		// the copy is made then, and the plugin waits.
		WP_Stub::reset();
		WP_Stub::$options = array(
			'rwpo_cart_sale_price'  => 'no',
			'rwpo_express_checkout' => 'yes',
		);

		Install::activate();

		$this->assertSame( 'no', WP_Stub::$options['rcus_cart_sale_price'], 'The guard holds back the services, not the copy.' );
		$this->assertSame( 'yes', WP_Stub::$options['rcus_express_checkout'] );
		$this->assertSame( RCUS_VERSION, WP_Stub::$options[ Install::VERSION_OPTION ], 'Recorded, so the first dashboard after WP Optimizer goes reseeds nothing.' );
	}

	#[RunInSeparateProcess]
	public function test_the_notice_names_the_plugin_to_deactivate(): void {
		WP_Stub::reset();

		$html = $this->render_notice();

		$this->assertStringStartsWith( '<div class="notice notice-warning"><p>', $html );
		// In two pieces, like KeptKeysTest's prefix: whole, a rename pass would
		// turn it into this plugin's own name, and the case would still pass.
		$this->assertStringContainsString( 'until WP ' . 'Optimizer is deactivated', $html, 'It says which plugin it waits for.' );
		$this->assertContains( 'activate_plugins', WP_Stub::$caps_checked );
	}

	#[RunInSeparateProcess]
	public function test_the_notice_stays_hidden_from_who_cannot_act_on_it(): void {
		WP_Stub::reset();
		WP_Stub::$can = false;

		$this->assertSame( '', $this->render_notice(), 'Only who can deactivate WP Optimizer is told to wait for it.' );
		$this->assertSame( array( 'activate_plugins' ), WP_Stub::$caps_checked );
	}

	/**
	 * Captures the notice.
	 *
	 * @return string
	 */
	private function render_notice(): string {
		ob_start();
		Plugin::render_waiting_notice();

		return (string) ob_get_clean();
	}
}
