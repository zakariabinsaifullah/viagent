<?php
/**
 * SEO tools for Yoast SEO and Rank Math: read and write titles, meta
 * descriptions, focus keywords, canonical URLs and indexing, and find
 * content with missing SEO data.
 *
 * @package Viagent
 */

namespace Viagent\Integrations;

use Viagent\Abilities\Abilities;
use Viagent\Abilities\Content;
use Viagent\Log\Activity_Log;
use Viagent\Security\Policy;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * SEO integration.
 */
class SEO {

	const CATEGORY = 'viagent-seo';

	/**
	 * Post meta keys per provider for each SEO field.
	 */
	const KEYS = array(
		'yoast'    => array(
			'title'          => '_yoast_wpseo_title',
			'description'    => '_yoast_wpseo_metadesc',
			'focus_keyword'  => '_yoast_wpseo_focuskw',
			'canonical'      => '_yoast_wpseo_canonical',
			'noindex'        => '_yoast_wpseo_meta-robots-noindex',
			'social_title'   => '_yoast_wpseo_opengraph-title',
			'social_summary' => '_yoast_wpseo_opengraph-description',
		),
		'rankmath' => array(
			'title'          => 'rank_math_title',
			'description'    => 'rank_math_description',
			'focus_keyword'  => 'rank_math_focus_keyword',
			'canonical'      => 'rank_math_canonical_url',
			'noindex'        => 'rank_math_robots',
			'social_title'   => 'rank_math_facebook_title',
			'social_summary' => 'rank_math_facebook_description',
		),
	);

	/**
	 * Active SEO plugin: "yoast", "rankmath" or null.
	 *
	 * @return string|null
	 */
	public static function provider() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		return null;
	}

	/**
	 * Provider display name.
	 *
	 * @return string
	 */
	private static function provider_name() {
		return 'yoast' === self::provider() ? 'Yoast SEO' : 'Rank Math';
	}

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_filter( 'viagent_tool_summaries', array( self::class, 'summaries' ) );
		add_filter( 'viagent_instructions', array( self::class, 'instructions' ) );
	}

	/**
	 * Registers the ability category.
	 */
	public static function register_categories() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'SEO', 'viagent' ),
				/* translators: %s: SEO plugin name */
				'description' => sprintf( __( 'Search titles and descriptions via %s.', 'viagent' ), self::provider_name() ),
			)
		);
	}

	/**
	 * Tells AI apps about the SEO tools.
	 *
	 * @param string[] $lines Instruction lines.
	 * @return string[]
	 */
	public static function instructions( $lines ) {
		$lines[] = sprintf( 'SEO is managed by %s: use get_seo and update_seo for search titles and meta descriptions (keep descriptions under 155 characters), and find_seo_issues to spot missing data.', self::provider_name() );
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
			'get-seo',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Get SEO settings', 'viagent' ),
				'description' => __( 'Gets the search title, meta description, focus keyword, canonical URL, indexing and social sharing text of a post, page or product.', 'viagent' ),
				'input'       => array( 'post_id' => $post_id ),
				'required'    => array( 'post_id' ),
				'execute'     => array( self::class, 'get_seo' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) ) || ! get_post( (int) ( $input['post_id'] ?? 0 ) );
				},
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'update-seo',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Update SEO settings', 'viagent' ),
				'description' => __( 'Sets the search title, meta description (ideally 120–155 characters), focus keyword, canonical URL, noindex or social sharing text. Only the fields you pass change; pass an empty string to clear one.', 'viagent' ),
				'input'       => array(
					'post_id'        => $post_id,
					'title'          => array(
						'type'        => 'string',
						'description' => __( 'Search result title. Yoast and Rank Math variables such as %%sitename%% are allowed.', 'viagent' ),
					),
					'description'    => array( 'type' => 'string' ),
					'focus_keyword'  => array( 'type' => 'string' ),
					'canonical'      => array( 'type' => 'string' ),
					'noindex'        => array(
						'type'        => 'boolean',
						'description' => __( 'true hides the page from search engines.', 'viagent' ),
					),
					'social_title'   => array( 'type' => 'string' ),
					'social_summary' => array( 'type' => 'string' ),
				),
				'required'    => array( 'post_id' ),
				'execute'     => array( self::class, 'update_seo' ),
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
			'find-seo-issues',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Find SEO issues', 'viagent' ),
				'description' => __( 'Finds published content with a missing meta description, focus keyword or custom search title, or that is hidden from search engines.', 'viagent' ),
				'input'       => array(
					'post_type' => array(
						'type'    => 'string',
						'default' => 'any',
					),
					'limit'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
						'default' => 50,
					),
				),
				'execute'     => array( self::class, 'find_issues' ),
				'permission'  => 'edit_posts',
				'meta'        => Abilities::read_meta(),
			)
		);
	}

	/**
	 * Finds a post of a public type.
	 *
	 * @param int $id Post ID.
	 * @return WP_Post|WP_Error
	 */
	private static function find( $id ) {
		$post = get_post( $id );
		if ( ! $post || ! is_post_type_viewable( $post->post_type ) ) {
			return new WP_Error( 'viagent_not_found', __( 'No public post, page or product found with that ID.', 'viagent' ) );
		}
		return $post;
	}

	/**
	 * Reads the SEO fields of a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private static function read( WP_Post $post ) {
		$values = array();
		foreach ( self::KEYS[ self::provider() ] as $field => $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( 'noindex' === $field ) {
				$value = 'yoast' === self::provider() ? '1' === (string) $value : in_array( 'noindex', (array) $value, true );
			}
			$values[ $field ] = $value;
		}
		return $values;
	}

	/**
	 * Gets SEO data.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_seo( $input ) {
		$post = self::find( (int) $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return array_merge(
			array(
				'post_id'  => $post->ID,
				'post'     => $post->post_title,
				'provider' => self::provider_name(),
				'link'     => get_permalink( $post ),
			),
			self::read( $post ),
			array( 'description_length' => mb_strlen( (string) get_post_meta( $post->ID, self::KEYS[ self::provider() ]['description'], true ) ) )
		);
	}

	/**
	 * Updates SEO data.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_seo( $input ) {
		$post = self::find( (int) $input['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$editable = Policy::check_post_editable( $post );
		if ( is_wp_error( $editable ) ) {
			return $editable;
		}

		$keys     = self::KEYS[ self::provider() ];
		$previous = array();
		$changed  = array();

		foreach ( $keys as $field => $key ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$previous[ $key ] = metadata_exists( 'post', $post->ID, $key ) ? get_post_meta( $post->ID, $key, true ) : null;
			$value            = self::to_meta( $field, $input[ $field ], $post->ID );

			if ( '' === $value || array() === $value ) {
				delete_post_meta( $post->ID, $key );
			} else {
				update_post_meta( $post->ID, $key, wp_slash( $value ) );
			}
			$changed[] = $field;
		}

		if ( empty( $changed ) ) {
			return new WP_Error( 'viagent_nothing_to_update', __( 'Pass at least one SEO field to change.', 'viagent' ) );
		}

		self::refresh_cache( $post->ID );

		Activity_Log::set_object( 'post', $post->ID );
		Activity_Log::set_undo(
			array(
				'action'  => 'restore_metas',
				'post_id' => $post->ID,
				'values'  => $previous,
			)
		);

		return array_merge(
			array(
				'post_id' => $post->ID,
				'updated' => $changed,
			),
			self::read( get_post( $post->ID ) )
		);
	}

	/**
	 * Makes the SEO plugin rebuild its cached data (Yoast "indexables") for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function refresh_cache( $post_id ) {
		clean_post_cache( $post_id );
		if ( 'yoast' === self::provider() && function_exists( 'YoastSEO' ) ) {
			try {
				$repository = YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class );
				$builder    = YoastSEO()->classes->get( \Yoast\WP\SEO\Builders\Indexable_Builder::class );
				$indexable  = $repository->find_by_id_and_type( $post_id, 'post', false );
				$builder->build_for_id_and_type( $post_id, 'post', $indexable ? $indexable : false );
			} catch ( \Throwable $e ) {
				// Yoast rebuilds the indexable itself on the next save; nothing else to do.
				unset( $e );
			}
		}
	}

	/**
	 * Converts a field value to the provider's storage format.
	 *
	 * @param string $field   Field.
	 * @param mixed  $value   Value.
	 * @param int    $post_id Post ID.
	 * @return mixed
	 */
	private static function to_meta( $field, $value, $post_id ) {
		if ( 'noindex' === $field ) {
			if ( 'yoast' === self::provider() ) {
				return $value ? '1' : '';
			}
			$robots = array_diff( (array) get_post_meta( $post_id, 'rank_math_robots', true ), array( 'noindex', 'index', '' ) );
			array_unshift( $robots, $value ? 'noindex' : 'index' );
			return array_values( $robots );
		}
		if ( 'canonical' === $field ) {
			return esc_url_raw( (string) $value );
		}
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Finds content with missing SEO data.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function find_issues( $input ) {
		$types = 'any' === ( $input['post_type'] ?? 'any' )
			? array_values( array_filter( array_merge( array_keys( Content::post_types() ), array( 'product' ) ), 'is_post_type_viewable' ) )
			: array( $input['post_type'] );

		foreach ( $types as $type ) {
			$object = get_post_type_object( $type );
			if ( ! $object || ! ( is_post_type_viewable( $object ) || current_user_can( $object->cap->edit_posts ) ) ) {
				/* translators: %s: post type */
				return new WP_Error( 'viagent_invalid_post_type', sprintf( __( 'Unknown post type "%s".', 'viagent' ), $type ) );
			}
		}

		$posts = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => (int) ( $input['limit'] ?? 50 ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			$seo    = self::read( $post );
			$issues = array();
			if ( '' === (string) $seo['description'] ) {
				$issues[] = 'missing meta description';
			} elseif ( mb_strlen( $seo['description'] ) > 160 ) {
				$issues[] = 'meta description too long';
			}
			if ( '' === (string) $seo['focus_keyword'] ) {
				$issues[] = 'no focus keyword';
			}
			if ( '' === (string) $seo['title'] ) {
				$issues[] = 'default search title';
			}
			if ( $seo['noindex'] ) {
				$issues[] = 'hidden from search engines';
			}
			if ( $issues ) {
				$items[] = array(
					'post_id' => $post->ID,
					'type'    => $post->post_type,
					'title'   => $post->post_title,
					'issues'  => $issues,
					'link'    => get_permalink( $post ),
				);
			}
		}

		return array(
			'checked'    => count( $posts ),
			'with_issue' => count( $items ),
			'items'      => $items,
		);
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
				'get_seo'         => __( 'Read the search title and description of a page.', 'viagent' ),
				'update_seo'      => __( 'Write search titles, descriptions and focus keywords.', 'viagent' ),
				'find_seo_issues' => __( 'Find pages with missing or weak SEO data.', 'viagent' ),
			)
		);
	}
}
