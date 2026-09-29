<?php
/**
 * User abilities. Roles above "editor" can never be granted by the AI.
 *
 * @package MCPAI
 */

namespace MCPAI\Abilities;

use MCPAI\Log\Activity_Log;
use MCPAI\Security\Policy;
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
		$profile = array(
			'email'        => array(
				'type'   => 'string',
				'format' => 'email',
			),
			'display_name' => array( 'type' => 'string' ),
			'first_name'   => array( 'type' => 'string' ),
			'last_name'    => array( 'type' => 'string' ),
			'website'      => array( 'type' => 'string' ),
			'bio'          => array( 'type' => 'string' ),
			'role'         => array(
				'type'        => 'string',
				'description' => __( 'Role slug, e.g. "subscriber", "author" or "editor". Administrator cannot be assigned.', 'mcpai' ),
			),
		);

		Abilities::add(
			'list-users',
			array(
				'category'    => 'mcpai-users',
				'label'       => __( 'List users', 'mcpai' ),
				'description' => __( 'Lists user accounts with their roles. Filter by role or search by name/email.', 'mcpai' ),
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
				'category'    => 'mcpai-users',
				'label'       => __( 'Get user', 'mcpai' ),
				'description' => __( 'Gets one user account by ID.', 'mcpai' ),
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

		Abilities::add(
			'create-user',
			array(
				'category'    => 'mcpai-users',
				'label'       => __( 'Create user', 'mcpai' ),
				'description' => __( 'Creates a user account. A strong password is generated and the new user gets an email to set their own.', 'mcpai' ),
				'input'       => array_merge(
					array(
						'username' => array(
							'type'      => 'string',
							'minLength' => 1,
						),
					),
					$profile
				),
				'required'    => array( 'username', 'email' ),
				'execute'     => array( self::class, 'create_user' ),
				'permission'  => 'create_users',
				'meta'        => Abilities::write_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'update-user',
			array(
				'category'    => 'mcpai-users',
				'label'       => __( 'Update user', 'mcpai' ),
				'description' => __( 'Updates a user\'s profile or role. Passwords cannot be changed, and administrators cannot be edited.', 'mcpai' ),
				'input'       => array_merge(
					array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					$profile
				),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'update_user' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'edit_user', (int) ( $input['id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);
	}

	/**
	 * Roles the AI may assign: editable roles without admin-level capabilities.
	 *
	 * @return string[]
	 */
	private static function assignable_roles() {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$roles = array();
		foreach ( get_editable_roles() as $slug => $role ) {
			$caps = $role['capabilities'];
			if ( empty( $caps['manage_options'] ) && empty( $caps['promote_users'] ) && empty( $caps['edit_users'] ) && empty( $caps['activate_plugins'] ) ) {
				$roles[] = $slug;
			}
		}
		return $roles;
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
		return $user ? self::item( $user ) : new WP_Error( 'mcpai_not_found', __( 'No user found with that ID.', 'mcpai' ) );
	}

	/**
	 * Checks a requested role.
	 *
	 * @param string $role Role slug.
	 * @return true|WP_Error
	 */
	private static function check_role( $role ) {
		if ( ! in_array( $role, self::assignable_roles(), true ) ) {
			return new WP_Error(
				'mcpai_role_not_allowed',
				sprintf(
					/* translators: 1: role, 2: allowed roles */
					__( 'The role "%1$s" cannot be assigned by an AI agent. Allowed roles: %2$s. Administrators must be added by a person in WordPress.', 'mcpai' ),
					$role,
					implode( ', ', self::assignable_roles() )
				)
			);
		}
		return true;
	}

	/**
	 * Maps profile input to wp_insert_user() fields.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	private static function user_data( array $input ) {
		$map  = array(
			'email'        => 'user_email',
			'display_name' => 'display_name',
			'first_name'   => 'first_name',
			'last_name'    => 'last_name',
			'website'      => 'user_url',
			'bio'          => 'description',
			'role'         => 'role',
		);
		$data = array();
		foreach ( $map as $from => $to ) {
			if ( isset( $input[ $from ] ) ) {
				$data[ $to ] = $input[ $from ];
			}
		}
		return $data;
	}

	/**
	 * Creates a user.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function create_user( $input ) {
		$input['role'] = $input['role'] ?? get_option( 'default_role', 'subscriber' );
		$allowed       = self::check_role( $input['role'] );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$data               = self::user_data( $input );
		$data['user_login'] = sanitize_user( $input['username'], true );
		$data['user_pass']  = wp_generate_password( 24 );

		$id = wp_insert_user( wp_slash( $data ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		wp_new_user_notification( $id, null, 'user' );
		Activity_Log::set_object( 'user', $id );

		return array_merge( self::item( get_userdata( $id ) ), array( 'note' => __( 'The user was emailed a link to set their password.', 'mcpai' ) ) );
	}

	/**
	 * Updates a user.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_user( $input ) {
		$user = get_userdata( (int) $input['id'] );
		if ( ! $user ) {
			return new WP_Error( 'mcpai_not_found', __( 'No user found with that ID.', 'mcpai' ) );
		}
		if ( user_can( $user, 'manage_options' ) || user_can( $user, 'promote_users' ) ) {
			return new WP_Error( 'mcpai_protected_user', __( 'Administrator accounts cannot be changed by an AI agent.', 'mcpai' ) );
		}
		if ( isset( $input['role'] ) ) {
			if ( get_current_user_id() === $user->ID ) {
				return new WP_Error( 'mcpai_own_role', __( 'You cannot change your own role.', 'mcpai' ) );
			}
			$allowed = self::check_role( $input['role'] );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		$data = self::user_data( $input );
		if ( empty( $data ) ) {
			return new WP_Error( 'mcpai_nothing_to_update', __( 'Pass at least one field to change.', 'mcpai' ) );
		}
		$data['ID'] = $user->ID;

		$result = wp_update_user( wp_slash( $data ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Activity_Log::set_object( 'user', $user->ID );
		return self::item( get_userdata( $user->ID ) );
	}
}
