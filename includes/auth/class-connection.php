<?php
/**
 * The authenticated connection for the current MCP request.
 *
 * @package Viagent
 */

namespace Viagent\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object describing who is connected and what they may do.
 */
class Connection {

	/**
	 * How the client authenticated: "api_key", "oauth" or "app_password".
	 *
	 * @var string
	 */
	public $method;

	/**
	 * WordPress user the AI acts as.
	 *
	 * @var int
	 */
	public $user_id;

	/**
	 * Access level (see Policy).
	 *
	 * @var string
	 */
	public $access_level;

	/**
	 * Whether the AI may only work with drafts.
	 *
	 * @var bool
	 */
	public $draft_only;

	/**
	 * Whether to expose only the three meta-tools (for apps with tool limits).
	 *
	 * @var bool
	 */
	public $compact;

	/**
	 * Program that made the request, e.g. "Antigravity" (from the MCP
	 * handshake) or "curl" (from the HTTP client). Shown in Activity so shared
	 * keys are easy to spot.
	 *
	 * @var string
	 */
	public $agent = '';

	/**
	 * Credential ID (API key or OAuth token row), 0 for application passwords.
	 *
	 * @var int
	 */
	public $credential_id;

	/**
	 * Friendly name of the connection.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Constructor.
	 *
	 * @param array $args Properties.
	 */
	public function __construct( array $args ) {
		$this->method        = (string) $args['method'];
		$this->user_id       = (int) $args['user_id'];
		$this->access_level  = (string) $args['access_level'];
		$this->draft_only    = (bool) $args['draft_only'];
		$this->compact       = ! empty( $args['compact'] ) || (bool) get_option( 'viagent_compact_mode', false );
		$this->credential_id = (int) ( $args['credential_id'] ?? 0 );
		$this->name          = (string) ( $args['name'] ?? '' );
	}
}
