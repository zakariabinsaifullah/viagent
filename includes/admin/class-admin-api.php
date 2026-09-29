<?php
/**
 * REST endpoints for the Mcpai admin screens (mcpai/v1/admin/*).
 *
 * @package MCPAI
 */

namespace MCPAI\Admin;

use MCPAI\Auth\API_Keys;
use MCPAI\Auth\OAuth\OAuth;
use MCPAI\Log\Activity_Log;
use MCPAI\MCP\Prompts;
use MCPAI\MCP\Tool_Registry;
use MCPAI\MCP\Transport;
use MCPAI\Security\Policy;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Admin REST API.
 */
class Admin_API {

	const NS = 'mcpai/v1';

	/**
	 * Registers routes.
	 */
	public static function register_routes() {
		$admin = array( self::class, 'can_manage' );
		$id    = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		register_rest_route(
			self::NS,
			'/admin/state',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_state' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			self::NS,
			'/admin/settings',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( self::class, 'update_settings' ),
				'permission_callback' => $admin,
				'args'                => array(
					'paused'                 => array( 'type' => 'boolean' ),
					'allow_permanent_delete' => array( 'type' => 'boolean' ),
					'compact_mode'           => array( 'type' => 'boolean' ),
					'delete_data'            => array( 'type' => 'boolean' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/admin/connections',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_connections' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'create_connection' ),
					'permission_callback' => $admin,
					'args'                => array(
						'name'         => array(
							'type'     => 'string',
							'required' => true,
						),
						'client'       => array( 'type' => 'string' ),
						'access_level' => array(
							'type'     => 'string',
							'enum'     => array_keys( Policy::levels() ),
							'required' => true,
						),
						'draft_only'   => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/admin/connections/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_connection' ),
					'permission_callback' => $admin,
					'args'                => $id,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'revoke_connection' ),
					'permission_callback' => $admin,
					'args'                => $id,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/admin/oauth-grants/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'revoke_grant' ),
				'permission_callback' => $admin,
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NS,
			'/admin/tools',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_tools' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'toggle_tool' ),
					'permission_callback' => $admin,
					'args'                => array(
						'ability' => array(
							'type'     => 'string',
							'required' => true,
						),
						'enabled' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/admin/prompts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_prompts' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'toggle_prompt' ),
					'permission_callback' => $admin,
					'args'                => array(
						'name'    => array(
							'type'     => 'string',
							'required' => true,
						),
						'enabled' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/admin/activity',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'list_activity' ),
				'permission_callback' => $admin,
				'args'                => array(
					'page'   => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'status' => array(
						'type' => 'string',
						'enum' => array( '', 'changes', 'error' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/admin/activity/(?P<id>\d+)/revert',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'revert_activity' ),
				'permission_callback' => $admin,
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NS,
			'/admin/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( Health::class, 'run' ),
				'permission_callback' => $admin,
			)
		);

		// Public probe used by the health check to see if the Authorization header reaches PHP.
		register_rest_route(
			self::NS,
			'/probe',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function ( WP_REST_Request $request ) {
					return array( 'authorization' => 'Bearer mcpai-probe' === $request->get_header( 'authorization' ) );
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Permission callback for admin routes.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Overall state for the header and settings screen.
	 *
	 * @return array
	 */
	public static function get_state() {
		$last = Activity_Log::query( array( 'per_page' => 1 ) );

		return array(
			'paused'                 => Policy::is_paused(),
			'allow_permanent_delete' => Policy::allows_permanent_delete(),
			'compact_mode'           => (bool) get_option( 'mcpai_compact_mode', false ),
			'delete_data'            => (bool) get_option( 'mcpai_delete_data_on_uninstall', false ),
			'integrations'           => \MCPAI\Integrations\Integrations::status(),
			'endpoint'               => Transport::endpoint_url(),
			'connections'            => count( API_Keys::all() ) + count( OAuth::active_grants() ),
			'activity_total'         => $last['total'],
			'last_activity'          => $last['items'] ? self::iso( $last['items'][0]->created_at ) : null,
		);
	}

	/**
	 * Updates global switches.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function update_settings( WP_REST_Request $request ) {
		if ( null !== $request['paused'] ) {
			update_option( Policy::PAUSED_OPTION, (bool) $request['paused'] );
		}
		if ( null !== $request['allow_permanent_delete'] ) {
			update_option( Policy::PERMANENT_DELETE_OPTION, (bool) $request['allow_permanent_delete'] );
		}
		if ( null !== $request['compact_mode'] ) {
			update_option( 'mcpai_compact_mode', (bool) $request['compact_mode'] );
		}
		if ( null !== $request['delete_data'] ) {
			update_option( 'mcpai_delete_data_on_uninstall', (bool) $request['delete_data'] );
		}
		return self::get_state();
	}

	/**
	 * Formats a key row for the UI.
	 *
	 * @param object $row Key row.
	 * @return array
	 */
	private static function connection( $row ) {
		$user = get_userdata( (int) $row->user_id );
		return array(
			'type'         => 'key',
			'id'           => (int) $row->id,
			'name'         => $row->name,
			'client'       => $row->client,
			'key_prefix'   => $row->key_prefix,
			'access_level' => $row->access_level,
			'draft_only'   => (bool) $row->draft_only,
			'compact'      => ! empty( $row->compact ),
			'user'         => $user ? $user->display_name : __( '(deleted user)', 'mcpai' ),
			'created_at'   => self::iso( $row->created_at ),
			'last_used_at' => self::iso( $row->last_used_at ),
		);
	}

	/**
	 * Converts a GMT MySQL datetime to ISO 8601.
	 *
	 * @param string|null $datetime Datetime.
	 * @return string|null
	 */
	private static function iso( $datetime ) {
		return $datetime ? gmdate( 'c', strtotime( $datetime . ' UTC' ) ) : null;
	}

	/**
	 * Lists active connections.
	 *
	 * @return array
	 */
	public static function list_connections() {
		$connections = array_map( array( self::class, 'connection' ), API_Keys::all() );

		foreach ( OAuth::active_grants() as $grant ) {
			$user          = get_userdata( (int) $grant->user_id );
			$connections[] = array(
				'type'         => 'oauth',
				'id'           => (int) $grant->id,
				'name'         => $grant->client_name ? $grant->client_name : __( 'AI app', 'mcpai' ),
				'client'       => self::guess_client( (string) $grant->client_name ),
				'key_prefix'   => '',
				'access_level' => $grant->access_level,
				'draft_only'   => (bool) $grant->draft_only,
				'user'         => $user ? $user->display_name : __( '(deleted user)', 'mcpai' ),
				'created_at'   => self::iso( $grant->created_at ),
				'last_used_at' => self::iso( $grant->last_used_at ),
			);
		}

		usort(
			$connections,
			static function ( $a, $b ) {
				return strcmp( (string) $b['created_at'], (string) $a['created_at'] );
			}
		);
		return $connections;
	}

	/**
	 * Catalog slug for an OAuth client, guessed from its self-reported name.
	 *
	 * @param string $name Client name.
	 * @return string
	 */
	private static function guess_client( $name ) {
		$name = strtolower( $name );
		$map  = array(
			'claude code'        => 'claude-code',
			'claude'             => 'claude-web',
			'chatgpt'            => 'chatgpt',
			'openai'             => 'chatgpt',
			'cursor'             => 'cursor',
			'visual studio code' => 'vscode',
			'vs code'            => 'vscode',
			'windsurf'           => 'windsurf',
			'codex'              => 'codex',
			'gemini'             => 'gemini-cli',
			'opencode'           => 'opencode',
		);
		foreach ( $map as $needle => $slug ) {
			if ( false !== strpos( $name, $needle ) ) {
				return $slug;
			}
		}
		return 'other';
	}

	/**
	 * Revokes an OAuth grant.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function revoke_grant( WP_REST_Request $request ) {
		if ( ! OAuth::revoke_grant( (int) $request['id'] ) ) {
			return new WP_Error( 'mcpai_not_found', __( 'Connection not found.', 'mcpai' ), array( 'status' => 404 ) );
		}
		return array( 'revoked' => true );
	}

	/**
	 * Gets one connection (polled by the wizard to detect the first request).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function get_connection( WP_REST_Request $request ) {
		foreach ( API_Keys::all() as $row ) {
			if ( (int) $row->id === (int) $request['id'] ) {
				return self::connection( $row );
			}
		}
		return new WP_Error( 'mcpai_not_found', __( 'Connection not found.', 'mcpai' ), array( 'status' => 404 ) );
	}

	/**
	 * Creates a connection (API key) for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function create_connection( WP_REST_Request $request ) {
		$draft_only = Policy::READ === $request['access_level'] ? true : (bool) $request['draft_only'];
		$row        = API_Keys::create(
			array(
				'user_id'      => get_current_user_id(),
				'name'         => $request['name'],
				'client'       => $request['client'],
				'access_level' => $request['access_level'],
				'draft_only'   => $draft_only,
				'compact'      => self::needs_compact( (string) $request['client'], (string) $request['access_level'], $draft_only ),
			)
		);
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$connection        = self::connection( (object) array_merge( $row, array( 'last_used_at' => null ) ) );
		$connection['key'] = $row['key'];
		return $connection;
	}

	/**
	 * Whether a new connection should use compact mode because the app limits
	 * how many tools it accepts (catalog `tool_limit`).
	 *
	 * @param string $client     Client slug.
	 * @param string $level      Access level.
	 * @param bool   $draft_only Draft-only mode.
	 * @return bool
	 */
	private static function needs_compact( $client, $level, $draft_only ) {
		$catalog = require MCPAI_DIR . 'includes/clients/catalog.php';
		$limit   = (int) ( $catalog[ $client ]['tool_limit'] ?? 0 );
		if ( ! $limit ) {
			return false;
		}
		$probe = new \MCPAI\Auth\Connection(
			array(
				'method'       => 'api_key',
				'user_id'      => get_current_user_id(),
				'access_level' => $level,
				'draft_only'   => $draft_only,
			)
		);
		return count( Tool_Registry::for_connection( $probe ) ) > $limit;
	}

	/**
	 * Revokes a connection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function revoke_connection( WP_REST_Request $request ) {
		if ( ! API_Keys::revoke( (int) $request['id'] ) ) {
			return new WP_Error( 'mcpai_not_found', __( 'Connection not found.', 'mcpai' ), array( 'status' => 404 ) );
		}
		return array( 'revoked' => true );
	}

	/**
	 * Lists Mcpai tools grouped by category with their switches.
	 *
	 * @return array
	 */
	public static function list_tools() {
		$groups    = array();
		$summaries = self::tool_summaries();
		foreach ( wp_get_ability_categories() as $category ) {
			if ( 0 === strpos( $category->get_slug(), 'mcpai-' ) ) {
				$groups[ $category->get_slug() ] = array(
					'slug'        => $category->get_slug(),
					'label'       => $category->get_label(),
					'description' => $category->get_description(),
					'tools'       => array(),
				);
			}
		}
		$groups['external'] = array(
			'slug'        => 'external',
			'label'       => __( 'From other plugins', 'mcpai' ),
			'description' => __( 'Abilities added by WordPress or your other plugins. They are off until you switch them on; review them before allowing AI apps to use them.', 'mcpai' ),
			'tools'       => array(),
		);

		foreach ( wp_get_abilities() as $ability ) {
			$own  = Tool_Registry::is_own( $ability );
			$slug = $own ? $ability->get_category() : 'external';
			if ( ! isset( $groups[ $slug ] ) ) {
				continue;
			}
			$annotations = (array) $ability->get_meta_item( 'annotations', array() );
			$tool        = Tool_Registry::tool_name( $ability->get_name() );
			$overrides   = (array) get_option( Policy::TOOL_OVERRIDES_OPTION, array() );

			$groups[ $slug ]['tools'][] = array(
				'ability'     => $ability->get_name(),
				'tool'        => $tool,
				'label'       => $ability->get_label(),
				'summary'     => $summaries[ $tool ] ?? $ability->get_description(),
				'description' => $ability->get_description(),
				'source'      => $own ? '' : strtok( $ability->get_name(), '/' ),
				'level'       => Tool_Registry::required_level( $ability ),
				'readonly'    => ! empty( $annotations['readonly'] ),
				'destructive' => ! empty( $annotations['destructive'] ),
				'enabled'     => $own ? Policy::is_tool_enabled( $ability ) : ! empty( $overrides[ $ability->get_name() ] ),
			);
		}

		return array_values(
			array_filter(
				$groups,
				static function ( $group ) {
					return ! empty( $group['tools'] );
				}
			)
		);
	}

	/**
	 * Plain-language summaries of Mcpai tools for the Tools screen. The ability
	 * descriptions are written for the AI; these are written for people.
	 *
	 * @return array<string,string>
	 */
	private static function tool_summaries() {
		$summaries = array(
			'get_site_info'          => __( 'See the site name, address, language, theme and content types.', 'mcpai' ),
			'search_content'         => __( 'Search all posts, pages and other content.', 'mcpai' ),
			'list_post_types'        => __( 'See which kinds of content the site has.', 'mcpai' ),
			'list_posts'             => __( 'Browse posts, pages and other content.', 'mcpai' ),
			'get_post'               => __( 'Read a post or page in full.', 'mcpai' ),
			'create_post'            => __( 'Write new posts and pages.', 'mcpai' ),
			'update_post'            => __( 'Edit existing posts and pages.', 'mcpai' ),
			'delete_post'            => __( 'Move posts and pages to the trash.', 'mcpai' ),
			'restore_post'           => __( 'Bring posts and pages back from the trash.', 'mcpai' ),
			'list_revisions'         => __( 'See earlier versions of a post or page.', 'mcpai' ),
			'restore_revision'       => __( 'Roll a post or page back to an earlier version.', 'mcpai' ),
			'get_post_meta'          => __( 'Read custom fields on a post.', 'mcpai' ),
			'update_post_meta'       => __( 'Change custom fields on a post.', 'mcpai' ),
			'list_block_types'       => __( 'See which blocks can be used in content.', 'mcpai' ),
			'list_patterns'          => __( 'Browse ready-made layouts (patterns).', 'mcpai' ),
			'get_pattern'            => __( 'Use a ready-made layout in content.', 'mcpai' ),
			'list_taxonomies'        => __( 'See the kinds of categories and tags.', 'mcpai' ),
			'list_terms'             => __( 'Browse categories and tags.', 'mcpai' ),
			'create_term'            => __( 'Add new categories and tags.', 'mcpai' ),
			'update_term'            => __( 'Rename or edit categories and tags.', 'mcpai' ),
			'delete_term'            => __( 'Delete categories and tags.', 'mcpai' ),
			'assign_terms'           => __( 'Put posts into categories and add tags.', 'mcpai' ),
			'list_media'             => __( 'Browse the media library.', 'mcpai' ),
			'upload_media_from_url'  => __( 'Add images and files from a web address.', 'mcpai' ),
			'upload_media_base64'    => __( 'Upload images and files the AI created.', 'mcpai' ),
			'update_media'           => __( 'Edit image titles, captions and alt text.', 'mcpai' ),
			'set_featured_image'     => __( 'Choose the featured image of a post.', 'mcpai' ),
			'delete_media'           => __( 'Permanently delete files from the media library.', 'mcpai' ),
			'list_comments'          => __( 'Read comments, including ones waiting for approval.', 'mcpai' ),
			'reply_to_comment'       => __( 'Reply to comments publicly.', 'mcpai' ),
			'moderate_comment'       => __( 'Approve, hold, mark as spam or trash comments.', 'mcpai' ),
			'list_users'             => __( 'See user accounts and their roles.', 'mcpai' ),
			'get_user'               => __( 'See one user account.', 'mcpai' ),
			'create_user'            => __( 'Add user accounts (never administrators).', 'mcpai' ),
			'update_user'            => __( 'Edit user profiles and roles (never administrators).', 'mcpai' ),
			'list_menus'             => __( 'See the site’s navigation menus.', 'mcpai' ),
			'get_menu'               => __( 'See the links in a menu.', 'mcpai' ),
			'add_menu_item'          => __( 'Add links to a menu.', 'mcpai' ),
			'remove_menu_item'       => __( 'Remove links from a menu.', 'mcpai' ),
			'update_navigation_menu' => __( 'Edit the navigation menu of a block theme.', 'mcpai' ),
			'get_settings'           => __( 'See the site title, homepage, timezone and other settings.', 'mcpai' ),
			'update_settings'        => __( 'Change the site title, homepage, timezone and similar settings.', 'mcpai' ),
			'list_plugins'           => __( 'See installed plugins.', 'mcpai' ),
			'activate_plugin'        => __( 'Turn installed plugins on.', 'mcpai' ),
			'deactivate_plugin'      => __( 'Turn plugins off.', 'mcpai' ),
			'install_plugin'         => __( 'Install plugins from WordPress.org.', 'mcpai' ),
			'list_themes'            => __( 'See installed themes.', 'mcpai' ),
			'activate_theme'         => __( 'Switch the site’s theme.', 'mcpai' ),
			'get_site_health'        => __( 'Check versions, updates and technical health.', 'mcpai' ),
			'get_error_log'          => __( 'Read the PHP error log to help fix problems.', 'mcpai' ),
		);

		/**
		 * Filters the plain-language tool summaries shown on the Tools screen.
		 *
		 * @param array<string,string> $summaries Tool name => summary.
		 */
		return apply_filters( 'mcpai_tool_summaries', $summaries );
	}

	/**
	 * Switches a tool on or off.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function toggle_tool( WP_REST_Request $request ) {
		$ability = wp_get_ability( $request['ability'] );
		if ( ! $ability ) {
			return new WP_Error( 'mcpai_not_found', __( 'Tool not found.', 'mcpai' ), array( 'status' => 404 ) );
		}

		$overrides = (array) get_option( Policy::TOOL_OVERRIDES_OPTION, array() );
		unset( $overrides[ $ability->get_name() ] );

		// Abilities from other plugins are off unless explicitly switched on.
		$default = Tool_Registry::is_own( $ability )
			? ( ( (array) $ability->get_meta_item( 'mcpai', array() ) )['default_enabled'] ?? true )
			: false;
		if ( (bool) $request['enabled'] !== (bool) $default ) {
			$overrides[ $ability->get_name() ] = (bool) $request['enabled'];
		}
		update_option( Policy::TOOL_OVERRIDES_OPTION, $overrides );

		return array(
			'ability' => $ability->get_name(),
			'enabled' => (bool) $request['enabled'],
		);
	}

	/**
	 * Lists ready-made tasks (MCP prompts) for the Tools screen.
	 *
	 * @return array
	 */
	public static function list_prompts() {
		$tools  = array();
		$result = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( Policy::is_tool_enabled( $ability ) ) {
				$tools[ Tool_Registry::tool_name( $ability->get_name() ) ] = true;
			}
		}
		foreach ( Prompts::definitions() as $name => $prompt ) {
			$result[] = array(
				'name'        => $name,
				'title'       => $prompt['title'],
				'description' => $prompt['description'],
				'enabled'     => Prompts::is_enabled( $name ),
				'available'   => ! array_diff( $prompt['requires'], array_keys( $tools ) ),
			);
		}
		return $result;
	}

	/**
	 * Switches a ready-made task on or off.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function toggle_prompt( WP_REST_Request $request ) {
		if ( ! isset( Prompts::definitions()[ $request['name'] ] ) ) {
			return new WP_Error( 'mcpai_not_found', __( 'Task not found.', 'mcpai' ), array( 'status' => 404 ) );
		}
		$overrides = (array) get_option( Prompts::OVERRIDES_OPTION, array() );
		if ( $request['enabled'] ) {
			unset( $overrides[ $request['name'] ] );
		} else {
			$overrides[ $request['name'] ] = false;
		}
		update_option( Prompts::OVERRIDES_OPTION, $overrides );
		return array(
			'name'    => $request['name'],
			'enabled' => (bool) $request['enabled'],
		);
	}

	/**
	 * Lists activity for the UI.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function list_activity( WP_REST_Request $request ) {
		$per_page = 25;
		$filter   = (string) $request['status'];
		$query    = array(
			'page'     => (int) $request['page'],
			'per_page' => $per_page,
		);
		if ( 'error' === $filter ) {
			$query['status'] = 'error';
		} elseif ( 'changes' === $filter ) {
			$query['exclude_tools'] = self::read_only_tools();
		}
		$result = Activity_Log::query( $query );

		$items = array();
		foreach ( $result['items'] as $row ) {
			$items[] = array(
				'id'          => (int) $row->id,
				'created_at'  => self::iso( $row->created_at ),
				'connection'  => $row->connection,
				'agent'       => (string) ( $row->agent ?? '' ),
				'tool'        => $row->tool,
				'status'      => $row->status,
				'message'     => $row->message,
				'duration_ms' => (int) $row->duration_ms,
				'arguments'   => json_decode( (string) $row->arguments, true ),
				'object'      => self::object_link_on_site( $row->object_type, (int) $row->object_id, (int) ( $row->site_id ?? 0 ) ),
				'site'        => ! empty( $row->site_id ) && is_multisite() ? get_blog_option( (int) $row->site_id, 'blogname' ) : null,
				'can_revert'  => $row->undo_data && ! $row->reverted_at,
				'undo_label'  => $row->undo_data ? self::undo_label( json_decode( $row->undo_data, true ) ) : null,
				'reverted_at' => self::iso( $row->reverted_at ),
			);
		}

		return array(
			'items'       => $items,
			'total'       => $result['total'],
			'total_pages' => (int) ceil( $result['total'] / $per_page ),
		);
	}

	/**
	 * MCP names of read-only Mcpai tools (hidden by the "changes only" filter).
	 *
	 * @return string[]
	 */
	private static function read_only_tools() {
		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			$annotations = (array) $ability->get_meta_item( 'annotations', array() );
			if ( ! empty( $annotations['readonly'] ) && 0 === strpos( $ability->get_name(), Tool_Registry::OWN_NAMESPACE ) ) {
				$names[] = Tool_Registry::tool_name( $ability->get_name() );
			}
		}
		return $names;
	}

	/**
	 * Plain-language description of what undoing an entry will do.
	 *
	 * @param array|null $undo Undo instructions.
	 * @return string
	 */
	private static function undo_label( $undo ) {
		switch ( $undo['action'] ?? '' ) {
			case 'trash_post':
				return __( 'The item the AI created will be moved to the trash.', 'mcpai' );
			case 'untrash_post':
				return __( 'The item will be restored from the trash.', 'mcpai' );
			case 'restore_post_fields':
				return __( 'The title, content and status will go back to how they were before this change.', 'mcpai' );
			case 'restore_meta':
				return __( 'The field will go back to its previous value.', 'mcpai' );
			case 'restore_terms':
				return __( 'The categories or tags will go back to how they were.', 'mcpai' );
			case 'restore_options':
				return __( 'The settings will go back to their previous values.', 'mcpai' );
			case 'restore_metas':
				return __( 'The fields will go back to their previous values.', 'mcpai' );
		}

		/**
		 * Filters the description of what undoing a change will do.
		 *
		 * @param string $label Label.
		 * @param array  $undo  Undo instructions.
		 */
		return apply_filters( 'mcpai_undo_label', __( 'This change will be rolled back.', 'mcpai' ), $undo );
	}

	/**
	 * Object link, looked up on the site where the change happened.
	 *
	 * @param string $type    Object type.
	 * @param int    $id      Object ID.
	 * @param int    $site_id Site ID (0 = this site).
	 * @return array|null
	 */
	private static function object_link_on_site( $type, $id, $site_id ) {
		if ( ! $site_id || ! is_multisite() || get_current_blog_id() === $site_id ) {
			return self::object_link( $type, $id );
		}
		switch_to_blog( $site_id );
		$link = self::object_link( $type, $id );
		restore_current_blog();
		return $link;
	}

	/**
	 * Title and edit link for a logged object.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object ID.
	 * @return array|null
	 */
	private static function object_link( $type, $id ) {
		if ( ! $id ) {
			return null;
		}
		switch ( $type ) {
			case 'post':
			case 'attachment':
				$post = get_post( $id );
				return $post ? array(
					'title' => $post->post_title ? $post->post_title : __( '(no title)', 'mcpai' ),
					'url'   => get_edit_post_link( $id, 'raw' ),
				) : null;
			case 'term':
				$term = get_term( $id );
				return $term && ! is_wp_error( $term ) ? array(
					'title' => $term->name,
					'url'   => get_edit_term_link( $term ),
				) : null;
			case 'comment':
				return array(
					'title' => sprintf( /* translators: %d: comment ID */ __( 'Comment #%d', 'mcpai' ), $id ),
					'url'   => admin_url( 'comment.php?action=editcomment&c=' . $id ),
				);
			case 'user':
				$user = get_userdata( $id );
				return $user ? array(
					'title' => $user->display_name,
					'url'   => get_edit_user_link( $id ),
				) : null;
			case 'order':
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
				return $order ? array(
					/* translators: %s: order number */
					'title' => sprintf( __( 'Order #%s', 'mcpai' ), $order->get_order_number() ),
					'url'   => $order->get_edit_order_url(),
				) : null;
		}
		return null;
	}

	/**
	 * Reverts a logged change.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function revert_activity( WP_REST_Request $request ) {
		$result = Activity_Log::revert( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return array( 'reverted' => true );
	}
}
