<?php
/**
 * Optional integrations with popular plugins. Each one registers its tools only
 * when its plugin is active.
 *
 * @package Viagent
 */

namespace Viagent\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Integrations registry.
 */
class Integrations {

	/**
	 * Known integrations.
	 *
	 * @return array<string,array{class:string,active:callable}>
	 */
	private static function known() {
		return array(
			'woocommerce' => array(
				'class'  => WooCommerce::class,
				'active' => static function () {
					return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
				},
			),
			'seo'         => array(
				'class'  => SEO::class,
				'active' => static function () {
					return null !== SEO::provider();
				},
			),
			'forms'       => array(
				'class'  => Forms::class,
				'active' => static function () {
					return ! empty( Forms::providers() );
				},
			),
			'network'     => array(
				'class'  => Network::class,
				'active' => static function () {
					return is_multisite();
				},
			),
			'acf'         => array(
				'class'  => ACF::class,
				'active' => static function () {
					return function_exists( 'acf_get_field_groups' ) && function_exists( 'update_field' );
				},
			),
		);
	}

	/**
	 * Human-readable integration name. Kept out of known() so translations are
	 * only loaded when the admin screens need them, never before init.
	 *
	 * @param string $slug Integration slug.
	 * @return string
	 */
	private static function label( $slug ) {
		switch ( $slug ) {
			case 'woocommerce':
				return 'WooCommerce';
			case 'seo':
				return __( 'SEO (Yoast SEO or Rank Math)', 'viagent' );
			case 'forms':
				return __( 'Forms (Contact Form 7, WPForms, Gravity Forms)', 'viagent' );
			case 'network':
				return __( 'Multisite network', 'viagent' );
			case 'acf':
				return __( 'Advanced Custom Fields', 'viagent' );
		}
		return $slug;
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
				'name'   => self::label( $slug ),
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
