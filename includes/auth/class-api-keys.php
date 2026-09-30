<?php
/**
 * API key storage. Only an HMAC of each key is stored; the plain key is shown once.
 *
 * @package Viagent
 */

namespace Viagent\Auth;

use Viagent\Installer;
use Viagent\Security\Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Viagent's own tables; rows change on nearly every request (authentication, logging), so object caching would not help.

/**
 * API keys repository.
 */
class API_Keys {

	const PREFIX = 'viagent_sk_';

	/**
	 * Hashes a plain key.
	 *
	 * @param string $key Plain key.
	 * @return string
	 */
	public static function hash( $key ) {
		return hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
	}

	/**
	 * Creates a key.
	 *
	 * @param array $args {
	 *     Key settings.
	 *
	 *     @type int    $user_id      User the AI acts as.
	 *     @type string $name         Friendly name.
	 *     @type string $client       Client slug from the catalog.
	 *     @type string $access_level Access level.
	 *     @type bool   $draft_only   Draft-only mode.
	 *     @type bool   $compact      Expose only the meta-tools.
	 *     @type string $expires_at   Optional GMT datetime.
	 * }
	 * @return array|WP_Error Row plus the plain `key`, shown only once.
	 */
	public static function create( array $args ) {
		global $wpdb;

		$user_id = (int) ( $args['user_id'] ?? 0 );
		$level   = (string) ( $args['access_level'] ?? Policy::READ );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'viagent_invalid_user', __( 'That user does not exist.', 'viagent' ) );
		}
		if ( ! Policy::is_valid_level( $level ) ) {
			return new WP_Error( 'viagent_invalid_level', __( 'Unknown access level.', 'viagent' ) );
		}

		$key = self::PREFIX . wp_generate_password( 40, false, false );
		$row = array(
			'user_id'      => $user_id,
			'name'         => sanitize_text_field( $args['name'] ?? __( 'AI connection', 'viagent' ) ),
			'client'       => sanitize_key( $args['client'] ?? '' ),
			'key_prefix'   => substr( $key, 0, 16 ),
			'key_hash'     => self::hash( $key ),
			'access_level' => $level,
			'draft_only'   => empty( $args['draft_only'] ) ? 0 : 1,
			'compact'      => empty( $args['compact'] ) ? 0 : 1,
			'created_at'   => current_time( 'mysql', true ),
			'expires_at'   => $args['expires_at'] ?? null,
		);

		if ( false === $wpdb->insert( Installer::table( 'keys' ), $row ) ) {
			return new WP_Error( 'viagent_db_error', __( 'Could not save the key.', 'viagent' ) );
		}

		$row['id']  = (int) $wpdb->insert_id;
		$row['key'] = $key;
		unset( $row['key_hash'] );

		return $row;
	}

	/**
	 * Finds an active (not revoked, not expired) key by its plain value.
	 *
	 * @param string $key Plain key.
	 * @return object|null
	 */
	public static function find_active( $key ) {
		global $wpdb;

		if ( 0 !== strpos( $key, self::PREFIX ) ) {
			return null;
		}

		$table = Installer::table( 'keys' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE key_hash = %s AND revoked_at IS NULL', $table, self::hash( $key ) )
		);

		if ( ! $row ) {
			return null;
		}
		if ( $row->expires_at && strtotime( $row->expires_at . ' UTC' ) < time() ) {
			return null;
		}

		return $row;
	}

	/**
	 * Records usage, throttled to one write per minute per key.
	 *
	 * @param object $row Key row.
	 */
	public static function touch( $row ) {
		global $wpdb;

		if ( $row->last_used_at && strtotime( $row->last_used_at . ' UTC' ) > time() - MINUTE_IN_SECONDS ) {
			return;
		}

		$wpdb->update(
			Installer::table( 'keys' ),
			array( 'last_used_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $row->id )
		);
	}

	/**
	 * Lists keys (without hashes), newest first.
	 *
	 * @param bool $include_revoked Whether to include revoked keys.
	 * @return array<int,object>
	 */
	public static function all( $include_revoked = false ) {
		global $wpdb;

		$table = Installer::table( 'keys' );

		if ( $include_revoked ) {
			return $wpdb->get_results( $wpdb->prepare( 'SELECT id, user_id, name, client, key_prefix, access_level, draft_only, compact, created_at, last_used_at, expires_at, revoked_at FROM %i ORDER BY id DESC', $table ) );
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, user_id, name, client, key_prefix, access_level, draft_only, compact, created_at, last_used_at, expires_at, revoked_at FROM %i WHERE revoked_at IS NULL ORDER BY id DESC', $table ) );
	}

	/**
	 * Revokes a key.
	 *
	 * @param int $id Key ID.
	 * @return bool
	 */
	public static function revoke( $id ) {
		global $wpdb;

		return (bool) $wpdb->update(
			Installer::table( 'keys' ),
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array(
				'id'         => (int) $id,
				'revoked_at' => null,
			)
		);
	}
}
