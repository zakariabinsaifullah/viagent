<?php
/**
 * Main plugin class: loads files and wires hooks.
 *
 * @package Viagent
 */

namespace Viagent;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Loads dependencies and registers hooks.
	 */
	private function __construct() {
		$this->load();

		add_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) );
		add_action( 'wp_initialize_site', array( Installer::class, 'initialize_site' ), 200 );
		add_filter( 'wpmu_drop_tables', array( Installer::class, 'drop_site_tables' ), 10, 2 );
		add_action( 'rest_api_init', array( MCP\Transport::class, 'register_routes' ) );
		add_filter( 'rest_request_before_callbacks', array( MCP\Transport::class, 'json_rpc_parse_error' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( Auth\Authenticator::class, 'add_challenge_header' ), 10, 3 );

		add_action( 'rest_api_init', array( Admin\Admin_API::class, 'register_routes' ) );
		Admin\Admin::init();

		add_action( Log\Activity_Log::PRUNE_HOOK, array( Log\Activity_Log::class, 'prune' ) );
		add_action( Log\Activity_Log::PRUNE_HOOK, array( Auth\OAuth\OAuth::class, 'prune' ) );

		Auth\OAuth\OAuth_API::init();
		Auth\OAuth\Authorize::init();

		add_action( 'wp_abilities_api_categories_init', array( Abilities\Abilities::class, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( Abilities\Abilities::class, 'register' ) );

		// Integrations load after every plugin so WooCommerce, SEO plugins and ACF can be detected.
		add_action( 'plugins_loaded', array( Integrations\Integrations::class, 'init' ), 20 );
		add_action( 'wp_abilities_api_categories_init', array( Integrations\Integrations::class, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( Integrations\Integrations::class, 'register' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once VIAGENT_DIR . 'includes/cli/class-cli.php';
			\WP_CLI::add_command( 'viagent', CLI\CLI::class );
		}
	}

	/**
	 * Requires plugin files.
	 */
	private function load() {
		$files = array(
			'class-installer.php',
			'security/class-policy.php',
			'auth/class-connection.php',
			'auth/class-api-keys.php',
			'auth/oauth/class-oauth.php',
			'auth/oauth/class-oauth-api.php',
			'auth/oauth/class-authorize.php',
			'auth/class-authenticator.php',
			'log/class-activity-log.php',
			'mcp/class-tool-registry.php',
			'mcp/class-meta-tools.php',
			'mcp/class-prompts.php',
			'mcp/class-server.php',
			'mcp/class-transport.php',
			'admin/class-admin.php',
			'admin/class-admin-api.php',
			'admin/class-health.php',
			'abilities/class-abilities.php',
			'abilities/class-site.php',
			'abilities/class-content.php',
			'abilities/class-blocks.php',
			'abilities/class-taxonomies.php',
			'abilities/class-media.php',
			'abilities/class-comments.php',
			'abilities/class-users.php',
			'abilities/class-menus.php',
			'abilities/class-settings.php',
			'abilities/class-extensions.php',
			'abilities/class-meta.php',
			'abilities/class-diagnostics.php',
			'integrations/class-integrations.php',
			'integrations/class-woocommerce.php',
			'integrations/class-seo.php',
			'integrations/class-acf.php',
			'integrations/class-forms.php',
			'integrations/class-network.php',
		);

		foreach ( $files as $file ) {
			require_once VIAGENT_DIR . 'includes/' . $file;
		}
	}
}
