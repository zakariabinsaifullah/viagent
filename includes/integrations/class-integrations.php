<?php
/**
 * Optional integrations with popular plugins. Each one registers its tools only
 * when its plugin is active.
 *
 * @package MCPAI
 */

namespace MCPAI\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Integrations registry.
 */
class Integrations {

	/**
	 * Known integrations.
	 *
	 * @return array<string,array{name:string,class:string,active:callable}>
	 */
	private static function known() {
		return array(
			'woocommerce' => array(
				'name'   => 'WooCommerce',
				'class'  => WooCommerce::class,
				'active' => static function () {
					return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
				},
			),
			'seo'         => array(
				'name'   => __( 'SEO (Yoast SEO or Rank Math)', 'mcpai' ),
				'class'  => SEO::class,
				'active' => static function () {
					return null !== SEO::provider();
				},
			),
			'forms'       => array(
				'name'   => __( 'Forms (Contact Form 7, WPForms, Gravity Forms)', 'mcpai' ),
				'class'  => Forms::class,
				'active' => static function () {
					return ! empty( Forms::providers() );
				},
			),
			'network'     => array(
				'name'   => __( 'Multisite network', 'mcpai' ),
				'class'  => Network::class,
				'active' => static function () {
					return is_multisite();
				},
			),
			'acf'         => array(
				'name'   => __( 'Advanced Custom Fields', 'mcpai' ),
				'class'  => ACF::class,
				'active' => static function () {
					return function_exists( 'acf_get_field_groups' ) && function_exists( 'update_field' );
				},
			),
		);
	}

	/**
	 * Active integration classes.
	 *
	 * @return string[]
	 */
	private static function active_classes() {
		$classes = array();
		foreach ( self::known() as $integration ) {
			if ( call_user_func( $integration['active'] ) ) {
				$classes[] = $integration['class'];
			}
		}
		return $classes;
	}

	/**
	 * Status for the admin screens.
	 *
	 * @return array[]
	 */
	public static function status() {
		$status = array();
		foreach ( self::known() as $slug => $integration ) {
			$status[] = array(
				'slug'   => $slug,
				'name'   => $integration['name'],
				'active' => (bool) call_user_func( $integration['active'] ),
			);
		}
		return $status;
	}

	/**
	 * Registers hooks once all plugins are loaded.
	 */
	public static function init() {
		foreach ( self::active_classes() as $class ) {
			$class::init();
		}
	}

	/**
	 * Registers ability categories for active integrations.
	 */
	public static function register_categories() {
		foreach ( self::active_classes() as $class ) {
			$class::register_categories();
		}
	}

	/**
	 * Registers abilities for active integrations.
	 */
	public static function register() {
		foreach ( self::active_classes() as $class ) {
			$class::register();
		}
	}
}
