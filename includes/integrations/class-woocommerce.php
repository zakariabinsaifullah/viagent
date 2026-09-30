<?php
/**
 * WooCommerce tools: store overview, products, orders, coupons and customers.
 *
 * Products follow the same safety rules as posts (draft-only mode, trash,
 * undo). Orders, coupons and customers contain business and personal data,
 * so they need Full control.
 *
 * @package Viagent
 */

namespace Viagent\Integrations;

use Viagent\Abilities\Abilities;
use Viagent\Abilities\Taxonomies;
use Viagent\Log\Activity_Log;
use Viagent\Security\Policy;
use WC_Order;
use WC_Product;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce integration.
 */
class WooCommerce {

	const CATEGORY = 'viagent-woocommerce';

	/**
	 * Product fields that can be set on create and update.
	 */
	const PRODUCT_PROPS = array( 'name', 'status', 'regular_price', 'sale_price', 'description', 'short_description', 'sku', 'manage_stock', 'stock_quantity', 'stock_status', 'featured', 'virtual', 'image_id', 'gallery_image_ids', 'product_url', 'button_text' );

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_filter( 'viagent_post_types', array( self::class, 'hide_store_post_types' ) );
		add_filter( 'viagent_apply_undo', array( self::class, 'apply_undo' ), 10, 2 );
		add_filter( 'viagent_undo_label', array( self::class, 'undo_label' ), 10, 2 );
		add_filter( 'viagent_tool_summaries', array( self::class, 'summaries' ) );
		add_filter( 'viagent_instructions', array( self::class, 'instructions' ) );
	}

	/**
	 * Registers the ability category.
	 */
	public static function register_categories() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'WooCommerce', 'viagent' ),
				'description' => __( 'Products, orders, coupons and customers.', 'viagent' ),
			)
		);
	}

	/**
	 * Store products, orders and coupons have dedicated tools, so the generic
	 * post tools leave them alone.
	 *
	 * @param array $types Post types.
	 * @return array
	 */
	public static function hide_store_post_types( $types ) {
		unset( $types['product'], $types['product_variation'], $types['shop_order'], $types['shop_order_placehold'], $types['shop_coupon'], $types['shop_order_refund'] );
		return $types;
	}

	/**
	 * Tells AI apps how to work with the store.
	 *
	 * @param string[] $lines Instruction lines.
	 * @return string[]
	 */
	public static function instructions( $lines ) {
		$lines[] = sprintf(
			'This site runs WooCommerce (currency %s). Use the product tools (list_products, create_product, update_product…) for products — not create_post — and the order, coupon and customer tools for store data. Prices are decimal strings such as "19.99".',
			get_woocommerce_currency()
		);
		return $lines;
	}

	/**
	 * Registers abilities.
	 */
	public static function register() {
		$id     = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$fields = self::product_schema();

		Abilities::add(
			'wc-store-overview',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Store overview', 'viagent' ),
				'description' => __( 'WooCommerce store summary: currency, product and order counts by status, revenue in the last 30 days and low-stock products.', 'viagent' ),
				'execute'     => array( self::class, 'overview' ),
				'permission'  => 'view_woocommerce_reports',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'list-products',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'List products', 'viagent' ),
				'description' => __( 'Lists store products with price, stock and status. Filter by search words, status, category slug, SKU or stock status.', 'viagent' ),
				'input'       => array_merge(
					array(
						'search'       => array( 'type' => 'string' ),
						'status'       => array(
							'type'    => 'string',
							'enum'    => array( 'any', 'publish', 'draft', 'pending', 'private', 'trash' ),
							'default' => 'any',
						),
						'category'     => array(
							'type'        => 'string',
							'description' => __( 'Product category slug.', 'viagent' ),
						),
						'sku'          => array( 'type' => 'string' ),
						'stock_status' => array(
							'type' => 'string',
							'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
						),
					),
					Abilities::paging()
				),
				'execute'     => array( self::class, 'list_products' ),
				'permission'  => 'edit_products',
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'get-product',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Get product', 'viagent' ),
				'description' => __( 'Gets one product with descriptions, prices, stock, images, categories, attributes and variations.', 'viagent' ),
				'input'       => array( 'id' => $id ),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'get_product' ),
				'permission'  => 'edit_products',
				'meta'        => Abilities::read_meta(),
			)
		);

		Abilities::add(
			'create-product',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Create product', 'viagent' ),
				'description' => __( 'Creates a simple or external product. Status defaults to "draft". Categories and tags can be names (created if missing). Use upload_media_from_url first to get image IDs.', 'viagent' ),
				'input'       => array_merge(
					array(
						'type' => array(
							'type'    => 'string',
							'enum'    => array( 'simple', 'external' ),
							'default' => 'simple',
						),
					),
					$fields
				),
				'required'    => array( 'name' ),
				'execute'     => array( self::class, 'create_product' ),
				'permission'  => 'edit_products',
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);

		Abilities::add(
			'update-product',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Update product', 'viagent' ),
				'description' => __( 'Updates a product. Only the fields you pass change. To remove a sale price, pass an empty string. The previous values are kept so the change can be undone.', 'viagent' ),
				'input'       => array_merge( array( 'id' => $id ), $fields ),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'update_product' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'edit_product', (int) ( $input['id'] ?? 0 ) ) || ! wc_get_product( (int) ( $input['id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'draft_safe' => true ) ),
			)
		);

		Abilities::add(
			'delete-product',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Delete product', 'viagent' ),
				'description' => __( 'Moves a product to the trash (it can be restored with restore_post).', 'viagent' ),
				'input'       => array( 'id' => $id ),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'delete_product' ),
				'permission'  => static function ( $input ) {
					return current_user_can( 'delete_product', (int) ( $input['id'] ?? 0 ) ) || ! wc_get_product( (int) ( $input['id'] ?? 0 ) );
				},
				'meta'        => Abilities::write_meta( Policy::CONTENT, array( 'destructive' => true ) ),
			)
		);

		$statuses = array_map(
			static function ( $key ) {
				return substr( $key, 3 );
			},
			array_keys( wc_get_order_statuses() )
		);

		Abilities::add(
			'list-orders',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'List orders', 'viagent' ),
				'description' => __( 'Lists orders, newest first. Filter by status, customer email or date.', 'viagent' ),
				'input'       => array_merge(
					array(
						'status'         => array(
							'type' => 'string',
							'enum' => array_merge( array( 'any' ), $statuses ),
						),
						'customer_email' => array( 'type' => 'string' ),
						'after'          => array(
							'type'        => 'string',
							'description' => __( 'Only orders created after this date (YYYY-MM-DD).', 'viagent' ),
						),
					),
					Abilities::paging()
				),
				'execute'     => array( self::class, 'list_orders' ),
				'permission'  => 'edit_shop_orders',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'get-order',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Get order', 'viagent' ),
				'description' => __( 'Gets one order with items, totals, customer, addresses and notes.', 'viagent' ),
				'input'       => array( 'id' => $id ),
				'required'    => array( 'id' ),
				'execute'     => array( self::class, 'get_order' ),
				'permission'  => 'edit_shop_orders',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'update-order-status',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Update order status', 'viagent' ),
				'description' => __( 'Changes an order\'s status, e.g. to "completed". WooCommerce may email the customer about the change.', 'viagent' ),
				'input'       => array(
					'id'     => $id,
					'status' => array(
						'type' => 'string',
						'enum' => $statuses,
					),
					'note'   => array(
						'type'        => 'string',
						'description' => __( 'Optional private note explaining the change.', 'viagent' ),
					),
				),
				'required'    => array( 'id', 'status' ),
				'execute'     => array( self::class, 'update_order_status' ),
				'permission'  => 'edit_shop_orders',
				'meta'        => Abilities::write_meta( Policy::ADMIN, array( 'idempotent' => true ) ),
			)
		);

		Abilities::add(
			'add-order-note',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Add order note', 'viagent' ),
				'description' => __( 'Adds a note to an order. Private by default; set customer_note to true to email it to the customer.', 'viagent' ),
				'input'       => array(
					'id'            => $id,
					'note'          => array(
						'type'      => 'string',
						'minLength' => 1,
					),
					'customer_note' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'    => array( 'id', 'note' ),
				'execute'     => array( self::class, 'add_order_note' ),
				'permission'  => 'edit_shop_orders',
				'meta'        => Abilities::write_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'list-coupons',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'List coupons', 'viagent' ),
				'description' => __( 'Lists discount coupons with amount, usage and expiry.', 'viagent' ),
				'input'       => Abilities::paging(),
				'execute'     => array( self::class, 'list_coupons' ),
				'permission'  => 'edit_shop_coupons',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'create-coupon',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'Create coupon', 'viagent' ),
				'description' => __( 'Creates a discount coupon that customers can use right away.', 'viagent' ),
				'input'       => array(
					'code'           => array(
						'type'      => 'string',
						'minLength' => 1,
					),
					'discount_type'  => array(
						'type'    => 'string',
						'enum'    => array( 'percent', 'fixed_cart', 'fixed_product' ),
						'default' => 'percent',
					),
					'amount'         => array(
						'type'        => 'string',
						'description' => __( 'Discount amount, e.g. "10" for 10% or 10 in the store currency.', 'viagent' ),
					),
					'description'    => array( 'type' => 'string' ),
					'expires'        => array(
						'type'        => 'string',
						'description' => __( 'Expiry date (YYYY-MM-DD).', 'viagent' ),
					),
					'usage_limit'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'minimum_amount' => array( 'type' => 'string' ),
					'free_shipping'  => array( 'type' => 'boolean' ),
					'individual_use' => array( 'type' => 'boolean' ),
				),
				'required'    => array( 'code', 'amount' ),
				'execute'     => array( self::class, 'create_coupon' ),
				'permission'  => 'edit_shop_coupons',
				'meta'        => Abilities::write_meta( Policy::ADMIN ),
			)
		);

		Abilities::add(
			'list-customers',
			array(
				'category'    => self::CATEGORY,
				'label'       => __( 'List customers', 'viagent' ),
				'description' => __( 'Lists customer accounts with order count and total spent.', 'viagent' ),
				'input'       => array_merge( array( 'search' => array( 'type' => 'string' ) ), Abilities::paging() ),
				'execute'     => array( self::class, 'list_customers' ),
				'permission'  => 'list_users',
				'meta'        => Abilities::read_meta( Policy::ADMIN ),
			)
		);
	}

	/**
	 * Editable product fields (JSON Schema).
	 *
	 * @return array
	 */
	private static function product_schema() {
		$money = array(
			'type'        => 'string',
			'description' => __( 'Decimal string, e.g. "19.99".', 'viagent' ),
		);
		return array(
			'name'              => array( 'type' => 'string' ),
			'status'            => array(
				'type' => 'string',
				'enum' => array( 'draft', 'pending', 'publish', 'private' ),
			),
			'regular_price'     => $money,
			'sale_price'        => $money,
			'description'       => array( 'type' => 'string' ),
			'short_description' => array( 'type' => 'string' ),
			'sku'               => array( 'type' => 'string' ),
			'manage_stock'      => array( 'type' => 'boolean' ),
			'stock_quantity'    => array( 'type' => 'integer' ),
			'stock_status'      => array(
				'type' => 'string',
				'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
			),
			'featured'          => array( 'type' => 'boolean' ),
			'virtual'           => array( 'type' => 'boolean' ),
			'categories'        => array(
				'type'        => 'array',
				'items'       => array( 'type' => array( 'string', 'integer' ) ),
				'description' => __( 'Category names or IDs; replaces existing ones.', 'viagent' ),
			),
			'tags'              => array(
				'type'  => 'array',
				'items' => array( 'type' => array( 'string', 'integer' ) ),
			),
			'image_id'          => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Main image (media library ID).', 'viagent' ),
			),
			'gallery_image_ids' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'product_url'       => array(
				'type'        => 'string',
				'description' => __( 'External products only: where to buy.', 'viagent' ),
			),
			'button_text'       => array( 'type' => 'string' ),
		);
	}

	/**
	 * Store overview.
	 *
	 * @return array
	 */
	public static function overview() {
		$products = wp_count_posts( 'product' );
		$orders   = array();
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$count = wc_orders_count( substr( $key, 3 ) );
			if ( $count ) {
				$orders[ substr( $key, 3 ) ] = $count;
			}
		}

		$recent  = wc_get_orders(
			array(
				'status'       => array( 'wc-processing', 'wc-completed' ),
				'date_created' => '>=' . gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ),
				'limit'        => 500,
				'return'       => 'objects',
			)
		);
		$revenue = 0.0;
		foreach ( $recent as $order ) {
			$revenue += (float) $order->get_total();
		}

		$low_stock = wc_get_products(
			array(
				'status'       => 'publish',
				'limit'        => 10,
				'stock_status' => 'outofstock',
				'return'       => 'objects',
			)
		);

		return array(
			'store'            => get_bloginfo( 'name' ),
			'currency'         => get_woocommerce_currency(),
			'products'         => array(
				'published' => (int) ( $products->publish ?? 0 ),
				'drafts'    => (int) ( $products->draft ?? 0 ),
			),
			'orders_by_status' => $orders,
			'last_30_days'     => array(
				'orders'  => count( $recent ),
				'revenue' => wc_format_decimal( $revenue, wc_get_price_decimals() ),
			),
			'out_of_stock'     => array_map(
				static function ( WC_Product $product ) {
					return array(
						'id'   => $product->get_id(),
						'name' => $product->get_name(),
					);
				},
				$low_stock
			),
			'shop_url'         => get_permalink( wc_get_page_id( 'shop' ) ),
		);
	}

	/**
	 * Compact product representation.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function product( WC_Product $product ) {
		$image = $product->get_image_id();
		return array(
			'id'             => $product->get_id(),
			'name'           => $product->get_name(),
			'type'           => $product->get_type(),
			'status'         => $product->get_status(),
			'sku'            => $product->get_sku(),
			'price'          => $product->get_price(),
			'regular_price'  => $product->get_regular_price(),
			'sale_price'     => $product->get_sale_price(),
			'on_sale'        => $product->is_on_sale(),
			'stock_status'   => $product->get_stock_status(),
			'stock_quantity' => $product->get_manage_stock() ? $product->get_stock_quantity() : null,
			'categories'     => wp_list_pluck( get_the_terms( $product->get_id(), 'product_cat' ) ?: array(), 'name' ), // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
			'image'          => $image ? wp_get_attachment_url( $image ) : null,
			'link'           => $product->get_permalink(),
			'edit_link'      => get_edit_post_link( $product->get_id(), 'raw' ),
		);
	}

	/**
	 * Finds a product.
	 *
	 * @param int $id ID.
	 * @return WC_Product|WP_Error
	 */
	private static function find_product( $id ) {
		$product = wc_get_product( $id );
		if ( ! $product || 'variation' === $product->get_type() ) {
			return new WP_Error( 'viagent_not_found', __( 'No product found with that ID.', 'viagent' ) );
		}
		return $product;
	}

	/**
	 * Lists products.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_products( $input ) {
		$status = $input['status'] ?? 'any';
		$args   = array(
			'status'   => 'any' === $status ? array( 'publish', 'draft', 'pending', 'private' ) : $status,
			'limit'    => (int) ( $input['per_page'] ?? 20 ),
			'page'     => (int) ( $input['page'] ?? 1 ),
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);
		foreach ( array( 'sku', 'stock_status' ) as $key ) {
			if ( ! empty( $input[ $key ] ) ) {
				$args[ $key ] = $input[ $key ];
			}
		}
		if ( ! empty( $input['category'] ) ) {
			$args['category'] = array( sanitize_title( $input['category'] ) );
		}
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = $input['search'];
		}

		$result = wc_get_products( $args );
		return array(
			'total'       => (int) $result->total,
			'total_pages' => (int) $result->max_num_pages,
			'currency'    => get_woocommerce_currency(),
			'items'       => array_map( array( self::class, 'product' ), $result->products ),
		);
	}

	/**
	 * Gets a product in full.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_product( $input ) {
		$product = self::find_product( (int) $input['id'] );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			$attributes[ wc_attribute_label( $attribute->get_name() ) ] = $attribute->is_taxonomy()
				? wp_list_pluck( $attribute->get_terms() ?: array(), 'name' ) // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
				: $attribute->get_options();
		}

		$variations = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation ) {
					$variations[] = array(
						'id'           => $child_id,
						'attributes'   => $variation->get_attributes(),
						'price'        => $variation->get_price(),
						'stock_status' => $variation->get_stock_status(),
					);
				}
			}
		}

		return array_merge(
			self::product( $product ),
			array(
				'description'       => $product->get_description(),
				'short_description' => $product->get_short_description(),
				'tags'              => wp_list_pluck( get_the_terms( $product->get_id(), 'product_tag' ) ?: array(), 'name' ), // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
				'gallery'           => array_map( 'wp_get_attachment_url', $product->get_gallery_image_ids() ),
				'attributes'        => $attributes,
				'variations'        => $variations,
				'total_sales'       => $product->get_total_sales(),
			)
		);
	}

	/**
	 * Applies input fields to a product object.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $input   Input.
	 * @return true|WP_Error
	 */
	private static function apply_props( WC_Product $product, array $input ) {
		if ( isset( $input['status'] ) ) {
			$allowed = Policy::check_status( $input['status'] );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
			if ( in_array( $input['status'], array( 'publish', 'private' ), true ) && ! current_user_can( 'publish_products' ) ) {
				return new WP_Error( 'viagent_cannot_publish', __( 'You are not allowed to publish products. Save it as "pending" instead.', 'viagent' ) );
			}
		}

		$props = array_intersect_key( $input, array_flip( self::PRODUCT_PROPS ) );
		if ( isset( $props['product_url'] ) && ! $product->is_type( 'external' ) ) {
			unset( $props['product_url'], $props['button_text'] );
		}
		if ( isset( $props['sku'] ) && '' !== $props['sku'] ) {
			$existing = wc_get_product_id_by_sku( $props['sku'] );
			if ( $existing && $existing !== $product->get_id() ) {
				return new WP_Error( 'viagent_duplicate_sku', __( 'Another product already uses that SKU.', 'viagent' ) );
			}
		}

		try {
			$product->set_props( $props );
		} catch ( \WC_Data_Exception $e ) {
			return new WP_Error( 'viagent_invalid_product', $e->getMessage() );
		}
		return true;
	}

	/**
	 * Sets categories and tags after saving.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $input      Input.
	 * @return true|WP_Error
	 */
	private static function apply_terms( $product_id, array $input ) {
		$map = array(
			'categories' => 'product_cat',
			'tags'       => 'product_tag',
		);
		foreach ( $map as $field => $taxonomy ) {
			if ( isset( $input[ $field ] ) ) {
				$result = Taxonomies::set_post_terms( get_post( $product_id ), $taxonomy, (array) $input[ $field ], false );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}
		return true;
	}

	/**
	 * Creates a product.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function create_product( $input ) {
		$input['status'] = $input['status'] ?? 'draft';
		$product         = 'external' === ( $input['type'] ?? 'simple' ) ? new \WC_Product_External() : new \WC_Product_Simple();

		$applied = self::apply_props( $product, $input );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$id = $product->save();
		if ( ! $id ) {
			return new WP_Error( 'viagent_save_failed', __( 'The product could not be saved.', 'viagent' ) );
		}

		Activity_Log::set_object( 'post', $id );
		Activity_Log::set_undo(
			array(
				'action'  => 'trash_post',
				'post_id' => $id,
			)
		);

		$terms    = self::apply_terms( $id, $input );
		$response = self::product( wc_get_product( $id ) );
		if ( is_wp_error( $terms ) ) {
			$response['warning'] = $terms->get_error_message();
		}
		return $response;
	}

	/**
	 * Updates a product.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_product( $input ) {
		$product = self::find_product( (int) $input['id'] );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		$editable = Policy::check_post_editable( get_post( $product->get_id() ) );
		if ( is_wp_error( $editable ) ) {
			return $editable;
		}

		// Snapshot the props and terms being changed, for undo.
		$data  = $product->get_data();
		$props = array();
		foreach ( array_intersect_key( $input, array_flip( self::PRODUCT_PROPS ) ) as $key => $unused ) {
			$props[ $key ] = $data[ $key ] ?? null;
		}
		$terms = array();
		foreach ( array(
			'categories' => 'product_cat',
			'tags'       => 'product_tag',
		) as $field => $taxonomy ) {
			if ( isset( $input[ $field ] ) ) {
				$terms[ $taxonomy ] = wp_get_object_terms( $product->get_id(), $taxonomy, array( 'fields' => 'ids' ) );
			}
		}

		$applied = self::apply_props( $product, $input );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}
		$product->save();

		Activity_Log::set_object( 'post', $product->get_id() );
		Activity_Log::set_undo(
			array(
				'action'  => 'wc_restore_product',
				'post_id' => $product->get_id(),
				'props'   => $props,
				'terms'   => $terms,
			)
		);

		$result   = self::apply_terms( $product->get_id(), $input );
		$response = self::product( wc_get_product( $product->get_id() ) );
		if ( is_wp_error( $result ) ) {
			$response['warning'] = $result->get_error_message();
		}
		return $response;
	}

	/**
	 * Trashes a product.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function delete_product( $input ) {
		$product = self::find_product( (int) $input['id'] );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		if ( 'trash' === $product->get_status() ) {
			return new WP_Error( 'viagent_already_trashed', __( 'This product is already in the trash.', 'viagent' ) );
		}

		$product->delete( false );
		Activity_Log::set_object( 'post', $product->get_id() );
		Activity_Log::set_undo(
			array(
				'action'  => 'untrash_post',
				'post_id' => $product->get_id(),
			)
		);
		return array(
			'id'      => $product->get_id(),
			'name'    => $product->get_name(),
			'trashed' => true,
		);
	}

	/**
	 * Compact order representation.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function order( WC_Order $order ) {
		$created = $order->get_date_created();
		return array(
			'id'        => $order->get_id(),
			'number'    => $order->get_order_number(),
			'status'    => $order->get_status(),
			'total'     => $order->get_total(),
			'currency'  => $order->get_currency(),
			'date'      => $created ? $created->date( 'Y-m-d H:i' ) : null,
			'customer'  => trim( $order->get_formatted_billing_full_name() ),
			'email'     => $order->get_billing_email(),
			'items'     => $order->get_item_count(),
			'edit_link' => $order->get_edit_order_url(),
		);
	}

	/**
	 * Finds an order.
	 *
	 * @param int $id ID.
	 * @return WC_Order|WP_Error
	 */
	private static function find_order( $id ) {
		$order = wc_get_order( $id );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'viagent_not_found', __( 'No order found with that ID.', 'viagent' ) );
		}
		return $order;
	}

	/**
	 * Lists orders.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_orders( $input ) {
		$args = array(
			'limit'    => (int) ( $input['per_page'] ?? 20 ),
			'page'     => (int) ( $input['page'] ?? 1 ),
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);
		if ( ! empty( $input['status'] ) && 'any' !== $input['status'] ) {
			$args['status'] = array( 'wc-' . $input['status'] );
		}
		if ( ! empty( $input['customer_email'] ) ) {
			$args['billing_email'] = sanitize_email( $input['customer_email'] );
		}
		if ( ! empty( $input['after'] ) ) {
			$args['date_created'] = '>=' . sanitize_text_field( $input['after'] );
		}

		$result = wc_get_orders( $args );
		return array(
			'total'       => (int) $result->total,
			'total_pages' => (int) $result->max_num_pages,
			'items'       => array_map( array( self::class, 'order' ), $result->orders ),
		);
	}

	/**
	 * Gets an order in full.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_order( $input ) {
		$order = self::find_order( (int) $input['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'       => $item->get_name(),
				'product_id' => $item->get_product_id(),
				'quantity'   => $item->get_quantity(),
				'total'      => $item->get_total(),
			);
		}

		$notes = array();
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			$notes[] = array(
				'date'          => $note->date_created ? $note->date_created->date( 'Y-m-d H:i' ) : null,
				'note'          => wp_strip_all_tags( $note->content ),
				'customer_note' => (bool) $note->customer_note,
			);
		}

		return array_merge(
			self::order( $order ),
			array(
				'payment_method' => $order->get_payment_method_title(),
				'subtotal'       => $order->get_subtotal(),
				'shipping_total' => $order->get_shipping_total(),
				'discount_total' => $order->get_discount_total(),
				'tax_total'      => $order->get_total_tax(),
				'billing'        => $order->get_address( 'billing' ),
				'shipping'       => $order->get_address( 'shipping' ),
				'customer_note'  => $order->get_customer_note(),
				'line_items'     => $items,
				'notes'          => $notes,
			)
		);
	}

	/**
	 * Updates an order's status.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_order_status( $input ) {
		$order = self::find_order( (int) $input['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$previous = $order->get_status();
		$order->update_status( $input['status'], isset( $input['note'] ) ? sanitize_textarea_field( $input['note'] ) . ' ' : '', true );

		Activity_Log::set_object( 'order', $order->get_id() );
		Activity_Log::set_undo(
			array(
				'action'   => 'wc_restore_order_status',
				'order_id' => $order->get_id(),
				'status'   => $previous,
			)
		);
		return self::order( wc_get_order( $order->get_id() ) );
	}

	/**
	 * Adds an order note.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function add_order_note( $input ) {
		$order = self::find_order( (int) $input['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$note_id = $order->add_order_note( sanitize_textarea_field( $input['note'] ), ! empty( $input['customer_note'] ), true );
		Activity_Log::set_object( 'order', $order->get_id() );
		return array(
			'order_id'      => $order->get_id(),
			'note_id'       => $note_id,
			'customer_note' => ! empty( $input['customer_note'] ),
		);
	}

	/**
	 * Lists coupons.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_coupons( $input ) {
		$query = new \WP_Query(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => (int) ( $input['per_page'] ?? 20 ),
				'paged'          => (int) ( $input['page'] ?? 1 ),
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$coupon  = new \WC_Coupon( $post->ID );
			$expires = $coupon->get_date_expires();
			$items[] = array(
				'id'            => $coupon->get_id(),
				'code'          => $coupon->get_code(),
				'discount_type' => $coupon->get_discount_type(),
				'amount'        => $coupon->get_amount(),
				'usage'         => $coupon->get_usage_count() . ( $coupon->get_usage_limit() ? ' / ' . $coupon->get_usage_limit() : '' ),
				'expires'       => $expires ? $expires->date( 'Y-m-d' ) : null,
				'status'        => $post->post_status,
			);
		}
		return array(
			'total' => (int) $query->found_posts,
			'items' => $items,
		);
	}

	/**
	 * Creates a coupon.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function create_coupon( $input ) {
		$code = wc_format_coupon_code( $input['code'] );
		if ( wc_get_coupon_id_by_code( $code ) ) {
			return new WP_Error( 'viagent_duplicate_coupon', __( 'A coupon with that code already exists.', 'viagent' ) );
		}

		$coupon = new \WC_Coupon();
		try {
			$coupon->set_props(
				array_filter(
					array(
						'code'           => $code,
						'discount_type'  => $input['discount_type'] ?? 'percent',
						'amount'         => wc_format_decimal( $input['amount'] ),
						'description'    => $input['description'] ?? null,
						'date_expires'   => $input['expires'] ?? null,
						'usage_limit'    => $input['usage_limit'] ?? null,
						'minimum_amount' => isset( $input['minimum_amount'] ) ? wc_format_decimal( $input['minimum_amount'] ) : null,
						'free_shipping'  => $input['free_shipping'] ?? null,
						'individual_use' => $input['individual_use'] ?? null,
					),
					static function ( $value ) {
						return null !== $value;
					}
				)
			);
		} catch ( \WC_Data_Exception $e ) {
			return new WP_Error( 'viagent_invalid_coupon', $e->getMessage() );
		}

		$id = $coupon->save();
		Activity_Log::set_object( 'post', $id );
		Activity_Log::set_undo(
			array(
				'action'  => 'trash_post',
				'post_id' => $id,
			)
		);

		return array(
			'id'            => $id,
			'code'          => $coupon->get_code(),
			'discount_type' => $coupon->get_discount_type(),
			'amount'        => $coupon->get_amount(),
			'edit_link'     => get_edit_post_link( $id, 'raw' ),
		);
	}

	/**
	 * Lists customers.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_customers( $input ) {
		$args = array(
			'role'        => 'customer',
			'number'      => (int) ( $input['per_page'] ?? 20 ),
			'paged'       => (int) ( $input['page'] ?? 1 ),
			'count_total' => true,
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = '*' . $input['search'] . '*';
		}
		$query = new \WP_User_Query( $args );

		$items = array();
		foreach ( $query->get_results() as $user ) {
			$customer = new \WC_Customer( $user->ID );
			$items[]  = array(
				'id'          => $user->ID,
				'name'        => trim( $customer->get_first_name() . ' ' . $customer->get_last_name() ) ?: $user->display_name, // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
				'email'       => $customer->get_email(),
				'orders'      => $customer->get_order_count(),
				'total_spent' => $customer->get_total_spent(),
			);
		}
		return array(
			'total' => (int) $query->get_total(),
			'items' => $items,
		);
	}

	/**
	 * Undo handlers for store changes.
	 *
	 * @param mixed $result Result from earlier handlers.
	 * @param array $undo   Undo instructions.
	 * @return mixed
	 */
	public static function apply_undo( $result, $undo ) {
		if ( null !== $result ) {
			return $result;
		}

		if ( 'wc_restore_product' === $undo['action'] ) {
			$product = wc_get_product( (int) $undo['post_id'] );
			if ( ! $product || ! current_user_can( 'edit_product', $product->get_id() ) ) {
				return new WP_Error( 'viagent_not_found', __( 'The product no longer exists or you cannot edit it.', 'viagent' ) );
			}
			$product->set_props( (array) $undo['props'] );
			$product->save();
			foreach ( (array) ( $undo['terms'] ?? array() ) as $taxonomy => $ids ) {
				wp_set_object_terms( $product->get_id(), array_map( 'intval', (array) $ids ), $taxonomy );
			}
			return true;
		}

		if ( 'wc_restore_order_status' === $undo['action'] ) {
			$order = wc_get_order( (int) $undo['order_id'] );
			if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
				return new WP_Error( 'viagent_not_found', __( 'The order no longer exists or you cannot edit it.', 'viagent' ) );
			}
			$order->update_status( $undo['status'], __( 'Status restored from Viagent activity.', 'viagent' ) . ' ', true );
			return true;
		}

		return null;
	}

	/**
	 * Undo descriptions.
	 *
	 * @param string $label Label.
	 * @param array  $undo  Undo instructions.
	 * @return string
	 */
	public static function undo_label( $label, $undo ) {
		switch ( $undo['action'] ?? '' ) {
			case 'wc_restore_product':
				return __( 'The product’s previous prices, stock and details will be restored.', 'viagent' );
			case 'wc_restore_order_status':
				/* translators: %s: order status */
				return sprintf( __( 'The order will go back to “%s”. The customer may get an email.', 'viagent' ), wc_get_order_status_name( $undo['status'] ) );
		}
		return $label;
	}

	/**
	 * Tool summaries for the Tools screen.
	 *
	 * @param array $summaries Summaries.
	 * @return array
	 */
	public static function summaries( $summaries ) {
		return array_merge(
			$summaries,
			array(
				'wc_store_overview'   => __( 'See sales, orders and stock at a glance.', 'viagent' ),
				'list_products'       => __( 'Browse store products with prices and stock.', 'viagent' ),
				'get_product'         => __( 'Read one product in full.', 'viagent' ),
				'create_product'      => __( 'Add new products.', 'viagent' ),
				'update_product'      => __( 'Change product prices, stock and details.', 'viagent' ),
				'delete_product'      => __( 'Move products to the trash.', 'viagent' ),
				'list_orders'         => __( 'Browse orders.', 'viagent' ),
				'get_order'           => __( 'Read one order with its items and customer.', 'viagent' ),
				'update_order_status' => __( 'Mark orders as completed, on hold, cancelled…', 'viagent' ),
				'add_order_note'      => __( 'Add notes to orders, optionally emailed to the customer.', 'viagent' ),
				'list_coupons'        => __( 'Browse discount coupons.', 'viagent' ),
				'create_coupon'       => __( 'Create discount coupons.', 'viagent' ),
				'list_customers'      => __( 'See customers and what they have spent.', 'viagent' ),
			)
		);
	}
}
