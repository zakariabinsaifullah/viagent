<?php
/**
 * Plugin Name:       Viagent
 * Description:       Connect your WordPress site to AI agents like Claude, ChatGPT, Cursor, Codex and more using the Model Context Protocol (MCP) — in a few clicks.
 * Version:           1.0.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Viagent
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       viagent
 *
 * @package Viagent
 */

defined( 'ABSPATH' ) || exit;

define( 'VIAGENT_VERSION', '1.0.1' );
define( 'VIAGENT_FILE', __FILE__ );
define( 'VIAGENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'VIAGENT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bail out early with an admin notice when requirements are not met.
 */
if ( ! function_exists( 'wp_register_ability' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Viagent needs WordPress 6.9 or newer. Please update WordPress to connect your site to AI agents.', 'viagent' );
			echo '</p></div>';
		}
	);
	return;
}

require_once VIAGENT_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Viagent\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Viagent\\Installer', 'deactivate' ) );

Viagent\Plugin::instance();
