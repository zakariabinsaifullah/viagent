<?php
/**
 * Block abilities: discover block types and patterns to build layouts with.
 *
 * @package MCPAI
 */

namespace MCPAI\Abilities;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Block abilities.
 */
class Blocks {

	/**
	 * Registers abilities.
	 */
	public static function register() {
		Abilities::add(
			'list-block-types',
			array(
				'category'    => 'mcpai-blocks',
				'label'       => __( 'List block types', 'mcpai' ),
				'description' => __( 'Lists blocks available on this site (core and from plugins) that can be used in content markup.', 'mcpai' ),
				'input'       => array(
					'search'   => array( 'type' => 'string' ),
					'category' => array(
						'type'        => 'string',
						'description' => __( 'e.g. "text", "media", "design", "widgets", "theme", "embed".', 'mcpai' ),
					),
				),
				'execute'     => array( self::class, 'list_block_types' ),
				'permission'  => 'edit_posts',
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'list-patterns',
			array(
				'category'    => 'mcpai-blocks',
				'label'       => __( 'List patterns', 'mcpai' ),
				'description' => __( 'Lists ready-made block patterns (theme, plugin and saved patterns) such as heroes, pricing tables or footers. Use get_pattern to fetch the markup and insert it into content.', 'mcpai' ),
				'input'       => array(
					'search'   => array( 'type' => 'string' ),
					'category' => array( 'type' => 'string' ),
				),
				'execute'     => array( self::class, 'list_patterns' ),
				'permission'  => 'edit_posts',
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'get-pattern',
			array(
				'category'    => 'mcpai-blocks',
				'label'       => __( 'Get pattern', 'mcpai' ),
				'description' => __( 'Gets the block markup of a pattern by name (from list_patterns). Saved patterns use the name "saved/<id>".', 'mcpai' ),
				'input'       => array( 'name' => array( 'type' => 'string' ) ),
				'required'    => array( 'name' ),
				'execute'     => array( self::class, 'get_pattern' ),
				'permission'  => 'edit_posts',
				'meta'        => Abilities::read_meta(),
			)
		);
	}

	/**
	 * Case-insensitive match on any of the given strings.
	 *
	 * @param string   $search   Search text.
	 * @param string[] $haystack Strings to search in.
	 * @return bool
	 */
	private static function matches( $search, array $haystack ) {
		return '' === $search || false !== stripos( implode( ' ', $haystack ), $search );
	}

	/**
	 * Lists block types.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_block_types( $input ) {
		$search   = (string) ( $input['search'] ?? '' );
		$category = (string) ( $input['category'] ?? '' );
		$result   = array();

		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $block ) {
			if ( ! empty( $block->parent ) && empty( $search ) ) {
				continue; // Skip inner-only blocks (e.g. core/column) unless searched for.
			}
			if ( $category && $block->category !== $category ) {
				continue;
			}
			if ( ! self::matches( $search, array( $block->name, (string) $block->title, (string) $block->description, implode( ' ', (array) $block->keywords ) ) ) ) {
				continue;
			}
			$result[] = array(
				'name'        => $block->name,
				'title'       => $block->title,
				'description' => $block->description,
				'category'    => $block->category,
			);
		}
		return $result;
	}

	/**
	 * Lists patterns.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_patterns( $input ) {
		$search   = (string) ( $input['search'] ?? '' );
		$category = (string) ( $input['category'] ?? '' );
		$result   = array();

		foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			if ( ! empty( $pattern['inserter'] ) || ! isset( $pattern['inserter'] ) ) {
				$categories = (array) ( $pattern['categories'] ?? array() );
				if ( $category && ! in_array( $category, $categories, true ) ) {
					continue;
				}
				if ( ! self::matches( $search, array( $pattern['name'], $pattern['title'], $pattern['description'] ?? '', implode( ' ', (array) ( $pattern['keywords'] ?? array() ) ) ) ) ) {
					continue;
				}
				$result[] = array(
					'name'        => $pattern['name'],
					'title'       => $pattern['title'],
					'description' => $pattern['description'] ?? '',
					'categories'  => $categories,
				);
			}
		}

		foreach ( get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
			)
		) as $saved ) {
			if ( self::matches( $search, array( $saved->post_title ) ) && ! $category ) {
				$result[] = array(
					'name'        => 'saved/' . $saved->ID,
					'title'       => $saved->post_title,
					'description' => __( 'Saved pattern. Insert as <!-- wp:block {"ref":ID} /--> to keep it synced.', 'mcpai' ),
					'categories'  => array( 'saved' ),
				);
			}
		}

		return $result;
	}

	/**
	 * Gets a pattern's markup.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_pattern( $input ) {
		$name = $input['name'];

		if ( 0 === strpos( $name, 'saved/' ) ) {
			$saved = get_post( (int) substr( $name, 6 ) );
			if ( ! $saved || 'wp_block' !== $saved->post_type || ! current_user_can( 'read_post', $saved->ID ) ) {
				return new WP_Error( 'mcpai_not_found', __( 'No saved pattern found with that name.', 'mcpai' ) );
			}
			return array(
				'name'         => $name,
				'title'        => $saved->post_title,
				'content'      => $saved->post_content,
				'synced_block' => sprintf( '<!-- wp:block {"ref":%d} /-->', $saved->ID ),
			);
		}

		$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( $name );
		if ( ! $pattern ) {
			return new WP_Error( 'mcpai_not_found', __( 'No pattern found with that name. Use list_patterns.', 'mcpai' ) );
		}
		return array(
			'name'    => $pattern['name'],
			'title'   => $pattern['title'],
			'content' => $pattern['content'],
		);
	}
}
