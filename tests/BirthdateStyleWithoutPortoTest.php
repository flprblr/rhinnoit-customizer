<?php
/**
 * The birth date field on a theme that is not Porto.
 *
 * Its own process: tests/bootstrap.php loads the Porto stubs unless
 * RCUS_TESTS_PORTO is 'none', and the child inherits the environment.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Frontend\Birthdate_Style;

/**
 * Covers RhinnoIT\Customizer\Frontend\Birthdate_Style without Porto.
 */
#[CoversClass( Birthdate_Style::class )]
final class BirthdateStyleWithoutPortoTest extends TestCase {

	/**
	 * Boots the child without Porto.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		putenv( 'RCUS_TESTS_PORTO=none' );
	}

	/**
	 * Leaves the environment as the rest of the suite expects it.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		putenv( 'RCUS_TESTS_PORTO' );
	}

	/**
	 * Off Porto the border would have no color, so nothing is hooked, switched on or not.
	 *
	 * The template is an option, known when the services register, so the
	 * answer is the same one the screen gives when it hides Porto's card.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_it_hooks_nothing_off_porto(): void {
		$this->assertNotSame( 'porto', get_template(), 'The child has to boot without Porto or this case means nothing.' );

		WP_Stub::reset();
		WP_Stub::$options['rcus_birthdate_style'] = 'yes';

		( new Birthdate_Style() )->register();

		$this->assertSame( array(), WP_Stub::$hooks );
	}
}
