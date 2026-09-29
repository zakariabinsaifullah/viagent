<?php
/**
 * Multisite network tools for super admins: list and create sites, and run
 * any tool on another site of the network.
 *
 * Every site keeps its own connections, settings and activity. A super admin
 * connected to one site can work on the others with run_on_site; the change is
 * logged on the connected site with a note of which site it touched, and it
 * can be undone from there.
 *
 * @package MCPAI
 */

namespace MCPAI\Integrations;

use MCPAI\Abilities\Abilities;
use MCPAI\Auth\Authenticator;
use MCPAI\Log\Activity_Log;
use MCPAI\MCP\Meta_Tools;
use MCPAI\MCP\Tool_Registry;
use MCPAI\Security\Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Network integration.
 */
class Network {

	const CATEGORY = 'mcpai-network';

	/**
	 * Registers hooks.
	 */
	public static function init() {
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
				'label'       => __( 'Network (multisite)', 'mcpai' ),
				'description' => __( 'Sites in your WordPress network. Super admins only.', 'mcpai' ),
			)
		);
	}

	/**
	 * Tells super admins' AI apps about the network.
	 *
	 * @param string[] $lines Instruction lines.
	 * @return string[]
	 */
	public static function instructions( $lines ) {
		if ( current_user_can( 'manage_network' ) ) {
			$lines[] = sprintf( 'This site is part of a WordPress network with %d sites. All other tools act on this site; to work on another site, find it with list_sites and use run_on_site.', get_blog_count() );
		}
		return $lines;
	}

	/**
	 * Registers abilities.
	 */
	public static function register() {
		Abilities::add(
			'list-sites',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'List network sites', 'mcpai' ),
				'description' => __( 'Lists the sites in this WordPress network with their ID, name, address, status and post count.', 'mcpai' ),
				'input'       => array_merge(
					array( 'search' => array( 'type' => 'string' ) ),
					Abilities::paging( 50 )
				),
				'execute'     => array( self::class, 'list_sites' ),
				'permission'  => 'manage_sites',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'create-site',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Create network site', 'mcpai' ),
				'description' => __( 'Creates a new site in the network. The connected super admin becomes its administrator.', 'mcpai' ),
				'input'       => array(
					'title' => array(
						'type'      => 'string',
						'minLength' => 1,
					),
					'slug'  => array(
						'type'        => 'string',
						'pattern'     => '^[a-z0-9-]+$',
						'description' => __( 'Lowercase address part, e.g. "shop" for shop.example.com or example.com/shop/.', 'mcpai' ),
					),
				),
				'required'    => array( 'title', 'slug' ),
				'execute'     => array( self::class, 'create_site' ),
				'permission'  => 'create_sites',
				'meta'        => Abilities::write_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'run-on-site',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Run a tool on another site', 'mcpai' ),
				'description' => __( 'Runs any other tool (e.g. list_posts, create_post, update_settings) on another site of the network. Pass the site_id from list_sites, the tool name and its arguments.', 'mcpai' ),
				'input'       => array(
					'site_id'   => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'tool'      => array( 'type' => 'string' ),
					'arguments' => array(
						'type'        => 'object',
						'description' => __( 'Arguments for the tool.', 'mcpai' ),
					),
				),
				'required'    => array( 'site_id', 'tool' ),
				'execute'     => array( self::class, 'run_on_site' ),
				'permission'  => 'manage_network',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'destructive' => true ) ),
			)
		);
	}

	/**
	 * Compact site representation.
	 *
	 * @param \WP_Site $site Site.
	 * @return array
	 */
	private static function site( $site ) {
		$status = 'active';
		if ( (int) $site->deleted ) {
			$status = 'deactivated';
		} elseif ( (int) $site->archived ) {
			$status = 'archived';
		} elseif ( (int) $site->spam ) {
			$status = 'spam';
		}
		return array(
			'id'        => (int) $site->blog_id,
			'name'      => $site->blogname,
			'url'       => $site->home,
			'admin_url' => get_admin_url( (int) $site->blog_id ),
			'status'    => $status,
			'public'    => (bool) $site->public,
			'posts'     => (int) $site->post_count,
			'main_site' => is_main_site( (int) $site->blog_id ),
		);
	}

	/**
	 * Lists sites.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_sites( $input ) {
		$per_page = (int) ( $input['per_page'] ?? 50 );
		$args     = array(
			'number' => $per_page,
			'offset' => ( (int) ( $input['page'] ?? 1 ) - 1 ) * $per_page,
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = $input['search'];
		}

		return array(
			'total' => (int) get_sites( array_merge( $args, array( 'count' => true, 'number' => 0, 'offset' => 0 ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'items' => array_map( array( self::class, 'site' ), get_sites( $args ) ),
		);
	}

	/**
	 * Creates a site.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function create_site( $input ) {
		$network = get_network();
		$slug    = sanitize_title( $input['slug'] );

		if ( is_subdomain_install() ) {
			$domain = $slug . '.' . preg_replace( '|^www\.|', '', $network->domain );
			$path   = $network->path;
		} else {
			$domain = $network->domain;
			$path   = $network->path . $slug . '/';
		}

		if ( domain_exists( $domain, $path, $network->id ) ) {
			return new WP_Error( 'mcpai_site_exists', __( 'A site with that address already exists.', 'mcpai' ) );
		}

		$site_id = wp_insert_site(
			array(
				'domain'     => $domain,
				'path'       => $path,
				'network_id' => $network->id,
				'title'      => sanitize_text_field( $input['title'] ),
				'user_id'    => get_current_user_id(),
			)
		);
		if ( is_wp_error( $site_id ) ) {
			return $site_id;
		}

		Activity_Log::set_object( 'site', $site_id );
		return self::site( get_site( $site_id ) );
	}

	/**
	 * Runs a tool on another site.
	 *
	 * @param array $input Input.
	 * @return mixed|WP_Error
	 */
	public static function run_on_site( $input ) {
		$site_id    = (int) $input['site_id'];
		$tool       = (string) $input['tool'];
		$connection = Authenticator::current();

		if ( ! $connection ) {
			return new WP_Error( 'mcpai_no_connection', 'run_on_site can only be used over an MCP connection.' );
		}
		if ( 'run_on_site' === $tool || Meta_Tools::is_meta( $tool ) ) {
			return new WP_Error( 'mcpai_invalid_tool', __( 'run_on_site cannot run itself or the compact-mode tools.', 'mcpai' ) );
		}
		$site = get_site( $site_id );
		if ( ! $site || (int) $site->deleted ) {
			return new WP_Error( 'mcpai_not_found', __( 'No active site found with that ID. Use list_sites.', 'mcpai' ) );
		}
		if ( get_current_blog_id() === $site_id ) {
			return new WP_Error( 'mcpai_same_site', __( 'That is the site you are connected to; call the tool directly.', 'mcpai' ) );
		}

		switch_to_blog( $site_id );
		try {
			if ( Policy::is_paused() ) {
				return new WP_Error( 'mcpai_paused', __( 'AI access to that site is paused by its owner.', 'mcpai' ) );
			}
			// The target site's own tool switches and integrations apply.
			$tools = Tool_Registry::for_connection( $connection );
			if ( ! isset( $tools[ $tool ] ) ) {
				/* translators: %s: tool name */
				return new WP_Error( 'mcpai_unknown_tool', sprintf( __( 'The tool "%s" is not available on that site.', 'mcpai' ), $tool ) );
			}

			Activity_Log::set_site( $site_id );
			$result = Tool_Registry::call( $tools[ $tool ], is_array( $input['arguments'] ?? null ) ? $input['arguments'] : array() );
		} finally {
			restore_current_blog();
		}

		return is_wp_error( $result ) ? $result : array(
			'site_id' => $site_id,
			'site'    => $site->blogname,
			'tool'    => $tool,
			'result'  => $result,
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
				'list_sites'  => __( 'See the sites in your network.', 'mcpai' ),
				'create_site' => __( 'Add new sites to your network.', 'mcpai' ),
				'run_on_site' => __( 'Work on any site of your network.', 'mcpai' ),
			)
		);
	}
}
