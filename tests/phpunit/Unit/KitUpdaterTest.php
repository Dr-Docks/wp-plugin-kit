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

	public function test_plugin_info_stays_sparse_without_rich_sections(): void {
		// A minimal manifest (no `sections`, `banners`, `tags`) must keep the
		// historical two-tab modal so every other plugin is unaffected.
		$info = $this->make( $this->remote() )
			->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'my-plugin' ) );

		$this->assertSame( array( 'description', 'changelog' ), array_keys( $info->sections ) );
		$this->assertObjectNotHasProperty( 'banners', $info );
		$this->assertObjectNotHasProperty( 'tags', $info );
		$this->assertObjectNotHasProperty( 'active_installs', $info );
	}

	public function test_plugin_info_maps_rich_manifest(): void {
		$info = $this->make(
			$this->remote(
				array(
					'active_installs' => 400,
					'tags'            => array( 'acf', 'impreza' ),
					'banners'         => array( 'high' => 'https://example.com/banner.png' ),
					'sections'        => array(
						'description'  => '<div class="sg-pi-notice">Heads up</div><p>Intro</p>',
						'installation' => '<div class="sg-pi-steps"><div class="sg-pi-step"></div></div>',
						'faq'          => '<div class="sg-pi-faq"><div class="sg-pi-q">Q?</div></div>',
						'screenshots'  => '<div class="sg-pi-shotgrid"></div>',
					),
					'changelog'       => "## 1.2.0\n- Item",
				)
			)
		)->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'my-plugin' ) );

		// Tabs appear in the fixed, WordPress-conventional order.
		$this->assertSame(
			array( 'description', 'installation', 'faq', 'screenshots', 'changelog' ),
			array_keys( $info->sections )
		);
		$this->assertStringContainsString( 'sg-pi-notice', $info->sections['description'] );
		$this->assertStringContainsString( '<h3>', $info->sections['changelog'], 'Changelog still comes from CHANGELOG markdown, not the manifest sections.' );
		$this->assertSame( 400, $info->active_installs );
		$this->assertSame( array( 'acf', 'impreza' ), $info->tags );
		$this->assertSame( 'https://example.com/banner.png', $info->banners['high'] );
	}

	public function test_plugin_info_parses_readme(): void {
		$readme = "=== My Plugin ===\n"
			. "Contributors: me\n"
			. "Tags: alpha, beta\n"
			. "Requires at least: 6.1\n"
			. "Tested up to: 7.0.2\n"
			. "Requires PHP: 7.4\n"
			. "Stable tag: 1.2.0\n\n"
			. "Short description here.\n\n"
			. "== Description ==\n\nIntro paragraph with **bold** and `code`.\n\n**Features**\n\n* First feature\n* Second feature\n\n"
			. "== Installation ==\n\n1. Step one.\n2. Step two.\n\n"
			. "== Frequently Asked Questions ==\n\n= A question? =\n\nAn answer.\n\n"
			. "== Screenshots ==\n\n1. First shot.\n2. Second shot.\n\n"
			. "== Changelog ==\n\n= 1.2.0 =\n* Did a thing.\n\n= 1.1.0 =\n* Older thing.\n";

		$info = $this->make( $this->remote( array( 'readme' => $readme ) ) )
			->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'my-plugin' ) );

		// Headers come from the readme.
		$this->assertSame( 'My Plugin', $info->name );
		$this->assertSame( '6.1', $info->requires );
		$this->assertSame( '7.0.2', $info->tested );
		$this->assertSame( '7.4', $info->requires_php );
		$this->assertSame( array( 'alpha', 'beta' ), $info->tags );

		// All five tabs, in order.
		$this->assertSame(
			array( 'description', 'installation', 'faq', 'screenshots', 'changelog' ),
			array_keys( $info->sections )
		);
		// Body markup.
		$this->assertStringContainsString( '<strong>bold</strong>', $info->sections['description'] );
		$this->assertStringContainsString( '<code>code</code>', $info->sections['description'] );
		$this->assertStringContainsString( '<ul><li>First feature</li>', $info->sections['description'] );
		$this->assertStringContainsString( '<ol><li>Step one.</li>', $info->sections['installation'] );
		$this->assertStringContainsString( '<h4>A question?</h4>', $info->sections['faq'] );
		// Screenshots use the native ol/li/img structure with convention URLs.
		$this->assertStringContainsString( '<ol><li><img src="', $info->sections['screenshots'] );
		$this->assertStringContainsString( '/assets/my-plugin/screenshot-1.png', $info->sections['screenshots'] );
		$this->assertSame( 2, substr_count( $info->sections['screenshots'], '<li>' ) );
		// Changelog rendered from readme (two versions).
		$this->assertSame( 2, substr_count( $info->sections['changelog'], '<h3>' ) );
	}

	public function test_plugin_info_skips_empty_manifest_sections(): void {
		// Missing/empty section keys are dropped, never rendered as blank tabs.
		$info = $this->make(
			$this->remote(
				array(
					'sections' => array(
						'description'  => '<p>Only this one</p>',
						'installation' => '',
					),
				)
			)
		)->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'my-plugin' ) );

		$this->assertSame( array( 'description', 'changelog' ), array_keys( $info->sections ) );
	}
}
