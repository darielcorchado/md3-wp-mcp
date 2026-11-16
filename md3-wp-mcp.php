<?php
/**
 * Plugin Name:       MD3 WP MCP
 * Plugin URI:        https://www.md3digitalsolutions.com
 * Description:       A content-only MCP server for WordPress with its own OAuth 2.1 server, so AI assistants can read, draft and publish posts and pages without ever touching code, users or settings.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Dariel Corchado, Jenrry Cordero
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       md3-wp-mcp
 *
 * @package MD3_WP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'MD3_WP_MCP_VERSION', '0.1.0' );
define( 'MD3_WP_MCP_FILE', __FILE__ );
define( 'MD3_WP_MCP_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Whether the runtime meets the plugin's requirements, and the bundled
 * dependencies (vendor/) are present.
 *
 * Kept free of PHP 8 syntax so the notice can show on older PHP versions.
 *
 * @return bool
 */
function md3_wp_mcp_requirements_met() {
	return version_compare( PHP_VERSION, '8.2', '>=' )
		&& function_exists( 'wp_register_ability' )
		&& file_exists( MD3_WP_MCP_DIR . 'vendor/autoload_packages.php' );
}

if ( md3_wp_mcp_requirements_met() ) {
	require_once MD3_WP_MCP_DIR . 'vendor/autoload_packages.php';
}

/**
 * Loads the plugin, or explains why it can't run.
 */
function md3_wp_mcp_load() {
	if ( ! md3_wp_mcp_requirements_met() ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'MD3 WP MCP requires PHP 8.2 or newer and WordPress 6.9 or newer (the Abilities API), or the plugin\'s vendor folder is missing: install the release build. The plugin is inactive until the requirements are met.', 'md3-wp-mcp' ) . '</p></div>';
			}
		);
		return;
	}

	\MD3\WPMCP\Plugin::boot();
}
add_action( 'plugins_loaded', 'md3_wp_mcp_load' );

/**
 * Loads the plugin's translations from the languages folder.
 */
function md3_wp_mcp_load_textdomain() {
	load_plugin_textdomain( 'md3-wp-mcp', false, dirname( plugin_basename( MD3_WP_MCP_FILE ) ) . '/languages' );
}
add_action( 'init', 'md3_wp_mcp_load_textdomain' );

register_activation_hook(
	__FILE__,
	static function () {
		if ( md3_wp_mcp_requirements_met() ) {
			\MD3\WPMCP\Plugin::activate();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		if ( md3_wp_mcp_requirements_met() ) {
			\MD3\WPMCP\Plugin::deactivate();
		}
	}
);
