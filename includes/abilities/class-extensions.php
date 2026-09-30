<?php
/**
 * Plugin and theme abilities. Read-only: installing, activating and switching
 * plugins or themes is left to people in the WordPress dashboard.
 *
 * @package Viagent
 */

namespace Viagent\Abilities;

use Viagent\Security\Policy;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin and theme abilities.
 */
class Extensions {

	/**
	 * Registers abilities.
	 */
	public static function register() {
		Abilities::add(
			'list-plugins',
			array(
				'category'    => 'viagent-extensions',
				'label'       => __( 'List plugins', 'viagent' ),
				'description' => __( 'Lists installed plugins with version, active state and whether an update is available.', 'viagent' ),
				'execute'     => array( self::class, 'list_plugins' ),
				'permission'  => 'activate_plugins',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'list-themes',
			array(
				'category'    => 'viagent-extensions',
				'label'       => __( 'List themes', 'viagent' ),
				'description' => __( 'Lists installed themes and which one is active.', 'viagent' ),
				'execute'     => array( self::class, 'list_themes' ),
				'permission'  => 'switch_themes',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
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
}
