<?php
/**
 * Removes Viagent data when the plugin is deleted — only if the site owner
 * turned on "Delete all Viagent data" in Viagent → Settings.
 *
 * @package Viagent
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'viagent_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removes the plugin's own data on uninstall.

/**
 * Removes Viagent tables, options and transients for the current site.
 */
function viagent_uninstall_site() {
	global $wpdb;

	foreach ( array( 'keys', 'activity', 'oauth_clients', 'oauth_tokens' ) as $viagent_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'viagent_' . $viagent_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own tables on uninstall.
	}

	$viagent_options = array(
		'viagent_db_version',
		'viagent_paused',
		'viagent_allow_permanent_delete',
		'viagent_tool_overrides',
		'viagent_prompt_overrides',
		'viagent_compact_mode',
		'viagent_activation_redirect',
		'viagent_delete_data_on_uninstall',
	);
	foreach ( $viagent_options as $viagent_option ) {
		delete_option( $viagent_option );
	}

	// Sessions, rate-limit counters and authorization codes.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_viagent_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_viagent_' ) . '%'
		)
	);

	wp_clear_scheduled_hook( 'viagent_prune_activity' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $viagent_site_id ) {
		switch_to_blog( $viagent_site_id );
		viagent_uninstall_site();
		restore_current_blog();
	}
} else {
	viagent_uninstall_site();
}
