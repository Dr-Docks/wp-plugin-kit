<?php
/**
 * Abstract settings base class.
 *
 * Provides the standard get/update/set_defaults pattern used by all
 * Dr.Docks and Jellit plugins. Concrete classes only need to define
 * OPTION_KEY and DEFAULTS.
 *
 * Usage:
 *
 *     class My_Plugin_Settings extends Kit_Settings {
 *         const OPTION_KEY = 'my_plugin_settings';
 *         const DEFAULTS   = array(
 *             'enabled' => true,
 *             'limit'   => 10,
 *         );
 *     }
 *
 *     // Read:
 *     My_Plugin_Settings::get( 'enabled' );        // true
 *     My_Plugin_Settings::get( 'limit', 5 );       // 10 (stored) or 5 (fallback)
 *     My_Plugin_Settings::get();                    // full array
 *
 *     // Write:
 *     My_Plugin_Settings::update( array( 'limit' => 20 ) );
 *
 *     // Activation:
 *     My_Plugin_Settings::set_defaults();
 *
 * @since   1.0.0
 * @package DrDocks\WP_Plugin_Kit
 */

declare(strict_types=1);

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Kit_Settings
 *
 * @since 1.0.0
 */
abstract class Kit_Settings {

	/**
	 * WordPress option key. Must be overridden by concrete class.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const OPTION_KEY = '';

	/**
	 * Default settings values. Override in concrete class.
	 *
	 * When set_defaults() is called and no option exists yet, these values
	 * are stored. Individual defaults also serve as fallbacks in get()
	 * when no explicit $fallback is provided.
	 *
	 * @since 1.0.0
	 * @var array<string, mixed>
	 */
	const DEFAULTS = array();

	/**
	 * In-memory cache for the settings option.
	 *
	 * Keyed per concrete class to prevent cross-class pollution when
	 * multiple plugins use Kit_Settings in the same request.
	 *
	 * @since 1.0.0
	 * @var array<string, array<string, mixed>|null>
	 */
	private static array $cache = array();

	/**
	 * Get a setting value, or all settings when $key is empty.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key      Setting key, or empty string to get all settings.
	 * @param mixed  $fallback Value returned when key is not set. When null,
	 *                         falls back to the value in DEFAULTS (if defined).
	 * @return mixed
	 */
	public static function get( string $key = '', mixed $fallback = null ): mixed {
		$class = static::class;

		if ( ! isset( self::$cache[ $class ] ) ) {
			self::$cache[ $class ] = (array) get_option( static::OPTION_KEY, array() );
		}

		if ( '' === $key ) {
			return self::$cache[ $class ];
		}

		if ( array_key_exists( $key, self::$cache[ $class ] ) ) {
			return self::$cache[ $class ][ $key ];
		}

		// Fall back to DEFAULTS when no explicit fallback given.
		if ( null === $fallback && array_key_exists( $key, static::DEFAULTS ) ) {
			return static::DEFAULTS[ $key ];
		}

		return $fallback;
	}

	/**
	 * Merge $data into the stored settings.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data Settings to merge into the stored option.
	 */
	public static function update( array $data ): void {
		$settings = (array) get_option( static::OPTION_KEY, array() );
		$settings = array_merge( $settings, $data );
		update_option( static::OPTION_KEY, $settings, false );
		self::$cache[ static::class ] = null; // Force fresh read on next get().
	}

	/**
	 * Store default settings on first activation (no-op when already set).
	 *
	 * Merges DEFAULTS so that new keys added in later versions are
	 * automatically available without overwriting existing values.
	 *
	 * @since 1.0.0
	 */
	public static function set_defaults(): void {
		$existing = get_option( static::OPTION_KEY );

		if ( false === $existing ) {
			update_option( static::OPTION_KEY, static::DEFAULTS, false );
			return;
		}

		// Merge new defaults into existing — existing values take precedence.
		$merged = array_merge( static::DEFAULTS, (array) $existing );
		if ( $merged !== (array) $existing ) {
			update_option( static::OPTION_KEY, $merged, false );
		}
	}

	/**
	 * Delete the stored option entirely.
	 *
	 * Intended for use in uninstall.php.
	 *
	 * @since 1.0.0
	 */
	public static function delete(): void {
		delete_option( static::OPTION_KEY );
		self::$cache[ static::class ] = null;
	}

	/**
	 * Verify AJAX nonce and admin capability in one call.
	 *
	 * Sends a JSON error response and terminates if either check fails.
	 * Use at the top of every admin AJAX handler.
	 *
	 * @since 1.0.0
	 *
	 * @param string $nonce_action Expected nonce action name.
	 * @param string $nonce_field  POST field containing the nonce (default 'nonce').
	 */
	public static function verify_ajax_admin( string $nonce_action, string $nonce_field = 'nonce' ): void {
		if ( ! check_ajax_referer( $nonce_action, $nonce_field, false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid or expired security token.', 'wp-plugin-kit' ) ),
				403
			);
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'wp-plugin-kit' ) ),
				403
			);
		}
	}
}
