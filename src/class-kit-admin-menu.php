<?php
/**
 * Shared top-level admin menu for Dr.Docks / Jellit plugins.
 *
 * Creates a single parent menu item under which all Kit-enabled plugins
 * can register their settings pages as submenus. Prevents menu clutter
 * when multiple plugins are active on the same site.
 *
 * Usage in any plugin:
 *
 *     // On admin_menu hook (priority 5 to ensure parent exists first):
 *     add_action( 'admin_menu', array( Kit_Admin_Menu::class, 'register_parent' ), 5 );
 *
 *     // Then register your submenu (priority 10+):
 *     add_action( 'admin_menu', function () {
 *         Kit_Admin_Menu::add_submenu(
 *             'My Plugin',
 *             'my-plugin',
 *             array( My_Settings::class, 'render_page' )
 *         );
 *     } );
 *
 * Plugins that prefer their own top-level menu (e.g. client-specific
 * plugins like LPP) can simply skip calling these methods.
 *
 * @since   1.0.0
 * @package DrDocks\WP_Plugin_Kit
 */

declare(strict_types=1);

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Kit_Admin_Menu
 *
 * @since 1.0.0
 */
class Kit_Admin_Menu {

	/**
	 * Parent menu slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const PARENT_SLUG = 'drdocks-kit';

	/**
	 * Whether the parent menu has been registered this request.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Register the shared parent menu item.
	 *
	 * Safe to call multiple times — only the first call creates the menu.
	 * Hook this on admin_menu at priority 5.
	 *
	 * @since 1.0.0
	 */
	public static function register_parent(): void {
		if ( self::$registered ) {
			return;
		}

		add_menu_page(
			__( 'Dr.Docks', 'wp-plugin-kit' ),
			__( 'Dr.Docks', 'wp-plugin-kit' ),
			'manage_options',
			self::PARENT_SLUG,
			'__return_empty_string', // Overridden by first submenu.
			'dashicons-shield',
			3
		);

		self::$registered = true;
	}

	/**
	 * Add a submenu page under the shared parent menu.
	 *
	 * Automatically registers the parent menu if not done yet.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $page_title Menu page title.
	 * @param string   $menu_slug  Unique slug for this submenu.
	 * @param callable $callback   Render callback.
	 * @param string   $capability Required capability (default 'manage_options').
	 * @return string|false The hook suffix, or false if the user lacks capability.
	 */
	public static function add_submenu(
		string $page_title,
		string $menu_slug,
		callable $callback,
		string $capability = 'manage_options'
	): string|false {
		if ( ! self::$registered ) {
			self::register_parent();
		}

		return add_submenu_page(
			self::PARENT_SLUG,
			$page_title,
			$page_title,
			$capability,
			$menu_slug,
			$callback
		);
	}
}
