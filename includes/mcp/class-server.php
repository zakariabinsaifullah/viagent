<?php
/**
 * MCP JSON-RPC method dispatcher.
 *
 * @package Viagent
 */

namespace Viagent\MCP;

use Viagent\Auth\Connection;
use Viagent\Log\Activity_Log;
use Viagent\Security\Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * MCP server.
 */
class Server {

	/**
	 * Protocol versions this server speaks, newest first.
	 */
	const PROTOCOL_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

	const PARSE_ERROR      = -32700;
	const INVALID_REQUEST  = -32600;
	const METHOD_NOT_FOUND = -32601;
	const INVALID_PARAMS   = -32602;
	const INTERNAL_ERROR   = -32603;

	/**
	 * Connection making the request.
	 *
	 * @var Connection
	 */
	private $connection;

	/**
	 * Constructor.
	 *
	 * @param Connection $connection Connection.
	 */
	public function __construct( Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * Handles one JSON-RPC message.
	 *
	 * @param mixed $message Decoded message.
	 * @return array|null Response, or null for notifications.
	 */
	public function handle( $message ) {
		if ( ! is_array( $message ) || ( $message['jsonrpc'] ?? '' ) !== '2.0' || ! isset( $message['method'] ) || ! is_string( $message['method'] ) ) {
			return self::error( null, self::INVALID_REQUEST, 'Invalid JSON-RPC request.' );
		}

		$is_notification = ! array_key_exists( 'id', $message );
		$id              = $is_notification ? null : $message['id'];
		$params          = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();

		if ( $is_notification ) {
			// notifications/initialized, notifications/cancelled, … need no reply.
			return null;
		}

		switch ( $message['method'] ) {
			case 'initialize':
				return self::result( $id, $this->initialize( $params ) );
			case 'ping':
				return self::result( $id, new \stdClass() );
			case 'tools/list':
				return self::result( $id, $this->tools_list() );
			case 'tools/call':
				return $this->tools_call( $id, $params );
			case 'resources/list':
				return self::result( $id, array( 'resources' => array() ) );
			case 'resources/templates/list':
				return self::result( $id, array( 'resourceTemplates' => array() ) );
			case 'prompts/list':
				return self::result( $id, Prompts::list_result( $this->connection ) );
			case 'prompts/get':
				$prompt = Prompts::get_result( $this->connection, (string) ( $params['name'] ?? '' ), (array) ( $params['arguments'] ?? array() ) );
				return is_wp_error( $prompt )
					? self::error( $id, self::INVALID_PARAMS, $prompt->get_error_message() )
					: self::result( $id, $prompt );
			default:
				return self::error( $id, self::METHOD_NOT_FOUND, sprintf( 'Method not found: %s', $message['method'] ) );
		}
	}

	/**
	 * Picks the protocol version to use for a client request.
	 *
	 * @param string $requested Version requested by the client.
	 * @return string
	 */
	public static function negotiate_version( $requested ) {
		return in_array( $requested, self::PROTOCOL_VERSIONS, true ) ? $requested : self::PROTOCOL_VERSIONS[0];
	}

	/**
	 * `initialize` result.
	 *
	 * @param array $params Params.
	 * @return array
	 */
	private function initialize( array $params ) {
		return array(
			'protocolVersion' => self::negotiate_version( (string) ( $params['protocolVersion'] ?? '' ) ),
			'capabilities'    => array(
				'tools'   => array( 'listChanged' => false ),
				'prompts' => array( 'listChanged' => false ),
			),
			'serverInfo'      => array(
				'name'    => 'viagent',
				'title'   => get_bloginfo( 'name' ) . ' (WordPress)',
				'version' => VIAGENT_VERSION,
			),
			'instructions'    => $this->instructions(),
		);
	}

	/**
	 * Short site brief that tells the AI how to behave on this site.
	 *
	 * @return string
	 */
	private function instructions() {
		$user   = wp_get_current_user();
		$labels = Policy::level_labels();
		$lines  = array(
			sprintf( 'You are connected to the WordPress site "%s" (%s).', get_bloginfo( 'name' ), home_url( '/' ) ),
			sprintf( 'You act as the user "%s" with the access level "%s".', $user->user_login, $labels[ $this->connection->access_level ] ?? $this->connection->access_level ),
			'Call get_site_info first to learn about the site, its post types and theme.',
			'Write post content as WordPress block markup (<!-- wp:paragraph --> etc.) so it stays editable in the block editor.',
			'Deleting moves items to the trash; tell the user what you changed and include links.',
			'Every change is logged, and the site owner can undo it from WordPress → Viagent → Activity.',
		);
		if ( $this->connection->compact ) {
			$lines[2] = 'Tools are grouped: call discover_tools to list them, describe_tool for a tool\'s inputs, and run_tool to use it. Start with run_tool name "get_site_info".';
		}
		/**
		 * Filters the instructions sent to AI apps when they connect.
		 *
		 * @param string[] $lines Instruction lines.
		 */
		$lines = apply_filters( 'viagent_instructions', $lines );
		if ( $this->connection->draft_only ) {
			$lines[] = 'Draft-only mode is ON: create and edit drafts only; you cannot publish, schedule or delete. Ask the user to publish from WordPress.';
		}
		return implode( "\n", $lines );
	}

	/**
	 * `tools/list` result.
	 *
	 * @return array
	 */
	private function tools_list() {
		if ( $this->connection->compact ) {
			return array( 'tools' => Meta_Tools::definitions() );
		}
		$tools = array();
		foreach ( Tool_Registry::for_connection( $this->connection ) as $name => $ability ) {
			$tools[] = Tool_Registry::definition( $name, $ability );
		}
		return array( 'tools' => $tools );
	}

	/**
	 * Handles `tools/call`. Tool failures are reported as `isError` results so the AI can react.
	 *
	 * @param mixed $id     Request ID.
	 * @param array $params Params.
	 * @return array
	 */
	private function tools_call( $id, array $params ) {
		$name      = (string) ( $params['name'] ?? '' );
		$arguments = $params['arguments'] ?? array();
		$tools     = Tool_Registry::for_connection( $this->connection );

		if ( Meta_Tools::is_meta( $name ) ) {
			$arguments = is_array( $arguments ) ? $arguments : array();
			if ( Meta_Tools::RUN === $name ) {
				$inner = (string) ( $arguments['name'] ?? '' );
				if ( Meta_Tools::is_meta( $inner ) ) {
					return self::error( $id, self::INVALID_PARAMS, 'run_tool cannot run a meta-tool.' );
				}
				return $this->tools_call(
					$id,
					array(
						'name'      => $inner,
						'arguments' => $arguments['arguments'] ?? array(),
					)
				);
			}
			$result = Meta_Tools::DISCOVER === $name
				? Meta_Tools::discover( $tools, (string) ( $arguments['search'] ?? '' ) )
				: Meta_Tools::describe( $tools, (string) ( $arguments['name'] ?? '' ) );
			return $this->tool_result( $id, $result );
		}

		if ( ! isset( $tools[ $name ] ) ) {
			return self::error( $id, self::INVALID_PARAMS, sprintf( 'Unknown tool: %s', $name ) );
		}
		if ( ! is_array( $arguments ) ) {
			return self::error( $id, self::INVALID_PARAMS, 'Tool arguments must be an object.' );
		}

		$result = Policy::check_rate_limit( $this->connection );
		if ( true === $result ) {
			$started = microtime( true );
			Activity_Log::begin();
			try {
				$result = Tool_Registry::call( $tools[ $name ], $arguments );
			} catch ( \Throwable $e ) {
				$result = new WP_Error( 'viagent_exception', $e->getMessage() );
			}
			Activity_Log::record( $this->connection, $name, $arguments, $result, (int) round( ( microtime( true ) - $started ) * 1000 ) );
		}

		return $this->tool_result( $id, $result );
	}

	/**
	 * Wraps a tool's return value (or WP_Error) as an MCP tool result.
	 *
	 * @param mixed $id     Request ID.
	 * @param mixed $result Result.
	 * @return array
	 */
	private function tool_result( $id, $result ) {
		if ( is_wp_error( $result ) ) {
			return self::result(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => self::error_text( $result ),
						),
					),
					'isError' => true,
				)
			);
		}

		return self::result(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
					),
				),
			)
		);
	}

	/**
	 * Human-readable text for a WP_Error, including every message it carries.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	private static function error_text( WP_Error $error ) {
		if ( 'ability_invalid_permissions' === $error->get_error_code() ) {
			return sprintf(
				'Error: The connected user "%s" is not allowed to do this. If it is needed, ask the site owner to grant more access in WordPress → Viagent. (forbidden)',
				wp_get_current_user()->user_login
			);
		}

		$messages = array();
		foreach ( $error->get_error_codes() as $code ) {
			foreach ( $error->get_error_messages( $code ) as $message ) {
				$messages[] = wp_strip_all_tags( $message ) . ' (' . $code . ')';
			}
		}
		return 'Error: ' . implode( ' ', $messages );
	}

	/**
	 * JSON-RPC success envelope.
	 *
	 * @param mixed $id     Request ID.
	 * @param mixed $result Result.
	 * @return array
	 */
	public static function result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * JSON-RPC error envelope.
	 *
	 * @param mixed  $id      Request ID.
	 * @param int    $code    Error code.
	 * @param string $message Message.
	 * @return array
	 */
	public static function error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
