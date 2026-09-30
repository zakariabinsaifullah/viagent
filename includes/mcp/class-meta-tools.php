<?php
/**
 * Compact mode: three meta-tools that give access to every tool, for AI apps
 * that limit how many tools a server may expose (e.g. Cursor).
 *
 * @package Viagent
 */

namespace Viagent\MCP;

use WP_Ability;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Meta tools.
 */
class Meta_Tools {

	const DISCOVER = 'discover_tools';
	const DESCRIBE = 'describe_tool';
	const RUN      = 'run_tool';

	/**
	 * Whether a tool name is one of the meta-tools.
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public static function is_meta( $name ) {
		return in_array( $name, array( self::DISCOVER, self::DESCRIBE, self::RUN ), true );
	}

	/**
	 * MCP definitions of the meta-tools.
	 *
	 * @return array[]
	 */
	public static function definitions() {
		return array(
			array(
				'name'        => self::DISCOVER,
				'title'       => __( 'Discover WordPress tools', 'viagent' ),
				'description' => __( 'Lists the WordPress tools available on this site (posts, pages, media, users, settings, plugins…). Call this first, then describe_tool for details and run_tool to use one.', 'viagent' ),
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array(
							'type'        => 'string',
							'description' => __( 'Optional words to filter tools, e.g. "media" or "publish".', 'viagent' ),
						),
					),
				),
				'annotations' => array(
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			),
			array(
				'name'        => self::DESCRIBE,
				'title'       => __( 'Describe a WordPress tool', 'viagent' ),
				'description' => __( 'Returns the full description and input schema of one tool from discover_tools.', 'viagent' ),
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name' => array( 'type' => 'string' ),
					),
					'required'   => array( 'name' ),
				),
				'annotations' => array(
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			),
			array(
				'name'        => self::RUN,
				'title'       => __( 'Run a WordPress tool', 'viagent' ),
				'description' => __( 'Runs one tool from discover_tools with arguments matching its input schema (see describe_tool).', 'viagent' ),
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'name'      => array( 'type' => 'string' ),
						'arguments' => array(
							'type'        => 'object',
							'description' => __( 'Arguments for the tool.', 'viagent' ),
						),
					),
					'required'   => array( 'name' ),
				),
				'annotations' => array(
					'destructiveHint' => true,
					'openWorldHint'   => false,
				),
			),
		);
	}

	/**
	 * Result of discover_tools.
	 *
	 * @param array<string,WP_Ability> $tools  Tools available to the connection.
	 * @param string                   $search Optional filter.
	 * @return array
	 */
	public static function discover( array $tools, $search ) {
		$result = array();
		foreach ( $tools as $name => $ability ) {
			$haystack = $name . ' ' . $ability->get_label() . ' ' . $ability->get_description();
			if ( '' !== $search && false === stripos( $haystack, $search ) ) {
				continue;
			}
			$annotations = (array) $ability->get_meta_item( 'annotations', array() );
			$sentences   = preg_split( '/(?<=\.)\s/', $ability->get_description(), 2 );
			$result[]    = array(
				'name'        => $name,
				'title'       => $ability->get_label(),
				'summary'     => $sentences[0],
				'read_only'   => ! empty( $annotations['readonly'] ),
				'destructive' => ! empty( $annotations['destructive'] ),
			);
		}
		return array(
			'count' => count( $result ),
			'tools' => $result,
			'next'  => 'Call describe_tool for the input schema, then run_tool.',
		);
	}

	/**
	 * Result of describe_tool.
	 *
	 * @param array<string,WP_Ability> $tools Tools available to the connection.
	 * @param string                   $name  Tool name.
	 * @return array|WP_Error
	 */
	public static function describe( array $tools, $name ) {
		if ( ! isset( $tools[ $name ] ) ) {
			return new WP_Error( 'viagent_unknown_tool', sprintf( 'Unknown tool "%s". Use discover_tools to see available tools.', $name ) );
		}
		return Tool_Registry::definition( $name, $tools[ $name ] );
	}
}
