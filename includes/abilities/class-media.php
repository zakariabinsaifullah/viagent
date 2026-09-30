<?php
/**
 * Media abilities: list, upload, edit and delete files in the media library.
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
 * Media abilities.
 */
class Media {

	const MAX_BASE64_BYTES = 6291456; // 6 MB decoded; the MCP request cap is 8 MB.

	/**
	 * Registers abilities.
	 */
	public static function register() {
		$details = array(
			'title'       => array( 'type' => 'string' ),
			'alt_text'    => array(
				'type'        => 'string',
				'description' => __( 'Describes the image for screen readers and SEO.', 'viagent' ),
			),
			'caption'     => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ),
			'attach_to'   => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Optional post ID to attach the file to.', 'viagent' ),
			),
		);
		$id      = array(
			'type'    => 'integer',
			'minimum' => 1,
		);

		Abilities::add(
			'list-media',
			array(
				'category'    => 'viagent-media',
				'label'       => __( 'List media', 'viagent' ),
				'description' => __( 'Lists files in the media library, newest first, with URLs, sizes and alt text.', 'viagent' ),
				'input'       => array_merge(
					array(
						'search'    => array( 'type' => 'string' ),
						'mime_type' => array(
							'type'        => 'string',
							'description' => __( 'e.g. "image", "image/png", "application/pdf", "video".', 'viagent' ),
						),
					),
					Abilities::paging()
				),
				'execute'     => array( self::class, 'list_media' ),
				'permission'  => 'upload_files',
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'upload-media-from-url',
			array(
				'category'    => 'viagent-media',
				'label'       => __( 'Upload media from URL', 'viagent' ),
				'description' => __( 'Downloads a file from a public http(s) URL into the media library and returns its ID and URL. Use the ID as featured_image_id or in image blocks.', 'viagent' ),
				'input'       => array_merge(
					array(
						'url'      => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'filename' => array(
							'type'        => 'string',
							'description' => __( 'Optional file name including extension.', 'viagent' ),
						),
					),
					$details
				),
				'required'    => array( 'url' ),
				'execute'     => array( self::class, 'upload_from_url' ),
				'permission'  => 'upload_files',
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);

		Abilities::add(
			'upload-media-base64',
			array(
				'category'    => 'viagent-media',
				'label'       => __( 'Upload media (base64)', 'viagent' ),
				'description' => __( 'Uploads a base64-encoded file (max 6 MB) to the media library. Prefer upload_media_from_url when the file is online.', 'viagent' ),
				'input'       => array_merge(
					array(
						'filename' => array(
							'type'        => 'string',
							'description' => __( 'File name including extension, e.g. "hero.png".', 'viagent' ),
						),
						'data'     => array(
							'type'        => 'string',
							'description' => __( 'Base64 file contents (a data: URL prefix is allowed).', 'viagent' ),
						),
					),
					$details
				),
				'required'    => array( 'filename', 'data' ),
				'execute'     => array( self::class, 'upload_base64' ),
				'permission'  => 'upload_files',
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);

		Abilities::add(
			'update-media',
			array(
				'category'    => 'viagent-media',
				'label'       => __( 'Update media details', 'viagent' ),
				'description' => __( 'Updates the title, alt text, caption or description of a media library item.', 'viagent' ),
				'input'       => array_merge( array( 'id' => $id ), array_diff_key( $details, array( 'attach_to' => true ) ) ),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'update_media' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'edit_post', (int) ( $input['id'] ?? 0 ) ) || ! get_post( (int) ( $input['id'] ?? 0 ) );
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
			'set-featured-image',
			array(
				'category'    => 'viagent-media',
				'label'       => __( 'Set featured image', 'viagent' ),
				'description' => __( 'Sets (or with image_id 0, removes) the featured image of a post or page.', 'viagent' ),
				'input'       => array(
					'post_id'  => $id,
					'image_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
				'required'    => array( 'post_id', 'image_id' ),
				'execute'     => array( self::class, 'set_featured_image' ),
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

		Abilities::add(
			'delete-media',
			array(
				'category'    => 'viagent-media',
				'label'       => __( 'Delete media', 'viagent' ),
				'description' => __( 'Permanently deletes a file from the media library (media has no trash). Only works if the site owner allows permanent deletion.', 'viagent' ),
				'input'       => array( 'id' => $id ),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'delete_media' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'delete_post', (int) ( $input['id'] ?? 0 ) ) || ! get_post( (int) ( $input['id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'destructive' => true ) ),
			)
		);
	}

	/**
	 * Compact representation of an attachment.
	 *
	 * @param WP_Post $attachment Attachment.
	 * @return array
	 */
	public static function item( WP_Post $attachment ) {
		$meta = wp_get_attachment_metadata( $attachment->ID );
		return array(
			'id'          => $attachment->ID,
			'title'       => $attachment->post_title,
			'url'         => wp_get_attachment_url( $attachment->ID ),
			'mime_type'   => $attachment->post_mime_type,
			'alt_text'    => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
			'caption'     => $attachment->post_excerpt,
			'description' => $attachment->post_content,
			'width'       => $meta['width'] ?? null,
			'height'      => $meta['height'] ?? null,
			'date'        => $attachment->post_date,
			'attached_to' => (int) $attachment->post_parent,
		);
	}

	/**
	 * Finds an attachment.
	 *
	 * @param int $id ID.
	 * @return WP_Post|WP_Error
	 */
	private static function find( $id ) {
		$attachment = get_post( $id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'viagent_not_found', __( 'No media item found with that ID.', 'viagent' ) );
		}
		return $attachment;
	}

	/**
	 * Lists media.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_media( $input ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
			'paged'          => (int) ( $input['page'] ?? 1 ),
			'perm'           => 'readable',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = $input['search'];
		}
		if ( ! empty( $input['mime_type'] ) ) {
			$args['post_mime_type'] = $input['mime_type'];
		}

		$query = new \WP_Query( $args );
		return array(
			'total' => (int) $query->found_posts,
			'items' => array_map( array( self::class, 'item' ), $query->posts ),
		);
	}

	/**
	 * Uploads from a URL. download_url() uses wp_safe_remote_get(), which refuses
	 * local and private network addresses.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function upload_from_url( $input ) {
		$url = esc_url_raw( $input['url'], array( 'http', 'https' ) );
		if ( ! $url ) {
			return new WP_Error( 'viagent_invalid_url', __( 'Only public http(s) URLs can be downloaded.', 'viagent' ) );
		}

		self::load_admin_includes();

		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$filename = $input['filename'] ?? '';
		if ( '' === $filename ) {
			$filename = wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		}

		return self::sideload( $tmp, $filename, $input );
	}

	/**
	 * Uploads base64 data.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function upload_base64( $input ) {
		$data = preg_replace( '/^data:[^;]+;base64,/', '', $input['data'] );
		$bin  = base64_decode( (string) $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding an upload.
		if ( false === $bin || '' === $bin ) {
			return new WP_Error( 'viagent_invalid_base64', __( 'data is not valid base64.', 'viagent' ) );
		}
		if ( strlen( $bin ) > self::MAX_BASE64_BYTES ) {
			return new WP_Error( 'viagent_too_large', __( 'File is larger than 6 MB. Upload it from a URL instead.', 'viagent' ) );
		}

		self::load_admin_includes();

		$tmp = wp_tempnam( $input['filename'] );
		if ( ! $tmp || false === file_put_contents( $tmp, $bin ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'viagent_write_failed', __( 'Could not write the temporary file.', 'viagent' ) );
		}

		return self::sideload( $tmp, $input['filename'], $input );
	}

	/**
	 * Moves a temporary file into the media library. WordPress checks the real
	 * file type against the allowed upload types.
	 *
	 * @param string $tmp      Temporary file path.
	 * @param string $filename Desired file name.
	 * @param array  $input    Input with optional details.
	 * @return array|WP_Error
	 */
	private static function sideload( $tmp, $filename, array $input ) {
		$filename = sanitize_file_name( $filename );
		$check    = wp_check_filetype_and_ext( $tmp, $filename );
		if ( ! $check['ext'] || ! $check['type'] ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'viagent_invalid_type', __( 'This file type is not allowed. Use an image, PDF, audio, video or another allowed type, with a matching file extension.', 'viagent' ) );
		}

		$post_data = array();
		if ( isset( $input['title'] ) ) {
			$post_data['post_title'] = $input['title'];
		}
		if ( isset( $input['caption'] ) ) {
			$post_data['post_excerpt'] = $input['caption'];
		}
		if ( isset( $input['description'] ) ) {
			$post_data['post_content'] = $input['description'];
		}

		$id = media_handle_sideload(
			array(
				'name'     => $filename,
				'tmp_name' => $tmp,
			),
			(int) ( $input['attach_to'] ?? 0 ),
			null,
			wp_slash( $post_data )
		);

		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}

		if ( isset( $input['alt_text'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $input['alt_text'] ) ) );
		}

		Activity_Log::set_object( 'attachment', $id );
		return self::item( get_post( $id ) );
	}

	/**
	 * Updates media details.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_media( $input ) {
		$attachment = self::find( (int) $input['id'] );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		$data = array();
		$map  = array(
			'title'       => 'post_title',
			'caption'     => 'post_excerpt',
			'description' => 'post_content',
		);
		foreach ( $map as $from => $to ) {
			if ( isset( $input[ $from ] ) ) {
				$data[ $to ] = $input[ $from ];
			}
		}
		if ( $data ) {
			$data['ID'] = $attachment->ID;
			$result     = wp_update_post( wp_slash( $data ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( isset( $input['alt_text'] ) ) {
			update_post_meta( $attachment->ID, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $input['alt_text'] ) ) );
		}

		Activity_Log::set_object( 'attachment', $attachment->ID );
		return self::item( get_post( $attachment->ID ) );
	}

	/**
	 * Sets or removes a featured image.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function set_featured_image( $input ) {
		$post = Content::find( (int) $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$editable = Policy::check_post_editable( $post );
		if ( is_wp_error( $editable ) ) {
			return $editable;
		}

		$image_id = (int) $input['image_id'];
		$previous = (int) get_post_thumbnail_id( $post );

		if ( 0 === $image_id ) {
			delete_post_thumbnail( $post );
		} elseif ( ! wp_attachment_is_image( $image_id ) ) {
			return new WP_Error( 'viagent_invalid_image', __( 'image_id is not an image in the media library.', 'viagent' ) );
		} else {
			set_post_thumbnail( $post, $image_id );
		}

		Activity_Log::set_object( 'post', $post->ID );
		Activity_Log::set_undo(
			array(
				'action'  => 'restore_meta',
				'post_id' => $post->ID,
				'key'     => '_thumbnail_id',
				'value'   => $previous ? (string) $previous : null,
			)
		);

		return array(
			'post_id'        => $post->ID,
			'featured_image' => $image_id ? wp_get_attachment_url( $image_id ) : null,
		);
	}

	/**
	 * Deletes a media item permanently.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function delete_media( $input ) {
		$attachment = self::find( (int) $input['id'] );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		if ( ! Policy::allows_permanent_delete() ) {
			return new WP_Error( 'viagent_permanent_delete_disabled', __( 'Deleting media is permanent and is turned off on this site. Ask the site owner to allow permanent deletion in Viagent.', 'viagent' ) );
		}

		Activity_Log::set_object( 'attachment', $attachment->ID );
		if ( ! wp_delete_attachment( $attachment->ID, true ) ) {
			return new WP_Error( 'viagent_delete_failed', __( 'Could not delete the media item.', 'viagent' ) );
		}
		return array(
			'id'      => $attachment->ID,
			'deleted' => true,
		);
	}

	/**
	 * Loads admin-only media helpers.
	 */
	private static function load_admin_includes() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}
