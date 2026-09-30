<?php
/**
 * Content abilities: posts, pages and custom post types.
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
 * Content abilities.
 */
class Content {

	/**
	 * Registers abilities.
	 */
	public static function register() {
		wp_register_ability(
			'viagent/list-post-types',
			array(
				'label'               => __( 'List post types', 'viagent' ),
				'description'         => __( 'Lists content types you can work with (posts, pages and custom post types) with their taxonomies and whether they support the block editor.', 'viagent' ),
				'category'            => 'viagent-content',
				'input_schema'        => array(),
				'execute_callback'    => array( self::class, 'list_post_types' ),
				'permission_callback' => static function () {
					return current_user_can( 'read' );
				},
				'meta'                => Abilities::read_meta(),
			)
		);

		wp_register_ability(
			'viagent/list-posts',
			array(
				'label'               => __( 'List posts', 'viagent' ),
				'description'         => __( 'Lists posts, pages or items of any post type, newest first. Filter by status, search words, author or category/tag. Returns summaries; use get_post for full content.', 'viagent' ),
				'category'            => 'viagent-content',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_type' => array(
							'type'        => 'string',
							'default'     => 'post',
							'description' => __( 'Post type name, e.g. "post" or "page". See list_post_types.', 'viagent' ),
						),
						'status'    => array(
							'type'        => 'string',
							'enum'        => array( 'any', 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
							'default'     => 'any',
							'description' => __( '"any" returns every status except trash.', 'viagent' ),
						),
						'search'    => array( 'type' => 'string' ),
						'author'    => array(
							'type'        => 'integer',
							'description' => __( 'Author user ID.', 'viagent' ),
						),
						'category'  => array(
							'type'        => 'string',
							'description' => __( 'Category slug (posts only).', 'viagent' ),
						),
						'tag'       => array(
							'type'        => 'string',
							'description' => __( 'Tag slug (posts only).', 'viagent' ),
						),
						'orderby'   => array(
							'type'    => 'string',
							'enum'    => array( 'date', 'modified', 'title', 'menu_order', 'ID' ),
							'default' => 'date',
						),
						'order'     => array(
							'type'    => 'string',
							'enum'    => array( 'asc', 'desc' ),
							'default' => 'desc',
						),
						'per_page'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'page'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( self::class, 'list_posts' ),
				'permission_callback' => static function () {
					return current_user_can( 'read' );
				},
				'meta'                => Abilities::read_meta(),
			)
		);

		wp_register_ability(
			'viagent/get-post',
			array(
				'label'               => __( 'Get post', 'viagent' ),
				'description'         => __( 'Gets one post, page or custom post type item by ID, including its full block content, excerpt, categories/tags/terms, featured image and links.', 'viagent' ),
				'category'            => 'viagent-content',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( self::class, 'get_post' ),
				'permission_callback' => static function ( $input ) {
					return self::can_read( (int) ( $input['id'] ?? 0 ) );
				},
				'meta'                => Abilities::read_meta(),
			)
		);

		self::register_writes();
	}

	/**
	 * Registers write abilities.
	 */
	private static function register_writes() {
		$fields = self::field_schema();

		Abilities::add(
			'create-post',
			array(
				'category'    => 'viagent-content',
				'label'       => __( 'Create post', 'viagent' ),
				'description' => __( 'Creates a post, page or custom post type item. Content should be block markup (<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->). Status defaults to "draft". Terms can be given as names (created if missing) or IDs.', 'viagent' ),
				'input'       => array_merge(
					array(
						'post_type' => array(
							'type'    => 'string',
							'default' => 'post',
						),
					),
					$fields
				),
				'required'    => array( 'title' ),
				'execute'     => array( self::class, 'create_post' ),
				'permission'  => static function ( $input ) {
					$type = get_post_type_object( $input['post_type'] ?? 'post' );
					return $type ? current_user_can( $type->cap->create_posts ) : current_user_can( 'edit_posts' );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);

		Abilities::add(
			'update-post',
			array(
				'category'    => 'viagent-content',
				'label'       => __( 'Update post', 'viagent' ),
				'description' => __( 'Updates a post, page or custom post type item. Only the fields you pass are changed. To edit content, first read it with get_post, then send the full new block markup. The previous version is kept so the site owner can undo the change.', 'viagent' ),
				'input'       => array_merge(
					array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					$fields
				),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'update_post' ),
				'permission'  => static function ( $input ) {
					return self::can( 'edit_post', (int) ( $input['id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);

		Abilities::add(
			'delete-post',
			array(
				'category'    => 'viagent-content',
				'label'       => __( 'Delete post', 'viagent' ),
				'description' => __( 'Moves a post, page or custom post type item to the trash (it can be restored with restore_post). Permanent deletion with force=true only works if the site owner allowed it.', 'viagent' ),
				'input'       => array(
					'id'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Skip the trash and delete permanently.', 'viagent' ),
					),
				),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'delete_post' ),
				'permission'  => static function ( $input ) {
					return self::can( 'delete_post', (int) ( $input['id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'destructive' => true ) ),
			)
		);

		Abilities::add(
			'restore-post',
			array(
				'category'    => 'viagent-content',
				'label'       => __( 'Restore post from trash', 'viagent' ),
				'description' => __( 'Restores a trashed post, page or custom post type item. It comes back as a draft.', 'viagent' ),
				'input'       => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'restore_post' ),
				'permission'  => static function ( $input ) {
					return self::can( 'delete_post', (int) ( $input['id'] ?? 0 ) );
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

		Abilities::add(
			'list-revisions',
			array(
				'category'    => 'viagent-content',
				'label'       => __( 'List revisions', 'viagent' ),
				'description' => __( 'Lists saved revisions (earlier versions) of a post or page, newest first.', 'viagent' ),
				'input'       => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'list_revisions' ),
				'permission'  => static function ( $input ) {
					return self::can( 'edit_post', (int) ( $input['id'] ?? 0 ) );
				},
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'restore-revision',
			array(
				'category'    => 'viagent-content',
				'label'       => __( 'Restore revision', 'viagent' ),
				'description' => __( 'Rolls a post or page back to an earlier revision (see list_revisions).', 'viagent' ),
				'input'       => array(
					'revision_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'    => array( 'revision_id' ),
				'execute'     => array( self::class, 'restore_revision' ),
				'permission'  => static function ( $input ) {
					$revision = wp_get_post_revision( (int) ( $input['revision_id'] ?? 0 ) );
					return $revision ? current_user_can( 'edit_post', $revision->post_parent ) : current_user_can( 'edit_posts' );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);
	}

	/**
	 * Input schema for editable post fields shared by create and update.
	 *
	 * @return array
	 */
	private static function field_schema() {
		return array(
			'title'             => array( 'type' => 'string' ),
			'content'           => array(
				'type'        => 'string',
				'description' => __( 'Block markup.', 'viagent' ),
			),
			'excerpt'           => array( 'type' => 'string' ),
			'status'            => array(
				'type'        => 'string',
				'enum'        => array( 'draft', 'pending', 'publish', 'future', 'private' ),
				'description' => __( 'Use "future" with a date to schedule.', 'viagent' ),
			),
			'slug'              => array( 'type' => 'string' ),
			'date'              => array(
				'type'        => 'string',
				'description' => __( 'Publish date in site time, "YYYY-MM-DD HH:MM:SS".', 'viagent' ),
			),
			'parent'            => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Parent ID for hierarchical types such as pages.', 'viagent' ),
			),
			'menu_order'        => array( 'type' => 'integer' ),
			'template'          => array(
				'type'        => 'string',
				'description' => __( 'Page template slug; empty string for the default.', 'viagent' ),
			),
			'comment_status'    => array(
				'type' => 'string',
				'enum' => array( 'open', 'closed' ),
			),
			'terms'             => array(
				'type'                 => 'object',
				'description'          => __( 'Terms per taxonomy, replacing existing ones, e.g. {"category": ["News"], "post_tag": ["ai", "wordpress"]}.', 'viagent' ),
				'additionalProperties' => array(
					'type'  => 'array',
					'items' => array( 'type' => array( 'string', 'integer' ) ),
				),
			),
			'featured_image_id' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Media library ID; 0 removes the featured image.', 'viagent' ),
			),
		);
	}

	/**
	 * Capability check on a post; missing posts pass so the tool can say "not found".
	 *
	 * @param string $capability Meta capability, e.g. "edit_post".
	 * @param int    $id         Post ID.
	 * @return bool
	 */
	private static function can( $capability, $id ) {
		$post = self::find( $id );
		return is_wp_error( $post ) ? current_user_can( 'edit_posts' ) : current_user_can( $capability, $post->ID );
	}

	/**
	 * Checks the requested status against draft-only mode and publishing rights.
	 *
	 * @param string        $status    Status.
	 * @param \WP_Post_Type $type      Post type.
	 * @return true|WP_Error
	 */
	private static function check_status( $status, $type ) {
		$allowed = Policy::check_status( $status );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && ! current_user_can( $type->cap->publish_posts ) ) {
			return new WP_Error( 'viagent_cannot_publish', __( 'You are not allowed to publish this type of content. Save it as "pending" for review instead.', 'viagent' ) );
		}
		return true;
	}

	/**
	 * Maps tool input to wp_insert_post() fields.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	private static function post_data( array $input ) {
		$map  = array(
			'title'          => 'post_title',
			'content'        => 'post_content',
			'excerpt'        => 'post_excerpt',
			'status'         => 'post_status',
			'slug'           => 'post_name',
			'parent'         => 'post_parent',
			'menu_order'     => 'menu_order',
			'template'       => 'page_template',
			'comment_status' => 'comment_status',
		);
		$data = array();
		foreach ( $map as $from => $to ) {
			if ( array_key_exists( $from, $input ) ) {
				$data[ $to ] = $input[ $from ];
			}
		}
		if ( ! empty( $input['date'] ) ) {
			$data['post_date']     = $input['date'];
			$data['post_date_gmt'] = get_gmt_from_date( $input['date'] );
			$data['edit_date']     = true;
		}
		return $data;
	}

	/**
	 * Sets terms (names, slugs or IDs) and the featured image after a save.
	 *
	 * @param WP_Post $post  Post.
	 * @param array   $input Input.
	 * @return true|WP_Error
	 */
	private static function apply_relations( WP_Post $post, array $input ) {
		foreach ( (array) ( $input['terms'] ?? array() ) as $taxonomy => $values ) {
			$result = Taxonomies::set_post_terms( $post, $taxonomy, (array) $values, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( isset( $input['featured_image_id'] ) ) {
			$image_id = (int) $input['featured_image_id'];
			if ( 0 === $image_id ) {
				delete_post_thumbnail( $post );
			} elseif ( ! wp_attachment_is_image( $image_id ) ) {
				return new WP_Error( 'viagent_invalid_image', __( 'featured_image_id is not an image in the media library.', 'viagent' ) );
			} else {
				set_post_thumbnail( $post, $image_id );
			}
		}
		return true;
	}

	/**
	 * Creates a post.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function create_post( $input ) {
		$post_type = $input['post_type'] ?? 'post';
		$types     = self::post_types();
		if ( ! isset( $types[ $post_type ] ) ) {
			return new WP_Error( 'viagent_invalid_post_type', sprintf( /* translators: %s: post type */ __( 'Unknown post type "%s". Use list_post_types to see available types.', 'viagent' ), $post_type ) );
		}

		$input['status'] = $input['status'] ?? 'draft';
		$allowed         = self::check_status( $input['status'], $types[ $post_type ] );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$data = array_merge(
			self::post_data( $input ),
			array(
				'post_type'   => $post_type,
				'post_author' => get_current_user_id(),
			)
		);

		$id = wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		Activity_Log::set_object( 'post', $id );
		Activity_Log::set_undo(
			array(
				'action'  => 'trash_post',
				'post_id' => $id,
			)
		);

		$post     = get_post( $id );
		$relation = self::apply_relations( $post, $input );

		return self::saved_response( $post, $relation );
	}

	/**
	 * Updates a post.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_post( $input ) {
		$post = self::find( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$editable = Policy::check_post_editable( $post );
		if ( is_wp_error( $editable ) ) {
			return $editable;
		}
		if ( isset( $input['status'] ) && $input['status'] !== $post->post_status ) {
			$allowed = self::check_status( $input['status'], get_post_type_object( $post->post_type ) );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		$data = self::post_data( $input );
		if ( empty( $data ) && ! isset( $input['terms'] ) && ! isset( $input['featured_image_id'] ) ) {
			return new WP_Error( 'viagent_nothing_to_update', __( 'Pass at least one field to change.', 'viagent' ) );
		}

		Activity_Log::set_object( 'post', $post->ID );
		Activity_Log::set_undo( self::snapshot( $post, $input ) );

		if ( $data ) {
			$data['ID'] = $post->ID;
			$result     = wp_update_post( wp_slash( $data ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$post     = get_post( $post->ID );
		$relation = self::apply_relations( $post, $input );

		return self::saved_response( $post, $relation );
	}

	/**
	 * Undo instructions that restore a post to its current state.
	 *
	 * @param WP_Post $post  Post before the change.
	 * @param array   $input Change being made (decides which relations to snapshot).
	 * @return array
	 */
	private static function snapshot( WP_Post $post, array $input = array() ) {
		$undo = array(
			'action'  => 'restore_post_fields',
			'post_id' => $post->ID,
			'fields'  => array(
				'post_title'     => $post->post_title,
				'post_content'   => $post->post_content,
				'post_excerpt'   => $post->post_excerpt,
				'post_status'    => $post->post_status,
				'post_name'      => $post->post_name,
				'post_parent'    => $post->post_parent,
				'menu_order'     => $post->menu_order,
				'comment_status' => $post->comment_status,
				'post_date'      => $post->post_date,
				'post_date_gmt'  => $post->post_date_gmt,
				'edit_date'      => true,
			),
		);

		if ( isset( $input['terms'] ) ) {
			$undo['terms'] = array();
			foreach ( array_keys( (array) $input['terms'] ) as $taxonomy ) {
				$ids                        = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
				$undo['terms'][ $taxonomy ] = is_wp_error( $ids ) ? array() : $ids;
			}
		}
		if ( isset( $input['featured_image_id'] ) ) {
			$undo['thumbnail_id'] = (int) get_post_thumbnail_id( $post );
		}

		return $undo;
	}

	/**
	 * Response after saving a post; relation errors are reported but the save stands.
	 *
	 * @param WP_Post       $post     Saved post.
	 * @param true|WP_Error $relation Result of applying terms/featured image.
	 * @return array
	 */
	private static function saved_response( WP_Post $post, $relation ) {
		$response = self::summary( $post );
		if ( 'publish' !== $post->post_status ) {
			$response['preview_link'] = get_preview_post_link( $post );
		}
		if ( is_wp_error( $relation ) ) {
			$response['warning'] = $relation->get_error_message();
		}
		return $response;
	}

	/**
	 * Trashes or permanently deletes a post.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function delete_post( $input ) {
		$post = self::find( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		Activity_Log::set_object( 'post', $post->ID );

		if ( ! empty( $input['force'] ) ) {
			if ( ! Policy::allows_permanent_delete() ) {
				return new WP_Error( 'viagent_permanent_delete_disabled', __( 'Permanent deletion is turned off on this site. Move the item to the trash instead (omit force).', 'viagent' ) );
			}
			$title = $post->post_title;
			if ( ! wp_delete_post( $post->ID, true ) ) {
				return new WP_Error( 'viagent_delete_failed', __( 'Could not delete the item.', 'viagent' ) );
			}
			return array(
				'id'      => $post->ID,
				'title'   => $title,
				'deleted' => true,
			);
		}

		if ( 'trash' === $post->post_status ) {
			return new WP_Error( 'viagent_already_trashed', __( 'This item is already in the trash.', 'viagent' ) );
		}
		if ( ! wp_trash_post( $post->ID ) ) {
			return new WP_Error( 'viagent_delete_failed', __( 'Could not move the item to the trash.', 'viagent' ) );
		}

		Activity_Log::set_undo(
			array(
				'action'  => 'untrash_post',
				'post_id' => $post->ID,
			)
		);

		return array(
			'id'      => $post->ID,
			'title'   => $post->post_title,
			'trashed' => true,
		);
	}

	/**
	 * Restores a post from the trash.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function restore_post( $input ) {
		$post = self::find( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( 'trash' !== $post->post_status ) {
			return new WP_Error( 'viagent_not_trashed', __( 'This item is not in the trash.', 'viagent' ) );
		}
		if ( ! wp_untrash_post( $post->ID ) ) {
			return new WP_Error( 'viagent_restore_failed', __( 'Could not restore the item.', 'viagent' ) );
		}

		Activity_Log::set_object( 'post', $post->ID );
		Activity_Log::set_undo(
			array(
				'action'  => 'trash_post',
				'post_id' => $post->ID,
			)
		);

		return self::summary( get_post( $post->ID ) );
	}

	/**
	 * Lists revisions of a post.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function list_revisions( $input ) {
		$post = self::find( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$items = array();
		foreach ( wp_get_post_revisions( $post->ID, array( 'posts_per_page' => 50 ) ) as $revision ) {
			$items[] = array(
				'revision_id' => $revision->ID,
				'date'        => $revision->post_modified,
				'author'      => get_the_author_meta( 'display_name', (int) $revision->post_author ),
				'title'       => $revision->post_title,
				'preview'     => wp_trim_words( wp_strip_all_tags( $revision->post_content ), 30 ),
			);
		}
		return $items;
	}

	/**
	 * Restores a revision.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function restore_revision( $input ) {
		$revision = wp_get_post_revision( (int) $input['revision_id'] );
		$post     = $revision ? self::find( $revision->post_parent ) : null;
		if ( ! $revision || is_wp_error( $post ) ) {
			return new WP_Error( 'viagent_not_found', __( 'No revision found with that ID.', 'viagent' ) );
		}

		$editable = Policy::check_post_editable( $post );
		if ( is_wp_error( $editable ) ) {
			return $editable;
		}

		Activity_Log::set_object( 'post', $post->ID );
		Activity_Log::set_undo( self::snapshot( $post ) );

		if ( ! wp_restore_post_revision( $revision->ID ) ) {
			return new WP_Error( 'viagent_restore_failed', __( 'Could not restore the revision.', 'viagent' ) );
		}
		return self::summary( get_post( $post->ID ) );
	}

	/**
	 * Post types the AI may work with: public ones plus any shown in the admin UI,
	 * excluding attachments (handled by media tools).
	 *
	 * @return \WP_Post_Type[]
	 */
	public static function post_types() {
		$types = get_post_types( array( 'show_ui' => true ), 'objects' );
		unset( $types['attachment'], $types['wp_block'], $types['wp_template'], $types['wp_template_part'], $types['wp_navigation'], $types['wp_font_family'], $types['wp_font_face'], $types['wp_global_styles'] );

		/**
		 * Filters post types exposed to AI agents.
		 *
		 * @param \WP_Post_Type[] $types Post type objects keyed by name.
		 */
		return apply_filters( 'viagent_post_types', $types );
	}

	/**
	 * Exposed post types the current user may browse: publicly viewable types,
	 * plus non-public types only for users who can edit them.
	 *
	 * @return \WP_Post_Type[] Post type objects keyed by name.
	 */
	public static function readable_post_types() {
		return array_filter(
			self::post_types(),
			static function ( $type ) {
				return is_post_type_viewable( $type ) || current_user_can( $type->cap->edit_posts );
			}
		);
	}

	/**
	 * Statuses the current user may list for a post type.
	 *
	 * @param string $post_type Post type. Empty for "any exposed type".
	 * @return string[]
	 */
	public static function readable_statuses( $post_type = '' ) {
		$type = $post_type ? get_post_type_object( $post_type ) : null;
		$can  = $type ? current_user_can( $type->cap->edit_posts ) : current_user_can( 'edit_posts' );
		return $can ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : array( 'publish' );
	}

	/**
	 * Whether the current user may read a post. Missing posts pass so that the
	 * execute callback can report a helpful "not found" error.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function can_read( $id ) {
		$post = self::find( $id );
		if ( is_wp_error( $post ) ) {
			return current_user_can( 'read' );
		}
		if ( 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) ) {
			return current_user_can( 'read' );
		}
		return current_user_can( 'read_post', $post->ID ) && current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Gets a post of an exposed post type.
	 *
	 * @param int $id Post ID.
	 * @return WP_Post|WP_Error
	 */
	public static function find( $id ) {
		$post = get_post( $id );
		if ( ! $post || ! isset( self::post_types()[ $post->post_type ] ) ) {
			return new WP_Error( 'viagent_not_found', __( 'No post found with that ID.', 'viagent' ) );
		}
		return $post;
	}

	/**
	 * Lists post types.
	 *
	 * @return array
	 */
	public static function list_post_types() {
		$result = array();
		foreach ( self::readable_post_types() as $type ) {
			$result[] = array(
				'name'         => $type->name,
				'label'        => $type->label,
				'description'  => $type->description,
				'hierarchical' => (bool) $type->hierarchical,
				'public'       => (bool) $type->public,
				'block_editor' => use_block_editor_for_post_type( $type->name ),
				'taxonomies'   => array_values( get_object_taxonomies( $type->name ) ),
				'can_create'   => current_user_can( $type->cap->create_posts ),
			);
		}
		return $result;
	}

	/**
	 * Lists posts.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function list_posts( $input ) {
		$post_type = $input['post_type'] ?? 'post';
		if ( ! isset( self::readable_post_types()[ $post_type ] ) ) {
			return new WP_Error( 'viagent_invalid_post_type', sprintf( /* translators: %s: post type */ __( 'Unknown post type "%s". Use list_post_types to see available types.', 'viagent' ), $post_type ) );
		}

		$allowed = self::readable_statuses( $post_type );
		$status  = $input['status'] ?? 'any';
		if ( 'trash' === $status ) {
			$allowed[] = 'trash';
		}
		if ( 'any' !== $status && ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'viagent_forbidden_status', __( 'You are not allowed to list items with that status.', 'viagent' ) );
		}

		$args = array(
			'post_type'           => $post_type,
			'post_status'         => 'any' === $status ? self::readable_statuses( $post_type ) : $status,
			'posts_per_page'      => (int) ( $input['per_page'] ?? 20 ),
			'paged'               => (int) ( $input['page'] ?? 1 ),
			'orderby'             => $input['orderby'] ?? 'date',
			'order'               => strtoupper( $input['order'] ?? 'desc' ),
			'ignore_sticky_posts' => true,
			'perm'                => 'readable',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = $input['search'];
		}
		if ( ! empty( $input['author'] ) ) {
			$args['author'] = (int) $input['author'];
		}
		if ( ! empty( $input['category'] ) ) {
			$args['category_name'] = sanitize_title( $input['category'] );
		}
		if ( ! empty( $input['tag'] ) ) {
			$args['tag'] = sanitize_title( $input['tag'] );
		}

		$query = new \WP_Query( $args );

		return array(
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $args['paged'],
			'items'       => array_map( array( self::class, 'summary' ), $query->posts ),
		);
	}

	/**
	 * Gets one post with full content.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_post( $input ) {
		$post = self::find( (int) $input['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->show_ui ) {
				continue;
			}
			$assigned = get_the_terms( $post, $taxonomy->name );
			if ( is_array( $assigned ) ) {
				$terms[ $taxonomy->name ] = array_map(
					static function ( $term ) {
						return array(
							'id'   => $term->term_id,
							'name' => $term->name,
							'slug' => $term->slug,
						);
					},
					$assigned
				);
			}
		}

		$thumbnail_id = (int) get_post_thumbnail_id( $post );

		return array_merge(
			self::summary( $post ),
			array(
				'content'        => $post->post_content,
				'excerpt'        => $post->post_excerpt,
				'parent'         => (int) $post->post_parent,
				'menu_order'     => (int) $post->menu_order,
				'template'       => get_page_template_slug( $post ),
				'comment_status' => $post->comment_status,
				'terms'          => $terms,
				'featured_image' => $thumbnail_id ? array(
					'id'  => $thumbnail_id,
					'url' => wp_get_attachment_url( $thumbnail_id ),
				) : null,
			)
		);
	}

	/**
	 * Compact representation of a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function summary( WP_Post $post ) {
		return array(
			'id'        => $post->ID,
			'type'      => $post->post_type,
			'title'     => $post->post_title,
			'status'    => $post->post_status,
			'slug'      => $post->post_name,
			'author'    => get_the_author_meta( 'display_name', (int) $post->post_author ),
			'date'      => $post->post_date,
			'modified'  => $post->post_modified,
			'link'      => get_permalink( $post ),
			'edit_link' => current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID, 'raw' ) : null,
		);
	}
}
