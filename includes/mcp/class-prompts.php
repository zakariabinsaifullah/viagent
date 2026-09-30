<?php
/**
 * Ready-made tasks (MCP prompts) that AI apps can offer as one-click actions,
 * e.g. in Claude Desktop's "+" menu or as slash commands in Claude Code and
 * VS Code. A prompt is only offered when the connection has the tools it needs.
 *
 * @package Viagent
 */

namespace Viagent\MCP;

use Viagent\Auth\Connection;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Prompts.
 */
class Prompts {

	const OVERRIDES_OPTION = 'viagent_prompt_overrides';

	/**
	 * All prompt definitions.
	 *
	 * Each prompt: title, description, arguments (name => [description, required]),
	 * requires (tool names, all needed) and build (callable returning the text).
	 *
	 * @return array<string,array>
	 */
	public static function definitions() {
		$prompts = array(
			'site_overview'        => array(
				'title'       => __( 'Get to know my site', 'viagent' ),
				'description' => __( 'A friendly overview of your site and ideas for what the AI can help with.', 'viagent' ),
				'arguments'   => array(),
				'requires'    => array( 'get_site_info' ),
				'build'       => static function () {
					return "Look at my WordPress site and give me a friendly, non-technical overview.\n"
						. "1. Use get_site_info to learn the site name, theme and content types, and list_posts to see the most recent content.\n"
						. "2. Summarize what the site is about, how much content it has, and when it was last updated.\n"
						. '3. Suggest three concrete things you could help me with next, based on what you found.';
				},
			),
			'write_blog_post'      => array(
				'title'       => __( 'Write a blog post', 'viagent' ),
				'description' => __( 'Drafts a new blog post in your site’s style, ready for you to review.', 'viagent' ),
				'arguments'   => array(
					'topic'    => array( __( 'What the post should be about.', 'viagent' ), true ),
					'audience' => array( __( 'Who it is for (optional).', 'viagent' ), false ),
					'length'   => array( __( 'Approximate number of words (optional, default 800).', 'viagent' ), false ),
				),
				'requires'    => array( 'create_post', 'list_posts' ),
				'build'       => static function ( $args, $tools ) {
					$length = ! empty( $args['length'] ) ? (int) $args['length'] : 800;
					$text   = "Write a new blog post for my WordPress site about: {$args['topic']}\n";
					if ( ! empty( $args['audience'] ) ) {
						$text .= "Audience: {$args['audience']}\n";
					}
					$text .= "\nSteps:\n"
						. "1. Use get_site_info and list_posts (the 5 latest published posts, read one or two with get_post) to match the site's language, tone and formatting.\n"
						. "2. Write about {$length} words: a clear title, a short intro, subheadings, and a conclusion. Use WordPress block markup (headings, paragraphs, lists).\n"
						. "3. Save it with create_post as a draft, with a one-sentence excerpt and fitting categories and tags (reuse existing ones from list_terms when they fit).\n";
					if ( isset( $tools['update_seo'] ) ) {
						$text .= "4. Set an SEO title and a meta description of 120–155 characters with update_seo.\n";
					}
					$text .= "\nDo not publish. When done, reply with the title, a two-sentence summary and the preview link.";
					return $text;
				},
			),
			'improve_page'         => array(
				'title'       => __( 'Improve a page', 'viagent' ),
				'description' => __( 'Reviews a page and suggests improvements before changing anything.', 'viagent' ),
				'arguments'   => array(
					'page' => array( __( 'Page title or ID.', 'viagent' ), true ),
					'goal' => array( __( 'What you want to improve, e.g. “clearer”, “more sales”, “shorter” (optional).', 'viagent' ), false ),
				),
				'requires'    => array( 'get_post', 'update_post', 'search_content' ),
				'build'       => static function ( $args ) {
					$goal = ! empty( $args['goal'] ) ? $args['goal'] : 'clearer, more engaging and easier to scan';
					return "Help me improve this page on my WordPress site: {$args['page']}\n"
						. "Goal: {$goal}\n\n"
						. "1. Find it with search_content (or get_post if an ID was given) and read it with get_post.\n"
						. "2. Suggest 3–5 specific improvements and show a short before/after example for the most important one.\n"
						. "3. Ask me before changing anything. After I confirm, update it with update_post, keeping the existing block structure and images.\n"
						. '4. Tell me what you changed. Remind me that I can undo it under Viagent → Activity.';
				},
			),
			'seo_audit'            => array(
				'title'       => __( 'SEO check-up', 'viagent' ),
				'description' => __( 'Finds pages with weak or missing SEO and offers to fix them.', 'viagent' ),
				'arguments'   => array(),
				'requires'    => array( 'list_posts', 'get_post' ),
				'build'       => static function ( $args, $tools ) {
					if ( isset( $tools['find_seo_issues'] ) ) {
						$text = "Do an SEO check-up of my WordPress site.\n"
							. "1. Run find_seo_issues.\n"
							. "2. Give me a short, prioritized report in plain language: what's wrong, why it matters, and which pages are affected.\n";
						if ( isset( $tools['update_seo'] ) ) {
							$text .= "3. Offer to write the missing meta descriptions (120–155 characters) and focus keywords. Only change them with update_seo after I confirm.\n";
						}
						return $text;
					}
					return "Do a basic SEO check-up of my WordPress site (no SEO plugin is installed).\n"
						. "1. Use list_posts for posts and pages, and read the most important ones with get_post.\n"
						. "2. Check titles (clear, under 60 characters), excerpts, heading structure and images without alt text (list_media).\n"
						. '3. Give me a prioritized, plain-language report, and suggest installing Yoast SEO or Rank Math if meta descriptions are needed.';
				},
			),
			'fix_missing_alt_text' => array(
				'title'       => __( 'Fix missing image descriptions', 'viagent' ),
				'description' => __( 'Adds alt text to images that have none, for accessibility and SEO.', 'viagent' ),
				'arguments'   => array(),
				'requires'    => array( 'list_media', 'update_media' ),
				'build'       => static function () {
					return "Find images in my media library without alt text and fix them.\n"
						. "1. Use list_media with mime_type \"image\" (page through all results) and collect images whose alt_text is empty.\n"
						. "2. For each, write short, descriptive alt text (under 125 characters) based on its title, file name and caption. Don't start with \"image of\".\n"
						. "3. Show me the list of proposed alt texts first. After I confirm, save them with update_media.\n"
						. '4. Report how many images you updated.';
				},
			),
			'content_ideas'        => array(
				'title'       => __( 'Plan my content', 'viagent' ),
				'description' => __( 'Suggests a content calendar based on what you already publish.', 'viagent' ),
				'arguments'   => array(
					'weeks' => array( __( 'How many weeks to plan (optional, default 4).', 'viagent' ), false ),
				),
				'requires'    => array( 'list_posts' ),
				'build'       => static function ( $args ) {
					$weeks = ! empty( $args['weeks'] ) ? (int) $args['weeks'] : 4;
					return "Plan the next {$weeks} weeks of content for my WordPress site.\n"
						. "1. Look at my recent posts (list_posts) and categories (list_terms) to understand my topics and how often I publish.\n"
						. "2. Suggest a weekly plan: a title, a one-line angle, and a category for each post. Mix in topics I haven't covered yet.\n"
						. '3. Do not create anything. Ask me which ideas I like — I may ask you to draft them.';
				},
			),
			'moderate_comments'    => array(
				'title'       => __( 'Review new comments', 'viagent' ),
				'description' => __( 'Sorts comments waiting for approval and drafts replies.', 'viagent' ),
				'arguments'   => array(),
				'requires'    => array( 'list_comments', 'moderate_comment' ),
				'build'       => static function () {
					return "Help me with comments waiting for approval on my WordPress site.\n"
						. "1. Use list_comments with status \"hold\".\n"
						. "2. Sort them into: likely spam, genuine comments, and questions that deserve a reply. Explain briefly why.\n"
						. "3. Suggest a short, friendly reply for each question.\n"
						. '4. Wait for my confirmation before approving, marking spam or posting replies.';
				},
			),
			'site_health_check'    => array(
				'title'       => __( 'Site health check', 'viagent' ),
				'description' => __( 'A plain-language report on updates, versions and technical health.', 'viagent' ),
				'arguments'   => array(),
				'requires'    => array( 'get_site_health', 'list_plugins' ),
				'build'       => static function () {
					return "Check the technical health of my WordPress site and explain it in plain language.\n"
						. "1. Use get_site_health and list_plugins.\n"
						. "2. Tell me what needs attention (updates, inactive plugins I could remove, debug settings, HTTPS), ordered by importance.\n"
						. '3. Don\'t change anything; tell me what you recommend and why.';
				},
			),
			'store_report'         => array(
				'title'       => __( 'Store report', 'viagent' ),
				'description' => __( 'Sales, orders to handle and stock issues for your WooCommerce store.', 'viagent' ),
				'arguments'   => array(
					'days' => array( __( 'Period in days (optional, default 7).', 'viagent' ), false ),
				),
				'requires'    => array( 'wc_store_overview', 'list_orders', 'list_products' ),
				'build'       => static function ( $args ) {
					$days  = ! empty( $args['days'] ) ? (int) $args['days'] : 7;
					$after = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
					return "Give me a short report on my WooCommerce store for the last {$days} days.\n"
						. "1. Use wc_store_overview, list_orders with after \"{$after}\", and list_products with stock_status \"outofstock\".\n"
						. "2. Report: number of orders and revenue, best-selling products, orders that still need action (processing or on-hold), and products out of stock.\n"
						. '3. Finish with 2–3 practical suggestions. Don\'t change anything.';
				},
			),
			'add_product'          => array(
				'title'       => __( 'Add a product', 'viagent' ),
				'description' => __( 'Creates a draft product from a short description.', 'viagent' ),
				'arguments'   => array(
					'details' => array( __( 'Product name, price and any details or image URL.', 'viagent' ), true ),
				),
				'requires'    => array( 'create_product' ),
				'build'       => static function ( $args, $tools ) {
					$text = "Add this product to my WooCommerce store as a draft: {$args['details']}\n\n"
						. "1. Write an appealing short description (1–2 sentences) and a full description with the key features as a list.\n"
						. "2. Create it with create_product (status draft), with the price and fitting categories (reuse existing ones from list_terms taxonomy \"product_cat\" when possible).\n";
					if ( isset( $tools['upload_media_from_url'] ) ) {
						$text .= "3. If an image URL was given, upload it with upload_media_from_url and use it as image_id.\n";
					}
					return $text . 'Reply with the product name, price and edit link. Do not publish.';
				},
			),
			'form_submissions'     => array(
				'title'       => __( 'Summarize form submissions', 'viagent' ),
				'description' => __( 'Reads recent form entries and summarizes what people are asking for.', 'viagent' ),
				'arguments'   => array(
					'form' => array( __( 'Form name (optional, default: all forms with entries).', 'viagent' ), false ),
				),
				'requires'    => array( 'list_forms', 'list_form_entries' ),
				'build'       => static function ( $args ) {
					$which = ! empty( $args['form'] ) ? "the form \"{$args['form']}\"" : 'every form that has entries';
					return "Summarize recent submissions for {$which} on my WordPress site.\n"
						. "1. Use list_forms to find the form(s), then list_form_entries (latest 50).\n"
						. "2. Group the submissions by topic, count them, and quote a few representative messages (leave out email addresses and phone numbers).\n"
						. "3. Point out anything urgent or that needs a reply, and suggest improvements to the form or website based on what people ask.\n"
						. '4. Don\'t contact anyone or change anything.';
				},
			),
		);

		/**
		 * Filters the ready-made tasks (MCP prompts).
		 *
		 * @param array $prompts Prompt definitions keyed by name.
		 */
		return apply_filters( 'viagent_prompts', $prompts );
	}

	/**
	 * Whether a prompt is switched on (all are, unless the admin turned one off).
	 *
	 * @param string $name Prompt name.
	 * @return bool
	 */
	public static function is_enabled( $name ) {
		$overrides = (array) get_option( self::OVERRIDES_OPTION, array() );
		return ! isset( $overrides[ $name ] ) || (bool) $overrides[ $name ];
	}

	/**
	 * Prompts available to a connection: enabled and with every required tool.
	 *
	 * @param Connection $connection Connection.
	 * @return array<string,array>
	 */
	public static function for_connection( Connection $connection ) {
		$tools     = Tool_Registry::for_connection( $connection );
		$available = array();
		foreach ( self::definitions() as $name => $prompt ) {
			if ( self::is_enabled( $name ) && ! array_diff( $prompt['requires'], array_keys( $tools ) ) ) {
				$available[ $name ] = $prompt;
			}
		}
		return $available;
	}

	/**
	 * `prompts/list` result.
	 *
	 * @param Connection $connection Connection.
	 * @return array
	 */
	public static function list_result( Connection $connection ) {
		$prompts = array();
		foreach ( self::for_connection( $connection ) as $name => $prompt ) {
			$arguments = array();
			foreach ( $prompt['arguments'] as $arg => $spec ) {
				$arguments[] = array(
					'name'        => $arg,
					'description' => $spec[0],
					'required'    => (bool) $spec[1],
				);
			}
			$prompts[] = array(
				'name'        => $name,
				'title'       => $prompt['title'],
				'description' => $prompt['description'],
				'arguments'   => $arguments,
			);
		}
		return array( 'prompts' => $prompts );
	}

	/**
	 * `prompts/get` result.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $name       Prompt name.
	 * @param array      $args       Arguments.
	 * @return array|WP_Error
	 */
	public static function get_result( Connection $connection, $name, array $args ) {
		$prompts = self::for_connection( $connection );
		if ( ! isset( $prompts[ $name ] ) ) {
			return new WP_Error( 'viagent_unknown_prompt', sprintf( 'Unknown prompt: %s', $name ) );
		}
		$prompt = $prompts[ $name ];

		$clean = array();
		foreach ( $prompt['arguments'] as $arg => $spec ) {
			$value = isset( $args[ $arg ] ) && is_scalar( $args[ $arg ] ) ? trim( sanitize_textarea_field( (string) $args[ $arg ] ) ) : '';
			if ( $spec[1] && '' === $value ) {
				return new WP_Error( 'viagent_missing_argument', sprintf( 'Missing required argument: %s', $arg ) );
			}
			$clean[ $arg ] = $value;
		}

		$text = call_user_func( $prompt['build'], $clean, Tool_Registry::for_connection( $connection ) );
		if ( $connection->compact ) {
			$text = "The WordPress tools are available through discover_tools, describe_tool and run_tool; call the tools named below with run_tool.\n\n" . $text;
		}

		return array(
			'description' => $prompt['description'],
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $text,
					),
				),
			),
		);
	}
}
