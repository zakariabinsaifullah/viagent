<?php
/**
 * The Mcpai admin page: menu, assets, first-run redirect and admin bar status.
 *
 * @package MCPAI
 */

namespace MCPAI\Admin;

use MCPAI\MCP\Transport;
use MCPAI\Security\Policy;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page.
 */
class Admin {

	const SLUG            = 'mcpai';
	const REDIRECT_OPTION = 'mcpai_activation_redirect';

	/**
	 * Hook suffix of the admin page (tools_page_mcpai).
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_init', array( self::class, 'maybe_redirect' ) );
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 100 );
		add_filter( 'plugin_action_links_' . plugin_basename( MCPAI_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * URL of the admin page.
	 *
	 * @param string $tab Optional tab.
	 * @return string
	 */
	public static function url( $tab = '' ) {
		return admin_url( 'tools.php?page=' . self::SLUG . ( $tab ? '#/' . $tab : '' ) );
	}

	/**
	 * Adds the page under Tools.
	 */
	public static function add_menu() {
		self::$hook_suffix = (string) add_management_page(
			__( 'Mcpai', 'mcpai' ),
			__( 'Mcpai', 'mcpai' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Renders the React root.
	 */
	public static function render() {
		echo '<div class="wrap mcpai-wrap"><div id="mcpai-root"><p class="mcpai-loading">' . esc_html__( 'Loading…', 'mcpai' ) . '</p></div></div>';
	}

	/**
	 * Enqueues the admin app.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( self::$hook_suffix !== $hook_suffix ) {
			return;
		}

		$asset_file = MCPAI_DIR . 'build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script( 'mcpai-admin', MCPAI_URL . 'build/index.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'mcpai-admin', MCPAI_URL . 'build/index.css', array( 'wp-components' ), $asset['version'] );
		wp_style_add_data( 'mcpai-admin', 'rtl', 'replace' );
		wp_set_script_translations( 'mcpai-admin', 'mcpai' );

		wp_add_inline_script(
			'mcpai-admin',
			'window.mcpaiSettings = ' . wp_json_encode( self::settings() ) . ';',
			'before'
		);
	}

	/**
	 * Data passed to the admin app.
	 *
	 * @return array
	 */
	private static function settings() {
		$clients = require MCPAI_DIR . 'includes/clients/catalog.php';
		foreach ( $clients as $slug => $client ) {
			$clients[ $slug ]['slug'] = $slug;
			if ( isset( $client['verify'] ) ) {
				$clients[ $slug ]['verify'] = str_replace( '{{name}}', self::server_name(), $client['verify'] );
			}
		}

		return array(
			'endpoint'   => Transport::endpoint_url(),
			'probeUrl'   => rest_url( 'mcpai/v1/probe' ),
			'serverName' => self::server_name(),
			'siteName'   => get_bloginfo( 'name' ),
			'siteUrl'    => home_url( '/' ),
			'isLocal'    => Health::is_local(),
			'oauth'      => \MCPAI\Auth\OAuth\OAuth::enabled(),
			'user'       => wp_get_current_user()->display_name,
			'levels'     => Policy::level_labels(),
			'clients'    => array_values( $clients ),
		);
	}

	/**
	 * Short identifier for the site used as the server name in client configs.
	 *
	 * @return string
	 */
	public static function server_name() {
		$slug = sanitize_title( get_bloginfo( 'name' ) );
		$slug = trim( substr( preg_replace( '/[^a-z0-9-]/', '', $slug ), 0, 30 ), '-' );
		return $slug ? 'wp-' . $slug : 'WordPress';
	}

	/**
	 * Sends the admin to the setup screen right after activating the plugin.
	 */
	public static function maybe_redirect() {
		if ( ! get_option( self::REDIRECT_OPTION ) ) {
			return;
		}
		delete_option( self::REDIRECT_OPTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of core's bulk-activation flag.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Admin bar status: shows when AI access is paused, with a link to resume.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public static function admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) || ! Policy::is_paused() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'mcpai-paused',
				'title' => esc_html__( 'AI access paused', 'mcpai' ),
				'href'  => self::url( 'settings' ),
				'meta'  => array( 'title' => esc_attr__( 'AI apps cannot connect. Click to resume.', 'mcpai' ) ),
			)
		);
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'mcpai' ) . '</a>' );
		return $links;
	}
}
