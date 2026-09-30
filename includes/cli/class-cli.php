<?php
/**
 * WP-CLI commands for managing Viagent connections.
 *
 * @package Viagent
 */

namespace Viagent\CLI;

use Viagent\Auth\API_Keys;
use Viagent\Log\Activity_Log;
use Viagent\MCP\Transport;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manage AI connections.
 */
class CLI {

	/**
	 * Creates an API key for an AI agent.
	 *
	 * ## OPTIONS
	 *
	 * --as=<user>
	 * : User login, email or ID the AI acts as.
	 *
	 * [--level=<level>]
	 * : Access level.
	 * ---
	 * default: read
	 * options:
	 *   - read
	 *   - content
	 *   - admin
	 * ---
	 *
	 * [--name=<name>]
	 * : Friendly name.
	 *
	 * [--allow-publish]
	 * : Turn off draft-only mode.
	 *
	 * ## EXAMPLES
	 *
	 *     wp viagent key-create --as=admin --level=content --name="Claude Code"
	 *
	 * @subcommand key-create
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public function key_create( $args, $assoc_args ) {
		$user = get_user_by( is_numeric( $assoc_args['as'] ) ? 'id' : ( is_email( $assoc_args['as'] ) ? 'email' : 'login' ), $assoc_args['as'] );
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$row = API_Keys::create(
			array(
				'user_id'      => $user->ID,
				'name'         => $assoc_args['name'] ?? 'CLI key',
				'access_level' => $assoc_args['level'] ?? 'read',
				'draft_only'   => empty( $assoc_args['allow-publish'] ),
			)
		);
		if ( is_wp_error( $row ) ) {
			WP_CLI::error( $row );
		}

		WP_CLI::line( 'Endpoint: ' . Transport::endpoint_url() );
		WP_CLI::line( 'Key:      ' . $row['key'] );
		WP_CLI::success( 'Key created (ID ' . $row['id'] . '). Copy it now — it will not be shown again.' );
	}

	/**
	 * Lists active API keys.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand key-list
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public function key_list( $args, $assoc_args ) {
		WP_CLI\Utils\format_items(
			$assoc_args['format'] ?? 'table',
			array_map( 'get_object_vars', API_Keys::all() ),
			array( 'id', 'name', 'user_id', 'key_prefix', 'access_level', 'draft_only', 'last_used_at' )
		);
	}

	/**
	 * Revokes an API key.
	 *
	 * <id>
	 * : Key ID.
	 *
	 * @subcommand key-revoke
	 *
	 * @param array $args Positional args.
	 */
	public function key_revoke( $args ) {
		if ( API_Keys::revoke( (int) $args[0] ) ) {
			WP_CLI::success( 'Key revoked.' );
		} else {
			WP_CLI::error( 'Key not found or already revoked.' );
		}
	}

	/**
	 * Lists recent AI activity.
	 *
	 * ## OPTIONS
	 *
	 * [--per-page=<number>]
	 * : Number of entries.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--status=<status>]
	 * : Filter by "ok" or "error".
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand activity-list
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 */
	public function activity_list( $args, $assoc_args ) {
		$result = Activity_Log::query(
			array(
				'per_page' => (int) ( $assoc_args['per-page'] ?? 20 ),
				'status'   => $assoc_args['status'] ?? '',
			)
		);

		$items = array_map(
			static function ( $row ) {
				$row             = get_object_vars( $row );
				$row['can_undo'] = $row['undo_data'] && ! $row['reverted_at'] ? 'yes' : '';
				return $row;
			},
			$result['items']
		);

		WP_CLI\Utils\format_items(
			$assoc_args['format'] ?? 'table',
			$items,
			array( 'id', 'created_at', 'connection', 'tool', 'status', 'object_type', 'object_id', 'duration_ms', 'can_undo', 'message' )
		);
	}

	/**
	 * Reverts a change made by an AI agent. Run with --user=<admin>.
	 *
	 * <id>
	 * : Activity entry ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp viagent activity-revert 42 --user=admin
	 *
	 * @subcommand activity-revert
	 *
	 * @param array $args Positional args.
	 */
	public function activity_revert( $args ) {
		$result = Activity_Log::revert( (int) $args[0] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result );
		}
		WP_CLI::success( 'Change reverted.' );
	}
}
