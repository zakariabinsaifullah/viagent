<?php
/**
 * Registers Mcpai ability categories and abilities, plus shared helpers.
 *
 * @package MCPAI
 */

namespace MCPAI\Abilities;

use MCPAI\Security\Policy;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities loader and shared helpers.
 */
class Abilities {

	/**
	 * Registers ability categories.
	 */
	public static function register_categories() {
		$categories = array(
			'mcpai-site'        => array( __( 'Site', 'mcpai' ), __( 'Site information and search.', 'mcpai' ) ),
			'mcpai-content'     => array( __( 'Content', 'mcpai' ), __( 'Posts, pages and custom post types.', 'mcpai' ) ),
			'mcpai-blocks'      => array( __( 'Blocks & patterns', 'mcpai' ), __( 'Block types and patterns for building layouts.', 'mcpai' ) ),
			'mcpai-taxonomies'  => array( __( 'Categories & tags', 'mcpai' ), __( 'Categories, tags and custom taxonomies.', 'mcpai' ) ),
			'mcpai-media'       => array( __( 'Media', 'mcpai' ), __( 'Images, documents and other uploads.', 'mcpai' ) ),
			'mcpai-comments'    => array( __( 'Comments', 'mcpai' ), __( 'Reading, replying to and moderating comments.', 'mcpai' ) ),
			'mcpai-users'       => array( __( 'Users', 'mcpai' ), __( 'User accounts and profiles.', 'mcpai' ) ),
			'mcpai-menus'       => array( __( 'Menus', 'mcpai' ), __( 'Navigation menus.', 'mcpai' ) ),
			'mcpai-settings'    => array( __( 'Settings', 'mcpai' ), __( 'General, reading and discussion settings.', 'mcpai' ) ),
			'mcpai-extensions'  => array( __( 'Plugins & themes', 'mcpai' ), __( 'Installing and activating plugins and themes.', 'mcpai' ) ),
			'mcpai-diagnostics' => array( __( 'Diagnostics', 'mcpai' ), __( 'Site health and error logs.', 'mcpai' ) ),
		);

		foreach ( $categories as $slug => $data ) {
			wp_register_ability_category(
				$slug,
				array(
					'label'       => $data[0],
					'description' => $data[1],
				)
			);
		}
	}

	/**
	 * Registers all abilities.
	 */
	public static function register() {
		Site::register();
		Content::register();
		Blocks::register();
		Taxonomies::register();
		Media::register();
		Comments::register();
		Users::register();
		Menus::register();
		Settings::register();
		Extensions::register();
		Meta::register();
		Diagnostics::register();
	}

	/**
	 * Compact ability registration.
	 *
	 * @param string $name Ability name without the "mcpai/" prefix.
	 * @param array  $args {
	 *     Ability settings.
	 *
	 *     @type string          $category    Category slug.
	 *     @type string          $label       Label.
	 *     @type string          $description Description shown to the AI.
	 *     @type array           $input       Input properties (JSON Schema). Omit for no input.
	 *     @type string[]        $required    Required input properties.
	 *     @type callable        $execute     Execute callback.
	 *     @type string|callable $permission  Capability name or permission callback.
	 *     @type array           $meta        Ability meta, see read_meta()/write_meta().
	 * }
	 */
	public static function add( $name, array $args ) {
		$permission = $args['permission'];
		if ( is_string( $permission ) ) {
			$capability = $permission;
			$permission = static function () use ( $capability ) {
				return current_user_can( $capability );
			};
			// Lets the registry hide the tool from users who lack the capability.
			$args['meta']['mcpai']['capability'] = $capability;
		}

		$schema = array();
		if ( isset( $args['input'] ) ) {
			$schema = array(
				'type'                 => 'object',
				'properties'           => $args['input'],
				'additionalProperties' => false,
			);
			if ( ! empty( $args['required'] ) ) {
				$schema['required'] = $args['required'];
			}
		}

		wp_register_ability(
			'mcpai/' . $name,
			array(
				'label'               => $args['label'],
				'description'         => $args['description'],
				'category'            => $args['category'],
				'input_schema'        => $schema,
				'execute_callback'    => $args['execute'],
				'permission_callback' => $permission,
				'meta'                => $args['meta'],
			)
		);
	}

	/**
	 * Meta for a read-only ability.
	 *
	 * @param string $level Required access level.
	 * @return array
	 */
	public static function read_meta( $level = Policy::READ ) {
		return array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'mcpai'       => array( 'level' => $level ),
		);
	}

	/**
	 * Meta for a write ability.
	 *
	 * @param string $level       Required access level.
	 * @param array  $flags {
	 *     Optional behaviour flags.
	 *
	 *     @type bool $destructive     Can remove or overwrite data. Default false.
	 *     @type bool $idempotent      Repeating the call has no further effect. Default false.
	 *     @type bool $draft_safe      Usable in draft-only mode (the tool guards itself). Default false.
	 *     @type bool $default_enabled On unless the admin switches it off. Default true.
	 * }
	 * @return array
	 */
	public static function write_meta( $level = Policy::CONTENT, array $flags = array() ) {
		return array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => ! empty( $flags['destructive'] ),
				'idempotent'  => ! empty( $flags['idempotent'] ),
			),
			'mcpai'       => array(
				'level'           => $level,
				'draft_safe'      => ! empty( $flags['draft_safe'] ),
				'default_enabled' => $flags['default_enabled'] ?? true,
			),
		);
	}

	/**
	 * Standard pagination input properties.
	 *
	 * @param int $default_per_page Default page size.
	 * @return array
	 */
	public static function paging( $default_per_page = 20 ) {
		return array(
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
				'default' => $default_per_page,
			),
			'page'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
		);
	}
}
