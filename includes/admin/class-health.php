<?php
/**
 * Connection health checks shown on the Mcpai screen. Each check explains
 * the problem in plain language and how to fix it.
 *
 * Reachability checks (endpoint and Authorization header) run in the browser
 * instead of as loopback requests, which hang on single-threaded dev servers
 * and are blocked by some hosts.
 *
 * @package MCPAI
 */

namespace MCPAI\Admin;

use MCPAI\Security\Policy;

defined( 'ABSPATH' ) || exit;

/**
 * Health checks.
 */
class Health {

	/**
	 * Runs all checks.
	 *
	 * @return array
	 */
	public static function run() {
		return array(
			'is_local' => self::is_local(),
			'checks'   => array_values(
				array_filter(
					array(
						self::check_paused(),
						self::check_permalinks(),
						self::check_https(),
						self::check_public(),
					)
				)
			),
		);
	}

	/**
	 * Builds a check result.
	 *
	 * @param string $id      ID.
	 * @param string $status  "good" | "warning" | "critical" | "info".
	 * @param string $label   Short label.
	 * @param string $message What it means.
	 * @param string $fix     How to fix it (optional).
	 * @return array
	 */
	private static function result( $id, $status, $label, $message, $fix = '' ) {
		return array(
			'id'      => $id,
			'status'  => $status,
			'label'   => $label,
			'message' => $message,
			'fix'     => $fix,
		);
	}

	/**
	 * Whether the site runs on this computer (not reachable from the internet).
	 *
	 * @return bool
	 */
	public static function is_local() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		if ( preg_match( '/\.(local|localhost|test|example|invalid|lan|internal)$/', $host ) ) {
			return true;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}
		return 'local' === wp_get_environment_type();
	}

	/**
	 * Kill switch.
	 *
	 * @return array|null
	 */
	private static function check_paused() {
		if ( ! Policy::is_paused() ) {
			return null;
		}
		return self::result(
			'paused',
			'warning',
			__( 'AI access is paused', 'mcpai' ),
			__( 'All AI apps are blocked until you resume access.', 'mcpai' ),
			__( 'Turn off "Pause all AI access" to reconnect.', 'mcpai' )
		);
	}

	/**
	 * Pretty permalinks.
	 *
	 * @return array
	 */
	private static function check_permalinks() {
		if ( get_option( 'permalink_structure' ) ) {
			return self::result( 'permalinks', 'good', __( 'Permalinks', 'mcpai' ), __( 'Pretty permalinks are on.', 'mcpai' ) );
		}
		return self::result(
			'permalinks',
			'info',
			__( 'Permalinks', 'mcpai' ),
			__( 'Plain permalinks are in use, so the connection URL contains "?rest_route=". It still works, but some apps prefer clean URLs.', 'mcpai' ),
			__( 'Go to Settings → Permalinks and choose "Post name".', 'mcpai' )
		);
	}

	/**
	 * HTTPS.
	 *
	 * @return array
	 */
	private static function check_https() {
		if ( wp_is_using_https() ) {
			return self::result( 'https', 'good', __( 'HTTPS', 'mcpai' ), __( 'Your site uses a secure (HTTPS) address, so keys are encrypted in transit.', 'mcpai' ) );
		}
		return self::result(
			'https',
			self::is_local() ? 'info' : 'critical',
			__( 'HTTPS', 'mcpai' ),
			__( 'Your site does not use HTTPS, so connection keys travel unencrypted. Web apps like Claude.ai and ChatGPT require HTTPS.', 'mcpai' ),
			__( 'Ask your host to enable a free SSL certificate, then switch to https:// in Settings → General.', 'mcpai' )
		);
	}

	/**
	 * Local sites can't be reached by cloud-based apps.
	 *
	 * @return array|null
	 */
	private static function check_public() {
		if ( ! self::is_local() ) {
			return null;
		}
		return self::result(
			'local',
			'info',
			__( 'Local site', 'mcpai' ),
			__( 'This site runs on your computer. Apps on the same computer (Claude Code, Claude Desktop, Cursor, VS Code, Codex…) can connect. Web apps like Claude.ai and ChatGPT cannot reach it.', 'mcpai' ),
			__( 'To use web apps, publish the site or share it through a tunnel or a WordPress Studio preview site.', 'mcpai' )
		);
	}
}
