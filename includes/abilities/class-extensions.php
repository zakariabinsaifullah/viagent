<?php
/**
 * Plugin and theme abilities. Installs come from WordPress.org only.
 *
 * @package MCPAI
 */

namespace MCPAI\Abilities;

use MCPAI\Log\Activity_Log;
use MCPAI\Security\Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin and theme abilities.
 */
class Extensions {

	/**
	 * Registers abilities.
	 */
	public static function register() {
		$plugin = array(
			'type'        => 'string',
			'description' => __( 'Plugin slug (e.g. "akismet") or file (e.g. "akismet/akismet.php").', 'mcpai' ),
		);

		Abilities::add(
			'list-plugins',
			array(
				'category'    => 'mcpai-extensions',
				'label'       => __( 'List plugins', 'mcpai' ),
				'description' => __( 'Lists installed plugins with version, active state and whether an update is available.', 'mcpai' ),
				'execute'     => array( self::class, 'list_plugins' ),
				'permission'  => 'activate_plugins',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'activate-plugin',
			array(
				'category'    => 'mcpai-extensions',
				'label'       => __( 'Activate plugin', 'mcpai' ),
				'description' => __( 'Activates an installed plugin.', 'mcpai' ),
				'input'       => array( 'plugin' => $plugin ),
				'required'    => array( 'plugin' ),
				'execute'     => array( self::class, 'activate_plugin' ),
				'permission'  => 'activate_plugins',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);

		Abilities::add(
			'deactivate-plugin',
			array(
				'category'    => 'mcpai-extensions',
				'label'       => __( 'Deactivate plugin', 'mcpai' ),
				'description' => __( 'Deactivates a plugin. Mcpai itself cannot be deactivated this way.', 'mcpai' ),
				'input'       => array( 'plugin' => $plugin ),
				'required'    => array( 'plugin' ),
				'execute'     => array( self::class, 'deactivate_plugin' ),
				'permission'  => 'activate_plugins',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);

		Abilities::add(
			'install-plugin',
			array(
				'category'    => 'mcpai-extensions',
				'label'       => __( 'Install plugin', 'mcpai' ),
				'description' => __( 'Installs a plugin from the WordPress.org directory by slug, optionally activating it.', 'mcpai' ),
				'input'       => array(
					'slug'     => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9-]+$',
					),
					'activate' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'    => array( 'slug' ),
				'execute'     => array( self::class, 'install_plugin' ),
				'permission'  => 'install_plugins',
				'meta'        => Abilities::write_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'list-themes',
			array(
				'category'    => 'mcpai-extensions',
				'label'       => __( 'List themes', 'mcpai' ),
				'description' => __( 'Lists installed themes and which one is active.', 'mcpai' ),
				'execute'     => array( self::class, 'list_themes' ),
				'permission'  => 'switch_themes',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'activate-theme',
			array(
				'category'    => 'mcpai-extensions',
				'label'       => __( 'Activate theme', 'mcpai' ),
				'description' => __( 'Switches the active theme. This changes how the whole site looks.', 'mcpai' ),
				'input'       => array(
					'stylesheet' => array(
						'type'        => 'string',
						'description' => __( 'Theme folder name from list_themes.', 'mcpai' ),
					),
				),
				'required'    => array( 'stylesheet' ),
				'execute'     => array( self::class, 'activate_theme' ),
				'permission'  => 'switch_themes',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);
	}

	/**
	 * Loads plugin admin helpers.
	 */
	private static function load_plugin_includes() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	/**
	 * Resolves a slug or file to an installed plugin file.
	 *
	 * @param string $plugin Slug or file.
	 * @return string|WP_Error
	 */
	private static function resolve( $plugin ) {
		self::load_plugin_includes();
		$plugins = get_plugins();

		if ( isset( $plugins[ $plugin ] ) ) {
			return $plugin;
		}
		foreach ( array_keys( $plugins ) as $file ) {
			if ( dirname( $file ) === $plugin || basename( $file, '.php' ) === $plugin ) {
				return $file;
			}
		}
		return new WP_Error( 'mcpai_plugin_not_found', __( 'That plugin is not installed. Use list_plugins to see installed plugins, or install_plugin to add one.', 'mcpai' ) );
	}

	/**
	 * Lists plugins.
	 *
	 * @return array
	 */
	public static function list_plugins() {
		self::load_plugin_includes();
		$updates = get_site_transient( 'update_plugins' );
		$result  = array();

		foreach ( get_plugins() as $file => $data ) {
			$result[] = array(
				'file'             => $file,
				'name'             => $data['Name'],
				'version'          => $data['Version'],
				'author'           => wp_strip_all_tags( $data['Author'] ),
				'description'      => wp_trim_words( wp_strip_all_tags( $data['Description'] ), 25 ),
				'active'           => is_plugin_active( $file ),
				'update_available' => isset( $updates->response[ $file ] ) ? $updates->response[ $file ]->new_version : null,
			);
		}
		return $result;
	}

	/**
	 * Activates a plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function activate_plugin( $input ) {
		$file = self::resolve( $input['plugin'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		Activity_Log::set_object( 'plugin', 0 );
		$result = activate_plugin( $file );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'plugin' => $file,
			'active' => true,
		);
	}

	/**
	 * Deactivates a plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function deactivate_plugin( $input ) {
		$file = self::resolve( $input['plugin'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( plugin_basename( MCPAI_FILE ) === $file ) {
			return new WP_Error( 'mcpai_self_deactivate', __( 'Mcpai cannot deactivate itself — that would disconnect you. The site owner can do it from the Plugins screen.', 'mcpai' ) );
		}

		Activity_Log::set_object( 'plugin', 0 );
		deactivate_plugins( $file );
		return array(
			'plugin' => $file,
			'active' => is_plugin_active( $file ),
		);
	}

	/**
	 * Installs a plugin from WordPress.org.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function install_plugin( $input ) {
		self::load_plugin_includes();
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$existing = self::resolve( $input['slug'] );
		if ( ! is_wp_error( $existing ) ) {
			return new WP_Error( 'mcpai_already_installed', sprintf( /* translators: %s: plugin file */ __( 'This plugin is already installed (%s). Use activate_plugin.', 'mcpai' ), $existing ) );
		}

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $input['slug'],
				'fields' => array( 'sections' => false ),
			)
		);
		if ( is_wp_error( $api ) ) {
			return new WP_Error( 'mcpai_plugin_not_found', __( 'No plugin with that slug was found on WordPress.org.', 'mcpai' ) );
		}

		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'mcpai_filesystem', __( 'WordPress cannot write files on this server without FTP credentials, so plugins must be installed from the Plugins screen.', 'mcpai' ) );
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_wp_error( $skin->result ) ) {
			return $skin->result;
		}
		if ( $skin->get_errors()->has_errors() ) {
			return $skin->get_errors();
		}
		if ( ! $result ) {
			return new WP_Error( 'mcpai_install_failed', __( 'The plugin could not be installed.', 'mcpai' ) );
		}

		$file = $upgrader->plugin_info();
		Activity_Log::set_object( 'plugin', 0 );

		$response = array(
			'plugin'  => $file,
			'name'    => $api->name,
			'version' => $api->version,
			'active'  => false,
		);

		if ( ! empty( $input['activate'] ) && $file ) {
			$activated = activate_plugin( $file );
			if ( is_wp_error( $activated ) ) {
				$response['warning'] = $activated->get_error_message();
			} else {
				$response['active'] = true;
			}
		}
		return $response;
	}

	/**
	 * Lists themes.
	 *
	 * @return array
	 */
	public static function list_themes() {
		$active = get_stylesheet();
		$result = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$result[] = array(
				'stylesheet'  => $stylesheet,
				'name'        => $theme->get( 'Name' ),
				'version'     => $theme->get( 'Version' ),
				'block_theme' => $theme->is_block_theme(),
				'parent'      => $theme->parent() ? $theme->get_template() : null,
				'active'      => $stylesheet === $active,
			);
		}
		return $result;
	}

	/**
	 * Activates a theme.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function activate_theme( $input ) {
		$theme = wp_get_theme( $input['stylesheet'] );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'mcpai_theme_not_found', __( 'That theme is not installed. Use list_themes to see installed themes.', 'mcpai' ) );
		}
		if ( ! $theme->is_allowed() ) {
			return new WP_Error( 'mcpai_theme_not_allowed', __( 'That theme is not allowed on this site.', 'mcpai' ) );
		}
		if ( $theme->errors() ) {
			return $theme->errors();
		}

		Activity_Log::set_object( 'theme', 0 );
		switch_theme( $theme->get_stylesheet() );

		return array(
			'active' => get_stylesheet(),
			'name'   => wp_get_theme()->get( 'Name' ),
		);
	}
}
