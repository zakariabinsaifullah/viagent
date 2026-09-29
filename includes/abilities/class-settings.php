<?php
/**
 * Settings abilities. Only an allowlist of safe options can be changed;
 * site URLs, admin email, registration and role settings are never writable.
 *
 * @package MCPAI
 */

namespace MCPAI\Abilities;

use MCPAI\Log\Activity_Log;
use MCPAI\Security\Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Settings abilities.
 */
class Settings {

	/**
	 * Writable options and their input schema.
	 *
	 * @return array<string,array>
	 */
	public static function writable() {
		$settings = array(
			'blogname'               => array(
				'type'        => 'string',
				'description' => __( 'Site title.', 'mcpai' ),
			),
			'blogdescription'        => array(
				'type'        => 'string',
				'description' => __( 'Tagline.', 'mcpai' ),
			),
			'timezone_string'        => array(
				'type'        => 'string',
				'description' => __( 'e.g. "Europe/London".', 'mcpai' ),
			),
			'date_format'            => array( 'type' => 'string' ),
			'time_format'            => array( 'type' => 'string' ),
			'start_of_week'          => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 6,
			),
			'posts_per_page'         => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
			),
			'show_on_front'          => array(
				'type'        => 'string',
				'enum'        => array( 'posts', 'page' ),
				'description' => __( 'Homepage shows latest posts or a static page.', 'mcpai' ),
			),
			'page_on_front'          => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Page ID used as the homepage when show_on_front is "page".', 'mcpai' ),
			),
			'page_for_posts'         => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Page ID that lists posts when show_on_front is "page".', 'mcpai' ),
			),
			'default_comment_status' => array(
				'type' => 'string',
				'enum' => array( 'open', 'closed' ),
			),
			'comment_moderation'     => array(
				'type'        => 'boolean',
				'description' => __( 'Comments must be manually approved.', 'mcpai' ),
			),
			'blog_public'            => array(
				'type'        => 'boolean',
				'description' => __( 'Allow search engines to index the site.', 'mcpai' ),
			),
			'site_icon'              => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Media library image ID used as the site icon.', 'mcpai' ),
			),
		);

		/**
		 * Filters the options AI agents may change. Never add security-sensitive options.
		 *
		 * @param array<string,array> $settings Option name => JSON schema.
		 */
		return apply_filters( 'mcpai_writable_settings', $settings );
	}

	/**
	 * Registers abilities.
	 */
	public static function register() {
		Abilities::add(
			'get-settings',
			array(
				'category'    => 'mcpai-settings',
				'label'       => __( 'Get settings', 'mcpai' ),
				'description' => __( 'Gets general, reading and discussion settings (site title, tagline, homepage, timezone, comments…).', 'mcpai' ),
				'execute'     => array( self::class, 'get_settings' ),
				'permission'  => 'manage_options',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'update-settings',
			array(
				'category'    => 'mcpai-settings',
				'label'       => __( 'Update settings', 'mcpai' ),
				'description' => __( 'Changes one or more site settings. Only the settings listed in the input are allowed.', 'mcpai' ),
				'input'       => self::writable(),
				'execute'     => array( self::class, 'update_settings' ),
				'permission'  => 'manage_options',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);
	}

	/**
	 * Gets settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$result = array();
		foreach ( self::writable() as $name => $schema ) {
			$value           = get_option( $name );
			$result[ $name ] = 'boolean' === $schema['type'] ? (bool) $value : ( 'integer' === $schema['type'] ? (int) $value : $value );
		}

		$result['read_only'] = array(
			'siteurl'     => get_option( 'siteurl' ),
			'home'        => get_option( 'home' ),
			'admin_email' => get_option( 'admin_email' ),
			'language'    => get_locale(),
			'permalinks'  => get_option( 'permalink_structure' ),
		);
		return $result;
	}

	/**
	 * Updates settings.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_settings( $input ) {
		$writable = self::writable();
		$changes  = array_intersect_key( (array) $input, $writable );
		if ( empty( $changes ) ) {
			return new WP_Error( 'mcpai_nothing_to_update', __( 'Pass at least one setting to change.', 'mcpai' ) );
		}

		foreach ( array( 'page_on_front', 'page_for_posts' ) as $page_option ) {
			if ( ! empty( $changes[ $page_option ] ) && 'page' !== get_post_type( (int) $changes[ $page_option ] ) ) {
				return new WP_Error( 'mcpai_invalid_page', sprintf( /* translators: %s: option name */ __( '%s must be the ID of a page.', 'mcpai' ), $page_option ) );
			}
		}
		if ( isset( $changes['timezone_string'] ) && ! in_array( $changes['timezone_string'], timezone_identifiers_list(), true ) ) {
			return new WP_Error( 'mcpai_invalid_timezone', __( 'Unknown timezone. Use a name like "America/New_York".', 'mcpai' ) );
		}

		$previous = array();
		foreach ( $changes as $name => $value ) {
			$previous[ $name ] = get_option( $name );
			if ( is_bool( $value ) ) {
				$value = $value ? 1 : 0;
			}
			update_option( $name, sanitize_option( $name, $value ) );
		}

		Activity_Log::set_object( 'settings', 0 );
		Activity_Log::set_undo(
			array(
				'action'  => 'restore_options',
				'options' => $previous,
			)
		);

		return array(
			'updated'  => array_keys( $changes ),
			'settings' => self::get_settings(),
		);
	}
}
