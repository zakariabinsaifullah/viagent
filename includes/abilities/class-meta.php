<?php
/**
 * Custom field (post meta) abilities. Protected keys (starting with "_") are
 * only available when a plugin registers them with show_in_rest.
 *
 * @package Viagent
 */

namespace Viagent\Abilities;

use Viagent\Log\Activity_Log;
use Viagent\Security\Policy;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Post meta abilities.
 */
class Meta {

	/**
	 * Registers abilities.
	 */
	public static function register() {
		$post_id = array(
			'type'    => 'integer',
			'minimum' => 1,
		);

		Abilities::add(
			'get-post-meta',
			array(
				'category'    => 'viagent-content',
				'label'       => __( 'Get custom fields', 'viagent' ),
				'description' => __( 'Gets the custom fields (post meta) of a post, or a single field by key.', 'viagent' ),
				'input'       => array(
					'post_id' => $post_id,
					'key'     => array( 'type' => 'string' ),
				),
				'required'    => array( 'post_id' ),
				'execute'     => array( self::class, 'get_meta' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) ) || ! get_post( (int) ( $input['post_id'] ?? 0 ) );
				},
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'update-post-meta',
			array(
				'category'    => 'viagent-content',
				'label'       => __( 'Update custom field', 'viagent' ),
				'description' => __( 'Sets or deletes one custom field (post meta) on a post.', 'viagent' ),
				'input'       => array(
					'post_id' => $post_id,
					'key'     => array(
						'type'      => 'string',
						'minLength' => 1,
					),
					'value'   => array(
						'type'        => array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ),
						'description' => __( 'New value. Omit or pass null with delete=true to remove the field.', 'viagent' ),
					),
					'delete'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'    => array( 'post_id', 'key' ),
				'execute'     => array( self::class, 'update_meta' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) ) || ! get_post( (int) ( $input['post_id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta(
					Policy::CONTENT,
					array(
						'idempotent' => true,
						'draft_safe' => true,
					)
				),
			)
		);
	}

	/**
	 * Whether a meta key is available to the AI.
	 *
	 * @param WP_Post $post Post.
	 * @param string  $key  Meta key.
	 * @return bool
	 */
	private static function is_allowed_key( WP_Post $post, $key ) {
		$registered = get_registered_meta_keys( 'post', $post->post_type ) + get_registered_meta_keys( 'post' );
		if ( isset( $registered[ $key ] ) ) {
			return ! empty( $registered[ $key ]['show_in_rest'] );
		}
		return ! is_protected_meta( $key, 'post' );
	}

	/**
	 * Gets meta.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_meta( $input ) {
		$post = Content::find( (int) $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! empty( $input['key'] ) ) {
			if ( ! self::is_allowed_key( $post, $input['key'] ) ) {
				return new WP_Error( 'viagent_protected_meta', __( 'This custom field is private to a plugin and cannot be read.', 'viagent' ) );
			}
			return array(
				'key'    => $input['key'],
				'exists' => metadata_exists( 'post', $post->ID, $input['key'] ),
				'value'  => get_post_meta( $post->ID, $input['key'], true ),
			);
		}

		$result = array();
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( self::is_allowed_key( $post, $key ) ) {
				$values         = array_map( 'maybe_unserialize', $values );
				$result[ $key ] = 1 === count( $values ) ? $values[0] : $values;
			}
		}
		return empty( $result ) ? new \stdClass() : $result;
	}

	/**
	 * Updates meta.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_meta( $input ) {
		$post = Content::find( (int) $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$key = $input['key'];
		if ( ! self::is_allowed_key( $post, $key ) ) {
			return new WP_Error( 'viagent_protected_meta', __( 'This custom field is private to a plugin and cannot be changed.', 'viagent' ) );
		}
		$editable = Policy::check_post_editable( $post );
		if ( is_wp_error( $editable ) ) {
			return $editable;
		}

		$previous = metadata_exists( 'post', $post->ID, $key ) ? get_post_meta( $post->ID, $key, true ) : null;

		Activity_Log::set_object( 'post', $post->ID );
		Activity_Log::set_undo(
			array(
				'action'  => 'restore_meta',
				'post_id' => $post->ID,
				'key'     => $key,
				'value'   => $previous,
			)
		);

		if ( ! empty( $input['delete'] ) || ! array_key_exists( 'value', $input ) || null === $input['value'] ) {
			delete_post_meta( $post->ID, $key );
			return array(
				'key'     => $key,
				'deleted' => true,
			);
		}

		update_post_meta( $post->ID, $key, wp_slash( $input['value'] ) );
		return array(
			'key'   => $key,
			'value' => get_post_meta( $post->ID, $key, true ),
		);
	}
}
