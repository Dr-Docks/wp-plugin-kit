<?php
/**
 * Plugin updater via drdocks.nl.
 *
 * Checks a static JSON endpoint for new versions and integrates with
 * the WordPress plugin update system. Drop-in replacement for the
 * updater previously bundled in dr-docks core.
 *
 * Usage in any plugin bootstrap:
 *
 *     $updater = new Kit_Updater( array(
 *         'plugin_file' => plugin_basename( __FILE__ ),
 *         'slug'        => 'my-plugin',
 *         'version'     => MY_PLUGIN_VERSION,
 *         'plugin_name' => 'My Plugin',
 *     ) );
 *     $updater->init();
 *
 * @since   1.0.0
 * @package DrDocks\WP_Plugin_Kit
 */

declare(strict_types=1);

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Kit_Updater
 *
 * @since 1.0.0
 */
class Kit_Updater {

	/**
	 * Base URL for the update server JSON endpoints.
	 *
	 * Each plugin checks {base_url}{slug}.json for update metadata.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const UPDATE_BASE_URL = 'https://drdocks.nl/api/updates/';

	/**
	 * Transient cache duration in seconds (6 hours).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private const CACHE_TTL = 21600;

	/**
	 * Plugin basename (e.g. "my-plugin/my-plugin.php").
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Plugin slug (e.g. "my-plugin").
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $slug;

	/**
	 * Current installed version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $version;

	/**
	 * Plugin display name (e.g. "My Plugin").
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $plugin_name;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param array{plugin_file: string, slug: string, version: string, plugin_name: string} $args Configuration.
	 */
	public function __construct( array $args ) {
		$this->plugin_file = $args['plugin_file'];
		$this->slug        = $args['slug'];
		$this->version     = $args['version'];
		$this->plugin_name = $args['plugin_name'];
	}

	/**
	 * Register WordPress hooks for update checking.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {
		add_filter( 'update_plugins_drdocks.nl', array( $this, 'check_update' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
	}

	/**
	 * Check for updates via drdocks.nl JSON endpoint.
	 *
	 * Hooked to update_plugins_drdocks.nl filter.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|false $update      The update data.
	 * @param array<string, mixed>       $plugin_data Plugin header data.
	 * @param string                     $plugin_file Plugin basename.
	 * @param string[]                   $locales     Installed locales.
	 * @return array<string, string>|false Update data or false.
	 */
	public function check_update( array|false $update, array $plugin_data, string $plugin_file, array $locales ): array|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by filter signature.
		if ( $plugin_file !== $this->plugin_file ) {
			return $update;
		}

		$remote = $this->get_remote_data();
		if ( ! $remote ) {
			return $update;
		}

		// Always return the payload so WordPress places the plugin in either
		// `response` (update available) or `no_update` (up to date). The latter
		// keeps the "View details" / changelog modal available at the current
		// version. We must not gate on version here.
		//
		// The `version` key is required: wp_update_plugins() rejects the payload
		// without it, and derives `new_version` from it. WordPress sets `id`,
		// `plugin` and `new_version` itself, so we only supply the essentials.
		return array(
			'slug'    => $this->slug,
			'version' => $remote['version'],
			'url'     => $remote['url'] ?? 'https://drdocks.nl',
			'package' => $remote['download_url'],
		);
	}

	/**
	 * Supply plugin info for the "View Details" modal.
	 *
	 * @since 1.0.0
	 *
	 * @param false|object|array<string, mixed> $result The result object or array.
	 * @param string                            $action The API action.
	 * @param object                            $args   Request arguments.
	 * @return false|object
	 */
	public function plugin_info( false|object|array $result, string $action, object $args ): false|object {
		if ( 'plugin_information' !== $action ) {
			return is_object( $result ) ? $result : false;
		}

		if ( ! isset( $args->slug ) || $this->slug !== $args->slug ) {
			return is_object( $result ) ? $result : false;
		}

		$remote = $this->get_remote_data();
		if ( ! $remote ) {
			return is_object( $result ) ? $result : false;
		}

		$info                = new \stdClass();
		$info->name          = $this->plugin_name;
		$info->slug          = $this->slug;
		$info->version       = $remote['version'];
		$info->author        = '<a href="https://drdocks.nl">Dr.Docks</a>';
		$info->homepage      = 'https://drdocks.nl';
		$info->requires      = $remote['requires'] ?? '6.5';
		$info->tested        = $remote['tested'] ?? '';
		$info->requires_php  = $remote['requires_php'] ?? '8.0';
		$info->download_link = $remote['download_url'];
		$info->trunk         = $remote['download_url'];
		$info->last_updated  = $remote['last_updated'] ?? '';

		$info->sections = array(
			'description' => sprintf(
				'<p>%s</p>',
				esc_html( $this->plugin_name )
			),
			'changelog'   => ! empty( $remote['changelog'] )
				? wp_kses_post( $this->parse_markdown( $remote['changelog'] ) )
				: '<p>See the plugin changelog for release notes.</p>',
		);

		return $info;
	}

	/**
	 * Fetch remote update data from drdocks.nl.
	 *
	 * The JSON endpoint returns: version, download_url, changelog,
	 * requires, requires_php, tested, last_updated, url.
	 *
	 * @since 1.0.0
	 * @return array<string, mixed>|null Remote data or null on failure.
	 */
	private function get_remote_data(): ?array {
		$transient_key = 'kit_update_' . $this->slug;
		$cached        = get_transient( $transient_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::UPDATE_BASE_URL . $this->slug . '.json',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'Kit-Updater/' . $this->version,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}
		if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['download_url'] ) ) {
			return null;
		}

		set_transient( $transient_key, $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Convert basic markdown to HTML for changelog display.
	 *
	 * @since 1.0.0
	 *
	 * @param string $markdown Markdown text.
	 * @return string HTML.
	 */
	private function parse_markdown( string $markdown ): string {
		$html = (string) preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $markdown );
		$html = (string) preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $html );
		$html = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
		$html = (string) preg_replace( '/`(.+?)`/', '<code>$1</code>', $html );
		$html = (string) preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );
		$html = (string) preg_replace( '/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $html );
		$html = (string) preg_replace( '/\n{2,}/', '</p><p>', $html );
		$html = '<p>' . $html . '</p>';

		return $html;
	}
}
