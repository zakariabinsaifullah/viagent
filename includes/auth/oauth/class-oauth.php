<?php
/**
 * OAuth 2.1 core: URLs, clients, authorization codes and tokens.
 *
 * Mcpai is both the authorization server and the resource server. Clients
 * register dynamically (RFC 7591), users approve on a consent screen, and
 * tokens are opaque random strings stored only as hashes.
 *
 * @package MCPAI
 */

namespace MCPAI\Auth\OAuth;

use MCPAI\Installer;
use MCPAI\MCP\Transport;
use MCPAI\Security\Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mcpai's own tables; rows change on nearly every request (authentication, logging), so object caching would not help.

/**
 * OAuth core.
 */
class OAuth {

	const ACCESS_PREFIX  = 'mcpai_at_';
	const REFRESH_PREFIX = 'mcpai_rt_';
	const CLIENT_PREFIX  = 'mcpai_c_';

	const ACCESS_TTL  = HOUR_IN_SECONDS;
	const REFRESH_TTL = 30 * DAY_IN_SECONDS;
	const CODE_TTL    = 10 * MINUTE_IN_SECONDS;

	/**
	 * Whether OAuth is enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		/**
		 * Filters whether one-click OAuth sign-in is available.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'mcpai_oauth_enabled', true );
	}

	/**
	 * Authorization server issuer identifier (the site URL, no trailing slash).
	 *
	 * @return string
	 */
	public static function issuer() {
		return untrailingslashit( home_url() );
	}

	/**
	 * Consent screen URL (runs inside wp-login.php so it works on every host).
	 *
	 * @return string
	 */
	public static function authorization_endpoint() {
		return add_query_arg( 'action', 'mcpai_authorize', wp_login_url() );
	}

	/**
	 * Protected resource metadata URL, advertised in WWW-Authenticate.
	 *
	 * @return string
	 */
	public static function resource_metadata_url() {
		return rest_url( 'mcpai/v1/oauth/protected-resource' );
	}

	/**
	 * RFC 8414 authorization server metadata.
	 *
	 * @return array
	 */
	public static function server_metadata() {
		return array(
			'issuer'                                     => self::issuer(),
			'authorization_endpoint'                     => self::authorization_endpoint(),
			'token_endpoint'                             => rest_url( 'mcpai/v1/oauth/token' ),
			'registration_endpoint'                      => rest_url( 'mcpai/v1/oauth/register' ),
			'revocation_endpoint'                        => rest_url( 'mcpai/v1/oauth/revoke' ),
			'response_types_supported'                   => array( 'code' ),
			'grant_types_supported'                      => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'           => array( 'S256' ),
			'token_endpoint_auth_methods_supported'      => array( 'none' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                           => array( 'mcp' ),
			'client_id_metadata_document_supported'      => true,
			'authorization_response_iss_parameter_supported' => true,
			'service_documentation'                      => \MCPAI\Admin\Admin::url(),
		);
	}

	/**
	 * RFC 9728 protected resource metadata.
	 *
	 * @return array
	 */
	public static function resource_metadata() {
		return array(
			'resource'                 => Transport::endpoint_url(),
			'authorization_servers'    => array( self::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => array( 'mcp' ),
			'resource_name'            => get_bloginfo( 'name' ),
		);
	}

	/**
	 * Hashes a secret for storage.
	 *
	 * @param string $secret Secret.
	 * @return string
	 */
	public static function hash( $secret ) {
		return hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) );
	}

	/**
	 * Random URL-safe string.
	 *
	 * @param int $length Length.
	 * @return string
	 */
	private static function random( $length ) {
		return wp_generate_password( $length, false, false );
	}

	/**
	 * Base64url without padding (RFC 7636).
	 *
	 * @param string $bytes Bytes.
	 * @return string
	 */
	public static function base64url( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE.
	}

	/**
	 * Whether a URL is the MCP resource (ignoring a trailing slash).
	 *
	 * @param string $resource_url Resource indicator from the client.
	 * @return bool
	 */
	public static function is_our_resource( $resource_url ) {
		return untrailingslashit( $resource_url ) === untrailingslashit( Transport::endpoint_url() );
	}

	/**
	 * Validates a redirect URI a client wants to register: https, loopback http,
	 * or an app-specific scheme such as cursor:// (RFC 8252).
	 *
	 * @param string $uri URI.
	 * @return bool
	 */
	public static function is_valid_redirect_uri( $uri ) {
		if ( ! is_string( $uri ) || strlen( $uri ) > 2000 || false !== strpos( $uri, '#' ) || preg_match( '/[\s\x00-\x1f\x7f]/', $uri ) ) {
			return false;
		}
		$parts  = wp_parse_url( $uri );
		$scheme = strtolower( $parts['scheme'] ?? '' );

		if ( 'https' === $scheme ) {
			return ! empty( $parts['host'] );
		}
		if ( 'http' === $scheme ) {
			return in_array( $parts['host'] ?? '', array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true );
		}
		// Private-use schemes, e.g. "cursor" or "com.example.app"; never script or data schemes.
		return (bool) preg_match( '/^[a-z][a-z0-9+.-]*$/', $scheme )
			&& ! in_array( $scheme, array( 'javascript', 'data', 'vbscript', 'file', 'about', 'blob' ), true );
	}

	/**
	 * Registers a client.
	 *
	 * @param string   $name          Client name.
	 * @param string[] $redirect_uris Redirect URIs.
	 * @return array|WP_Error Client row.
	 */
	public static function register_client( $name, array $redirect_uris ) {
		global $wpdb;

		$client = array(
			'client_id'     => self::CLIENT_PREFIX . self::random( 24 ),
			'client_name'   => mb_substr( sanitize_text_field( $name ? $name : __( 'AI app', 'mcpai' ) ), 0, 100 ),
			'redirect_uris' => wp_json_encode( array_values( $redirect_uris ) ),
			'created_at'    => current_time( 'mysql', true ),
		);

		if ( false === $wpdb->insert( Installer::table( 'oauth_clients' ), $client ) ) {
			return new WP_Error( 'server_error', 'Could not save the client.' );
		}
		return $client;
	}

	/**
	 * Gets a client.
	 *
	 * @param string $client_id Client ID.
	 * @return object|null Row with decoded `redirect_uris`.
	 */
	public static function get_client( $client_id ) {
		global $wpdb;
		if ( is_string( $client_id ) && self::is_metadata_client_id( $client_id ) ) {
			return self::get_metadata_client( $client_id );
		}
		if ( ! is_string( $client_id ) || 0 !== strpos( $client_id, self::CLIENT_PREFIX ) ) {
			return null;
		}
		$table  = Installer::table( 'oauth_clients' );
		$client = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE client_id = %s', $table, $client_id ) );
		if ( $client ) {
			$client->redirect_uris = (array) json_decode( $client->redirect_uris, true );
		}
		return $client;
	}

	/**
	 * Whether a client ID is a Client ID Metadata Document URL (an https URL
	 * with a path, as used by ChatGPT and other MCP clients).
	 *
	 * @param string $client_id Client ID.
	 * @return bool
	 */
	public static function is_metadata_client_id( $client_id ) {
		$parts = wp_parse_url( $client_id );
		return is_array( $parts )
			&& 'https' === ( $parts['scheme'] ?? '' )
			&& ! empty( $parts['host'] )
			&& ! empty( $parts['path'] ) && '/' !== $parts['path']
			&& ! isset( $parts['fragment'] ) && ! isset( $parts['user'] )
			&& strlen( $client_id ) <= 255;
	}

	/**
	 * Loads a client from its Client ID Metadata Document (CIMD). The document
	 * is fetched from the client_id URL, validated, cached for an hour, and
	 * recorded in the clients table so connections show the app's name.
	 *
	 * @param string $client_id Client ID (document URL).
	 * @return object|null Client with `client_id`, `client_name` and `redirect_uris`.
	 */
	private static function get_metadata_client( $client_id ) {
		global $wpdb;

		$cache_key = 'mcpai_cimd_' . md5( $client_id );
		$document  = get_transient( $cache_key );

		if ( ! is_array( $document ) ) {
			// wp_safe_remote_get() refuses local and private network addresses.
			$response = wp_safe_remote_get(
				$client_id,
				array(
					'timeout'             => 5,
					'redirection'         => 0,
					'limit_response_size' => 16384,
					'headers'             => array( 'Accept' => 'application/json' ),
				)
			);
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}
			$document = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $document ) ) {
				return null;
			}
			set_transient( $cache_key, $document, HOUR_IN_SECONDS );
		}

		// The document must describe itself, and only list safe redirect URIs.
		if ( ( $document['client_id'] ?? '' ) !== $client_id || empty( $document['redirect_uris'] ) || ! is_array( $document['redirect_uris'] ) ) {
			return null;
		}
		$redirect_uris = array_values( array_filter( $document['redirect_uris'], array( self::class, 'is_valid_redirect_uri' ) ) );
		if ( empty( $redirect_uris ) ) {
			return null;
		}

		$host   = (string) wp_parse_url( $client_id, PHP_URL_HOST );
		$name   = mb_substr( sanitize_text_field( (string) ( $document['client_name'] ?? $host ) ), 0, 100 );
		$table  = Installer::table( 'oauth_clients' );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE client_id = %s', $table, $client_id ) );
		$row    = array(
			'client_name'   => '' !== $name ? $name : $host,
			'redirect_uris' => wp_json_encode( $redirect_uris ),
		);
		if ( $exists ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $exists ) );
		} else {
			$wpdb->insert(
				$table,
				array_merge(
					$row,
					array(
						'client_id'  => $client_id,
						'created_at' => current_time( 'mysql', true ),
					)
				)
			);
		}

		return (object) array(
			'client_id'     => $client_id,
			'client_name'   => $row['client_name'],
			'redirect_uris' => $redirect_uris,
		);
	}

	/**
	 * Issues a single-use authorization code.
	 *
	 * @param array $grant {
	 *     Approved grant.
	 *
	 *     @type string $client_id      Client ID.
	 *     @type int    $user_id        User who approved.
	 *     @type string $redirect_uri   Redirect URI used.
	 *     @type string $code_challenge PKCE challenge.
	 *     @type string $access_level   Approved access level.
	 *     @type bool   $draft_only     Draft-only mode.
	 * }
	 * @return string Code.
	 */
	public static function issue_code( array $grant ) {
		$code = self::random( 40 );
		set_transient( 'mcpai_code_' . self::hash( $code ), $grant, self::CODE_TTL );
		return $code;
	}

	/**
	 * Consumes an authorization code (single use).
	 *
	 * @param string $code Code.
	 * @return array|null Grant.
	 */
	public static function consume_code( $code ) {
		$key   = 'mcpai_code_' . self::hash( (string) $code );
		$grant = get_transient( $key );
		delete_transient( $key );
		return is_array( $grant ) ? $grant : null;
	}

	/**
	 * Creates a token pair for an approved grant.
	 *
	 * @param array $grant Grant (see issue_code()).
	 * @return array|WP_Error Token response.
	 */
	public static function issue_tokens( array $grant ) {
		global $wpdb;

		$access  = self::ACCESS_PREFIX . self::random( 40 );
		$refresh = self::REFRESH_PREFIX . self::random( 48 );
		$now     = time();

		$inserted = $wpdb->insert(
			Installer::table( 'oauth_tokens' ),
			array(
				'client_id'          => $grant['client_id'],
				'user_id'            => (int) $grant['user_id'],
				'access_hash'        => self::hash( $access ),
				'refresh_hash'       => self::hash( $refresh ),
				'access_level'       => $grant['access_level'],
				'draft_only'         => empty( $grant['draft_only'] ) ? 0 : 1,
				'created_at'         => gmdate( 'Y-m-d H:i:s', $now ),
				'access_expires_at'  => gmdate( 'Y-m-d H:i:s', $now + self::ACCESS_TTL ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $now + self::REFRESH_TTL ),
			)
		);
		if ( false === $inserted ) {
			return new WP_Error( 'server_error', 'Could not save the token.' );
		}

		self::touch_client( $grant['client_id'] );
		return self::token_response( $access, $refresh );
	}

	/**
	 * Rotates a refresh token into a new token pair.
	 *
	 * @param string $refresh_token Refresh token.
	 * @param string $client_id     Client ID.
	 * @return array|WP_Error Token response.
	 */
	public static function refresh( $refresh_token, $client_id ) {
		global $wpdb;

		$table = Installer::table( 'oauth_tokens' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE refresh_hash = %s AND revoked_at IS NULL', $table, self::hash( (string) $refresh_token ) ) );

		if ( ! $row || $row->client_id !== $client_id || strtotime( $row->refresh_expires_at . ' UTC' ) < time() || ! get_userdata( (int) $row->user_id ) ) {
			return new WP_Error( 'invalid_grant', 'The refresh token is invalid, expired or revoked.' );
		}

		$access  = self::ACCESS_PREFIX . self::random( 40 );
		$refresh = self::REFRESH_PREFIX . self::random( 48 );
		$now     = time();

		$wpdb->update(
			$table,
			array(
				'access_hash'        => self::hash( $access ),
				'refresh_hash'       => self::hash( $refresh ),
				'access_expires_at'  => gmdate( 'Y-m-d H:i:s', $now + self::ACCESS_TTL ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $now + self::REFRESH_TTL ),
			),
			array( 'id' => (int) $row->id )
		);

		self::touch_client( $client_id );
		return self::token_response( $access, $refresh );
	}

	/**
	 * Token endpoint response body.
	 *
	 * @param string $access  Access token.
	 * @param string $refresh Refresh token.
	 * @return array
	 */
	private static function token_response( $access, $refresh ) {
		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TTL,
			'refresh_token' => $refresh,
			'scope'         => 'mcp',
		);
	}

	/**
	 * Finds the grant for a valid (unexpired, unrevoked) access token.
	 *
	 * @param string $access_token Access token.
	 * @return object|null Row joined with the client name.
	 */
	public static function find_access_token( $access_token ) {
		global $wpdb;

		if ( 0 !== strpos( $access_token, self::ACCESS_PREFIX ) ) {
			return null;
		}

		$tokens  = Installer::table( 'oauth_tokens' );
		$clients = Installer::table( 'oauth_clients' );
		$row     = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT t.*, c.client_name FROM %i t LEFT JOIN %i c ON c.client_id = t.client_id WHERE t.access_hash = %s AND t.revoked_at IS NULL',
				$tokens,
				$clients,
				self::hash( $access_token )
			)
		);

		if ( ! $row || strtotime( $row->access_expires_at . ' UTC' ) < time() ) {
			return null;
		}

		if ( ! $row->last_used_at || strtotime( $row->last_used_at . ' UTC' ) < time() - MINUTE_IN_SECONDS ) {
			$wpdb->update( $tokens, array( 'last_used_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $row->id ) );
		}
		return $row;
	}

	/**
	 * Revokes the grant that owns an access or refresh token (RFC 7009).
	 *
	 * @param string $token Token.
	 */
	public static function revoke_token( $token ) {
		global $wpdb;

		$column = 0 === strpos( (string) $token, self::REFRESH_PREFIX ) ? 'refresh_hash' : 'access_hash';
		$wpdb->update(
			Installer::table( 'oauth_tokens' ),
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array(
				$column      => self::hash( (string) $token ),
				'revoked_at' => null,
			)
		);
	}

	/**
	 * Revokes a grant by ID (admin screen).
	 *
	 * @param int $id Grant ID.
	 * @return bool
	 */
	public static function revoke_grant( $id ) {
		global $wpdb;
		return (bool) $wpdb->update(
			Installer::table( 'oauth_tokens' ),
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array(
				'id'         => (int) $id,
				'revoked_at' => null,
			)
		);
	}

	/**
	 * Active grants (connections made through OAuth), newest first.
	 *
	 * @return array<int,object>
	 */
	public static function active_grants() {
		global $wpdb;

		$tokens  = Installer::table( 'oauth_tokens' );
		$clients = Installer::table( 'oauth_clients' );
		$now     = current_time( 'mysql', true );

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.id, t.client_id, t.user_id, t.access_level, t.draft_only, t.created_at, t.last_used_at, c.client_name FROM %i t LEFT JOIN %i c ON c.client_id = t.client_id WHERE t.revoked_at IS NULL AND t.refresh_expires_at > %s ORDER BY t.id DESC',
				$tokens,
				$clients,
				$now
			)
		);
	}

	/**
	 * Records client activity.
	 *
	 * @param string $client_id Client ID.
	 */
	private static function touch_client( $client_id ) {
		global $wpdb;
		$wpdb->update( Installer::table( 'oauth_clients' ), array( 'last_used_at' => current_time( 'mysql', true ) ), array( 'client_id' => $client_id ) );
	}

	/**
	 * Access levels the current user may grant on the consent screen.
	 *
	 * @return array<string,array{level:string,draft_only:bool,label:string,description:string}>
	 */
	public static function consent_options() {
		$options = array(
			'read' => array(
				'level'       => Policy::READ,
				'draft_only'  => true,
				'label'       => __( 'Read only', 'mcpai' ),
				'description' => __( 'Look at posts, pages, media and settings. Cannot change anything.', 'mcpai' ),
			),
		);
		if ( current_user_can( 'edit_posts' ) ) {
			$options['drafts'] = array(
				'level'       => Policy::CONTENT,
				'draft_only'  => true,
				'label'       => __( 'Write drafts', 'mcpai' ),
				'description' => __( 'Create and edit drafts and upload media. You review and publish.', 'mcpai' ),
			);
		}
		if ( current_user_can( 'publish_posts' ) ) {
			$options['publish'] = array(
				'level'       => Policy::CONTENT,
				'draft_only'  => false,
				'label'       => __( 'Write and publish', 'mcpai' ),
				'description' => __( 'Publish, edit and trash content, and moderate comments.', 'mcpai' ),
			);
		}
		if ( current_user_can( 'manage_options' ) ) {
			$options['admin'] = array(
				'level'       => Policy::ADMIN,
				'draft_only'  => false,
				'label'       => __( 'Full control', 'mcpai' ),
				'description' => __( 'Everything above plus settings, menus, plugins, themes and users (never administrators).', 'mcpai' ),
			);
		}
		return $options;
	}

	/**
	 * Deletes expired or revoked grants and clients that never finished connecting.
	 */
	public static function prune() {
		global $wpdb;

		$tokens  = Installer::table( 'oauth_tokens' );
		$clients = Installer::table( 'oauth_clients' );
		$week    = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
		$month   = gmdate( 'Y-m-d H:i:s', time() - MONTH_IN_SECONDS );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE refresh_expires_at < %s OR revoked_at < %s', $tokens, $week, $week ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s AND client_id NOT IN (SELECT client_id FROM %i)', $clients, $month, $tokens ) );
		// phpcs:enable
	}
}
