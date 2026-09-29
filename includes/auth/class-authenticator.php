<?php
/**
 * Authenticates MCP requests and switches to the connected user.
 *
 * @package MCPAI
 */

namespace MCPAI\Auth;

use MCPAI\Auth\OAuth\OAuth;
use MCPAI\Security\Policy;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticator.
 */
class Authenticator {

	/**
	 * Connection for the current request.
	 *
	 * @var Connection|null
	 */
	private static $current = null;

	/**
	 * Returns the connection for the current request, if any.
	 *
	 * @return Connection|null
	 */
	public static function current() {
		return self::$current;
	}

	/**
	 * Authenticates a request. On success the connection's user becomes the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return Connection|WP_Error
	 */
	public static function authenticate( WP_REST_Request $request ) {
		if ( Policy::is_paused() ) {
			return new WP_Error(
				'mcpai_paused',
				__( 'AI access to this site is paused by the site owner.', 'mcpai' ),
				array( 'status' => 503 )
			);
		}

		$connection = self::from_token( self::bearer_token( $request ) );

		if ( ! $connection ) {
			$connection = self::from_application_password();
		}

		if ( ! $connection ) {
			return new WP_Error(
				'mcpai_unauthorized',
				__( 'Missing or invalid Mcpai key. Create a connection in WordPress under Mcpai.', 'mcpai' ),
				array( 'status' => 401 )
			);
		}

		wp_set_current_user( $connection->user_id );
		self::$current = $connection;

		return $connection;
	}

	/**
	 * Reads the token from `Authorization: Bearer` or the `X-MCPAI-Key` fallback
	 * (some hosts strip the Authorization header).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function bearer_token( WP_REST_Request $request ) {
		$header = (string) $request->get_header( 'authorization' );
		if ( preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $header, $m ) ) {
			return $m[1];
		}
		return trim( (string) $request->get_header( 'x_mcpai_key' ) );
	}

	/**
	 * Resolves a bearer token into a connection.
	 *
	 * @param string $token Token.
	 * @return Connection|null
	 */
	private static function from_token( $token ) {
		if ( '' === $token ) {
			return null;
		}

		if ( 0 === strpos( $token, OAuth::ACCESS_PREFIX ) ) {
			return self::from_oauth_token( $token );
		}

		$row = API_Keys::find_active( $token );
		if ( ! $row || ! get_userdata( (int) $row->user_id ) ) {
			return null;
		}

		API_Keys::touch( $row );

		return new Connection(
			array(
				'method'        => 'api_key',
				'user_id'       => $row->user_id,
				'access_level'  => $row->access_level,
				'draft_only'    => $row->draft_only,
				'compact'       => $row->compact ?? 0,
				'credential_id' => $row->id,
				'name'          => $row->name,
			)
		);
	}

	/**
	 * Resolves an OAuth access token into a connection.
	 *
	 * @param string $token Access token.
	 * @return Connection|null
	 */
	private static function from_oauth_token( $token ) {
		$row = OAuth::enabled() ? OAuth::find_access_token( $token ) : null;
		if ( ! $row || ! get_userdata( (int) $row->user_id ) ) {
			return null;
		}

		return new Connection(
			array(
				'method'        => 'oauth',
				'user_id'       => $row->user_id,
				'access_level'  => $row->access_level,
				'draft_only'    => $row->draft_only,
				'compact'       => $row->compact ?? 0,
				'credential_id' => $row->id,
				'name'          => $row->client_name ? $row->client_name : __( 'AI app', 'mcpai' ),
			)
		);
	}

	/**
	 * Accepts WordPress Application Passwords (Basic auth), already validated by core.
	 * The access level follows the user's own capabilities.
	 *
	 * @return Connection|null
	 */
	private static function from_application_password() {
		if ( ! did_action( 'application_password_did_authenticate' ) || ! is_user_logged_in() ) {
			return null;
		}

		if ( current_user_can( 'manage_options' ) ) {
			$level = Policy::ADMIN;
		} elseif ( current_user_can( 'edit_posts' ) ) {
			$level = Policy::CONTENT;
		} else {
			$level = Policy::READ;
		}

		return new Connection(
			array(
				'method'       => 'app_password',
				'user_id'      => get_current_user_id(),
				'access_level' => $level,
				'draft_only'   => false,
				'name'         => __( 'Application Password', 'mcpai' ),
			)
		);
	}

	/**
	 * Adds a WWW-Authenticate challenge to 401 responses from the MCP endpoint.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_REST_Response
	 */
	public static function add_challenge_header( $response, $server, $request ) {
		if ( 401 === $response->get_status() && 0 === strpos( $request->get_route(), '/mcpai/v1/mcp' ) ) {
			$challenge = 'Bearer realm="Mcpai"';
			if ( OAuth::enabled() ) {
				$challenge .= ', resource_metadata="' . OAuth::resource_metadata_url() . '", scope="mcp"';
			}
			if ( '' !== self::bearer_token( $request ) ) {
				$challenge .= ', error="invalid_token"';
			}
			$response->header( 'WWW-Authenticate', $challenge );
		}
		return $response;
	}
}
