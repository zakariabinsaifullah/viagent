<?php
/**
 * Plugin Name:       Mcpai
 * Description:       Connect your WordPress site to AI agents like Claude, ChatGPT, Cursor, Codex and more using the Model Context Protocol (MCP) — in a few clicks.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Mcpai
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mcpai
 *
 * @package MCPAI
 */

defined( 'ABSPATH' ) || exit;

define( 'MCPAI_VERSION', '1.0.0' );
define( 'MCPAI_FILE', __FILE__ );
define( 'MCPAI_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCPAI_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bail out early with an admin notice when requirements are not met.
 */
if ( ! function_exists( 'wp_register_ability' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Mcpai needs WordPress 6.9 or newer. Please update WordPress to connect your site to AI agents.', 'mcpai' );
			echo '</p></div>';
		}
	);
	return;
}

require_once MCPAI_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'MCPAI\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MCPAI\\Installer', 'deactivate' ) );

MCPAI\Plugin::instance();
