<?php
/**
 * Shared utility functions.
 *
 * Static helper methods available to all Kit-enabled plugins.
 *
 * @since   1.0.0
 * @package DrDocks\WP_Plugin_Kit
 */

declare(strict_types=1);

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Kit_Helpers
 *
 * @since 1.0.0
 */
class Kit_Helpers {

	/**
	 * Check whether the current request is a WordPress REST API request.
	 *
	 * Works early in the bootstrap (before parse_request).
	 *
	 * @since 1.0.0
	 */
	public static function is_rest(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Detection only.
			return true;
		}

		// Pretty permalinks: check request path against REST prefix.
		$req_path = isset( $_SERVER['REQUEST_URI'] )
			? wp_parse_url( sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH )
			: null;

		if ( $req_path ) {
			$prefix  = rest_get_url_prefix();
			$pattern = '/' . trim( $prefix, '/' ) . '/';

			if ( false !== strpos( trailingslashit( $req_path ), $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether the current request is an AJAX request.
	 *
	 * @since 1.0.0
	 */
	public static function is_ajax(): bool {
		return wp_doing_ajax();
	}

	/**
	 * Check whether the current request is asynchronous (AJAX or REST).
	 *
	 * @since 1.0.0
	 */
	public static function is_async(): bool {
		return self::is_ajax() || self::is_rest();
	}
}
