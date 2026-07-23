<?php
/**
 * Unit tests for Kit_Updater.
 *
 * Guards the update-payload contract that WordPress' wp_update_plugins()
 * expects (regressions here previously hid the "View details" modal and
 * broke update detection), plus the manifest-driven author and the
 * markdown changelog rendering.
 *
 * get_remote_data() is overridden with canned data, so no HTTP or
 * transients are exercised.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Testable subclass: returns canned remote data instead of hitting drdocks.nl.
 */
final class TestableKitUpdater extends Kit_Updater {

	/** @var array<string, mixed>|null */
	public $fake = null;

	protected function get_remote_data(): ?array {
		return $this->fake;
	}
}

final class KitUpdaterTest extends TestCase {

	/**
	 * @param array<string, mixed>|null $remote
	 */
	private function make( ?array $remote ): TestableKitUpdater {
		$updater = new TestableKitUpdater(
			array(
				'plugin_file' => 'my-plugin/my-plugin.php',
				'slug'        => 'my-plugin',
				'version'     => '1.2.0',
				'plugin_name' => 'My Plugin',
			)
		);
		$updater->fake = $remote;
		return $updater;
	}

	/**
	 * @param array<string, mixed> $override
	 * @return array<string, mixed>
	 */
	private function remote( array $override = array() ): array {
		return array_merge(
			array(
				'version'      => '1.2.0',
				'download_url' => 'https://drdocks.nl/api/updates/downloads/my-plugin-1.2.0.zip',
				'url'          => 'https://drdocks.nl',
			),
			$override
		);
	}

	public function test_payload_has_required_version_field(): void {
		$result = $this->make( $this->remote() )
			->check_update( false, array(), 'my-plugin/my-plugin.php', array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'version', $result, "wp_update_plugins() rejects payloads without a 'version' key" );
		$this->assertSame( '1.2.0', $result['version'] );
		$this->assertArrayHasKey( 'slug', $result );
		$this->assertArrayHasKey( 'package', $result );
	}

	public function test_returns_payload_when_up_to_date(): void {
		// Up to date must NOT return false, otherwise WordPress never lists the
		// plugin under no_update and the "View details" link disappears.
		$result = $this->make( $this->remote( array( 'version' => '1.2.0' ) ) )
			->check_update( false, array(), 'my-plugin/my-plugin.php', array() );

		$this->assertIsArray( $result );
	}

	public function test_returns_payload_when_update_available(): void {
		$result = $this->make( $this->remote( array( 'version' => '2.0.0' ) ) )
			->check_update( false, array(), 'my-plugin/my-plugin.php', array() );

		$this->assertIsArray( $result );
		$this->assertSame( '2.0.0', $result['version'] );
	}

	public function test_ignores_other_plugins(): void {
		$result = $this->make( $this->remote() )
			->check_update( false, array(), 'some-other/some-other.php', array() );

		$this->assertFalse( $result );
	}

	public function test_passes_through_without_remote_data(): void {
		$result = $this->make( null )
			->check_update( false, array(), 'my-plugin/my-plugin.php', array() );

		$this->assertFalse( $result );
	}

	public function test_plugin_info_uses_manifest_author(): void {
		$info = $this->make(
			$this->remote(
				array(
					'author'    => '<a href="https://samengebrand.nl">samengebrand.nl</a>',
					'homepage'  => 'https://samengebrand.nl',
					'changelog' => '',
				)
			)
		)->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'my-plugin' ) );

		$this->assertIsObject( $info );
		$this->assertStringContainsString( 'samengebrand.nl', $info->author );
		$this->assertSame( 'https://samengebrand.nl', $info->homepage );
	}

	public function test_plugin_info_defaults_to_drdocks_author(): void {
		$info = $this->make( $this->remote( array( 'changelog' => '' ) ) )
			->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'my-plugin' ) );

		$this->assertStringContainsString( 'Dr.Docks', $info->author );
	}

	public function test_changelog_markdown_renders_headings_and_lists(): void {
		$info = $this->make(
			$this->remote(
				array( 'changelog' => "## 1.2.0 - 2026-07-23\n- First item\n- Second item" )
			)
		)->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'my-plugin' ) );

		$this->assertStringContainsString( '<h3>', $info->sections['changelog'] );
		$this->assertStringContainsString( '<li>', $info->sections['changelog'] );
		$this->assertStringNotContainsString( '## 1.2.0', $info->sections['changelog'] );
	}
}
