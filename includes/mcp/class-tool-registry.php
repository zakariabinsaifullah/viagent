<?php
/**
 * Maps WordPress abilities to MCP tools.
 *
 * @package Viagent
 */

namespace Viagent\MCP;

use Viagent\Auth\Connection;
use Viagent\Security\Policy;
use WP_Ability;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Tool registry.
 */
class Tool_Registry {

	const OWN_NAMESPACE = 'viagent/';

	/**
	 * Converts an ability name to an MCP tool name.
	 * "viagent/list-posts" → "list_posts"; "acme/do-thing" → "acme__do_thing".
	 *
	 * @param string $ability_name Ability name.
	 * @return string
	 */
	public static function tool_name( $ability_name ) {
		if ( 0 === strpos( $ability_name, self::OWN_NAMESPACE ) ) {
			$ability_name = substr( $ability_name, strlen( self::OWN_NAMESPACE ) );
		}
		return substr( str_replace( array( '/', '-' ), array( '__', '_' ), $ability_name ), 0, 64 );
	}

	/**
	 * Access level an ability requires. Read-only abilities default to "read",
	 * everything else to "admin" unless the ability declares `meta.viagent.level`.
	 *
	 * @param WP_Ability $ability Ability.
	 * @return string
	 */
	public static function required_level( WP_Ability $ability ) {
		$viagent = $ability->get_meta_item( 'viagent', array() );
		if ( is_array( $viagent ) && ! empty( $viagent['level'] ) && Policy::is_valid_level( $viagent['level'] ) ) {
			return $viagent['level'];
		}
		$annotations = $ability->get_meta_item( 'annotations', array() );
		return empty( $annotations['readonly'] ) ? Policy::ADMIN : Policy::READ;
	}

	/**
	 * Abilities exposed to the given connection, keyed by tool name.
	 *
	 * @param Connection $connection Connection.
	 * @return array<string,WP_Ability>
	 */
	public static function for_connection( Connection $connection ) {
		$tools = array();

		foreach ( wp_get_abilities() as $ability ) {
			if ( ! self::is_exposed( $ability ) || ! Policy::is_tool_enabled( $ability ) ) {
				continue;
			}
			if ( $connection->draft_only && ! Policy::is_draft_safe( $ability ) ) {
				continue;
			}
			if ( ! Policy::allows( $connection->access_level, self::required_level( $ability ) ) ) {
				continue;
			}
			// Hide tools the connected user could never run (e.g. network tools for a site admin).
			$viagent = (array) $ability->get_meta_item( 'viagent', array() );
			if ( ! empty( $viagent['capability'] ) && ! current_user_can( $viagent['capability'] ) ) {
				continue;
			}
			$tools[ self::tool_name( $ability->get_name() ) ] = $ability;
		}

		/**
		 * Filters the abilities exposed as MCP tools for a connection.
		 *
		 * @param array<string,WP_Ability> $tools      Tools keyed by MCP tool name.
		 * @param Connection               $connection Connection.
		 */
		return apply_filters( 'viagent_tools', $tools, $connection );
	}

	/**
	 * Whether an ability comes from Viagent itself.
	 *
	 * @param WP_Ability $ability Ability.
	 * @return bool
	 */
	public static function is_own( WP_Ability $ability ) {
		return 0 === strpos( $ability->get_name(), self::OWN_NAMESPACE );
	}

	/**
	 * Whether an ability is exposed over MCP. Viagent's own abilities always are
	 * (subject to their switches); abilities from other plugins only after the
	 * site owner switches them on under Tools.
	 *
	 * @param WP_Ability $ability Ability.
	 * @return bool
	 */
	private static function is_exposed( WP_Ability $ability ) {
		if ( self::is_own( $ability ) ) {
			$exposed = true;
		} else {
			$overrides = (array) get_option( Policy::TOOL_OVERRIDES_OPTION, array() );
			$exposed   = ! empty( $overrides[ $ability->get_name() ] );
		}

		/**
		 * Filters whether an ability is exposed over MCP.
		 *
		 * @param bool       $exposed Whether the ability is exposed.
		 * @param WP_Ability $ability Ability.
		 */
		return (bool) apply_filters( 'viagent_ability_exposed', $exposed, $ability );
	}

	/**
	 * Builds the MCP tool definition for an ability.
	 *
	 * @param string     $name    MCP tool name.
	 * @param WP_Ability $ability Ability.
	 * @return array
	 */
	public static function definition( $name, WP_Ability $ability ) {
		$schema = $ability->get_input_schema();
		if ( self::is_wrapped( $ability ) ) {
			// MCP tools take an object; wrap scalar or array inputs as { "input": … }.
			$schema = array(
				'type'       => 'object',
				'properties' => array( 'input' => $schema ),
				'required'   => array_key_exists( 'default', $schema ) ? array() : array( 'input' ),
			);
		} elseif ( empty( $schema ) ) {
			$schema = array( 'type' => 'object' );
		}
		unset( $schema['default'] );
		if ( empty( $schema['properties'] ) ) {
			$schema['properties'] = new \stdClass();
		}

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$hints       = array( 'title' => $ability->get_label() );
		$map         = array(
			'readonly'    => 'readOnlyHint',
			'destructive' => 'destructiveHint',
			'idempotent'  => 'idempotentHint',
		);
		foreach ( $map as $from => $to ) {
			if ( isset( $annotations[ $from ] ) ) {
				$hints[ $to ] = (bool) $annotations[ $from ];
			}
		}
		$hints['openWorldHint'] = false;

		return array(
			'name'        => $name,
			'title'       => $ability->get_label(),
			'description' => $ability->get_description(),
			'inputSchema' => $schema,
			'annotations' => $hints,
		);
	}

	/**
	 * Executes a tool.
	 *
	 * @param WP_Ability $ability   Ability.
	 * @param array      $arguments Arguments from the client.
	 * @return mixed|WP_Error
	 */
	public static function call( WP_Ability $ability, array $arguments ) {
		$input = $arguments;
		if ( self::is_wrapped( $ability ) ) {
			$input = $arguments['input'] ?? null;
		} elseif ( empty( $arguments ) && empty( $ability->get_input_schema() ) ) {
			$input = null;
		}
		return $ability->execute( $input );
	}

	/**
	 * Whether an ability's input is not an object and must be wrapped.
	 *
	 * @param WP_Ability $ability Ability.
	 * @return bool
	 */
	private static function is_wrapped( WP_Ability $ability ) {
		$schema = $ability->get_input_schema();
		return ! empty( $schema ) && 'object' !== ( $schema['type'] ?? 'object' );
	}
}
