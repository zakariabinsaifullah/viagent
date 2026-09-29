<?php
/**
 * Removes Mcpai data when the plugin is deleted — only if the site owner
 * turned on "Delete all Mcpai data" in Mcpai → Settings.
 *
 * @package MCPAI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'mcpai_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removes the plugin's own data on uninstall.

/**
 * Removes Mcpai tables, options and transients for the current site.
 */
function mcpai_uninstall_site() {
	global $wpdb;

	foreach ( array( 'keys', 'activity', 'oauth_clients', 'oauth_tokens' ) as $mcpai_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'mcpai_' . $mcpai_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own tables on uninstall.
	}

	$mcpai_options = array(
		'mcpai_db_version',
		'mcpai_paused',
		'mcpai_allow_permanent_delete',
		'mcpai_tool_overrides',
		'mcpai_prompt_overrides',
		'mcpai_compact_mode',
		'mcpai_activation_redirect',
		'mcpai_delete_data_on_uninstall',
	);
	foreach ( $mcpai_options as $mcpai_option ) {
		delete_option( $mcpai_option );
	}

	// Sessions, rate-limit counters and authorization codes.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_mcpai_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_mcpai_' ) . '%'
		)
	);

	wp_clear_scheduled_hook( 'mcpai_prune_activity' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $mcpai_site_id ) {
		switch_to_blog( $mcpai_site_id );
		mcpai_uninstall_site();
		restore_current_blog();
	}
} else {
	mcpai_uninstall_site();
}
