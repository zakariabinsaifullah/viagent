<?php
/**
 * Menu abilities: classic navigation menus and block-theme Navigation menus.
 *
 * @package Viagent
 */

namespace Viagent\Abilities;

use Viagent\Log\Activity_Log;
use Viagent\Security\Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Menu abilities.
 */
class Menus {

	/**
	 * Registers abilities.
	 */
	public static function register() {
		$id = array(
			'type'    => 'integer',
			'minimum' => 1,
		);

		Abilities::add(
			'list-menus',
			array(
				'category'    => 'viagent-menus',
				'label'       => __( 'List menus', 'viagent' ),
				'description' => __( 'Lists navigation menus. Block themes use "block" menus (Navigation block, edited as block markup); classic themes use "classic" menus with items and theme locations.', 'viagent' ),
				'execute'     => array( self::class, 'list_menus' ),
				'permission'  => 'edit_theme_options',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'get-menu',
			array(
				'category'    => 'viagent-menus',
				'label'       => __( 'Get menu', 'viagent' ),
				'description' => __( 'Gets a menu: items for classic menus, block markup for block menus.', 'viagent' ),
				'input'       => array(
					'id'   => $id,
					'type' => array(
						'type'    => 'string',
						'enum'    => array( 'classic', 'block' ),
						'default' => 'classic',
					),
				),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'get_menu' ),
				'permission'  => 'edit_theme_options',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'add-menu-item',
			array(
				'category'    => 'viagent-menus',
				'label'       => __( 'Add classic menu item', 'viagent' ),
				'description' => __( 'Adds a link to a classic menu. Link either to a post/page/term (object_type + object_id) or to a custom url.', 'viagent' ),
				'input'       => array(
					'menu_id'        => $id,
					'title'          => array( 'type' => 'string' ),
					'url'            => array( 'type' => 'string' ),
					'object_type'    => array(
						'type'        => 'string',
						'description' => __( 'Post type (e.g. "page") or taxonomy (e.g. "category").', 'viagent' ),
					),
					'object_id'      => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'parent_item_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'position'       => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
				'required'    => array( 'menu_id' ),
				'execute'     => array( self::class, 'add_menu_item' ),
				'permission'  => 'edit_theme_options',
				'meta'        => Abilities::write_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'remove-menu-item',
			array(
				'category'    => 'viagent-menus',
				'label'       => __( 'Remove classic menu item', 'viagent' ),
				'description' => __( 'Removes an item from a classic menu.', 'viagent' ),
				'input'       => array( 'item_id' => $id ),
				'required'    => array( 'item_id' ),
				'execute'     => array( self::class, 'remove_menu_item' ),
				'permission'  => 'edit_theme_options',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'destructive' => true ) ),
			)
		);

		Abilities::add(
			'update-navigation-menu',
			array(
				'category'    => 'viagent-menus',
				'label'       => __( 'Update block navigation menu', 'viagent' ),
				'description' => __( 'Replaces the block markup of a block-theme navigation menu, e.g. <!-- wp:navigation-link {"label":"About","url":"/about/","kind":"custom"} /-->. Read it first with get_menu (type "block").', 'viagent' ),
				'input'       => array(
					'id'      => $id,
					'content' => array( 'type' => 'string' ),
					'title'   => array( 'type' => 'string' ),
				),
				'required'    => array( 'id', 'content' ),
				'execute'     => array( self::class, 'update_navigation' ),
				'permission'  => 'edit_theme_options',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);
	}

	/**
	 * Lists menus.
	 *
	 * @return array
	 */
	public static function list_menus() {
		$locations = get_nav_menu_locations();
		$classic   = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$classic[] = array(
				'id'        => $menu->term_id,
				'name'      => $menu->name,
				'items'     => $menu->count,
				'locations' => array_keys( $locations, $menu->term_id, true ),
			);
		}

		$block = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'wp_navigation',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 50,
			)
		) as $navigation ) {
			$block[] = array(
				'id'     => $navigation->ID,
				'title'  => $navigation->post_title,
				'status' => $navigation->post_status,
			);
		}

		return array(
			'theme_uses_blocks' => wp_is_block_theme(),
			'block_menus'       => $block,
			'classic_menus'     => $classic,
			'classic_locations' => get_registered_nav_menus(),
		);
	}

	/**
	 * Gets a menu.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_menu( $input ) {
		if ( 'block' === ( $input['type'] ?? 'classic' ) ) {
			$navigation = get_post( (int) $input['id'] );
			if ( ! $navigation || 'wp_navigation' !== $navigation->post_type ) {
				return new WP_Error( 'viagent_not_found', __( 'No block menu found with that ID.', 'viagent' ) );
			}
			return array(
				'id'      => $navigation->ID,
				'title'   => $navigation->post_title,
				'content' => $navigation->post_content,
			);
		}

		$menu = wp_get_nav_menu_object( (int) $input['id'] );
		if ( ! $menu ) {
			return new WP_Error( 'viagent_not_found', __( 'No classic menu found with that ID.', 'viagent' ) );
		}

		$items = array();
		foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
			$items[] = array(
				'id'        => $item->ID,
				'title'     => $item->title,
				'url'       => $item->url,
				'type'      => $item->type,
				'object'    => $item->object,
				'object_id' => (int) $item->object_id,
				'parent'    => (int) $item->menu_item_parent,
				'position'  => (int) $item->menu_order,
			);
		}

		return array(
			'id'    => $menu->term_id,
			'name'  => $menu->name,
			'items' => $items,
		);
	}

	/**
	 * Adds a classic menu item.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function add_menu_item( $input ) {
		$menu = wp_get_nav_menu_object( (int) $input['menu_id'] );
		if ( ! $menu ) {
			return new WP_Error( 'viagent_not_found', __( 'No classic menu found with that ID.', 'viagent' ) );
		}

		$args = array(
			'menu-item-title'     => $input['title'] ?? '',
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => (int) ( $input['parent_item_id'] ?? 0 ),
			'menu-item-position'  => (int) ( $input['position'] ?? 0 ),
		);

		if ( ! empty( $input['object_type'] ) && ! empty( $input['object_id'] ) ) {
			$args['menu-item-type']      = taxonomy_exists( $input['object_type'] ) ? 'taxonomy' : 'post_type';
			$args['menu-item-object']    = $input['object_type'];
			$args['menu-item-object-id'] = (int) $input['object_id'];
		} elseif ( ! empty( $input['url'] ) ) {
			$args['menu-item-type'] = 'custom';
			$args['menu-item-url']  = esc_url_raw( $input['url'] );
		} else {
			return new WP_Error( 'viagent_missing_target', __( 'Pass either url, or object_type and object_id.', 'viagent' ) );
		}

		$item_id = wp_update_nav_menu_item( $menu->term_id, 0, wp_slash( $args ) );
		if ( is_wp_error( $item_id ) ) {
			return $item_id;
		}

		Activity_Log::set_object( 'menu_item', $item_id );
		return self::get_menu( array( 'id' => $menu->term_id ) );
	}

	/**
	 * Removes a classic menu item.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function remove_menu_item( $input ) {
		$item = get_post( (int) $input['item_id'] );
		if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
			return new WP_Error( 'viagent_not_found', __( 'No menu item found with that ID.', 'viagent' ) );
		}

		Activity_Log::set_object( 'menu_item', $item->ID );
		if ( ! wp_delete_post( $item->ID, true ) ) {
			return new WP_Error( 'viagent_delete_failed', __( 'Could not remove the menu item.', 'viagent' ) );
		}
		return array(
			'id'      => $item->ID,
			'removed' => true,
		);
	}

	/**
	 * Updates a block navigation menu.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_navigation( $input ) {
		$navigation = get_post( (int) $input['id'] );
		if ( ! $navigation || 'wp_navigation' !== $navigation->post_type ) {
			return new WP_Error( 'viagent_not_found', __( 'No block menu found with that ID.', 'viagent' ) );
		}

		$data = array(
			'ID'           => $navigation->ID,
			'post_content' => $input['content'],
		);
		if ( isset( $input['title'] ) ) {
			$data['post_title'] = $input['title'];
		}

		Activity_Log::set_object( 'post', $navigation->ID );
		Activity_Log::set_undo(
			array(
				'action'  => 'restore_post_fields',
				'post_id' => $navigation->ID,
				'fields'  => array(
					'post_title'   => $navigation->post_title,
					'post_content' => $navigation->post_content,
				),
			)
		);

		$result = wp_update_post( wp_slash( $data ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::get_menu(
			array(
				'id'   => $navigation->ID,
				'type' => 'block',
			)
		);
	}
}
