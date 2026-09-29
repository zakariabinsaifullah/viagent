<?php
/**
 * Activity log: every tool call the AI makes, with a way to undo content changes.
 *
 * Abilities describe what they touched with `set_object()` and how to undo it
 * with `set_undo()`; the MCP server records the call afterwards.
 *
 * @package MCPAI
 */

namespace MCPAI\Log;

use MCPAI\Auth\Connection;
use MCPAI\Installer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mcpai's own tables; rows change on nearly every request (authentication, logging), so object caching would not help.

/**
 * Activity log.
 */
class Activity_Log {

	const PRUNE_HOOK     = 'mcpai_prune_activity';
	const MAX_ARG_LENGTH = 2000;

	/**
	 * Object touched by the call in progress.
	 *
	 * @var array{type:string,id:int}|null
	 */
	private static $object = null;

	/**
	 * Undo instructions for the call in progress.
	 *
	 * @var array|null
	 */
	private static $undo = null;

	/**
	 * Site the current call acted on, when a super admin ran a tool on another
	 * site of the network (0 = this site).
	 *
	 * @var int
	 */
	private static $site_id = 0;

	/**
	 * Resets per-call state. Called before each tool runs.
	 */
	public static function begin() {
		self::$object  = null;
		self::$undo    = null;
		self::$site_id = 0;
	}

	/**
	 * Records that the current call acted on another site of the network.
	 *
	 * @param int $site_id Site ID.
	 */
	public static function set_site( $site_id ) {
		self::$site_id = (int) $site_id;
	}

	/**
	 * Records which object the current call touched.
	 *
	 * @param string $type Object type, e.g. "post", "term", "comment".
	 * @param int    $id   Object ID.
	 */
	public static function set_object( $type, $id ) {
		self::$object = array(
			'type' => $type,
			'id'   => (int) $id,
		);
	}

	/**
	 * Records how to undo the current call (see `revert()` for actions).
	 *
	 * @param array $undo Undo instructions with an `action` key.
	 */
	public static function set_undo( array $undo ) {
		self::$undo = $undo;
	}

	/**
	 * Writes a log entry for a finished tool call.
	 *
	 * @param Connection $connection  Connection.
	 * @param string     $tool        MCP tool name.
	 * @param array      $arguments   Arguments.
	 * @param mixed      $result      Result or WP_Error.
	 * @param int        $duration_ms Duration in milliseconds.
	 */
	public static function record( Connection $connection, $tool, array $arguments, $result, $duration_ms ) {
		global $wpdb;

		$is_error = is_wp_error( $result );

		$wpdb->insert(
			Installer::table( 'activity' ),
			array(
				'created_at'    => current_time( 'mysql', true ),
				'user_id'       => $connection->user_id,
				'method'        => $connection->method,
				'credential_id' => $connection->credential_id,
				'connection'    => $connection->name,
				'agent'         => $connection->agent,
				'tool'          => $tool,
				'arguments'     => wp_json_encode( self::redact( $arguments ) ),
				'status'        => $is_error ? 'error' : 'ok',
				'message'       => $is_error ? $result->get_error_message() : '',
				'duration_ms'   => (int) $duration_ms,
				'object_type'   => self::$object['type'] ?? '',
				'object_id'     => self::$object['id'] ?? 0,
				'undo_data'     => ( ! $is_error && self::$undo ) ? wp_json_encode( self::$undo ) : null,
				'site_id'       => self::$site_id,
			)
		);
	}

	/**
	 * Removes secrets and shortens long values before logging.
	 *
	 * @param array $arguments Arguments.
	 * @return array
	 */
	private static function redact( array $arguments ) {
		foreach ( $arguments as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/pass|secret|token|api_?key|base64|^data$/i', $key ) ) {
				$arguments[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$arguments[ $key ] = self::redact( $value );
			} elseif ( is_string( $value ) && strlen( $value ) > self::MAX_ARG_LENGTH ) {
				$arguments[ $key ] = substr( $value, 0, self::MAX_ARG_LENGTH ) . '… [truncated]';
			}
		}
		return $arguments;
	}

	/**
	 * Queries log entries, newest first.
	 *
	 * @param array $args {
	 *     Optional query arguments.
	 *
	 *     @type int    $per_page Items per page. Default 50.
	 *     @type int    $page     Page. Default 1.
	 *     @type string $status   "ok" or "error".
	 *     @type string   $tool          Tool name.
	 *     @type string[] $exclude_tools Tool names to leave out.
	 * }
	 * @return array{items:array,total:int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$table    = Installer::table( 'activity' );
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$where    = array( '1=1' );
		$values   = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}
		if ( ! empty( $args['tool'] ) ) {
			$where[]  = 'tool = %s';
			$values[] = $args['tool'];
		}
		if ( ! empty( $args['exclude_tools'] ) ) {
			$where[] = 'tool NOT IN (' . implode( ', ', array_fill( 0, count( $args['exclude_tools'] ), '%s' ) ) . ')';
			$values  = array_merge( $values, array_values( $args['exclude_tools'] ) );
		}

		$where_sql = implode( ' AND ', $where );
		// $where_sql only contains fixed column names and placeholders; every value is passed to prepare().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where_sql}", array_merge( array( $table ), $values ) ) );
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( array( $table ), $values, array( $per_page, $offset ) ) ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Gets one entry.
	 *
	 * @param int $id Entry ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Installer::table( 'activity' );
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ) );
	}

	/**
	 * Undoes a logged change. Runs as the current (admin) user.
	 *
	 * @param int $id Entry ID.
	 * @return true|WP_Error
	 */
	public static function revert( $id ) {
		global $wpdb;

		$entry = self::get( $id );
		if ( ! $entry ) {
			return new WP_Error( 'mcpai_not_found', __( 'Activity entry not found.', 'mcpai' ) );
		}
		if ( $entry->reverted_at ) {
			return new WP_Error( 'mcpai_already_reverted', __( 'This change was already reverted.', 'mcpai' ) );
		}
		$undo = $entry->undo_data ? json_decode( $entry->undo_data, true ) : null;
		if ( ! is_array( $undo ) || empty( $undo['action'] ) ) {
			return new WP_Error( 'mcpai_not_revertable', __( 'This change cannot be reverted automatically.', 'mcpai' ) );
		}

		$switched = ! empty( $entry->site_id ) && is_multisite() && get_current_blog_id() !== (int) $entry->site_id;
		if ( $switched ) {
			switch_to_blog( (int) $entry->site_id );
		}
		$result = self::apply_undo( $undo );
		if ( $switched ) {
			restore_current_blog();
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$wpdb->update(
			Installer::table( 'activity' ),
			array( 'reverted_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $id )
		);
		return true;
	}

	/**
	 * Applies undo instructions.
	 *
	 * @param array $undo Undo instructions.
	 * @return true|WP_Error
	 */
	private static function apply_undo( array $undo ) {
		$post_id = (int) ( $undo['post_id'] ?? 0 );

		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'mcpai_forbidden', __( 'You are not allowed to change this item.', 'mcpai' ) );
		}

		switch ( $undo['action'] ) {
			case 'restore_post_fields':
				$result = wp_update_post( wp_slash( array_merge( (array) $undo['fields'], array( 'ID' => $post_id ) ) ), true );
				if ( ! is_wp_error( $result ) && isset( $undo['terms'] ) ) {
					foreach ( (array) $undo['terms'] as $taxonomy => $term_ids ) {
						wp_set_object_terms( $post_id, array_map( 'intval', (array) $term_ids ), $taxonomy );
					}
				}
				if ( ! is_wp_error( $result ) && array_key_exists( 'thumbnail_id', $undo ) ) {
					$undo['thumbnail_id'] ? set_post_thumbnail( $post_id, (int) $undo['thumbnail_id'] ) : delete_post_thumbnail( $post_id );
				}
				return is_wp_error( $result ) ? $result : true;

			case 'trash_post':
				return wp_trash_post( $post_id ) ? true : new WP_Error( 'mcpai_revert_failed', __( 'Could not move the item to the trash.', 'mcpai' ) );

			case 'untrash_post':
				return wp_untrash_post( $post_id ) ? true : new WP_Error( 'mcpai_revert_failed', __( 'Could not restore the item from the trash.', 'mcpai' ) );

			case 'restore_meta':
				if ( null === $undo['value'] ) {
					delete_post_meta( $post_id, $undo['key'] );
				} else {
					update_post_meta( $post_id, $undo['key'], wp_slash( $undo['value'] ) );
				}
				return true;

			case 'restore_terms':
				$result = wp_set_object_terms( $post_id, array_map( 'intval', (array) $undo['term_ids'] ), $undo['taxonomy'] );
				return is_wp_error( $result ) ? $result : true;

			case 'restore_metas':
				foreach ( (array) $undo['values'] as $key => $value ) {
					if ( null === $value ) {
						delete_post_meta( $post_id, $key );
					} else {
						update_post_meta( $post_id, $key, wp_slash( $value ) );
					}
				}
				return true;

			case 'restore_options':
				if ( ! current_user_can( 'manage_options' ) ) {
					return new WP_Error( 'mcpai_forbidden', __( 'You are not allowed to change settings.', 'mcpai' ) );
				}
				foreach ( (array) $undo['options'] as $name => $value ) {
					update_option( $name, $value );
				}
				return true;
		}

		/**
		 * Applies undo instructions for actions added by integrations.
		 *
		 * @param true|WP_Error|null $result Null when no handler took the action.
		 * @param array              $undo   Undo instructions.
		 */
		$result = apply_filters( 'mcpai_apply_undo', null, $undo );
		if ( null !== $result ) {
			return $result;
		}

		return new WP_Error( 'mcpai_not_revertable', __( 'This change cannot be reverted automatically.', 'mcpai' ) );
	}

	/**
	 * Deletes entries older than the retention period. Runs daily via cron.
	 */
	public static function prune() {
		global $wpdb;

		/**
		 * Filters how many days activity is kept.
		 *
		 * @param int $days Days. Default 90.
		 */
		$days  = max( 1, (int) apply_filters( 'mcpai_activity_retention_days', 90 ) );
		$table = Installer::table( 'activity' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $table, gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Makes sure the daily prune job is scheduled.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Removes the prune job (plugin deactivation).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
	}
}
