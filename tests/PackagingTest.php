<?php
/**
 * Guards what WordPress and the zip read out of the main plugin file: the header's identity, and the hooks registered there.
 *
 * @package RhinnoIT\Customizer
 */

use PHPUnit\Framework\TestCase;
use RhinnoIT\Customizer\Install;

final class PackagingTest extends TestCase {

	/**
	 * Path of the main plugin file, found the same way bin/build-zip.sh finds it.
	 *
	 * @return string
	 */
	private function main_file_path(): string {
		foreach ( glob( dirname( __DIR__ ) . '/*.php' ) ?: array() as $file ) {
			if ( str_contains( (string) file_get_contents( $file ), ' * Plugin Name:' ) ) {
				return $file;
			}
		}

		$this->fail( 'No plugin header found in the repository root.' );
	}

	/**
	 * Contents of the main plugin file.
	 *
	 * @return string
	 */
	private function main_file(): string {
		return (string) file_get_contents( $this->main_file_path() );
	}

	public function test_the_header_version_matches_the_runtime_constant(): void {
		$source = $this->main_file();

		preg_match( '/^ \* Version: *(\S+)/m', $source, $header );
		preg_match( "/define\( '[A-Z0-9_]+_VERSION', '([^']+)' \)/", $source, $constant );

		$this->assertNotEmpty( $header[1] ?? '', 'The plugin header has no Version.' );
		$this->assertNotEmpty( $constant[1] ?? '', 'No *_VERSION constant is defined.' );
		$this->assertSame(
			$header[1],
			$constant[1],
			'The header version and the runtime constant drifted. WordPress and the zip read the header; '
			. 'Install::check_version() reads the constant.'
		);
	}

	public function test_the_plugin_name_carries_the_group_prefix(): void {
		preg_match( '/^ \* Plugin Name: *(.+?) *$/m', $this->main_file(), $name );

		$this->assertNotEmpty( $name[1] ?? '', 'The plugin header has no Plugin Name.' );
		$this->assertStringStartsWith(
			'RHINNO IT - ',
			$name[1],
			'Every plugin of the group is listed as "RHINNO IT - <what it does>", in English.'
		);
	}

	public function test_the_menu_entry_does_not_repeat_the_group_name(): void {
		$source = $this->main_file();

		preg_match( "/define\( '[A-Z0-9_]+_MENU_PARENT_TITLE', '([^']+)' \)/", $source, $node );
		preg_match( "/define\( '[A-Z0-9_]+_MENU_TITLE', '([^']+)' \)/", $source, $entry );

		// Without this the assertion below reads an empty string, which starts
		// with nothing and would pass on any declaration the pattern stops
		// matching.
		$this->assertNotEmpty( $entry[1] ?? '', 'No *_MENU_TITLE constant is declared.' );
		$this->assertSame( 'RHINNO IT', $node[1] ?? '', 'The shared node is named the same in every plugin of the group.' );
		$this->assertStringStartsNotWith(
			'RHINNO IT',
			$entry[1],
			'The entry hangs from a node that already says RHINNO IT.'
		);
	}

	/**
	 * The main file hands the plugin's activation and deactivation to Install, whose methods run on what WordPress passes.
	 *
	 * No case loads the main file - the bootstrap defines its constants - so
	 * its source is read, as the header is. Without the deactivation hook,
	 * deactivating the plugin would leave the rates its shipping rules took
	 * away in every shopper's session.
	 *
	 * @return void
	 */
	public function test_activation_and_deactivation_reach_install(): void {
		$source = $this->main_file();

		foreach ( array( 'activation' => 'activate', 'deactivation' => 'deactivate' ) as $hook => $method ) {
			$this->assertStringContainsString(
				sprintf( "register_%s_hook( RCUS_FILE, array( Install::class, '%s' ) );", $hook, $method ),
				$source,
				sprintf( 'The main file no longer hands the %s to Install::%s().', $hook, $method )
			);

			$reflection = new ReflectionMethod( Install::class, $method );

			$this->assertTrue( $reflection->isPublic() && $reflection->isStatic(), sprintf( 'WordPress calls Install::%s() by its class.', $method ) );
			$this->assertSame( 0, $reflection->getNumberOfRequiredParameters(), sprintf( 'WordPress passes Install::%s() only whether it runs network wide.', $method ) );
		}
	}

	public function test_the_package_manifest_declares_no_version_to_drift(): void {
		$manifest = dirname( __DIR__ ) . '/package.json';

		if ( ! is_readable( $manifest ) ) {
			$this->markTestSkipped( 'This plugin ships no build, so there is no package.json to go stale.' );
		}

		$package = json_decode( (string) file_get_contents( $manifest ), true );

		$this->assertIsArray( $package );
		$this->assertArrayNotHasKey(
			'version',
			$package,
			'package.json must not carry a version: nothing reads it and it silently goes stale.'
		);
	}

	public function test_nothing_the_plugin_needs_at_runtime_is_excluded_from_the_zip(): void {
		$shipped = array( 'includes', 'languages', 'uninstall.php', basename( $this->main_file_path() ) );

		// build/ is gitignored and absent from the PHP job of the CI, so it is
		// only checked against the exclusions: the zip is the one place it has
		// to be, since there is no src/ in it to compile from.
		$packaged = is_readable( dirname( __DIR__ ) . '/package.json' )
			? array_merge( $shipped, array( 'build' ) )
			: $shipped;

		// The two bin/build-zip.sh adds by rule, plus what .distignore declares.
		$excluded = array( '.*', 'dist' );

		foreach ( file( dirname( __DIR__ ) . '/.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $line ) {
			$line = trim( $line );

			if ( '' !== $line && ! str_starts_with( $line, '#' ) ) {
				$excluded[] = ltrim( $line, '/' );
			}
		}

		foreach ( $shipped as $path ) {
			$this->assertFileExists( dirname( __DIR__ ) . '/' . $path );
		}

		foreach ( $packaged as $path ) {
			$this->assertNotContains(
				$path,
				$excluded,
				sprintf( '.distignore drops %s, so the zip installs as a fatal require.', $path )
			);
		}
	}
}
