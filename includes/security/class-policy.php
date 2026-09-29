<?php
/**
 * Access levels and safety rules: draft-only mode, permanent deletes,
 * per-tool switches, rate limits and the kill switch.
 *
 * @package MCPAI
 */

namespace MCPAI\Security;

use MCPAI\Auth\Authenticator;
use MCPAI\Auth\Connection;
use WP_Ability;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Policy.
 */
class Policy {

	const READ    = 'read';
	const CONTENT = 'content';
	const ADMIN   = 'admin';

	const PAUSED_OPTION           = 'mcpai_paused';
	const PERMANENT_DELETE_OPTION = 'mcpai_allow_permanent_delete';
	const TOOL_OVERRIDES_OPTION   = 'mcpai_tool_overrides';

	/**
	 * Post statuses that are not visible to site visitors.
	 */
	const PRIVATE_STATUSES = array( 'draft', 'pending', 'auto-draft' );

	/**
	 * Access levels, lowest first.
	 *
	 * @return array<string,int>
	 */
	public static function levels() {
		return array(
			self::READ    => 1,
			self::CONTENT => 2,
			self::ADMIN   => 3,
		);
	}

	/**
	 * Human-readable labels for access levels.
	 *
	 * @return array<string,string>
	 */
	public static function level_labels() {
		return array(
			self::READ    => __( 'Read only', 'mcpai' ),
			self::CONTENT => __( 'Content editor', 'mcpai' ),
			self::ADMIN   => __( 'Full control', 'mcpai' ),
		);
	}

	/**
	 * Whether an access level string is valid.
	 *
	 * @param string $level Level.
	 * @return bool
	 */
	public static function is_valid_level( $level ) {
		return isset( self::levels()[ $level ] );
	}

	/**
	 * Whether a connection with $granted level may use a tool requiring $required level.
	 *
	 * @param string $granted  Level granted to the connection.
	 * @param string $required Level the tool needs.
	 * @return bool
	 */
	public static function allows( $granted, $required ) {
		$levels = self::levels();
		if ( ! isset( $levels[ $granted ], $levels[ $required ] ) ) {
			return false;
		}
		return $levels[ $granted ] >= $levels[ $required ];
	}

	/**
	 * Whether all AI access is paused by the site owner.
	 *
	 * @return bool
	 */
	public static function is_paused() {
		return (bool) get_option( self::PAUSED_OPTION, false );
	}

	/**
	 * Whether the admin allows the AI to delete things permanently (skip the trash).
	 *
	 * @return bool
	 */
	public static function allows_permanent_delete() {
		return (bool) get_option( self::PERMANENT_DELETE_OPTION, false );
	}

	/**
	 * Whether the current MCP connection is limited to drafts.
	 *
	 * @return bool
	 */
	public static function is_draft_only() {
		$connection = Authenticator::current();
		return $connection && $connection->draft_only;
	}

	/**
	 * Blocks statuses that would make content public while in draft-only mode.
	 *
	 * @param string $status Requested post status.
	 * @return true|WP_Error
	 */
	public static function check_status( $status ) {
		if ( self::is_draft_only() && ! in_array( $status, self::PRIVATE_STATUSES, true ) ) {
			return new WP_Error(
				'mcpai_draft_only',
				__( 'Draft-only mode is on, so the status can only be "draft" or "pending". Ask the site owner to publish it from WordPress, or to allow publishing in Mcpai.', 'mcpai' )
			);
		}
		return true;
	}

	/**
	 * Blocks changes to content visitors can already see while in draft-only mode.
	 *
	 * @param WP_Post $post Post.
	 * @return true|WP_Error
	 */
	public static function check_post_editable( WP_Post $post ) {
		if ( self::is_draft_only() && ! in_array( $post->post_status, self::PRIVATE_STATUSES, true ) ) {
			return new WP_Error(
				'mcpai_draft_only',
				sprintf(
					/* translators: %s: post status */
					__( 'Draft-only mode is on and this item is "%s", so it cannot be changed. Create a new draft instead, or ask the site owner for more access in Mcpai.', 'mcpai' ),
					$post->post_status
				)
			);
		}
		return true;
	}

	/**
	 * Whether a tool is switched on. Admin overrides win over the ability's default.
	 *
	 * @param WP_Ability $ability Ability.
	 * @return bool
	 */
	public static function is_tool_enabled( WP_Ability $ability ) {
		$overrides = (array) get_option( self::TOOL_OVERRIDES_OPTION, array() );
		if ( isset( $overrides[ $ability->get_name() ] ) ) {
			return (bool) $overrides[ $ability->get_name() ];
		}
		$mcpai = (array) $ability->get_meta_item( 'mcpai', array() );
		return $mcpai['default_enabled'] ?? true;
	}

	/**
	 * Whether a tool may be used in draft-only mode. Read-only tools always can;
	 * write tools must opt in with `meta.mcpai.draft_safe` and guard themselves.
	 *
	 * @param WP_Ability $ability Ability.
	 * @return bool
	 */
	public static function is_draft_safe( WP_Ability $ability ) {
		$annotations = (array) $ability->get_meta_item( 'annotations', array() );
		if ( ! empty( $annotations['readonly'] ) ) {
			return true;
		}
		$mcpai = (array) $ability->get_meta_item( 'mcpai', array() );
		return ! empty( $mcpai['draft_safe'] );
	}

	/**
	 * Counts a tool call against the per-connection limit.
	 *
	 * @param Connection $connection Connection.
	 * @return true|WP_Error
	 */
	public static function check_rate_limit( Connection $connection ) {
		/**
		 * Filters the number of tool calls a connection may make per minute.
		 *
		 * @param int        $limit      Calls per minute. 0 disables the limit.
		 * @param Connection $connection Connection.
		 */
		$limit = (int) apply_filters( 'mcpai_rate_limit', 120, $connection );
		if ( $limit <= 0 ) {
			return true;
		}

		$key   = 'mcpai_rl_' . md5( $connection->method . ':' . $connection->credential_id . ':' . $connection->user_id . ':' . gmdate( 'YmdHi' ) );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, 2 * MINUTE_IN_SECONDS );

		if ( $count > $limit ) {
			return new WP_Error( 'mcpai_rate_limited', __( 'Too many requests. Please wait a minute and try again.', 'mcpai' ) );
		}
		return true;
	}
}
