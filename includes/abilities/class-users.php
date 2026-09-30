<?php
/**
 * User abilities. Read-only: accounts and roles are managed by people in WordPress.
 *
 * @package Viagent
 */

namespace Viagent\Abilities;

use Viagent\Security\Policy;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * User abilities.
 */
class Users {

	/**
	 * Registers abilities.
	 */
	public static function register() {
		Abilities::add(
			'list-users',
			array(
				'category'    => 'viagent-users',
				'label'       => __( 'List users', 'viagent' ),
				'description' => __( 'Lists user accounts with their roles. Filter by role or search by name/email.', 'viagent' ),
				'input'       => array_merge(
					array(
						'role'   => array( 'type' => 'string' ),
						'search' => array( 'type' => 'string' ),
					),
					Abilities::paging()
				),
				'execute'     => array( self::class, 'list_users' ),
				'permission'  => 'list_users',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'get-user',
			array(
				'category'    => 'viagent-users',
				'label'       => __( 'Get user', 'viagent' ),
				'description' => __( 'Gets one user account by ID.', 'viagent' ),
				'input'       => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'get_user' ),
				'permission'  => 'list_users',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);
	}

	/**
	 * Compact representation of a user.
	 *
	 * @param WP_User $user User.
	 * @return array
	 */
	public static function item( WP_User $user ) {
		return array(
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'website'      => $user->user_url,
			'bio'          => $user->description,
			'roles'        => array_values( $user->roles ),
			'registered'   => $user->user_registered,
			'posts'        => (int) count_user_posts( $user->ID ),
		);
	}

	/**
	 * Lists users.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_users( $input ) {
		$args = array(
			'number'      => (int) ( $input['per_page'] ?? 20 ),
			'paged'       => (int) ( $input['page'] ?? 1 ),
			'count_total' => true,
		);
		if ( ! empty( $input['role'] ) ) {
			$args['role'] = $input['role'];
		}
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = '*' . $input['search'] . '*';
		}

		$query = new \WP_User_Query( $args );
		return array(
			'total' => (int) $query->get_total(),
			'items' => array_map( array( self::class, 'item' ), $query->get_results() ),
		);
	}

	/**
	 * Gets a user.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_user( $input ) {
		$user = get_userdata( (int) $input['id'] );
		return $user ? self::item( $user ) : new WP_Error( 'viagent_not_found', __( 'No user found with that ID.', 'viagent' ) );
	}
}
