<?php
/**
 * WooCommerce product, variation, category, tag, and attribute abilities.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Helpers ────────────────────────────────────────────────────────────────

function mcp_wc_product_type_aliases(): array {
	$aliases = array(
		'physical'  => 'simple',
		'virtual'   => 'simple',
		'digital'   => 'simple',
		'affiliate' => 'external',
		'grouped'   => 'grouped',
		'variable'  => 'variable',
	);
	if ( function_exists( 'wc_get_product_types' ) ) {
		foreach ( array_keys( wc_get_product_types() ) as $type ) {
			$aliases[ $type ] = $type;
		}
	}
	return $aliases;
}

function mcp_wc_get_product_or_error( int $id, string $action ): array {
	$product = wc_get_product( $id );
	if ( ! $product ) {
		return array( 'success' => false, 'message' => 'Product not found with ID: ' . $id );
	}
	if ( ! current_user_can( 'edit_products' ) ) {
		return array( 'success' => false, 'message' => 'You do not have permission to ' . $action . ' products.' );
	}
	return array( 'success' => true, 'product' => $product );
}

function mcp_wc_validate_price( ?string $price ): ?string {
	if ( null === $price || '' === $price ) {
		return null;
	}
	return preg_match( '/^(?:-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)|)$/', $price ) ? $price : null;
}

// ─── Products ────────────────────────────────────────────────────────────────

function mcp_wc_register_product_abilities(): void {
	if ( ! mcp_wc_is_active() ) {
		return;
	}

	mcp_wc_register_products_query();
	mcp_wc_register_product_create();
	mcp_wc_register_product_update();
	mcp_wc_register_product_delete();
	mcp_wc_register_variations_query();
	mcp_wc_register_variation_create();
	mcp_wc_register_variation_update();
	mcp_wc_register_variation_delete();
	mcp_wc_register_categories_query();
	mcp_wc_register_category_create();
	mcp_wc_register_category_update();
	mcp_wc_register_category_delete();
	mcp_wc_register_tags_query();
	mcp_wc_register_tag_create();
	mcp_wc_register_tag_update();
	mcp_wc_register_tag_delete();
	mcp_wc_register_attributes_query();
	mcp_wc_register_attribute_terms_query();
	mcp_wc_register_attribute_term_create();
	mcp_wc_register_attribute_term_update();
	mcp_wc_register_attribute_term_delete();
	mcp_wc_register_attribute_create();
	mcp_wc_register_attribute_update();
	mcp_wc_register_attribute_delete();
	mcp_wc_register_product_meta_query();
	mcp_wc_register_product_duplicate();
	mcp_wc_register_products_bulk_stock();
}

// ─── Products Query ──────────────────────────────────────────────────────────

function mcp_wc_register_products_query(): void {
	mcp_wc_register_ability( 'woocommerce-mcp/products-query', array(
		'label'               => 'Query products',
		'description'         => 'Find products by ID or common catalog filters.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                => array( 'type' => 'integer', 'minimum' => 1 ),
				'search'            => array( 'type' => 'string' ),
				'sku'               => array( 'type' => 'string', 'description' => 'Limit results to products with SKUs that partially match this string.' ),
				'status'            => array( 'type' => 'string', 'enum' => mcp_wc_allowed_product_statuses() ),
				'product_type_alias' => array( 'type' => 'string', 'enum' => array_keys( mcp_wc_product_type_aliases() ) ),
				'stock_status'      => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
				'category_id'       => array( 'type' => 'integer', 'description' => 'Filter by product category ID.' ),
				'tag_id'            => array( 'type' => 'integer', 'description' => 'Filter by product tag ID.' ),
			'low_stock'         => array( 'type' => 'boolean', 'description' => 'Only return products with low stock (manage_stock=true and quantity below threshold).' ),
			'date_after'        => array( 'type' => 'string', 'format' => 'date-time', 'description' => 'Filter products created after this date.' ),
			'page'              => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page'          => array( 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100 ),
			),
			'additionalProperties' => false,
			'default'              => array(),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'products'    => array( 'type' => 'array', 'items' => mcp_wc_product_output_schema() ),
				'total_pages' => array( 'type' => 'integer' ),
				'page'        => array( 'type' => 'integer' ),
				'per_page'    => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'edit_products' ) ) {
				return array( 'error' => 'You do not have permission to query products.' );
			}

			if ( isset( $input['id'] ) ) {
				$product = wc_get_product( (int) $input['id'] );
				if ( ! $product ) {
					return array( 'products' => array(), 'total_pages' => 0, 'page' => 1, 'per_page' => (int) ( $input['per_page'] ?? 10 ) );
				}
				return array(
					'products'    => array( mcp_wc_format_product( $product ) ),
					'total_pages' => 1,
					'page'        => 1,
					'per_page'    => 1,
				);
			}

			$page     = (int) ( $input['page'] ?? 1 );
			$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );
			$args     = array(
				'status'   => $input['status'] ?? null,
				'page'     => $page,
				'limit'    => $per_page,
				'paginate' => true,
			);

			if ( ! empty( $input['search'] ) ) {
				$args['s'] = sanitize_text_field( $input['search'] );
			}
			if ( ! empty( $input['sku'] ) ) {
				$args['sku'] = sanitize_text_field( $input['sku'] );
			}
			if ( ! empty( $input['stock_status'] ) ) {
				$args['stock_status'] = sanitize_text_field( $input['stock_status'] );
			}
			if ( ! empty( $input['category_id'] ) ) {
				$args['category'] = array( (int) $input['category_id'] );
			}
			if ( ! empty( $input['tag_id'] ) ) {
				$args['tag'] = array( (int) $input['tag_id'] );
			}
			if ( ! empty( $input['low_stock'] ) ) {
				$args['low_in_stock'] = true;
			}
			if ( ! empty( $input['date_after'] ) ) {
				$args['date_created'] = sanitize_text_field( $input['date_after'] );
			}

			$product_type_alias = $input['product_type_alias'] ?? null;
			if ( null !== $product_type_alias ) {
				$type = mcp_wc_map_product_type_alias( $product_type_alias );
				$args['type'] = $type;

				if ( 'simple' === $type ) {
					if ( 'virtual' === $product_type_alias ) {
						$args['virtual'] = true;
					} elseif ( 'digital' === $product_type_alias ) {
						$args['virtual'] = true;
						$args['downloadable'] = true;
					}
				}
			}

			$results = wc_get_products( $args );

			if ( null !== $product_type_alias && 'physical' === $product_type_alias ) {
				$filtered = array();
				foreach ( $results->products as $product ) {
					if ( ! $product->get_virtual() && ! $product->get_downloadable() ) {
						$filtered[] = $product;
					}
				}
				$results->products = $filtered;
				$results->total = count( $filtered );
				$results->max_num_pages = max( 1, (int) ceil( $results->total / $per_page ) );
			}

			$products = array();
			foreach ( $results->products as $product ) {
				$products[] = mcp_wc_format_product( $product );
			}

			return array(
				'products'    => $products,
				'total_pages' => (int) $results->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_products' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );
}

// ─── Product Create ──────────────────────────────────────────────────────────

function mcp_wc_register_product_create(): void {
	mcp_wc_register_ability( 'woocommerce-mcp/product-create', array(
		'label'               => 'Create product',
		'description'         => 'Create a product using supported catalog fields.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'product_type_alias' => array(
					'type'        => 'string',
					'enum'        => array_keys( mcp_wc_product_type_aliases() ),
					'default'     => 'physical',
					'description' => 'Product type. Use the WC type name directly for extension types (subscription, booking, bundle, etc).',
				),
				'name'               => array( 'type' => 'string' ),
				'slug'               => array( 'type' => 'string', 'description' => 'URL slug. Auto-generated from name if omitted.' ),
				'sku'                => array( 'type' => 'string' ),
				'regular_price'      => array( 'type' => 'string', 'pattern' => '^(?:-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)|)$' ),
				'sale_price'         => array( 'type' => 'string', 'pattern' => '^(?:-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)|)$' ),
				'description'        => array( 'type' => 'string' ),
				'short_description'  => array( 'type' => 'string' ),
				'status'             => array( 'type' => 'string', 'enum' => mcp_wc_allowed_product_statuses() ),
				'manage_stock'       => array( 'type' => 'boolean' ),
				'stock_quantity'     => array( 'type' => 'integer' ),
				'stock_status'       => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
				'virtual'            => array( 'type' => 'boolean' ),
				'downloadable'       => array( 'type' => 'boolean' ),
				'catalog_visibility' => array( 'type' => 'string', 'enum' => array( 'visible', 'catalog', 'search', 'hidden' ) ),
				'weight'             => array( 'type' => 'string' ),
				'dimensions'         => array( 'type' => 'object', 'properties' => array(
					'length' => array( 'type' => 'string' ),
					'width'  => array( 'type' => 'string' ),
					'height' => array( 'type' => 'string' ),
				), 'additionalProperties' => false ),
				'category_ids'       => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'tag_ids'            => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'external_url'       => array( 'type' => 'string', 'format' => 'uri' ),
				'button_text'        => array( 'type' => 'string' ),
				'grouped_products'   => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'featured_image_id'  => array( 'type' => 'integer', 'description' => 'Media library attachment ID for the product featured image.' ),
				'gallery_image_ids'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Media library attachment IDs for the product image gallery.' ),
				'downloads'          => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'        => array( 'type' => 'string' ),
							'file'        => array( 'type' => 'string', 'description' => 'File URL or path.' ),
							'download_id' => array( 'type' => 'string', 'description' => 'Leave blank for new files, or provide ID to update an existing downloadable.' ),
						),
						'required'             => array( 'name', 'file' ),
						'additionalProperties' => false,
					),
					'description' => 'Downloadable files for the product. Providing this array REPLACES all existing downloadable files.',
				),
				'upsell_ids'         => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Product IDs to show as upsells.' ),
			'cross_sell_ids'     => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Product IDs to show as cross-sells.' ),
			'date_on_sale_from'  => array( 'type' => 'string', 'format' => 'date-time', 'description' => 'Start date for the sale price.' ),
			'date_on_sale_to'    => array( 'type' => 'string', 'format' => 'date-time', 'description' => 'End date for the sale price.' ),
			'tax_status'         => array( 'type' => 'string', 'enum' => array( 'taxable', 'shipping', 'none' ), 'description' => 'Tax status of the product.' ),
			'tax_class'          => array( 'type' => 'string', 'description' => 'Tax class slug (e.g. standard, reduced-rate, zero-rate).' ),
			'shipping_class_id'  => array( 'type' => 'integer', 'description' => 'Shipping class term ID.' ),
			'sold_individually'  => array( 'type' => 'boolean', 'description' => 'Limit purchases to 1 per order.' ),
			'backorders'         => array( 'type' => 'string', 'enum' => array( 'no', 'notify', 'yes' ), 'description' => 'Backorder policy.' ),
			'low_stock_amount'   => array( 'type' => 'integer', 'description' => 'Low stock threshold when manage_stock is enabled.' ),
			'reviews_allowed'    => array( 'type' => 'boolean', 'description' => 'Allow customer reviews.' ),
			'purchase_note'      => array( 'type' => 'string', 'description' => 'Note sent to customer after purchase.' ),
			'menu_order'         => array( 'type' => 'integer', 'description' => 'Custom ordering position.' ),
			'attributes'         => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'name'      => array( 'type' => 'string' ),
						'value'     => array( 'type' => 'string' ),
						'visible'   => array( 'type' => 'boolean' ),
						'variation' => array( 'type' => 'boolean' ),
						'options'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
					'additionalProperties' => false,
				) ),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'product' => mcp_wc_product_output_schema(),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			try {
			if ( ! current_user_can( 'edit_products' ) ) {
				return array( 'error' => 'You do not have permission to create products.' );
			}

			$alias   = $input['product_type_alias'] ?? 'physical';
			$wc_type = mcp_wc_map_product_type_alias( $alias );

			$class_map = array(
				'simple'   => \WC_Product_Simple::class,
				'variable' => \WC_Product_Variable::class,
				'grouped'  => \WC_Product_Grouped::class,
				'external' => \WC_Product_External::class,
			);

			if ( isset( $class_map[ $wc_type ] ) ) {
				$classname = $class_map[ $wc_type ];
			} else {
				$classname = 'WC_Product_' . implode( '_', array_map( 'ucfirst', explode( '-', str_replace( '_', '-', $wc_type ) ) ) );
				if ( ! class_exists( $classname ) ) {
					$classname = \WC_Product::class;
				}
			}

			$product = new $classname();

			$product->set_name( sanitize_text_field( $input['name'] ) );

			if ( isset( $input['slug'] ) ) {
				$product->set_slug( sanitize_title( $input['slug'] ) );
			}
			if ( isset( $input['sku'] ) ) {
				$product->set_sku( sanitize_text_field( $input['sku'] ) );
			}
			if ( isset( $input['description'] ) ) {
				$product->set_description( wp_kses_post( $input['description'] ) );
			}
			if ( isset( $input['short_description'] ) ) {
				$product->set_short_description( wp_kses_post( $input['short_description'] ) );
			}
			if ( isset( $input['status'] ) ) {
				$product->set_status( sanitize_text_field( $input['status'] ) );
			}
			if ( isset( $input['catalog_visibility'] ) && method_exists( $product, 'set_catalog_visibility' ) ) {
				$product->set_catalog_visibility( sanitize_text_field( $input['catalog_visibility'] ) );
			}

			if ( isset( $input['regular_price'] ) && method_exists( $product, 'set_regular_price' ) ) {
				$product->set_regular_price( sanitize_text_field( $input['regular_price'] ) );
			}
			if ( isset( $input['sale_price'] ) && method_exists( $product, 'set_sale_price' ) ) {
				$product->set_sale_price( sanitize_text_field( $input['sale_price'] ) );
			}

			if ( method_exists( $product, 'set_virtual' ) && method_exists( $product, 'set_downloadable' ) ) {
				if ( 'virtual' === $alias || 'digital' === $alias ) {
					$product->set_virtual( true );
				}
				if ( 'digital' === $alias ) {
					$product->set_downloadable( true );
				}
				if ( ! in_array( $alias, array( 'virtual', 'digital' ), true ) ) {
					if ( isset( $input['virtual'] ) ) {
						$product->set_virtual( (bool) $input['virtual'] );
					}
					if ( isset( $input['downloadable'] ) ) {
						$product->set_downloadable( (bool) $input['downloadable'] );
					}
				}
			}

			if ( isset( $input['manage_stock'] ) && method_exists( $product, 'set_manage_stock' ) ) {
				$product->set_manage_stock( (bool) $input['manage_stock'] );
			}
			if ( isset( $input['stock_quantity'] ) && method_exists( $product, 'set_stock_quantity' ) ) {
				$product->set_stock_quantity( (int) $input['stock_quantity'] );
			}
			if ( isset( $input['stock_status'] ) && method_exists( $product, 'set_stock_status' ) ) {
				$product->set_stock_status( sanitize_text_field( $input['stock_status'] ) );
			}

			if ( isset( $input['weight'] ) && method_exists( $product, 'set_weight' ) ) {
				$product->set_weight( sanitize_text_field( $input['weight'] ) );
			}
			if ( isset( $input['dimensions'] ) && is_array( $input['dimensions'] ) ) {
				if ( isset( $input['dimensions']['length'] ) && method_exists( $product, 'set_length' ) ) {
					$product->set_length( sanitize_text_field( $input['dimensions']['length'] ) );
				}
				if ( isset( $input['dimensions']['width'] ) && method_exists( $product, 'set_width' ) ) {
					$product->set_width( sanitize_text_field( $input['dimensions']['width'] ) );
				}
				if ( isset( $input['dimensions']['height'] ) && method_exists( $product, 'set_height' ) ) {
					$product->set_height( sanitize_text_field( $input['dimensions']['height'] ) );
				}
			}

			if ( isset( $input['external_url'] ) && method_exists( $product, 'set_product_url' ) ) {
				$product->set_product_url( esc_url_raw( $input['external_url'] ) );
			}
			if ( isset( $input['button_text'] ) && method_exists( $product, 'set_button_text' ) ) {
				$product->set_button_text( sanitize_text_field( $input['button_text'] ) );
			}

			if ( isset( $input['grouped_products'] ) && method_exists( $product, 'set_children' ) ) {
				$product->set_children( array_map( 'absint', $input['grouped_products'] ) );
			}

			if ( isset( $input['attributes'] ) && is_array( $input['attributes'] ) && method_exists( $product, 'set_attributes' ) ) {
				$attrs = array();
				foreach ( $input['attributes'] as $attr_data ) {
					if ( ! isset( $attr_data['name'] ) || '' === $attr_data['name'] ) {
						continue;
					}
					$attr = new \WC_Product_Attribute();
					$attr->set_name( sanitize_text_field( $attr_data['name'] ) );
					if ( isset( $attr_data['options'] ) && is_array( $attr_data['options'] ) ) {
						$attr->set_options( array_map( 'sanitize_text_field', $attr_data['options'] ) );
					} elseif ( isset( $attr_data['value'] ) ) {
						$attr->set_options( array_map( 'trim', explode( '|', sanitize_text_field( $attr_data['value'] ) ) ) );
					}
					$attr->set_visible( isset( $attr_data['visible'] ) ? (bool) $attr_data['visible'] : true );
					$attr->set_variation( isset( $attr_data['variation'] ) ? (bool) $attr_data['variation'] : false );
					$attrs[] = $attr;
				}
				$product->set_attributes( $attrs );
			}

			$product_params = array(
				'upsell_ids'         => 'set_upsell_ids',
				'cross_sell_ids'     => 'set_cross_sell_ids',
				'date_on_sale_from'  => 'set_date_on_sale_from',
				'date_on_sale_to'    => 'set_date_on_sale_to',
				'tax_status'         => 'set_tax_status',
				'tax_class'          => 'set_tax_class',
				'shipping_class_id'  => 'set_shipping_class_id',
				'sold_individually'  => 'set_sold_individually',
				'backorders'         => 'set_backorders',
				'low_stock_amount'   => 'set_low_stock_amount',
				'reviews_allowed'    => 'set_reviews_allowed',
				'purchase_note'      => 'set_purchase_note',
				'menu_order'         => 'set_menu_order',
			);
			foreach ( $product_params as $param => $method ) {
				if ( isset( $input[ $param ] ) && method_exists( $product, $method ) ) {
					if ( in_array( $param, array( 'upsell_ids', 'cross_sell_ids' ), true ) && is_array( $input[ $param ] ) ) {
						$product->$method( array_map( 'absint', $input[ $param ] ) );
					} elseif ( 'date_on_sale_from' === $param || 'date_on_sale_to' === $param ) {
						$date = '' !== $input[ $param ] ? mcp_wc_parse_date( $input[ $param ] ) : null;
						$product->$method( $date ? $date->getTimestamp() : '' );
					} elseif ( 'tax_status' === $param || 'tax_class' === $param || 'backorders' === $param ) {
						$product->$method( sanitize_text_field( $input[ $param ] ) );
					} elseif ( 'purchase_note' === $param ) {
						$product->$method( wp_kses_post( $input[ $param ] ) );
					} elseif ( 'shipping_class_id' === $param || 'low_stock_amount' === $param || 'menu_order' === $param ) {
						$product->$method( (int) $input[ $param ] );
					} else {
						$product->$method( $input[ $param ] );
					}
				}
			}

			$product_id = $product->save();

			// Ensure frontend visibility: WC 10.9 set_catalog_visibility does not reliably persist _visibility meta
			$visibility = sanitize_text_field( $input['catalog_visibility'] ?? 'visible' );
			update_post_meta( $product_id, '_visibility', $visibility );
			wp_set_object_terms( $product_id, array( $visibility ), 'product_visibility' );

			if ( isset( $input['category_ids'] ) && is_array( $input['category_ids'] ) ) {
				wp_set_object_terms( $product_id, array_map( 'absint', $input['category_ids'] ), 'product_cat' );
			}
			if ( isset( $input['tag_ids'] ) && is_array( $input['tag_ids'] ) ) {
				wp_set_object_terms( $product_id, array_map( 'absint', $input['tag_ids'] ), 'product_tag' );
			}

			if ( isset( $input['featured_image_id'] ) ) {
				$product->set_image_id( (int) $input['featured_image_id'] );
				$product->save();
			}
			if ( isset( $input['gallery_image_ids'] ) && is_array( $input['gallery_image_ids'] ) ) {
				$product->set_gallery_image_ids( array_map( 'absint', $input['gallery_image_ids'] ) );
				$product->save();
			}

			if ( isset( $input['downloads'] ) && is_array( $input['downloads'] ) && $product->is_downloadable() ) {
				$downloads = array();
				foreach ( $input['downloads'] as $dl ) {
					$download = new \WC_Product_Download();
					if ( ! empty( $dl['download_id'] ) ) {
						$download->set_id( sanitize_text_field( $dl['download_id'] ) );
					}
					$download->set_name( sanitize_text_field( $dl['name'] ) );
					$download->set_file( esc_url_raw( $dl['file'] ) );
					$downloads[] = $download;
				}
				$product->set_downloads( $downloads );
				$product->save();
			}

			$product = wc_get_product( $product_id );
			return array( 'product' => mcp_wc_format_product( $product ) );
			} catch ( \Throwable $e ) {
				return array( 'error' => $e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']' );
			}
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_products' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
		),
	) );
}

// ─── Product Update ──────────────────────────────────────────────────────────

function mcp_wc_register_product_update(): void {
	mcp_wc_register_ability( 'woocommerce-mcp/product-update', array(
		'label'               => 'Update product',
		'description'         => 'Update an existing product using supported catalog fields.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
			'id'                 => array( 'type' => 'integer', 'minimum' => 1 ),
			'name'               => array( 'type' => 'string' ),
			'slug'               => array( 'type' => 'string', 'description' => 'URL slug. Auto-generated from name if omitted.' ),
			'sku'                => array( 'type' => 'string' ),
				'regular_price'      => array( 'type' => 'string', 'pattern' => '^(?:-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)|)$' ),
				'sale_price'         => array( 'type' => 'string', 'pattern' => '^(?:-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)|)$' ),
				'description'        => array( 'type' => 'string' ),
				'short_description'  => array( 'type' => 'string' ),
				'status'             => array( 'type' => 'string', 'enum' => mcp_wc_allowed_product_statuses() ),
				'manage_stock'       => array( 'type' => 'boolean' ),
				'stock_quantity'     => array( 'type' => 'integer' ),
				'stock_status'       => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
				'virtual'            => array( 'type' => 'boolean' ),
				'downloadable'       => array( 'type' => 'boolean' ),
				'catalog_visibility' => array( 'type' => 'string', 'enum' => array( 'visible', 'catalog', 'search', 'hidden' ) ),
				'weight'             => array( 'type' => 'string' ),
				'dimensions'         => array( 'type' => 'object', 'properties' => array(
					'length' => array( 'type' => 'string' ),
					'width'  => array( 'type' => 'string' ),
					'height' => array( 'type' => 'string' ),
				), 'additionalProperties' => false ),
				'category_ids'       => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'tag_ids'            => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'external_url'       => array( 'type' => 'string', 'format' => 'uri' ),
				'button_text'        => array( 'type' => 'string' ),
				'grouped_products'   => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'featured_image_id'  => array( 'type' => 'integer', 'description' => 'Media library attachment ID for the product featured image.' ),
				'gallery_image_ids'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Media library attachment IDs for the product image gallery.' ),
				'downloads'          => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'        => array( 'type' => 'string' ),
							'file'        => array( 'type' => 'string', 'description' => 'File URL or path.' ),
							'download_id' => array( 'type' => 'string', 'description' => 'Leave blank for new files, or provide ID to update an existing downloadable.' ),
						),
						'required'             => array( 'name', 'file' ),
						'additionalProperties' => false,
					),
					'description' => 'Downloadable files for the product. Providing this array REPLACES all existing downloadable files.',
				),
				'upsell_ids'         => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Product IDs to show as upsells.' ),
			'cross_sell_ids'     => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Product IDs to show as cross-sells.' ),
			'date_on_sale_from'  => array( 'type' => 'string', 'format' => 'date-time', 'description' => 'Start date for the sale price.' ),
			'date_on_sale_to'    => array( 'type' => 'string', 'format' => 'date-time', 'description' => 'End date for the sale price.' ),
			'tax_status'         => array( 'type' => 'string', 'enum' => array( 'taxable', 'shipping', 'none' ), 'description' => 'Tax status of the product.' ),
			'tax_class'          => array( 'type' => 'string', 'description' => 'Tax class slug (e.g. standard, reduced-rate, zero-rate).' ),
			'shipping_class_id'  => array( 'type' => 'integer', 'description' => 'Shipping class term ID.' ),
			'sold_individually'  => array( 'type' => 'boolean', 'description' => 'Limit purchases to 1 per order.' ),
			'backorders'         => array( 'type' => 'string', 'enum' => array( 'no', 'notify', 'yes' ), 'description' => 'Backorder policy.' ),
			'low_stock_amount'   => array( 'type' => 'integer', 'description' => 'Low stock threshold when manage_stock is enabled.' ),
			'reviews_allowed'    => array( 'type' => 'boolean', 'description' => 'Allow customer reviews.' ),
			'purchase_note'      => array( 'type' => 'string', 'description' => 'Note sent to customer after purchase.' ),
			'menu_order'         => array( 'type' => 'integer', 'description' => 'Custom ordering position.' ),
			'attributes'         => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'name'      => array( 'type' => 'string' ),
						'value'     => array( 'type' => 'string' ),
						'visible'   => array( 'type' => 'boolean' ),
						'variation' => array( 'type' => 'boolean' ),
						'options'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
					'additionalProperties' => false,
				) ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'product' => mcp_wc_product_output_schema(),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			try {
			$result = mcp_wc_get_product_or_error( (int) $input['id'], 'update' );
			if ( ! $result['success'] ) {
				return $result;
			}

			$product = $result['product'];

			if ( isset( $input['name'] ) ) {
				$product->set_name( sanitize_text_field( $input['name'] ) );
			}
			if ( isset( $input['slug'] ) ) {
				$product->set_slug( sanitize_title( $input['slug'] ) );
			}
			if ( isset( $input['sku'] ) ) {
				$product->set_sku( sanitize_text_field( $input['sku'] ) );
			}
			if ( isset( $input['description'] ) ) {
				$product->set_description( wp_kses_post( $input['description'] ) );
			}
			if ( isset( $input['short_description'] ) ) {
				$product->set_short_description( wp_kses_post( $input['short_description'] ) );
			}
			if ( isset( $input['status'] ) ) {
				$product->set_status( sanitize_text_field( $input['status'] ) );
			}
			if ( isset( $input['catalog_visibility'] ) && method_exists( $product, 'set_catalog_visibility' ) ) {
				$product->set_catalog_visibility( sanitize_text_field( $input['catalog_visibility'] ) );
			}

			if ( isset( $input['regular_price'] ) && method_exists( $product, 'set_regular_price' ) ) {
				$product->set_regular_price( sanitize_text_field( $input['regular_price'] ) );
			}
			if ( isset( $input['sale_price'] ) && method_exists( $product, 'set_sale_price' ) ) {
				$product->set_sale_price( sanitize_text_field( $input['sale_price'] ) );
			}

			if ( isset( $input['virtual'] ) && method_exists( $product, 'set_virtual' ) ) {
				$product->set_virtual( (bool) $input['virtual'] );
			}
			if ( isset( $input['downloadable'] ) && method_exists( $product, 'set_downloadable' ) ) {
				$product->set_downloadable( (bool) $input['downloadable'] );
			}

			if ( isset( $input['manage_stock'] ) && method_exists( $product, 'set_manage_stock' ) ) {
				$product->set_manage_stock( (bool) $input['manage_stock'] );
			}
			if ( isset( $input['stock_quantity'] ) && method_exists( $product, 'set_stock_quantity' ) ) {
				$product->set_stock_quantity( (int) $input['stock_quantity'] );
			}
			if ( isset( $input['stock_status'] ) && method_exists( $product, 'set_stock_status' ) ) {
				$product->set_stock_status( sanitize_text_field( $input['stock_status'] ) );
			}

			if ( isset( $input['weight'] ) && method_exists( $product, 'set_weight' ) ) {
				$product->set_weight( sanitize_text_field( $input['weight'] ) );
			}
			if ( isset( $input['dimensions'] ) && is_array( $input['dimensions'] ) ) {
				if ( isset( $input['dimensions']['length'] ) && method_exists( $product, 'set_length' ) ) {
					$product->set_length( sanitize_text_field( $input['dimensions']['length'] ) );
				}
				if ( isset( $input['dimensions']['width'] ) && method_exists( $product, 'set_width' ) ) {
					$product->set_width( sanitize_text_field( $input['dimensions']['width'] ) );
				}
				if ( isset( $input['dimensions']['height'] ) && method_exists( $product, 'set_height' ) ) {
					$product->set_height( sanitize_text_field( $input['dimensions']['height'] ) );
				}
			}

			if ( isset( $input['external_url'] ) && method_exists( $product, 'set_product_url' ) ) {
				$product->set_product_url( esc_url_raw( $input['external_url'] ) );
			}
			if ( isset( $input['button_text'] ) && method_exists( $product, 'set_button_text' ) ) {
				$product->set_button_text( sanitize_text_field( $input['button_text'] ) );
			}

			if ( isset( $input['grouped_products'] ) && method_exists( $product, 'set_children' ) ) {
				$product->set_children( array_map( 'absint', $input['grouped_products'] ) );
			}

			if ( isset( $input['attributes'] ) && is_array( $input['attributes'] ) && method_exists( $product, 'set_attributes' ) ) {
				$attrs = array();
				foreach ( $input['attributes'] as $attr_data ) {
					if ( ! isset( $attr_data['name'] ) || '' === $attr_data['name'] ) {
						continue;
					}
					$attr = new \WC_Product_Attribute();
					$attr->set_name( sanitize_text_field( $attr_data['name'] ) );
					if ( isset( $attr_data['options'] ) && is_array( $attr_data['options'] ) ) {
						$attr->set_options( array_map( 'sanitize_text_field', $attr_data['options'] ) );
					} elseif ( isset( $attr_data['value'] ) ) {
						$attr->set_options( array_map( 'trim', explode( '|', sanitize_text_field( $attr_data['value'] ) ) ) );
					}
					$attr->set_visible( isset( $attr_data['visible'] ) ? (bool) $attr_data['visible'] : true );
					$attr->set_variation( isset( $attr_data['variation'] ) ? (bool) $attr_data['variation'] : false );
					$attrs[] = $attr;
				}
				$product->set_attributes( $attrs );
			}

			$product_params = array(
				'upsell_ids'         => 'set_upsell_ids',
				'cross_sell_ids'     => 'set_cross_sell_ids',
				'date_on_sale_from'  => 'set_date_on_sale_from',
				'date_on_sale_to'    => 'set_date_on_sale_to',
				'tax_status'         => 'set_tax_status',
				'tax_class'          => 'set_tax_class',
				'shipping_class_id'  => 'set_shipping_class_id',
				'sold_individually'  => 'set_sold_individually',
				'backorders'         => 'set_backorders',
				'low_stock_amount'   => 'set_low_stock_amount',
				'reviews_allowed'    => 'set_reviews_allowed',
				'purchase_note'      => 'set_purchase_note',
				'menu_order'         => 'set_menu_order',
			);
			foreach ( $product_params as $param => $method ) {
				if ( isset( $input[ $param ] ) && method_exists( $product, $method ) ) {
					if ( in_array( $param, array( 'upsell_ids', 'cross_sell_ids' ), true ) && is_array( $input[ $param ] ) ) {
						$product->$method( array_map( 'absint', $input[ $param ] ) );
					} elseif ( 'date_on_sale_from' === $param || 'date_on_sale_to' === $param ) {
						$date = '' !== $input[ $param ] ? mcp_wc_parse_date( $input[ $param ] ) : null;
						$product->$method( $date ? $date->getTimestamp() : '' );
					} elseif ( 'tax_status' === $param || 'tax_class' === $param || 'backorders' === $param ) {
						$product->$method( sanitize_text_field( $input[ $param ] ) );
					} elseif ( 'purchase_note' === $param ) {
						$product->$method( wp_kses_post( $input[ $param ] ) );
					} elseif ( 'shipping_class_id' === $param || 'low_stock_amount' === $param || 'menu_order' === $param ) {
						$product->$method( (int) $input[ $param ] );
					} else {
						$product->$method( $input[ $param ] );
					}
				}
			}

			$product->save();

			// Ensure frontend visibility: WC 10.9 set_catalog_visibility does not reliably persist _visibility meta
			if ( isset( $input['catalog_visibility'] ) ) {
				$visibility = sanitize_text_field( $input['catalog_visibility'] );
				update_post_meta( $product->get_id(), '_visibility', $visibility );
				wp_set_object_terms( $product->get_id(), array( $visibility ), 'product_visibility' );
			}

			if ( isset( $input['category_ids'] ) && is_array( $input['category_ids'] ) ) {
				wp_set_object_terms( $product->get_id(), array_map( 'absint', $input['category_ids'] ), 'product_cat' );
			}
			if ( isset( $input['tag_ids'] ) && is_array( $input['tag_ids'] ) ) {
				wp_set_object_terms( $product->get_id(), array_map( 'absint', $input['tag_ids'] ), 'product_tag' );
			}

			if ( isset( $input['featured_image_id'] ) ) {
				$product->set_image_id( (int) $input['featured_image_id'] );
				$product->save();
			}
			if ( isset( $input['gallery_image_ids'] ) && is_array( $input['gallery_image_ids'] ) ) {
				$product->set_gallery_image_ids( array_map( 'absint', $input['gallery_image_ids'] ) );
				$product->save();
			}

			if ( isset( $input['downloads'] ) && is_array( $input['downloads'] ) && $product->is_downloadable() ) {
				$downloads = array();
				foreach ( $input['downloads'] as $dl ) {
					$download = new \WC_Product_Download();
					if ( ! empty( $dl['download_id'] ) ) {
						$download->set_id( sanitize_text_field( $dl['download_id'] ) );
					}
					$download->set_name( sanitize_text_field( $dl['name'] ) );
					$download->set_file( esc_url_raw( $dl['file'] ) );
					$downloads[] = $download;
				}
				$product->set_downloads( $downloads );
				$product->save();
			}

			return array( 'product' => mcp_wc_format_product( wc_get_product( $product->get_id() ) ) );
			} catch ( \Throwable $e ) {
				return array( 'error' => $e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']' );
			}
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_products' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
		),
	) );
}

// ─── Product Delete ──────────────────────────────────────────────────────────

function mcp_wc_register_product_delete(): void {
	mcp_wc_register_ability( 'woocommerce-mcp/product-delete', array(
		'label'               => 'Delete product',
		'description'         => 'Delete, trash, or restore a product.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array( 'type' => 'integer', 'minimum' => 1 ),
				'force' => array( 'type' => 'boolean', 'default' => false ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
				'id'      => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			$result = mcp_wc_get_product_or_error( (int) $input['id'], 'delete' );
			if ( ! $result['success'] ) {
				return $result;
			}

			$force  = (bool) ( $input['force'] ?? false );
			$success = $result['product']->delete( $force );

			return array(
				'deleted' => (bool) $success,
				'id'      => (int) $input['id'],
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_products' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	) );
}

// ─── Variations ──────────────────────────────────────────────────────────────

function mcp_wc_register_variations_query(): void {
	mcp_wc_register_ability( 'woocommerce/variations-query', array(
		'label'               => 'Query variations',
		'description'         => 'List variations for a variable parent product.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'page'       => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page'   => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
			),
			'required'             => array( 'product_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'variations'  => array( 'type' => 'array', 'items' => mcp_wc_product_output_schema() ),
				'total_pages' => array( 'type' => 'integer' ),
				'page'        => array( 'type' => 'integer' ),
				'per_page'    => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'edit_products' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$product = wc_get_product( (int) $input['product_id'] );
			if ( ! $product || 'variable' !== $product->get_type() ) {
				return array( 'error' => 'Product not found or not a variable product.' );
			}

			$page     = (int) ( $input['page'] ?? 1 );
			$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 25 ) ) );

			$variation_ids   = $product->get_children();
			$total           = count( $variation_ids );
			$total_pages     = max( 1, (int) ceil( $total / $per_page ) );
			$offset          = ( $page - 1 ) * $per_page;
			$paged_ids       = array_slice( $variation_ids, $offset, $per_page );

			$variations = array();
			foreach ( $paged_ids as $vid ) {
				$variation = wc_get_product( $vid );
				if ( $variation ) {
					$variations[] = mcp_wc_format_product( $variation );
				}
			}

			return array(
				'variations'  => $variations,
				'total_pages' => $total_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_products' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );
}

function mcp_wc_register_variation_create(): void {
	mcp_wc_register_ability( 'woocommerce/variation-create', array(
		'label'               => 'Create variation',
		'description'         => 'Create a product variation for a variable parent product.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id'     => array( 'type' => 'integer', 'minimum' => 1 ),
				'attributes'     => array( 'type' => 'object', 'description' => 'Attribute slug => option value pairs.', 'additionalProperties' => array( 'type' => 'string' ) ),
				'sku'            => array( 'type' => 'string' ),
				'regular_price'  => array( 'type' => 'string', 'pattern' => '^(?:-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)|)$' ),
				'sale_price'     => array( 'type' => 'string', 'pattern' => '^(?:-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)|)$' ),
				'manage_stock'   => array( 'type' => 'boolean' ),
				'stock_quantity' => array( 'type' => 'integer' ),
				'stock_status'   => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
				'description'    => array( 'type' => 'string' ),
				'weight'         => array( 'type' => 'string' ),
			),
			'required'             => array( 'product_id', 'attributes' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'variation' => mcp_wc_product_output_schema(),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'edit_products' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$parent = wc_get_product( (int) $input['product_id'] );
			if ( ! $parent || 'variable' !== $parent->get_type() ) {
				return array( 'error' => 'Parent product not found or not variable.' );
			}

			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );

			$attrs = array();
			if ( isset( $input['attributes'] ) && is_array( $input['attributes'] ) ) {
				foreach ( $input['attributes'] as $slug => $value ) {
					$slug = sanitize_title( $slug );
					$attrs[ 'attribute_' . $slug ] = sanitize_text_field( $value );
				}
			}
			$variation->set_attributes( $attrs );

			if ( isset( $input['sku'] ) ) { $variation->set_sku( sanitize_text_field( $input['sku'] ) ); }
			if ( isset( $input['regular_price'] ) ) { $variation->set_regular_price( sanitize_text_field( $input['regular_price'] ) ); }
			if ( isset( $input['sale_price'] ) ) { $variation->set_sale_price( sanitize_text_field( $input['sale_price'] ) ); }
			if ( isset( $input['manage_stock'] ) ) { $variation->set_manage_stock( (bool) $input['manage_stock'] ); }
			if ( isset( $input['stock_quantity'] ) ) { $variation->set_stock_quantity( (int) $input['stock_quantity'] ); }
			if ( isset( $input['stock_status'] ) ) { $variation->set_stock_status( sanitize_text_field( $input['stock_status'] ) ); }
			if ( isset( $input['description'] ) ) { $variation->set_description( wp_kses_post( $input['description'] ) ); }
			if ( isset( $input['weight'] ) ) { $variation->set_weight( sanitize_text_field( $input['weight'] ) ); }

			$variation_id = $variation->save();

			return array( 'variation' => mcp_wc_format_product( wc_get_product( $variation_id ) ) );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_products' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
		),
	) );
}

function mcp_wc_register_variation_update(): void {
	mcp_wc_register_ability( 'woocommerce/variation-update', array(
		'label'               => 'Update variation',
		'description'         => 'Update an existing product variation.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'             => array( 'type' => 'integer', 'minimum' => 1 ),
				'sku'            => array( 'type' => 'string' ),
				'regular_price'  => array( 'type' => 'string', 'pattern' => '^(?:-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)|)$' ),
				'sale_price'     => array( 'type' => 'string', 'pattern' => '^(?:-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)|)$' ),
				'manage_stock'   => array( 'type' => 'boolean' ),
				'stock_quantity' => array( 'type' => 'integer' ),
				'stock_status'   => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
				'description'    => array( 'type' => 'string' ),
				'weight'         => array( 'type' => 'string' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'variation' => mcp_wc_product_output_schema(),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'edit_products' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$variation = wc_get_product( (int) $input['id'] );
			if ( ! $variation || 'variation' !== $variation->get_type() ) {
				return array( 'error' => 'Variation not found.' );
			}

			foreach ( array( 'sku', 'regular_price', 'sale_price' ) as $prop ) {
				if ( isset( $input[ $prop ] ) ) {
					$setter = 'set_' . $prop;
					$variation->$setter( sanitize_text_field( $input[ $prop ] ) );
				}
			}
			if ( isset( $input['manage_stock'] ) ) { $variation->set_manage_stock( (bool) $input['manage_stock'] ); }
			if ( isset( $input['stock_quantity'] ) ) { $variation->set_stock_quantity( (int) $input['stock_quantity'] ); }
			if ( isset( $input['stock_status'] ) ) { $variation->set_stock_status( sanitize_text_field( $input['stock_status'] ) ); }
			if ( isset( $input['description'] ) ) { $variation->set_description( wp_kses_post( $input['description'] ) ); }
			if ( isset( $input['weight'] ) ) { $variation->set_weight( sanitize_text_field( $input['weight'] ) ); }

			$variation->save();

			return array( 'variation' => mcp_wc_format_product( wc_get_product( $variation->get_id() ) ) );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_products' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
		),
	) );
}

function mcp_wc_register_variation_delete(): void {
	mcp_wc_register_ability( 'woocommerce/variation-delete', array(
		'label'               => 'Delete variation',
		'description'         => 'Delete a product variation.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array( 'type' => 'integer', 'minimum' => 1 ),
				'force' => array( 'type' => 'boolean', 'default' => true ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
				'id'      => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'edit_products' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$variation = wc_get_product( (int) $input['id'] );
			if ( ! $variation || 'variation' !== $variation->get_type() ) {
				return array( 'error' => 'Variation not found.' );
			}

			$force   = (bool) ( $input['force'] ?? true );
			$success = $variation->delete( $force );

			return array( 'deleted' => (bool) $success, 'id' => (int) $input['id'] );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_products' );
		},
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
		),
	) );
}

// ─── Categories ──────────────────────────────────────────────────────────────

function mcp_wc_format_term( \WP_Term $term ): array {
	return array(
		'id'          => $term->term_id,
		'name'        => $term->name,
		'slug'        => $term->slug,
		'description' => $term->description,
		'count'       => (int) $term->count,
		'parent_id'   => $term->parent ? (int) $term->parent : 0,
		'permalink'   => get_term_link( $term ),
	);
}

function mcp_wc_register_categories_query(): void {
	mcp_wc_register_ability( 'woocommerce/categories-query', array(
		'label'               => 'Query categories',
		'description'         => 'List product categories with optional filters.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'       => array( 'type' => 'integer', 'minimum' => 1 ),
				'search'   => array( 'type' => 'string' ),
				'parent'   => array( 'type' => 'integer', 'description' => 'Filter by parent category ID. Use 0 for top-level.' ),
				'orderby'  => array( 'type' => 'string', 'enum' => array( 'name', 'id', 'slug', 'count' ) ),
				'order'    => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
				'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => mcp_wc_paginated_schema( array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'count'       => array( 'type' => 'integer' ),
				'parent_id'   => array( 'type' => 'integer' ),
				'permalink'   => array( 'type' => 'string', 'format' => 'uri' ),
			),
			'additionalProperties' => false,
		) ),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			if ( isset( $input['id'] ) ) {
				$term = get_term( (int) $input['id'], 'product_cat' );
				if ( ! $term || is_wp_error( $term ) ) {
					return array( 'items' => array(), 'total_pages' => 0, 'page' => 1, 'per_page' => (int) ( $input['per_page'] ?? 25 ) );
				}
				return array( 'items' => array( mcp_wc_format_term( $term ) ), 'total_pages' => 1, 'page' => 1, 'per_page' => 1 );
			}

			$page     = (int) ( $input['page'] ?? 1 );
			$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 25 ) ) );
			$args     = array(
				'taxonomy' => 'product_cat',
				'hide_empty' => false,
				'number'   => $per_page,
				'offset'   => ( $page - 1 ) * $per_page,
			);

			if ( ! empty( $input['search'] ) ) { $args['search'] = sanitize_text_field( $input['search'] ); }
			if ( isset( $input['parent'] ) ) { $args['parent'] = (int) $input['parent']; }
			if ( ! empty( $input['orderby'] ) ) { $args['orderby'] = sanitize_text_field( $input['orderby'] ); }
			if ( ! empty( $input['order'] ) ) { $args['order'] = strtoupper( sanitize_text_field( $input['order'] ) ); }

			$terms = get_terms( $args );
			if ( is_wp_error( $terms ) ) { return array( 'error' => $terms->get_error_message() ); }

			$total_count = wp_count_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'search' => $args['search'] ?? '', 'parent' => $args['parent'] ?? '' ) );
			if ( is_wp_error( $total_count ) ) { $total_count = 0; }

			return array(
				'items'       => array_map( 'mcp_wc_format_term', $terms ),
				'total_pages' => max( 1, (int) ceil( (int) $total_count / $per_page ) ),
				'page'        => $page,
				'per_page'    => $per_page,
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_product_terms' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

function mcp_wc_register_category_create(): void {
	mcp_wc_register_ability( 'woocommerce/category-create', array(
		'label'               => 'Create category',
		'description'         => 'Create a product category.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'parent'      => array( 'type' => 'integer', 'description' => 'Parent category ID.' ),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'category' => array( 'type' => 'object', 'properties' => array(
					'id'          => array( 'type' => 'integer' ),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'count'       => array( 'type' => 'integer' ),
					'parent_id'   => array( 'type' => 'integer' ),
					'permalink'   => array( 'type' => 'string', 'format' => 'uri' ),
				), 'additionalProperties' => false ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$args = array(
				'name'  => sanitize_text_field( $input['name'] ),
				'slug'  => isset( $input['slug'] ) ? sanitize_title( $input['slug'] ) : '',
				'parent'    => isset( $input['parent'] ) ? (int) $input['parent'] : 0,
				'description' => isset( $input['description'] ) ? wp_kses_post( $input['description'] ) : '',
			);

			$result = wp_insert_term( $args['name'], 'product_cat', $args );
			if ( is_wp_error( $result ) ) {
				return array( 'error' => $result->get_error_message() );
			}

			$term = get_term( $result['term_id'], 'product_cat' );
			return array( 'category' => mcp_wc_format_term( $term ) );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_product_terms' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		),
	) );
}

function mcp_wc_register_category_update(): void {
	mcp_wc_register_ability( 'woocommerce/category-update', array(
		'label'               => 'Update category',
		'description'         => 'Update a product category.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => array( 'type' => 'integer', 'minimum' => 1 ),
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'parent'      => array( 'type' => 'integer' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'category' => array( 'type' => 'object', 'properties' => array(
					'id'          => array( 'type' => 'integer' ),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'count'       => array( 'type' => 'integer' ),
					'parent_id'   => array( 'type' => 'integer' ),
					'permalink'   => array( 'type' => 'string', 'format' => 'uri' ),
				), 'additionalProperties' => false ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$id = (int) $input['id'];
			$args = array();
			if ( isset( $input['name'] ) ) { $args['name'] = sanitize_text_field( $input['name'] ); }
			if ( isset( $input['slug'] ) ) { $args['slug'] = sanitize_title( $input['slug'] ); }
			if ( isset( $input['description'] ) ) { $args['description'] = wp_kses_post( $input['description'] ); }
			if ( isset( $input['parent'] ) ) { $args['parent'] = (int) $input['parent']; }

			$result = wp_update_term( $id, 'product_cat', $args );
			if ( is_wp_error( $result ) ) {
				return array( 'error' => $result->get_error_message() );
			}

			$term = get_term( $id, 'product_cat' );
			return array( 'category' => mcp_wc_format_term( $term ) );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_product_terms' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		),
	) );
}

function mcp_wc_register_category_delete(): void {
	mcp_wc_register_ability( 'woocommerce/category-delete', array(
		'label'               => 'Delete category',
		'description'         => 'Delete a product category.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'minimum' => 1 ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
				'id'      => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$result = wp_delete_term( (int) $input['id'], 'product_cat' );
			return array(
				'deleted' => ! is_wp_error( $result ) && true === $result,
				'id'      => (int) $input['id'],
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_product_terms' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		),
	) );
}

// ─── Tags ────────────────────────────────────────────────────────────────────

function mcp_wc_register_tags_query(): void {
	mcp_wc_register_ability( 'woocommerce/tags-query', array(
		'label'               => 'Query tags',
		'description'         => 'List product tags.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'       => array( 'type' => 'integer', 'minimum' => 1 ),
				'search'   => array( 'type' => 'string' ),
				'orderby'  => array( 'type' => 'string', 'enum' => array( 'name', 'id', 'slug', 'count' ) ),
				'order'    => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
				'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => mcp_wc_paginated_schema( array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'count'       => array( 'type' => 'integer' ),
				'parent_id'   => array( 'type' => 'integer' ),
				'permalink'   => array( 'type' => 'string', 'format' => 'uri' ),
			),
			'additionalProperties' => false,
		) ),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			if ( isset( $input['id'] ) ) {
				$term = get_term( (int) $input['id'], 'product_tag' );
				if ( ! $term || is_wp_error( $term ) ) {
					return array( 'items' => array(), 'total_pages' => 0, 'page' => 1, 'per_page' => (int) ( $input['per_page'] ?? 25 ) );
				}
				return array( 'items' => array( mcp_wc_format_term( $term ) ), 'total_pages' => 1, 'page' => 1, 'per_page' => 1 );
			}

			$page     = (int) ( $input['page'] ?? 1 );
			$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 25 ) ) );
			$args     = array(
				'taxonomy'   => 'product_tag',
				'hide_empty' => false,
				'number'     => $per_page,
				'offset'     => ( $page - 1 ) * $per_page,
			);

			if ( ! empty( $input['search'] ) ) { $args['search'] = sanitize_text_field( $input['search'] ); }
			if ( ! empty( $input['orderby'] ) ) { $args['orderby'] = sanitize_text_field( $input['orderby'] ); }
			if ( ! empty( $input['order'] ) ) { $args['order'] = strtoupper( sanitize_text_field( $input['order'] ) ); }

			$terms = get_terms( $args );
			if ( is_wp_error( $terms ) ) { return array( 'error' => $terms->get_error_message() ); }

			$total_count = wp_count_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false, 'search' => $args['search'] ?? '' ) );
			if ( is_wp_error( $total_count ) ) { $total_count = 0; }

			return array(
				'items'       => array_map( 'mcp_wc_format_term', $terms ),
				'total_pages' => max( 1, (int) ceil( (int) $total_count / $per_page ) ),
				'page'        => $page,
				'per_page'    => $per_page,
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_product_terms' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

function mcp_wc_register_tag_create(): void {
	mcp_wc_register_ability( 'woocommerce/tag-create', array(
		'label'               => 'Create tag',
		'description'         => 'Create a product tag.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'tag' => array( 'type' => 'object', 'properties' => array(
					'id'          => array( 'type' => 'integer' ),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'count'       => array( 'type' => 'integer' ),
					'parent_id'   => array( 'type' => 'integer' ),
					'permalink'   => array( 'type' => 'string', 'format' => 'uri' ),
				), 'additionalProperties' => false ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$args = array(
				'name'        => sanitize_text_field( $input['name'] ),
				'slug'        => isset( $input['slug'] ) ? sanitize_title( $input['slug'] ) : '',
				'description' => isset( $input['description'] ) ? wp_kses_post( $input['description'] ) : '',
			);

			$result = wp_insert_term( $args['name'], 'product_tag', $args );
			if ( is_wp_error( $result ) ) {
				return array( 'error' => $result->get_error_message() );
			}

			$term = get_term( $result['term_id'], 'product_tag' );
			return array( 'tag' => mcp_wc_format_term( $term ) );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_product_terms' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		),
	) );
}

function mcp_wc_register_tag_update(): void {
	mcp_wc_register_ability( 'woocommerce/tag-update', array(
		'label'               => 'Update tag',
		'description'         => 'Update a product tag.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => array( 'type' => 'integer', 'minimum' => 1 ),
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'tag' => array( 'type' => 'object', 'properties' => array(
					'id'          => array( 'type' => 'integer' ),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'count'       => array( 'type' => 'integer' ),
					'parent_id'   => array( 'type' => 'integer' ),
					'permalink'   => array( 'type' => 'string', 'format' => 'uri' ),
				), 'additionalProperties' => false ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$id = (int) $input['id'];
			$args = array();
			if ( isset( $input['name'] ) ) { $args['name'] = sanitize_text_field( $input['name'] ); }
			if ( isset( $input['slug'] ) ) { $args['slug'] = sanitize_title( $input['slug'] ); }
			if ( isset( $input['description'] ) ) { $args['description'] = wp_kses_post( $input['description'] ); }

			$result = wp_update_term( $id, 'product_tag', $args );
			if ( is_wp_error( $result ) ) {
				return array( 'error' => $result->get_error_message() );
			}

			$term = get_term( $id, 'product_tag' );
			return array( 'tag' => mcp_wc_format_term( $term ) );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_product_terms' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		),
	) );
}

function mcp_wc_register_tag_delete(): void {
	mcp_wc_register_ability( 'woocommerce/tag-delete', array(
		'label'               => 'Delete tag',
		'description'         => 'Delete a product tag.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'minimum' => 1 ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
				'id'      => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$result = wp_delete_term( (int) $input['id'], 'product_tag' );
			return array(
				'deleted' => ! is_wp_error( $result ) && true === $result,
				'id'      => (int) $input['id'],
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_product_terms' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		),
	) );
}

// ─── Attributes ──────────────────────────────────────────────────────────────

function mcp_wc_register_attributes_query(): void {
	mcp_wc_register_ability( 'woocommerce/attributes-query', array(
		'label'               => 'Query attributes',
		'description'         => 'List global product attributes.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'minimum' => 1 ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'attributes' => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer' ),
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'type'        => array( 'type' => 'string', 'enum' => array( 'select', 'text' ) ),
						'order_by'    => array( 'type' => 'string' ),
						'has_archives' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				) ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			if ( isset( $input['id'] ) ) {
				$attribute = wc_get_attribute( (int) $input['id'] );
				if ( ! $attribute ) {
					return array( 'attributes' => array() );
				}
				return array( 'attributes' => array( mcp_wc_format_attribute( $attribute ) ) );
			}

			$attributes = wc_get_attribute_taxonomies();
			return array(
				'attributes' => array_map( 'mcp_wc_format_attribute', $attributes ),
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_product_terms' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

function mcp_wc_format_attribute( $attribute ): array {
	if ( is_object( $attribute ) && ! empty( $attribute->attribute_id ) ) {
		return array(
			'id'           => (int) $attribute->attribute_id,
			'name'         => $attribute->attribute_label,
			'slug'         => $attribute->attribute_name,
			'type'         => $attribute->attribute_type ?? 'select',
			'order_by'     => $attribute->attribute_orderby ?? 'menu_order',
			'has_archives' => (bool) ( $attribute->attribute_public ?? false ),
		);
	}
	return array();
}

function mcp_wc_register_attribute_terms_query(): void {
	mcp_wc_register_ability( 'woocommerce/attribute-terms-query', array(
		'label'               => 'Query attribute terms',
		'description'         => 'List terms for a global product attribute.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'attribute_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'search'       => array( 'type' => 'string' ),
				'page'         => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page'     => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
			),
			'required'             => array( 'attribute_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => mcp_wc_paginated_schema( array(
			'type'       => 'object',
			'properties' => array(
				'id'        => array( 'type' => 'integer' ),
				'name'      => array( 'type' => 'string' ),
				'slug'      => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'count'     => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
		) ),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$attribute = wc_get_attribute( (int) $input['attribute_id'] );
			if ( ! $attribute ) {
				return array( 'error' => 'Attribute not found.' );
			}

			$taxonomy  = wc_attribute_taxonomy_name( $attribute->slug );
			$page      = (int) ( $input['page'] ?? 1 );
			$per_page  = min( 100, max( 1, (int) ( $input['per_page'] ?? 25 ) ) );
			$args = array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => $per_page,
				'offset'     => ( $page - 1 ) * $per_page,
			);

			if ( ! empty( $input['search'] ) ) {
				$args['search'] = sanitize_text_field( $input['search'] );
			}

			$terms = get_terms( $args );
			if ( is_wp_error( $terms ) ) { return array( 'error' => $terms->get_error_message() ); }

			$items = array();
			foreach ( $terms as $term ) {
				$items[] = array(
					'id'          => $term->term_id,
					'name'        => $term->name,
					'slug'        => $term->slug,
					'description' => $term->description,
					'count'       => (int) $term->count,
				);
			}

			$total = wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
			if ( is_wp_error( $total ) ) { $total = 0; }

			return array(
				'items'       => $items,
				'total_pages' => max( 1, (int) ceil( (int) $total / $per_page ) ),
				'page'        => $page,
				'per_page'    => $per_page,
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_product_terms' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

function mcp_wc_register_attribute_term_create(): void {
	mcp_wc_register_ability( 'woocommerce/attribute-term-create', array(
		'label'               => 'Create attribute term',
		'description'         => 'Create a term for a global product attribute.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'attribute_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'name'         => array( 'type' => 'string' ),
				'slug'         => array( 'type' => 'string' ),
				'description'  => array( 'type' => 'string' ),
			),
			'required'             => array( 'attribute_id', 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'term' => array( 'type' => 'object', 'properties' => array(
					'id' => array( 'type' => 'integer' ), 'name' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ), 'count' => array( 'type' => 'integer' ),
				), 'additionalProperties' => false ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$attribute = wc_get_attribute( (int) $input['attribute_id'] );
			if ( ! $attribute ) { return array( 'error' => 'Attribute not found.' ); }

			$taxonomy = wc_attribute_taxonomy_name( $attribute->slug );
			$args = array(
				'name'        => sanitize_text_field( $input['name'] ),
				'slug'        => isset( $input['slug'] ) ? sanitize_title( $input['slug'] ) : '',
				'description' => isset( $input['description'] ) ? wp_kses_post( $input['description'] ) : '',
			);

			$result = wp_insert_term( $args['name'], $taxonomy, $args );
			if ( is_wp_error( $result ) ) { return array( 'error' => $result->get_error_message() ); }

			$term = get_term( $result['term_id'], $taxonomy );
			return array( 'term' => array( 'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'count' => (int) $term->count ) );
		},
		'permission_callback' => function (): bool { return current_user_can( 'manage_product_terms' ); },
		'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ) ),
	) );
}

function mcp_wc_register_attribute_term_update(): void {
	mcp_wc_register_ability( 'woocommerce/attribute-term-update', array(
		'label'               => 'Update attribute term',
		'description'         => 'Update an attribute term.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => array( 'type' => 'integer', 'minimum' => 1 ),
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'term' => array( 'type' => 'object', 'properties' => array(
					'id' => array( 'type' => 'integer' ), 'name' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ), 'count' => array( 'type' => 'integer' ),
				), 'additionalProperties' => false ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$id = (int) $input['id'];
			$term = get_term( $id );
			if ( ! $term || is_wp_error( $term ) ) { return array( 'error' => 'Term not found.' ); }

			$args = array();
			if ( isset( $input['name'] ) ) { $args['name'] = sanitize_text_field( $input['name'] ); }
			if ( isset( $input['slug'] ) ) { $args['slug'] = sanitize_title( $input['slug'] ); }
			if ( isset( $input['description'] ) ) { $args['description'] = wp_kses_post( $input['description'] ); }

			$result = wp_update_term( $id, $term->taxonomy, $args );
			if ( is_wp_error( $result ) ) { return array( 'error' => $result->get_error_message() ); }

			$term = get_term( $id, $term->taxonomy );
			return array( 'term' => array( 'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'count' => (int) $term->count ) );
		},
		'permission_callback' => function (): bool { return current_user_can( 'manage_product_terms' ); },
		'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ) ),
	) );
}

function mcp_wc_register_attribute_term_delete(): void {
	mcp_wc_register_ability( 'woocommerce/attribute-term-delete', array(
		'label'               => 'Delete attribute term',
		'description'         => 'Delete an attribute term.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'minimum' => 1 ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
				'id'      => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$id = (int) $input['id'];
			$term = get_term( $id );
			if ( ! $term || is_wp_error( $term ) ) { return array( 'error' => 'Term not found.' ); }

			$result = wp_delete_term( $id, $term->taxonomy );
			return array( 'deleted' => ! is_wp_error( $result ) && true === $result, 'id' => $id );
		},
		'permission_callback' => function (): bool { return current_user_can( 'manage_product_terms' ); },
		'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ) ),
	) );
}

// ─── Attribute CRUD ──────────────────────────────────────────────────────────

function mcp_wc_register_attribute_create(): void {
	mcp_wc_register_ability( 'woocommerce/attribute-create', array(
		'label'               => 'Create attribute',
		'description'         => 'Create a global product attribute taxonomy.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'name'         => array( 'type' => 'string' ),
				'slug'         => array( 'type' => 'string', 'description' => 'Auto-generated from name if omitted.' ),
				'type'         => array( 'type' => 'string', 'enum' => array( 'select', 'text' ), 'default' => 'select' ),
				'order_by'     => array( 'type' => 'string', 'enum' => array( 'menu_order', 'name', 'name_num', 'id' ), 'default' => 'menu_order' ),
				'has_archives' => array( 'type' => 'boolean', 'default' => false ),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'attribute' => array( 'type' => 'object' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$name = sanitize_text_field( $input['name'] );
			$slug = ! empty( $input['slug'] ) ? sanitize_title( $input['slug'] ) : sanitize_title( $name );
			if ( strlen( $slug ) > 28 ) { $slug = substr( $slug, 0, 28 ); }

			$existing = wc_get_attribute_taxonomies();
			foreach ( $existing as $attr ) {
				if ( $attr->attribute_name === $slug ) {
					return array( 'error' => 'An attribute with this slug already exists.' );
				}
			}

			$attribute_id = wc_create_attribute( array(
				'name'         => $name,
				'slug'         => $slug,
				'type'         => $input['type'] ?? 'select',
				'order_by'     => $input['order_by'] ?? 'menu_order',
				'has_archives' => $input['has_archives'] ?? false,
			) );

			if ( is_wp_error( $attribute_id ) ) {
				return array( 'error' => $attribute_id->get_error_message() );
			}

			$taxonomy_name = wc_attribute_taxonomy_name( $slug );
			if ( ! taxonomy_exists( $taxonomy_name ) ) {
				register_taxonomy( $taxonomy_name, array( 'product' ), array() );
			}

			$attribute = wc_get_attribute( $attribute_id );
			if ( ! $attribute || ( is_object( $attribute ) && empty( $attribute->attribute_id ) ) ) {
				// Re-fetch after cache flush
				delete_transient( 'wc_attribute_taxonomies' );
				$attribute = wc_get_attribute( $attribute_id );
			}
			return array( 'attribute' => $attribute ? mcp_wc_format_attribute( $attribute ) : array( 'id' => $attribute_id, 'name' => $name, 'slug' => $slug ) );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_product_terms' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		),
	) );
}

function mcp_wc_register_attribute_update(): void {
	mcp_wc_register_ability( 'woocommerce/attribute-update', array(
		'label'               => 'Update attribute',
		'description'         => 'Update a global product attribute taxonomy.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'           => array( 'type' => 'integer', 'minimum' => 1 ),
				'name'         => array( 'type' => 'string' ),
				'slug'         => array( 'type' => 'string' ),
				'type'         => array( 'type' => 'string', 'enum' => array( 'select', 'text' ) ),
				'order_by'     => array( 'type' => 'string', 'enum' => array( 'menu_order', 'name', 'name_num', 'id' ) ),
				'has_archives' => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'attribute' => array( 'type' => 'object' ) ),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$attr = wc_get_attribute( (int) $input['id'] );
			if ( ! $attr ) {
				return array( 'error' => 'Attribute not found.' );
			}

			$data = array( 'attribute_id' => $attr->id );
			if ( isset( $input['name'] ) ) { $data['attribute_label'] = sanitize_text_field( $input['name'] ); }
			if ( isset( $input['slug'] ) ) {
				$slug = sanitize_title( $input['slug'] );
				if ( strlen( $slug ) > 28 ) { $slug = substr( $slug, 0, 28 ); }
				$data['attribute_name'] = $slug;
			}
			if ( isset( $input['type'] ) ) { $data['attribute_type'] = sanitize_text_field( $input['type'] ); }
			if ( isset( $input['order_by'] ) ) { $data['attribute_orderby'] = sanitize_text_field( $input['order_by'] ); }
			if ( isset( $input['has_archives'] ) ) { $data['attribute_public'] = (int) (bool) $input['has_archives']; }

			$result = wc_update_attribute( (int) $input['id'], $data );
			if ( is_wp_error( $result ) ) {
				return array( 'error' => $result->get_error_message() );
			}

			return array( 'attribute' => mcp_wc_format_attribute( wc_get_attribute( (int) $input['id'] ) ) );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_product_terms' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		),
	) );
}

function mcp_wc_register_attribute_delete(): void {
	mcp_wc_register_ability( 'woocommerce/attribute-delete', array(
		'label'               => 'Delete attribute',
		'description'         => 'Delete a global product attribute taxonomy.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'minimum' => 1 ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'deleted' => array( 'type' => 'boolean' ), 'id' => array( 'type' => 'integer' ) ),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$result = wc_delete_attribute( (int) $input['id'] );
			return array( 'deleted' => (bool) $result, 'id' => (int) $input['id'] );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_product_terms' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		),
	) );
}

// ─── Product Meta Query ──────────────────────────────────────────────────────

function mcp_wc_register_product_meta_query(): void {
	mcp_wc_register_ability( 'woocommerce-mcp/product-meta-query', array(
		'label'       => 'Get product meta',
		'description' => 'Read custom meta fields for a product. Essential for extension interoperability (Subscriptions, Bookings, Bundles, etc.).',
		'category'    => 'site',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'product_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'keys'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Specific meta keys to retrieve. If empty, returns all product meta.' ),
			),
			'required'   => array( 'product_id' ),
			'additionalProperties' => false,
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'meta' => array( 'type' => 'object', 'additionalProperties' => true ),
			),
			'additionalProperties' => false,
		),
		'execute_callback' => function( array $input ): array {
			if ( ! current_user_can( 'edit_products' ) ) {
				return array( 'error' => 'Permission denied.' );
			}
			$product = wc_get_product( (int) $input['product_id'] );
			if ( ! $product ) {
				return array( 'error' => 'Product not found.' );
			}
			$all_meta = get_post_meta( $product->get_id() );
			$filtered = array();
			$keys = $input['keys'] ?? array();
			foreach ( $all_meta as $key => $values ) {
				if ( ! empty( $keys ) && ! in_array( $key, $keys, true ) ) {
					continue;
				}
				$filtered[ $key ] = count( $values ) === 1 ? $values[0] : $values;
			}
			return array( 'meta' => $filtered );
		},
		'permission_callback' => function(): bool {
			return current_user_can( 'edit_products' );
		},
		'meta' => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
	) );
}

// ─── Product Duplicate ────────────────────────────────────────────────────────

function mcp_wc_register_product_duplicate(): void {
	mcp_wc_register_ability( 'woocommerce-mcp/product-duplicate', array(
		'label'               => 'Duplicate product',
		'description'         => 'Duplicate an existing product with a new name and optional SKU.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'     => array( 'type' => 'integer', 'minimum' => 1 ),
				'name'   => array( 'type' => 'string', 'description' => 'New product name. Defaults to "Original Name (Copy)".' ),
				'sku'    => array( 'type' => 'string', 'description' => 'New SKU. Auto-generated if omitted.' ),
				'status' => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'private', 'publish' ), 'default' => 'draft' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'product' => mcp_wc_product_output_schema(),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'edit_products' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$original = wc_get_product( (int) $input['id'] );
			if ( ! $original ) {
				return array( 'error' => 'Product not found.' );
			}

			$duplicate = ( new \WC_Admin_Duplicate_Product() )->product_duplicate( $original );

			if ( isset( $input['name'] ) ) {
				$duplicate->set_name( sanitize_text_field( $input['name'] ) );
			}
			if ( isset( $input['sku'] ) ) {
				$duplicate->set_sku( sanitize_text_field( $input['sku'] ) );
			}
			if ( isset( $input['status'] ) ) {
				$duplicate->set_status( sanitize_text_field( $input['status'] ) );
			}

			$duplicate->save();

			return array( 'product' => mcp_wc_format_product( wc_get_product( $duplicate->get_id() ) ) );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_products' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		),
	) );
}

// ─── Products Bulk Stock ──────────────────────────────────────────────────────

function mcp_wc_register_products_bulk_stock(): void {
	mcp_wc_register_ability( 'woocommerce-mcp/products-bulk-stock', array(
		'label'               => 'Bulk stock update',
		'description'         => 'Update stock quantities for multiple products at once.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'products' => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'             => array( 'type' => 'integer', 'minimum' => 1 ),
						'stock_quantity' => array( 'type' => 'integer' ),
						'stock_status'   => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
						'manage_stock'   => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'id' ),
					'additionalProperties' => false,
				) ),
			),
			'required'             => array( 'products' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'updated' => array( 'type' => 'integer' ),
				'errors'  => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'edit_products' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$updated = 0;
			$errors  = array();

			foreach ( $input['products'] as $item ) {
				$product = wc_get_product( (int) $item['id'] );
				if ( ! $product ) {
					$errors[] = array( 'id' => (int) $item['id'], 'error' => 'Product not found.' );
					continue;
				}

				if ( isset( $item['manage_stock'] ) ) {
					$product->set_manage_stock( (bool) $item['manage_stock'] );
				}
				if ( isset( $item['stock_quantity'] ) && $product->get_manage_stock() ) {
					$product->set_stock_quantity( (int) $item['stock_quantity'] );
				}
				if ( isset( $item['stock_status'] ) ) {
					$product->set_stock_status( sanitize_text_field( $item['stock_status'] ) );
				}

				$product->save();
				++$updated;
			}

			return array( 'updated' => $updated, 'errors' => $errors );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_products' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		),
	) );
}
