<?php
/**
 * Public OAuth endpoints: discovery (/.well-known/*), dynamic client
 * registration, token and revocation.
 *
 * @package MCPAI
 */

namespace MCPAI\Auth\OAuth;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth API.
 */
class OAuth_API {

	const REGISTRATIONS_PER_HOUR = 30;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		if ( ! OAuth::enabled() ) {
			return;
		}
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'init', array( self::class, 'serve_well_known' ), 1 );
	}

	/**
	 * Serves discovery documents at /.well-known/… Covers the RFC 8414 and
	 * RFC 9728 root and path-suffixed forms, plus the OpenID Connect location
	 * newer MCP clients also try (needed for sites installed in a subfolder).
	 */
	public static function serve_well_known() {
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only matched against fixed patterns.
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( false === strpos( $path, '/.well-known/' ) ) {
			return;
		}

		// Strip the install folder when the request is inside it.
		if ( '/' !== $home && 0 === strpos( $path, rtrim( $home, '/' ) . '/.well-known/' ) ) {
			$path = substr( $path, strlen( rtrim( $home, '/' ) ) );
		}

		if ( preg_match( '#^/\.well-known/oauth-protected-resource(/.*)?$#', $path ) ) {
			self::send_json( OAuth::resource_metadata() );
		}
		if ( preg_match( '#^/\.well-known/(oauth-authorization-server|openid-configuration)(/.*)?$#', $path ) ) {
			self::send_json( OAuth::server_metadata() );
		}
	}

	/**
	 * Sends a public JSON document and stops.
	 *
	 * @param array $data Data.
	 */
	private static function send_json( array $data ) {
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version' );
		header( 'Cache-Control: public, max-age=300' );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		if ( 'OPTIONS' !== $method ) {
			echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		}
		exit;
	}

	/**
	 * Registers REST routes.
	 */
	public static function register_routes() {
		$public = '__return_true';

		register_rest_route(
			'mcpai/v1',
			'/oauth/protected-resource',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function () {
					return OAuth::resource_metadata();
				},
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			'mcpai/v1',
			'/oauth/authorization-server',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function () {
					return OAuth::server_metadata();
				},
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			'mcpai/v1',
			'/oauth/register',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'register_client' ),
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			'mcpai/v1',
			'/oauth/token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'token' ),
				'permission_callback' => $public,
			)
		);

		register_rest_route(
			'mcpai/v1',
			'/oauth/revoke',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'revoke' ),
				'permission_callback' => $public,
			)
		);
	}

	/**
	 * OAuth error response (RFC 6749 §5.2).
	 *
	 * @param string $error       Error code.
	 * @param string $description Description.
	 * @param int    $status      HTTP status.
	 * @return WP_REST_Response
	 */
	private static function error( $error, $description, $status = 400 ) {
		return self::no_store(
			new WP_REST_Response(
				array(
					'error'             => $error,
					'error_description' => $description,
				),
				$status
			)
		);
	}

	/**
	 * Marks a response as not cacheable.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return WP_REST_Response
	 */
	private static function no_store( WP_REST_Response $response ) {
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * Dynamic client registration (RFC 7591). Only public clients using PKCE.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function register_client( WP_REST_Request $request ) {
		$ip_key = 'mcpai_reg_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . gmdate( 'YmdH' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$count  = (int) get_transient( $ip_key ) + 1;
		set_transient( $ip_key, $count, HOUR_IN_SECONDS );
		if ( $count > self::REGISTRATIONS_PER_HOUR ) {
			return self::error( 'invalid_client_metadata', 'Too many registrations. Try again later.', 429 );
		}

		$redirect_uris = $request->get_param( 'redirect_uris' );
		if ( ! is_array( $redirect_uris ) || empty( $redirect_uris ) || count( $redirect_uris ) > 10 ) {
			return self::error( 'invalid_redirect_uri', 'redirect_uris must be a non-empty array.' );
		}
		foreach ( $redirect_uris as $uri ) {
			if ( ! OAuth::is_valid_redirect_uri( $uri ) ) {
				return self::error( 'invalid_redirect_uri', 'Redirect URIs must use https, a loopback http address, or an app-specific scheme.' );
			}
		}

		$grant_types = (array) ( $request->get_param( 'grant_types' ) ?? array( 'authorization_code' ) );
		if ( array_diff( $grant_types, array( 'authorization_code', 'refresh_token' ) ) ) {
			return self::error( 'invalid_client_metadata', 'Only authorization_code and refresh_token grants are supported.' );
		}

		$client = OAuth::register_client( (string) $request->get_param( 'client_name' ), $redirect_uris );
		if ( is_wp_error( $client ) ) {
			return self::error( 'server_error', $client->get_error_message(), 500 );
		}

		return self::no_store(
			new WP_REST_Response(
				array(
					'client_id'                  => $client['client_id'],
					'client_id_issued_at'        => time(),
					'client_name'                => $client['client_name'],
					'redirect_uris'              => array_values( $redirect_uris ),
					'grant_types'                => array( 'authorization_code', 'refresh_token' ),
					'response_types'             => array( 'code' ),
					'token_endpoint_auth_method' => 'none',
				),
				201
			)
		);
	}

	/**
	 * Token endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function token( WP_REST_Request $request ) {
		$grant_type = (string) $request->get_param( 'grant_type' );
		$client_id  = (string) $request->get_param( 'client_id' );

		if ( ! OAuth::get_client( $client_id ) ) {
			return self::error( 'invalid_client', 'Unknown client_id.', 401 );
		}

		if ( 'refresh_token' === $grant_type ) {
			$result = OAuth::refresh( (string) $request->get_param( 'refresh_token' ), $client_id );
			return is_wp_error( $result )
				? self::error( $result->get_error_code(), $result->get_error_message() )
				: self::no_store( new WP_REST_Response( $result, 200 ) );
		}

		if ( 'authorization_code' !== $grant_type ) {
			return self::error( 'unsupported_grant_type', 'Use authorization_code or refresh_token.' );
		}

		$grant = OAuth::consume_code( (string) $request->get_param( 'code' ) );
		if ( ! $grant || $grant['client_id'] !== $client_id ) {
			return self::error( 'invalid_grant', 'The authorization code is invalid, expired or already used.' );
		}
		if ( $grant['redirect_uri'] !== (string) $request->get_param( 'redirect_uri' ) ) {
			return self::error( 'invalid_grant', 'redirect_uri does not match the authorization request.' );
		}

		$verifier = (string) $request->get_param( 'code_verifier' );
		if ( ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier )
			|| ! hash_equals( $grant['code_challenge'], OAuth::base64url( hash( 'sha256', $verifier, true ) ) ) ) {
			return self::error( 'invalid_grant', 'PKCE verification failed.' );
		}

		$resource = (string) $request->get_param( 'resource' );
		if ( '' !== $resource && ! OAuth::is_our_resource( $resource ) ) {
			return self::error( 'invalid_target', 'Unknown resource.' );
		}

		if ( ! get_userdata( (int) $grant['user_id'] ) ) {
			return self::error( 'invalid_grant', 'The approving user no longer exists.' );
		}

		$result = OAuth::issue_tokens( $grant );
		return is_wp_error( $result )
			? self::error( 'server_error', $result->get_error_message(), 500 )
			: self::no_store( new WP_REST_Response( $result, 200 ) );
	}

	/**
	 * Revocation endpoint (RFC 7009): always 200.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function revoke( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		if ( '' !== $token ) {
			OAuth::revoke_token( $token );
		}
		return self::no_store( new WP_REST_Response( null, 200 ) );
	}
}
