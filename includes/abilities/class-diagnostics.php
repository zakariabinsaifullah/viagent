<?php
/**
 * Diagnostics abilities: site health overview and the PHP error log.
 *
 * @package MCPAI
 */

namespace MCPAI\Abilities;

use MCPAI\Security\Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostics abilities.
 */
class Diagnostics {

	const MAX_LOG_BYTES = 65536;

	/**
	 * Registers abilities.
	 */
	public static function register() {
		Abilities::add(
			'get-site-health',
			array(
				'category'    => 'mcpai-diagnostics',
				'label'       => __( 'Get site health', 'mcpai' ),
				'description' => __( 'Technical overview for troubleshooting: WordPress/PHP versions, pending updates, debug settings, memory, HTTPS and cron.', 'mcpai' ),
				'execute'     => array( self::class, 'get_site_health' ),
				'permission'  => 'manage_options',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		$log_meta                             = Abilities::read_meta( Policy::ADMIN );
		$log_meta['mcpai']['default_enabled'] = false;

		Abilities::add(
			'get-error-log',
			array(
				'category'    => 'mcpai-diagnostics',
				'label'       => __( 'Get PHP error log', 'mcpai' ),
				'description' => __( 'Returns the last lines of the PHP / WordPress debug log to help find errors.', 'mcpai' ),
				'input'       => array(
					'lines' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
						'default' => 50,
					),
				),
				'execute'     => array( self::class, 'get_error_log' ),
				'permission'  => 'manage_options',
				'meta'        => $log_meta,
			)
		);
	}

	/**
	 * Site health overview.
	 *
	 * @return array
	 */
	public static function get_site_health() {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$core    = get_site_transient( 'update_core' );
		$plugins = get_site_transient( 'update_plugins' );
		$themes  = get_site_transient( 'update_themes' );

		$core_update = null;
		if ( isset( $core->updates[0] ) && 'upgrade' === $core->updates[0]->response ) {
			$core_update = $core->updates[0]->current;
		}

		return array(
			'wordpress'         => get_bloginfo( 'version' ),
			'php'               => PHP_VERSION,
			'database'          => defined( 'DB_ENGINE' ) ? DB_ENGINE : 'mysql',
			'https'             => wp_is_using_https(),
			'permalinks'        => get_option( 'permalink_structure' ) ? 'pretty' : 'plain',
			'memory_limit'      => WP_MEMORY_LIMIT,
			'object_cache'      => wp_using_ext_object_cache(),
			'debug'             => array(
				'WP_DEBUG'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'WP_DEBUG_LOG'     => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
				'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			),
			'cron_disabled'     => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'active_plugins'    => count( (array) get_option( 'active_plugins', array() ) ),
			'updates_available' => array(
				'core'    => $core_update,
				'plugins' => isset( $plugins->response ) ? count( $plugins->response ) : 0,
				'themes'  => isset( $themes->response ) ? count( $themes->response ) : 0,
			),
		);
	}

	/**
	 * Path of the active error log, if any.
	 *
	 * @return string
	 */
	private static function log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && WP_DEBUG_LOG ) {
			return WP_DEBUG_LOG;
		}
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			return WP_CONTENT_DIR . '/debug.log';
		}
		return (string) ini_get( 'error_log' );
	}

	/**
	 * Tail of the error log.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_error_log( $input ) {
		$path = self::log_path();
		if ( '' === $path || ! is_readable( $path ) ) {
			return new WP_Error( 'mcpai_no_log', __( 'No readable error log. The site owner can turn on WP_DEBUG_LOG to start logging.', 'mcpai' ) );
		}

		$size   = filesize( $path );
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return new WP_Error( 'mcpai_no_log', __( 'The error log could not be opened.', 'mcpai' ) );
		}
		fseek( $handle, max( 0, $size - self::MAX_LOG_BYTES ) );
		$tail = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$lines = array_slice( preg_split( '/\r\n|\n/', trim( $tail ) ), -1 * (int) ( $input['lines'] ?? 50 ) );

		return array(
			'file'  => basename( $path ),
			'size'  => size_format( $size ),
			'lines' => $lines,
		);
	}
}
