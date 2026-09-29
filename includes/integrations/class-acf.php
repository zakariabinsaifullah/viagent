<?php
/**
 * Advanced Custom Fields tools: discover field groups and read or update
 * field values on posts, pages and products.
 *
 * @package MCPAI
 */

namespace MCPAI\Integrations;

use MCPAI\Abilities\Abilities;
use MCPAI\Log\Activity_Log;
use MCPAI\Security\Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * ACF integration.
 */
class ACF {

	const CATEGORY = 'mcpai-acf';

	/**
	 * Field types the AI may write. Complex types (repeaters, flexible content,
	 * galleries…) are readable but not writable in this version.
	 */
	const WRITABLE_TYPES = array( 'text', 'textarea', 'number', 'range', 'email', 'url', 'password', 'wysiwyg', 'select', 'checkbox', 'radio', 'button_group', 'true_false', 'date_picker', 'date_time_picker', 'time_picker', 'color_picker', 'image', 'file', 'link', 'page_link', 'post_object', 'relationship', 'taxonomy', 'user', 'oembed' );

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_filter( 'mcpai_apply_undo', array( self::class, 'apply_undo' ), 10, 2 );
		add_filter( 'mcpai_tool_summaries', array( self::class, 'summaries' ) );
		add_filter( 'mcpai_instructions', array( self::class, 'instructions' ) );
	}

	/**
	 * Registers the ability category.
	 */
	public static function register_categories() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Custom fields (ACF)', 'mcpai' ),
				'description' => __( 'Fields added with Advanced Custom Fields.', 'mcpai' ),
			)
		);
	}

	/**
	 * Tells AI apps about ACF.
	 *
	 * @param string[] $lines Instruction lines.
	 * @return string[]
	 */
	public static function instructions( $lines ) {
		$lines[] = 'Custom fields are managed with Advanced Custom Fields: use list_acf_field_groups to see which fields exist, then get_acf_fields and update_acf_field (instead of the raw post meta tools).';
		return $lines;
	}

	/**
	 * Registers abilities.
	 */
	public static function register() {
		$post_id = array(
			'type'    => 'integer',
			'minimum' => 1,
		);

		Abilities::add(
			'list-acf-field-groups',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'List ACF field groups', 'mcpai' ),
				'description' => __( 'Lists ACF field groups with their fields (name, label, type, choices) and where they appear. Optionally only groups that apply to one post.', 'mcpai' ),
				'input'       => array( 'post_id' => $post_id ),
				'execute'     => array( self::class, 'list_field_groups' ),
				'permission'  => 'edit_posts',
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'get-acf-fields',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Get ACF fields', 'mcpai' ),
				'description' => __( 'Gets all ACF field values of a post, page or product, with each field\'s label and type.', 'mcpai' ),
				'input'       => array( 'post_id' => $post_id ),
				'required'    => array( 'post_id' ),
				'execute'     => array( self::class, 'get_fields' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) ) || ! get_post( (int) ( $input['post_id'] ?? 0 ) );
				},
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'update-acf-field',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Update ACF field', 'mcpai' ),
				'description' => __( 'Sets one ACF field on a post by field name or key. Use IDs for image, file, post and user fields; arrays for checkboxes and relationships; true/false for toggles; "Ymd" dates such as "20261231" for date pickers.', 'mcpai' ),
				'input'       => array(
					'post_id' => $post_id,
					'field'   => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Field name (e.g. "price_note") or key (e.g. "field_abc123").', 'mcpai' ),
					),
					'value'   => array(
						'type' => array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ),
					),
				),
				'required'    => array( 'post_id', 'field', 'value' ),
				'execute'     => array( self::class, 'update_field' ),
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
	 * Compact field description.
	 *
	 * @param array $field ACF field.
	 * @return array
	 */
	private static function field( array $field ) {
		$item = array(
			'name'     => $field['name'],
			'key'      => $field['key'],
			'label'    => $field['label'],
			'type'     => $field['type'],
			'required' => ! empty( $field['required'] ),
			'writable' => in_array( $field['type'], self::WRITABLE_TYPES, true ),
		);
		if ( ! empty( $field['choices'] ) ) {
			$item['choices'] = $field['choices'];
		}
		if ( ! empty( $field['instructions'] ) ) {
			$item['instructions'] = wp_strip_all_tags( $field['instructions'] );
		}
		if ( ! empty( $field['sub_fields'] ) ) {
			$item['sub_fields'] = array_map( array( self::class, 'field' ), $field['sub_fields'] );
		}
		return $item;
	}

	/**
	 * Lists field groups.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_field_groups( $input ) {
		$filter = ! empty( $input['post_id'] ) ? array( 'post_id' => (int) $input['post_id'] ) : array();
		$groups = array();

		foreach ( acf_get_field_groups( $filter ) as $group ) {
			$locations = array();
			foreach ( (array) $group['location'] as $or ) {
				$locations[] = implode(
					' and ',
					array_map(
						static function ( $rule ) {
							return $rule['param'] . ' ' . $rule['operator'] . ' ' . $rule['value'];
						},
						(array) $or
					)
				);
			}
			$groups[] = array(
				'title'    => $group['title'],
				'key'      => $group['key'],
				'active'   => (bool) $group['active'],
				'shown_on' => $locations,
				'fields'   => array_map( array( self::class, 'field' ), (array) acf_get_fields( $group ) ),
			);
		}
		return $groups;
	}

	/**
	 * Gets field values.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_fields( $input ) {
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'mcpai_not_found', __( 'No post found with that ID.', 'mcpai' ) );
		}

		$fields = array();
		foreach ( (array) get_field_objects( $post->ID, false ) as $field ) {
			$fields[ $field['name'] ] = array(
				'label' => $field['label'],
				'type'  => $field['type'],
				'value' => $field['value'],
			);
		}
		return array(
			'post_id' => $post->ID,
			'title'   => $post->post_title,
			'fields'  => empty( $fields ) ? new \stdClass() : $fields,
		);
	}

	/**
	 * Updates a field.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_field( $input ) {
		$post = get_post( (int) $input['post_id'] );
		if ( ! $post ) {
			return new WP_Error( 'mcpai_not_found', __( 'No post found with that ID.', 'mcpai' ) );
		}
		$editable = Policy::check_post_editable( $post );
		if ( is_wp_error( $editable ) ) {
			return $editable;
		}

		$field = acf_get_field( $input['field'] );
		if ( ! $field ) {
			// Field names only resolve in the context of a post.
			$field = get_field_object( $input['field'], $post->ID, false, false );
		}
		if ( ! $field ) {
			return new WP_Error( 'mcpai_unknown_field', __( 'No ACF field with that name or key applies to this post. Use list_acf_field_groups with the post_id.', 'mcpai' ) );
		}
		if ( ! in_array( $field['type'], self::WRITABLE_TYPES, true ) ) {
			/* translators: %s: field type */
			return new WP_Error( 'mcpai_unsupported_field', sprintf( __( 'Fields of type "%s" can be read but not changed by AI apps yet. Edit this field in WordPress.', 'mcpai' ), $field['type'] ) );
		}

		$previous = get_field( $field['key'], $post->ID, false );
		if ( ! update_field( $field['key'], $input['value'], $post->ID ) && get_field( $field['key'], $post->ID, false ) !== $input['value'] ) {
			return new WP_Error( 'mcpai_update_failed', __( 'The field could not be saved.', 'mcpai' ) );
		}

		Activity_Log::set_object( 'post', $post->ID );
		Activity_Log::set_undo(
			array(
				'action'    => 'acf_restore_field',
				'post_id'   => $post->ID,
				'field_key' => $field['key'],
				'value'     => $previous,
			)
		);

		return array(
			'post_id' => $post->ID,
			'field'   => $field['name'],
			'value'   => get_field( $field['key'], $post->ID, false ),
		);
	}

	/**
	 * Undo handler.
	 *
	 * @param mixed $result Result from earlier handlers.
	 * @param array $undo   Undo instructions.
	 * @return mixed
	 */
	public static function apply_undo( $result, $undo ) {
		if ( null !== $result || 'acf_restore_field' !== $undo['action'] ) {
			return $result;
		}
		if ( ! current_user_can( 'edit_post', (int) $undo['post_id'] ) ) {
			return new WP_Error( 'mcpai_forbidden', __( 'You are not allowed to change this item.', 'mcpai' ) );
		}
		if ( null === $undo['value'] ) {
			delete_field( $undo['field_key'], (int) $undo['post_id'] );
		} else {
			update_field( $undo['field_key'], $undo['value'], (int) $undo['post_id'] );
		}
		return true;
	}

	/**
	 * Tool summaries for the Tools screen.
	 *
	 * @param array $summaries Summaries.
	 * @return array
	 */
	public static function summaries( $summaries ) {
		return array_merge(
			$summaries,
			array(
				'list_acf_field_groups' => __( 'See which custom fields exist and where.', 'mcpai' ),
				'get_acf_fields'        => __( 'Read the custom fields of a post or page.', 'mcpai' ),
				'update_acf_field'      => __( 'Fill in or change custom fields.', 'mcpai' ),
			)
		);
	}
}
