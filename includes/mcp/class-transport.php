<?php
/**
 * MCP Streamable HTTP transport on top of the WordPress REST API.
 *
 * Endpoint: /wp-json/viagent/v1/mcp
 *
 * @package Viagent
 */

namespace Viagent\MCP;

use Viagent\Auth\Authenticator;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Transport.
 */
class Transport {

	const REST_NAMESPACE = 'viagent/v1';
	const ROUTE          = '/mcp';
	const MAX_BODY_BYTES = 8388608; // 8 MB, enough for base64 image uploads.
	const SESSION_TTL    = DAY_IN_SECONDS;

	/**
	 * Public URL of the MCP endpoint.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( self::REST_NAMESPACE . self::ROUTE );
	}

	/**
	 * Registers the REST route.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'handle_post' ),
					'permission_callback' => array( self::class, 'authenticate' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( self::class, 'handle_delete' ),
					'permission_callback' => array( self::class, 'authenticate' ),
				),
				array(
					// No server-initiated streams: tell clients to use POST only.
					'methods'             => 'GET',
					'callback'            => static function () {
						$response = new WP_REST_Response( null, 405 );
						$response->header( 'Allow', 'POST, DELETE' );
						return $response;
					},
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Returns malformed JSON on the MCP route as a JSON-RPC parse error instead of
	 * the generic REST error, so MCP clients understand it.
	 *
	 * @param mixed           $response Response or error from earlier checks.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function json_rpc_parse_error( $response, $handler, $request ) {
		if ( is_wp_error( $response ) && 'rest_invalid_json' === $response->get_error_code() && self::ROUTE === substr( $request->get_route(), -strlen( self::ROUTE ) ) && 0 === strpos( $request->get_route(), '/' . self::REST_NAMESPACE ) ) {
			return self::json( Server::error( null, Server::PARSE_ERROR, 'Parse error: body must be valid JSON.' ), 400 );
		}
		return $response;
	}

	/**
	 * Permission callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function authenticate( WP_REST_Request $request ) {
		$connection = Authenticator::authenticate( $request );
		return is_wp_error( $connection ) ? $connection : true;
	}

	/**
	 * Handles a POSTed JSON-RPC message or batch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_post( WP_REST_Request $request ) {
		$body = $request->get_body();

		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return self::json( Server::error( null, Server::INVALID_REQUEST, 'Request is too large.' ), 413 );
		}

		$message = json_decode( $body, true );
		if ( null === $message || ! is_array( $message ) ) {
			return self::json( Server::error( null, Server::PARSE_ERROR, 'Parse error: body must be JSON.' ), 400 );
		}

		// Sessions are optional; an unknown session ID means the client should re-initialize.
		$session_id = (string) $request->get_header( 'mcp_session_id' );
		if ( '' !== $session_id && ! self::session_exists( $session_id ) && ! self::contains_initialize( $message ) ) {
			return self::json( Server::error( null, Server::INVALID_REQUEST, 'Session not found. Please re-initialize.' ), 404 );
		}

		$connection        = Authenticator::current();
		$connection->agent = self::detect_agent( $request, $message, $session_id );

		$server   = new Server( $connection );
		$is_batch = array_keys( $message ) === range( 0, count( $message ) - 1 );
		$messages = $is_batch ? $message : array( $message );

		$responses = array();
		foreach ( $messages as $single ) {
			$reply = $server->handle( $single );
			if ( null !== $reply ) {
				$responses[] = $reply;
			}
		}

		if ( empty( $responses ) ) {
			return new WP_REST_Response( null, 202 );
		}

		$response = self::json( $is_batch ? $responses : $responses[0] );

		if ( self::contains_initialize( $message ) ) {
			$response->header( 'Mcp-Session-Id', self::start_session( $connection->agent ) );
		}

		return $response;
	}

	/**
	 * Ends a session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_delete( WP_REST_Request $request ) {
		$session_id = (string) $request->get_header( 'mcp_session_id' );
		if ( '' !== $session_id ) {
			delete_transient( self::session_key( $session_id ) );
		}
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Whether the message (or batch) contains an initialize request.
	 *
	 * @param array $message Decoded body.
	 * @return bool
	 */
	private static function contains_initialize( array $message ) {
		$messages = isset( $message['method'] ) ? array( $message ) : $message;
		foreach ( $messages as $single ) {
			if ( is_array( $single ) && 'initialize' === ( $single['method'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Starts a session bound to the connected user.
	 *
	 * @param string $agent Program that opened the session.
	 * @return string Session ID.
	 */
	private static function start_session( $agent ) {
		$id = wp_generate_uuid4();
		set_transient(
			self::session_key( $id ),
			array(
				'user'  => get_current_user_id(),
				'agent' => $agent,
			),
			self::SESSION_TTL
		);
		return $id;
	}

	/**
	 * Name of the program making the request: the MCP clientInfo name from
	 * initialize (remembered for the session), else the HTTP client name.
	 *
	 * @param WP_REST_Request $request    Request.
	 * @param array           $message    Decoded body.
	 * @param string          $session_id Session ID header.
	 * @return string
	 */
	private static function detect_agent( WP_REST_Request $request, array $message, $session_id ) {
		$messages = isset( $message['method'] ) ? array( $message ) : $message;
		foreach ( $messages as $single ) {
			if ( is_array( $single ) && 'initialize' === ( $single['method'] ?? '' ) && ! empty( $single['params']['clientInfo']['name'] ) ) {
				return self::clean_agent( (string) $single['params']['clientInfo']['name'] );
			}
		}

		if ( '' !== $session_id ) {
			$session = get_transient( self::session_key( $session_id ) );
			if ( is_array( $session ) && ! empty( $session['agent'] ) ) {
				return $session['agent'];
			}
		}

		// "curl/8.7.1" → "curl", "node" → "node".
		$user_agent = (string) $request->get_header( 'user_agent' );
		return self::clean_agent( (string) strtok( $user_agent, '/ ' ) );
	}

	/**
	 * Sanitizes a program name for display.
	 *
	 * @param string $agent Raw name.
	 * @return string
	 */
	private static function clean_agent( $agent ) {
		return mb_substr( sanitize_text_field( $agent ), 0, 60 );
	}

	/**
	 * Whether a session exists for the current user.
	 *
	 * @param string $id Session ID.
	 * @return bool
	 */
	private static function session_exists( $id ) {
		$session = get_transient( self::session_key( $id ) );
		$user    = is_array( $session ) ? (int) ( $session['user'] ?? 0 ) : (int) $session;
		return get_current_user_id() === $user;
	}

	/**
	 * Transient key for a session.
	 *
	 * @param string $id Session ID.
	 * @return string
	 */
	private static function session_key( $id ) {
		return 'viagent_sess_' . md5( $id );
	}

	/**
	 * JSON response helper.
	 *
	 * @param mixed $data   Data.
	 * @param int   $status Status.
	 * @return WP_REST_Response
	 */
	private static function json( $data, $status = 200 ) {
		return new WP_REST_Response( $data, $status );
	}
}
