<?php
/**
 * Site abilities: site overview and cross-type search.
 *
 * @package MCPAI
 */

namespace MCPAI\Abilities;

use MCPAI\Auth\Authenticator;

defined( 'ABSPATH' ) || exit;

/**
 * Site abilities.
 */
class Site {

	/**
	 * Registers abilities.
	 */
	public static function register() {
		wp_register_ability(
			'mcpai/get-site-info',
			array(
				'label'               => __( 'Get site info', 'mcpai' ),
				'description'         => __( 'Overview of the WordPress site: name, URLs, language, timezone, active theme, available post types and who you are connected as. Call this first.', 'mcpai' ),
				'category'            => 'mcpai-site',
				'input_schema'        => array(),
				'execute_callback'    => array( self::class, 'get_site_info' ),
				'permission_callback' => static function () {
					return current_user_can( 'read' );
				},
				'meta'                => Abilities::read_meta(),
			)
		);

		wp_register_ability(
			'mcpai/search-content',
			array(
				'label'               => __( 'Search content', 'mcpai' ),
				'description'         => __( 'Full-text search across posts, pages and other public content types. Returns matching items with ID, type, title and link.', 'mcpai' ),
				'category'            => 'mcpai-site',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'query'    => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'Words to search for.', 'mcpai' ),
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
							'default' => 20,
						),
					),
					'required'             => array( 'query' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( self::class, 'search_content' ),
				'permission_callback' => static function () {
					return current_user_can( 'read' );
				},
				'meta'                => Abilities::read_meta(),
			)
		);
	}

	/**
	 * Site overview.
	 *
	 * @return array
	 */
	public static function get_site_info() {
		$theme      = wp_get_theme();
		$user       = wp_get_current_user();
		$connection = Authenticator::current();

		$post_types = array();
		foreach ( Content::post_types() as $type ) {
			$counts       = wp_count_posts( $type->name );
			$post_types[] = array(
				'name'      => $type->name,
				'label'     => $type->label,
				'published' => (int) ( $counts->publish ?? 0 ),
				'drafts'    => (int) ( $counts->draft ?? 0 ),
			);
		}

		return array(
			'name'         => get_bloginfo( 'name' ),
			'tagline'      => get_bloginfo( 'description' ),
			'url'          => home_url( '/' ),
			'admin_url'    => admin_url(),
			'language'     => get_bloginfo( 'language' ),
			'timezone'     => wp_timezone_string(),
			'wordpress'    => get_bloginfo( 'version' ),
			'theme'        => array(
				'name'        => $theme->get( 'Name' ),
				'block_theme' => $theme->is_block_theme(),
			),
			'post_types'   => $post_types,
			'connected_as' => array(
				'user'         => $user->user_login,
				'roles'        => array_values( $user->roles ),
				'access_level' => $connection ? $connection->access_level : null,
				'draft_only'   => $connection ? $connection->draft_only : null,
			),
		);
	}

	/**
	 * Cross-type search.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function search_content( $input ) {
		$query = new \WP_Query(
			array(
				's'                   => $input['query'],
				'post_type'           => wp_list_pluck( Content::post_types(), 'name' ),
				'post_status'         => Content::readable_statuses(),
				'posts_per_page'      => (int) ( $input['per_page'] ?? 20 ),
				'ignore_sticky_posts' => true,
				'perm'                => 'readable',
			)
		);

		return array(
			'total' => (int) $query->found_posts,
			'items' => array_map( array( Content::class, 'summary' ), $query->posts ),
		);
	}
}
