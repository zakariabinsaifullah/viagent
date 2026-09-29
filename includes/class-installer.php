<?php
/**
 * Database tables and version migrations.
 *
 * Schema stays SQLite-safe (no ENUM / FULLTEXT) so it works on WordPress Studio.
 *
 * @package MCPAI
 */

namespace MCPAI;

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
class Installer {

	const DB_VERSION        = '7';
	const DB_VERSION_OPTION = 'mcpai_db_version';

	/**
	 * Table short names.
	 */
	const TABLES = array( 'keys', 'activity', 'oauth_clients', 'oauth_tokens' );

	/**
	 * Activation hook. On a network activation every site gets its tables.
	 *
	 * @param bool $network_wide Whether the plugin is network-activated.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}
		self::install();
		add_option( Admin\Admin::REDIRECT_OPTION, 1 );
	}

	/**
	 * Creates tables for a new site in a network where Mcpai is network-activated.
	 *
	 * @param \WP_Site $site New site.
	 */
	public static function initialize_site( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active_for_network( plugin_basename( MCPAI_FILE ) ) ) {
			switch_to_blog( $site->blog_id );
			self::install();
			restore_current_blog();
		}
	}

	/**
	 * Drops Mcpai tables when a site is deleted from the network.
	 *
	 * @param string[] $tables  Tables WordPress will drop.
	 * @param int      $site_id Site ID.
	 * @return string[]
	 */
	public static function drop_site_tables( $tables, $site_id ) {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $site_id );
		foreach ( self::TABLES as $table ) {
			$tables[] = $prefix . 'mcpai_' . $table;
		}
		return $tables;
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate() {
		Log\Activity_Log::unschedule();
	}

	/**
	 * Runs the installer when the stored schema version is outdated.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Returns a table name with the site prefix.
	 *
	 * @param string $name Short table name, e.g. "keys".
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'mcpai_' . $name;
	}

	/**
	 * Creates or updates tables.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$keys     = self::table( 'keys' );
		$activity = self::table( 'activity' );
		$clients  = self::table( 'oauth_clients' );
		$tokens   = self::table( 'oauth_tokens' );

		dbDelta(
			"CREATE TABLE {$keys} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				name varchar(190) NOT NULL DEFAULT '',
				client varchar(64) NOT NULL DEFAULT '',
				key_prefix varchar(24) NOT NULL DEFAULT '',
				key_hash char(64) NOT NULL,
				access_level varchar(20) NOT NULL DEFAULT 'read',
				draft_only tinyint(1) NOT NULL DEFAULT 1,
				compact tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				last_used_at datetime DEFAULT NULL,
				expires_at datetime DEFAULT NULL,
				revoked_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY key_hash (key_hash),
				KEY user_id (user_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$activity} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				method varchar(20) NOT NULL DEFAULT '',
				credential_id bigint(20) unsigned NOT NULL DEFAULT 0,
				connection varchar(190) NOT NULL DEFAULT '',
				agent varchar(100) NOT NULL DEFAULT '',
				tool varchar(100) NOT NULL DEFAULT '',
				arguments longtext,
				status varchar(10) NOT NULL DEFAULT 'ok',
				message text,
				duration_ms int(11) unsigned NOT NULL DEFAULT 0,
				object_type varchar(20) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				undo_data longtext,
				site_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reverted_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY tool (tool)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$clients} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				client_id varchar(255) NOT NULL,
				client_name varchar(190) NOT NULL DEFAULT '',
				redirect_uris text NOT NULL,
				created_at datetime NOT NULL,
				last_used_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY client_id (client_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$tokens} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				client_id varchar(255) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				access_hash char(64) NOT NULL,
				refresh_hash char(64) NOT NULL,
				access_level varchar(20) NOT NULL DEFAULT 'read',
				draft_only tinyint(1) NOT NULL DEFAULT 1,
				compact tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				access_expires_at datetime NOT NULL,
				refresh_expires_at datetime NOT NULL,
				last_used_at datetime DEFAULT NULL,
				revoked_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY access_hash (access_hash),
				UNIQUE KEY refresh_hash (refresh_hash),
				KEY client_id (client_id)
			) {$charset};"
		);

		Log\Activity_Log::schedule();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}
}
