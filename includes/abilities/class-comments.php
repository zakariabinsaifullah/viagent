<?php
/**
 * Comment abilities: list, reply and moderate.
 *
 * @package MCPAI
 */

namespace MCPAI\Abilities;

use MCPAI\Log\Activity_Log;
use MCPAI\Security\Policy;
use WP_Comment;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Comment abilities.
 */
class Comments {

	/**
	 * Registers abilities.
	 */
	public static function register() {
		$id = array(
			'type'    => 'integer',
			'minimum' => 1,
		);

		Abilities::add(
			'list-comments',
			array(
				'category'    => 'mcpai-comments',
				'label'       => __( 'List comments', 'mcpai' ),
				'description' => __( 'Lists comments, newest first. Filter by post and status ("hold" = waiting for approval).', 'mcpai' ),
				'input'       => array_merge(
					array(
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'status'  => array(
							'type'    => 'string',
							'enum'    => array( 'all', 'approve', 'hold', 'spam', 'trash' ),
							'default' => 'all',
						),
						'search'  => array( 'type' => 'string' ),
					),
					Abilities::paging()
				),
				'execute'     => array( self::class, 'list_comments' ),
				'permission'  => 'moderate_comments',
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'reply-to-comment',
			array(
				'category'    => 'mcpai-comments',
				'label'       => __( 'Reply to comment', 'mcpai' ),
				'description' => __( 'Publishes a reply to a comment as the connected user. The reply is public immediately.', 'mcpai' ),
				'input'       => array(
					'comment_id' => $id,
					'content'    => array(
						'type'      => 'string',
						'minLength' => 1,
					),
				),
				'required'    => array( 'comment_id', 'content' ),
				'execute'     => array( self::class, 'reply' ),
				'permission'  => 'moderate_comments',
				'meta'        => Abilities::write_meta(),
			)
		);

		Abilities::add(
			'moderate-comment',
			array(
				'category'    => 'mcpai-comments',
				'label'       => __( 'Moderate comment', 'mcpai' ),
				'description' => __( 'Approves, unapproves, marks as spam or trashes a comment.', 'mcpai' ),
				'input'       => array(
					'comment_id' => $id,
					'action'     => array(
						'type' => 'string',
						'enum' => array( 'approve', 'hold', 'spam', 'trash' ),
					),
				),
				'required'    => array( 'comment_id', 'action' ),
				'execute'     => array( self::class, 'moderate' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'edit_comment', (int) ( $input['comment_id'] ?? 0 ) ) || ! get_comment( (int) ( $input['comment_id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'idempotent' => true ) ),
			)
		);
	}

	/**
	 * Compact representation of a comment.
	 *
	 * @param WP_Comment $comment Comment.
	 * @return array
	 */
	public static function item( WP_Comment $comment ) {
		return array(
			'id'         => (int) $comment->comment_ID,
			'post_id'    => (int) $comment->comment_post_ID,
			'post_title' => get_the_title( (int) $comment->comment_post_ID ),
			'parent'     => (int) $comment->comment_parent,
			'author'     => $comment->comment_author,
			'date'       => $comment->comment_date,
			'status'     => wp_get_comment_status( $comment ),
			'content'    => $comment->comment_content,
			'link'       => get_comment_link( $comment ),
		);
	}

	/**
	 * Lists comments.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_comments( $input ) {
		$per_page = (int) ( $input['per_page'] ?? 20 );
		$args     = array(
			'status' => $input['status'] ?? 'all',
			'number' => $per_page,
			'offset' => ( (int) ( $input['page'] ?? 1 ) - 1 ) * $per_page,
			'type'   => 'comment',
		);
		if ( ! empty( $input['post_id'] ) ) {
			$args['post_id'] = (int) $input['post_id'];
		}
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = $input['search'];
		}

		$comments = get_comments( $args );
		$total    = get_comments(
			array_merge(
				$args,
				array(
					'count'  => true,
					'number' => 0,
					'offset' => 0,
				)
			)
		);

		return array(
			'total' => (int) $total,
			'items' => array_map( array( self::class, 'item' ), $comments ),
		);
	}

	/**
	 * Replies to a comment.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function reply( $input ) {
		$parent = get_comment( (int) $input['comment_id'] );
		if ( ! $parent ) {
			return new WP_Error( 'mcpai_not_found', __( 'No comment found with that ID.', 'mcpai' ) );
		}

		$user = wp_get_current_user();
		$id   = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID'      => (int) $parent->comment_post_ID,
					'comment_parent'       => (int) $parent->comment_ID,
					'comment_content'      => current_user_can( 'unfiltered_html' ) ? $input['content'] : wp_kses_post( $input['content'] ),
					'user_id'              => $user->ID,
					'comment_author'       => $user->display_name,
					'comment_author_email' => $user->user_email,
					'comment_author_url'   => $user->user_url,
					'comment_approved'     => 1,
				)
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'mcpai_comment_failed', __( 'Could not save the reply.', 'mcpai' ) );
		}

		Activity_Log::set_object( 'comment', $id );
		return self::item( get_comment( $id ) );
	}

	/**
	 * Moderates a comment.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function moderate( $input ) {
		$comment = get_comment( (int) $input['comment_id'] );
		if ( ! $comment ) {
			return new WP_Error( 'mcpai_not_found', __( 'No comment found with that ID.', 'mcpai' ) );
		}

		$status = array(
			'approve' => 'approve',
			'hold'    => 'hold',
			'spam'    => 'spam',
			'trash'   => 'trash',
		)[ $input['action'] ];

		$result = wp_set_comment_status( $comment, $status, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Activity_Log::set_object( 'comment', (int) $comment->comment_ID );
		return self::item( get_comment( (int) $comment->comment_ID ) );
	}
}
