<?php
/**
 * Taxonomy abilities: categories, tags and custom taxonomies.
 *
 * @package MCPAI
 */

namespace MCPAI\Abilities;

use MCPAI\Log\Activity_Log;
use MCPAI\Security\Policy;
use WP_Error;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Taxonomy abilities.
 */
class Taxonomies {

	/**
	 * Registers abilities.
	 */
	public static function register() {
		$taxonomy = array(
			'type'        => 'string',
			'default'     => 'category',
			'description' => __( 'Taxonomy name, e.g. "category" or "post_tag". See list_taxonomies.', 'mcpai' ),
		);
		$term_id  = array(
			'type'    => 'integer',
			'minimum' => 1,
		);

		Abilities::add(
			'list-taxonomies',
			array(
				'category'    => 'mcpai-taxonomies',
				'label'       => __( 'List taxonomies', 'mcpai' ),
				'description' => __( 'Lists taxonomies (categories, tags and custom ones) with the post types they apply to.', 'mcpai' ),
				'execute'     => array( self::class, 'list_taxonomies' ),
				'permission'  => 'read',
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'list-terms',
			array(
				'category'    => 'mcpai-taxonomies',
				'label'       => __( 'List terms', 'mcpai' ),
				'description' => __( 'Lists terms (e.g. categories or tags) of a taxonomy, including empty ones, with post counts.', 'mcpai' ),
				'input'       => array_merge(
					array(
						'taxonomy' => $taxonomy,
						'search'   => array( 'type' => 'string' ),
						'parent'   => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
					Abilities::paging( 50 )
				),
				'execute'     => array( self::class, 'list_terms' ),
				'permission'  => 'read',
				'meta'        => Abilities::read_meta(),
			)
		);

		$term_fields = array(
			'name'        => array( 'type' => 'string' ),
			'slug'        => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ),
			'parent'      => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
		);

		Abilities::add(
			'create-term',
			array(
				'category'    => 'mcpai-taxonomies',
				'label'       => __( 'Create term', 'mcpai' ),
				'description' => __( 'Creates a category, tag or custom taxonomy term.', 'mcpai' ),
				'input'       => array_merge( array( 'taxonomy' => $taxonomy ), $term_fields ),
				'required'    => array( 'name' ),
				'execute'     => array( self::class, 'create_term' ),
				'permission'  => static function ( $input ) {
					$tax = get_taxonomy( $input['taxonomy'] ?? 'category' );
					return $tax ? current_user_can( $tax->cap->edit_terms ) : current_user_can( 'manage_categories' );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);

		Abilities::add(
			'update-term',
			array(
				'category'    => 'mcpai-taxonomies',
				'label'       => __( 'Update term', 'mcpai' ),
				'description' => __( 'Renames or edits a term (name, slug, description, parent).', 'mcpai' ),
				'input'       => array_merge( array( 'id' => $term_id ), $term_fields ),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'update_term' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'edit_term', (int) ( $input['id'] ?? 0 ) ) || ! get_term( (int) ( $input['id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'idempotent' => true ) ),
			)
		);

		Abilities::add(
			'delete-term',
			array(
				'category'    => 'mcpai-taxonomies',
				'label'       => __( 'Delete term', 'mcpai' ),
				'description' => __( 'Permanently deletes a term. Posts keep existing but lose this term. The default category cannot be deleted.', 'mcpai' ),
				'input'       => array( 'id' => $term_id ),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'delete_term' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'delete_term', (int) ( $input['id'] ?? 0 ) ) || ! get_term( (int) ( $input['id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'destructive' => true ) ),
			)
		);

		Abilities::add(
			'assign-terms',
			array(
				'category'    => 'mcpai-taxonomies',
				'label'       => __( 'Assign terms to post', 'mcpai' ),
				'description' => __( 'Sets or adds terms on a post. Terms can be names, slugs or IDs; missing names are created.', 'mcpai' ),
				'input'       => array(
					'post_id'  => $term_id,
					'taxonomy' => $taxonomy,
					'terms'    => array(
						'type'  => 'array',
						'items' => array( 'type' => array( 'string', 'integer' ) ),
					),
					'append'   => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Add to existing terms instead of replacing them.', 'mcpai' ),
					),
				),
				'required'    => array( 'post_id', 'terms' ),
				'execute'     => array( self::class, 'assign_terms' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) ) || ! get_post( (int) ( $input['post_id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);
	}

	/**
	 * Taxonomies exposed to the AI.
	 *
	 * @return \WP_Taxonomy[]
	 */
	public static function taxonomies() {
		$taxonomies = get_taxonomies( array( 'show_ui' => true ), 'objects' );
		unset( $taxonomies['nav_menu'], $taxonomies['wp_pattern_category'] );
		return $taxonomies;
	}

	/**
	 * Returns a taxonomy or an error the AI can act on.
	 *
	 * @param string $name Taxonomy name.
	 * @return \WP_Taxonomy|WP_Error
	 */
	private static function taxonomy( $name ) {
		$taxonomies = self::taxonomies();
		if ( ! isset( $taxonomies[ $name ] ) ) {
			return new WP_Error( 'mcpai_invalid_taxonomy', sprintf( /* translators: %s: taxonomy */ __( 'Unknown taxonomy "%s". Use list_taxonomies to see available ones.', 'mcpai' ), $name ) );
		}
		return $taxonomies[ $name ];
	}

	/**
	 * Compact representation of a term.
	 *
	 * @param WP_Term $term Term.
	 * @return array
	 */
	public static function term( WP_Term $term ) {
		return array(
			'id'          => $term->term_id,
			'taxonomy'    => $term->taxonomy,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => $term->parent,
			'count'       => $term->count,
			'link'        => get_term_link( $term ),
		);
	}

	/**
	 * Lists taxonomies.
	 *
	 * @return array
	 */
	public static function list_taxonomies() {
		$result = array();
		foreach ( self::taxonomies() as $taxonomy ) {
			$result[] = array(
				'name'         => $taxonomy->name,
				'label'        => $taxonomy->label,
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'post_types'   => array_values( $taxonomy->object_type ),
			);
		}
		return $result;
	}

	/**
	 * Lists terms.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function list_terms( $input ) {
		$taxonomy = self::taxonomy( $input['taxonomy'] ?? 'category' );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}

		$per_page = (int) ( $input['per_page'] ?? 50 );
		$args     = array(
			'taxonomy'   => $taxonomy->name,
			'hide_empty' => false,
			'search'     => $input['search'] ?? '',
			'number'     => $per_page,
			'offset'     => ( (int) ( $input['page'] ?? 1 ) - 1 ) * $per_page,
		);
		if ( isset( $input['parent'] ) ) {
			$args['parent'] = (int) $input['parent'];
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		unset( $args['number'], $args['offset'] );
		return array(
			'total' => (int) wp_count_terms( $args ),
			'items' => array_map( array( self::class, 'term' ), $terms ),
		);
	}

	/**
	 * Creates a term.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function create_term( $input ) {
		$taxonomy = self::taxonomy( $input['taxonomy'] ?? 'category' );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}

		$result = wp_insert_term( wp_slash( $input['name'] ), $taxonomy->name, wp_slash( self::term_args( $input ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Activity_Log::set_object( 'term', $result['term_id'] );
		return self::term( get_term( $result['term_id'] ) );
	}

	/**
	 * Updates a term.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_term( $input ) {
		$term = get_term( (int) $input['id'] );
		if ( ! $term instanceof WP_Term || is_wp_error( self::taxonomy( $term->taxonomy ) ) ) {
			return new WP_Error( 'mcpai_not_found', __( 'No term found with that ID.', 'mcpai' ) );
		}

		$args = self::term_args( $input );
		if ( isset( $input['name'] ) ) {
			$args['name'] = $input['name'];
		}

		$result = wp_update_term( $term->term_id, $term->taxonomy, wp_slash( $args ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Activity_Log::set_object( 'term', $term->term_id );
		return self::term( get_term( $term->term_id ) );
	}

	/**
	 * Deletes a term.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function delete_term( $input ) {
		$term = get_term( (int) $input['id'] );
		if ( ! $term instanceof WP_Term || is_wp_error( self::taxonomy( $term->taxonomy ) ) ) {
			return new WP_Error( 'mcpai_not_found', __( 'No term found with that ID.', 'mcpai' ) );
		}

		Activity_Log::set_object( 'term', $term->term_id );

		$result = wp_delete_term( $term->term_id, $term->taxonomy );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			return new WP_Error( 'mcpai_delete_failed', __( 'This term cannot be deleted (it may be the default category).', 'mcpai' ) );
		}

		return array(
			'id'      => $term->term_id,
			'name'    => $term->name,
			'deleted' => true,
		);
	}

	/**
	 * Assigns terms to a post.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function assign_terms( $input ) {
		$post = Content::find( (int) $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$taxonomy = $input['taxonomy'] ?? 'category';
		$previous = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );

		$result = self::set_post_terms( $post, $taxonomy, (array) $input['terms'], ! empty( $input['append'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Activity_Log::set_object( 'post', $post->ID );
		Activity_Log::set_undo(
			array(
				'action'   => 'restore_terms',
				'post_id'  => $post->ID,
				'taxonomy' => $taxonomy,
				'term_ids' => is_wp_error( $previous ) ? array() : $previous,
			)
		);

		return array(
			'post_id'  => $post->ID,
			'taxonomy' => $taxonomy,
			'terms'    => array_map( array( self::class, 'term' ), wp_get_object_terms( $post->ID, $taxonomy ) ),
		);
	}

	/**
	 * Sets terms on a post from a mix of IDs, slugs and names, creating missing
	 * terms when the user may. Shared with Content::create_post/update_post.
	 *
	 * @param WP_Post $post     Post.
	 * @param string  $taxonomy Taxonomy name.
	 * @param array   $values   Term IDs, slugs or names.
	 * @param bool    $append   Add instead of replace.
	 * @return true|WP_Error
	 */
	public static function set_post_terms( WP_Post $post, $taxonomy, array $values, $append ) {
		$tax = self::taxonomy( $taxonomy );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		if ( ! in_array( $post->post_type, $tax->object_type, true ) ) {
			return new WP_Error( 'mcpai_taxonomy_mismatch', sprintf( /* translators: 1: taxonomy, 2: post type */ __( 'The taxonomy "%1$s" is not used by "%2$s".', 'mcpai' ), $tax->name, $post->post_type ) );
		}
		if ( ! current_user_can( $tax->cap->assign_terms ) ) {
			return new WP_Error( 'mcpai_forbidden', __( 'You are not allowed to assign these terms.', 'mcpai' ) );
		}
		$editable = Policy::check_post_editable( $post );
		if ( is_wp_error( $editable ) ) {
			return $editable;
		}

		$ids = array();
		foreach ( $values as $value ) {
			$term = is_int( $value ) || ctype_digit( (string) $value ) ? get_term( (int) $value, $tax->name ) : null;
			if ( ! $term instanceof WP_Term ) {
				$term = get_term_by( 'slug', sanitize_title( (string) $value ), $tax->name );
			}
			if ( ! $term instanceof WP_Term ) {
				$term = get_term_by( 'name', (string) $value, $tax->name );
			}
			if ( ! $term instanceof WP_Term ) {
				if ( ! current_user_can( $tax->cap->edit_terms ) ) {
					return new WP_Error( 'mcpai_forbidden', sprintf( /* translators: %s: term name */ __( 'The term "%s" does not exist and you are not allowed to create it.', 'mcpai' ), $value ) );
				}
				$created = wp_insert_term( wp_slash( (string) $value ), $tax->name );
				if ( is_wp_error( $created ) ) {
					return $created;
				}
				$term = get_term( $created['term_id'], $tax->name );
			}
			$ids[] = $term->term_id;
		}

		$result = wp_set_object_terms( $post->ID, $ids, $tax->name, $append );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Maps input to wp_insert_term()/wp_update_term() args.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	private static function term_args( array $input ) {
		return array_intersect_key( $input, array_flip( array( 'slug', 'description', 'parent' ) ) );
	}
}
