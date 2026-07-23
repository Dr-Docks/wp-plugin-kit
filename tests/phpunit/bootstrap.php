<?php
/**
 * PHPUnit bootstrap for the Kit unit tests.
 *
 * No WordPress test suite: composer autoload + minimal stubs for the few
 * WP functions Kit_Updater calls outside get_remote_data (which the tests
 * override with canned data, so no HTTP/transients are needed).
 */

declare(strict_types=1);

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

require_once __DIR__ . '/../../vendor/autoload.php';

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) { // phpcs:ignore
		return true;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return $url;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return is_string( $text ) ? trim( $text ) : $text;
	}
}
