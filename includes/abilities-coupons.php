<?php
/**
 * WooCommerce coupon abilities.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function mcp_wc_register_coupon_abilities(): void {
	if ( ! mcp_wc_is_active() ) {
		return;
	}

	mcp_wc_register_coupons_query();
	mcp_wc_register_coupon_create();
	mcp_wc_register_coupon_update();
	mcp_wc_register_coupon_delete();
}

function mcp_wc_get_coupon_or_error( int $id, string $action ): array {
	$coupon = new \WC_Coupon( $id );
	if ( ! $coupon->get_id() ) {
		return array( 'success' => false, 'message' => 'Coupon not found with ID: ' . $id );
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return array( 'success' => false, 'message' => 'You do not have permission to ' . $action . ' coupons.' );
	}
	return array( 'success' => true, 'coupon' => $coupon );
}

function mcp_wc_discount_types(): array {
	if ( ! function_exists( 'wc_get_coupon_types' ) ) {
		return array( 'percent', 'fixed_cart', 'fixed_product' );
	}
	return array_keys( wc_get_coupon_types() );
}

// ─── Coupons Query ───────────────────────────────────────────────────────────

function mcp_wc_register_coupons_query(): void {
	mcp_wc_register_ability( 'woocommerce/coupons-query', array(
		'label'               => 'Query coupons',
		'description'         => 'List coupons with optional filters.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'      => array( 'type' => 'integer', 'minimum' => 1 ),
				'code'    => array( 'type' => 'string' ),
				'search'  => array( 'type' => 'string' ),
				'type'    => array( 'type' => 'string', 'enum' => mcp_wc_discount_types() ),
				'page'    => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100 ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'coupons'     => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'                           => array( 'type' => 'integer' ),
						'code'                         => array( 'type' => 'string' ),
						'description'                  => array( 'type' => 'string' ),
						'discount_type'                => array( 'type' => 'string' ),
						'amount'                       => array( 'type' => 'string' ),
						'individual_use'               => array( 'type' => 'boolean' ),
						'product_ids'                  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'excluded_product_ids'         => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'product_categories'           => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'excluded_product_categories'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'usage_limit'                  => array( 'type' => 'integer' ),
						'usage_limit_per_user'         => array( 'type' => 'integer' ),
						'usage_count'                  => array( 'type' => 'integer' ),
						'minimum_amount'               => array( 'type' => 'string' ),
						'maximum_amount'               => array( 'type' => 'string' ),
						'free_shipping'                => array( 'type' => 'boolean' ),
						'date_expires'                 => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
						'date_expires_gmt'             => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
						'date_created'                 => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
						'date_created_gmt'             => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
						'date_modified'                => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
						'date_modified_gmt'            => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
					),
					'additionalProperties' => false,
				) ),
				'total_pages' => array( 'type' => 'integer' ),
				'page'        => array( 'type' => 'integer' ),
				'per_page'    => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			if ( isset( $input['id'] ) ) {
				$coupon = new \WC_Coupon( (int) $input['id'] );
				if ( ! $coupon->get_id() ) {
					return array( 'coupons' => array(), 'total_pages' => 0, 'page' => 1, 'per_page' => (int) ( $input['per_page'] ?? 10 ) );
				}
				return array( 'coupons' => array( mcp_wc_format_coupon( $coupon ) ), 'total_pages' => 1, 'page' => 1, 'per_page' => 1 );
			}

			$page     = (int) ( $input['page'] ?? 1 );
			$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );
			$args     = array(
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'fields'         => 'ids',
			);

			if ( ! empty( $input['code'] ) ) {
				$args['s'] = sanitize_text_field( $input['code'] );
				$args['exact'] = false;
				$args['sentence'] = true;
			}
			if ( ! empty( $input['search'] ) ) {
				$args['s'] = sanitize_text_field( $input['search'] );
			}

			$query = new \WP_Query( $args );
			$coupons = array();
			foreach ( $query->posts as $post_id ) {
				$coupon = new \WC_Coupon( $post_id );
				if ( $coupon->get_id() ) {
					if ( ! empty( $input['type'] ) && $coupon->get_discount_type() !== $input['type'] ) {
						continue;
					}
					$coupons[] = mcp_wc_format_coupon( $coupon );
				}
			}

			return array(
				'coupons'     => $coupons,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_woocommerce' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

// ─── Coupon Create ───────────────────────────────────────────────────────────

function mcp_wc_register_coupon_create(): void {
	mcp_wc_register_ability( 'woocommerce/coupon-create', array(
		'label'               => 'Create coupon',
		'description'         => 'Create a new coupon.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'code'                         => array( 'type' => 'string' ),
				'description'                  => array( 'type' => 'string' ),
				'discount_type'                => array( 'type' => 'string', 'enum' => mcp_wc_discount_types() ),
				'amount'                       => array( 'type' => 'string' ),
				'individual_use'               => array( 'type' => 'boolean' ),
				'product_ids'                  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'excluded_product_ids'         => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'product_categories'           => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'excluded_product_categories'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'usage_limit'                  => array( 'type' => 'integer' ),
				'usage_limit_per_user'         => array( 'type' => 'integer' ),
				'minimum_amount'               => array( 'type' => 'string' ),
				'maximum_amount'               => array( 'type' => 'string' ),
				'free_shipping'                => array( 'type' => 'boolean' ),
				'date_expires'                 => array( 'type' => 'string', 'format' => 'date-time' ),
			),
			'required'             => array( 'code' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'coupon' => array( 'type' => 'object', 'properties' => array(
				'id'              => array( 'type' => 'integer' ), 'code' => array( 'type' => 'string' ),
				'discount_type'   => array( 'type' => 'string' ), 'amount' => array( 'type' => 'string' ),
				'usage_limit'     => array( 'type' => 'integer' ), 'usage_count' => array( 'type' => 'integer' ),
				'minimum_amount'  => array( 'type' => 'string' ), 'free_shipping' => array( 'type' => 'boolean' ),
			), 'additionalProperties' => false ) ),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$coupon = new \WC_Coupon();
			$coupon->set_code( wc_format_coupon_code( sanitize_text_field( $input['code'] ) ) );

			if ( isset( $input['description'] ) ) { $coupon->set_description( wp_kses_post( $input['description'] ) ); }
			if ( isset( $input['discount_type'] ) ) { $coupon->set_discount_type( sanitize_text_field( $input['discount_type'] ) ); }
			if ( isset( $input['amount'] ) ) { $coupon->set_amount( sanitize_text_field( $input['amount'] ) ); }
			if ( isset( $input['individual_use'] ) ) { $coupon->set_individual_use( (bool) $input['individual_use'] ); }
			if ( isset( $input['product_ids'] ) ) { $coupon->set_product_ids( array_map( 'absint', $input['product_ids'] ) ); }
			if ( isset( $input['excluded_product_ids'] ) ) { $coupon->set_excluded_product_ids( array_map( 'absint', $input['excluded_product_ids'] ) ); }
			if ( isset( $input['product_categories'] ) ) { $coupon->set_product_categories( array_map( 'absint', $input['product_categories'] ) ); }
			if ( isset( $input['excluded_product_categories'] ) ) { $coupon->set_excluded_product_categories( array_map( 'absint', $input['excluded_product_categories'] ) ); }
			if ( isset( $input['usage_limit'] ) ) { $coupon->set_usage_limit( (int) $input['usage_limit'] ); }
			if ( isset( $input['usage_limit_per_user'] ) ) { $coupon->set_usage_limit_per_user( (int) $input['usage_limit_per_user'] ); }
			if ( isset( $input['minimum_amount'] ) ) { $coupon->set_minimum_amount( sanitize_text_field( $input['minimum_amount'] ) ); }
			if ( isset( $input['maximum_amount'] ) ) { $coupon->set_maximum_amount( sanitize_text_field( $input['maximum_amount'] ) ); }
			if ( isset( $input['free_shipping'] ) ) { $coupon->set_free_shipping( (bool) $input['free_shipping'] ); }
			if ( ! empty( $input['date_expires'] ) ) {
				$date = mcp_wc_parse_date( $input['date_expires'] );
				if ( $date ) { $coupon->set_date_expires( $date->getTimestamp() ); }
			}

			$coupon_id = $coupon->save();
			return array( 'coupon' => mcp_wc_format_coupon( new \WC_Coupon( $coupon_id ) ) );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_woocommerce' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		),
	) );
}

// ─── Coupon Update ───────────────────────────────────────────────────────────

function mcp_wc_register_coupon_update(): void {
	mcp_wc_register_ability( 'woocommerce/coupon-update', array(
		'label'               => 'Update coupon',
		'description'         => 'Update an existing coupon.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                           => array( 'type' => 'integer', 'minimum' => 1 ),
				'code'                         => array( 'type' => 'string' ),
				'description'                  => array( 'type' => 'string' ),
				'discount_type'                => array( 'type' => 'string', 'enum' => mcp_wc_discount_types() ),
				'amount'                       => array( 'type' => 'string' ),
				'individual_use'               => array( 'type' => 'boolean' ),
				'product_ids'                  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'excluded_product_ids'         => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'product_categories'           => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'excluded_product_categories'  => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'usage_limit'                  => array( 'type' => 'integer' ),
				'usage_limit_per_user'         => array( 'type' => 'integer' ),
				'minimum_amount'               => array( 'type' => 'string' ),
				'maximum_amount'               => array( 'type' => 'string' ),
				'free_shipping'                => array( 'type' => 'boolean' ),
				'date_expires'                 => array( 'type' => 'string', 'format' => 'date-time' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'coupon' => array( 'type' => 'object' ) ),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			$result = mcp_wc_get_coupon_or_error( (int) $input['id'], 'update' );
			if ( ! $result['success'] ) { return $result; }

			$coupon = $result['coupon'];

			if ( isset( $input['code'] ) ) { $coupon->set_code( wc_format_coupon_code( sanitize_text_field( $input['code'] ) ) ); }
			if ( isset( $input['description'] ) ) { $coupon->set_description( wp_kses_post( $input['description'] ) ); }
			if ( isset( $input['discount_type'] ) ) { $coupon->set_discount_type( sanitize_text_field( $input['discount_type'] ) ); }
			if ( isset( $input['amount'] ) ) { $coupon->set_amount( sanitize_text_field( $input['amount'] ) ); }
			if ( isset( $input['individual_use'] ) ) { $coupon->set_individual_use( (bool) $input['individual_use'] ); }
			if ( isset( $input['product_ids'] ) ) { $coupon->set_product_ids( array_map( 'absint', $input['product_ids'] ) ); }
			if ( isset( $input['excluded_product_ids'] ) ) { $coupon->set_excluded_product_ids( array_map( 'absint', $input['excluded_product_ids'] ) ); }
			if ( isset( $input['product_categories'] ) ) { $coupon->set_product_categories( array_map( 'absint', $input['product_categories'] ) ); }
			if ( isset( $input['excluded_product_categories'] ) ) { $coupon->set_excluded_product_categories( array_map( 'absint', $input['excluded_product_categories'] ) ); }
			if ( isset( $input['usage_limit'] ) ) { $coupon->set_usage_limit( (int) $input['usage_limit'] ); }
			if ( isset( $input['usage_limit_per_user'] ) ) { $coupon->set_usage_limit_per_user( (int) $input['usage_limit_per_user'] ); }
			if ( isset( $input['minimum_amount'] ) ) { $coupon->set_minimum_amount( sanitize_text_field( $input['minimum_amount'] ) ); }
			if ( isset( $input['maximum_amount'] ) ) { $coupon->set_maximum_amount( sanitize_text_field( $input['maximum_amount'] ) ); }
			if ( isset( $input['free_shipping'] ) ) { $coupon->set_free_shipping( (bool) $input['free_shipping'] ); }
			if ( isset( $input['date_expires'] ) ) {
				$date = '' !== $input['date_expires'] ? mcp_wc_parse_date( $input['date_expires'] ) : null;
				$coupon->set_date_expires( $date ? $date->getTimestamp() : '' );
			}

			$coupon->save();
			return array( 'coupon' => mcp_wc_format_coupon( new \WC_Coupon( $coupon->get_id() ) ) );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_woocommerce' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		),
	) );
}

// ─── Coupon Delete ───────────────────────────────────────────────────────────

function mcp_wc_register_coupon_delete(): void {
	mcp_wc_register_ability( 'woocommerce/coupon-delete', array(
		'label'               => 'Delete coupon',
		'description'         => 'Delete a coupon.',
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
			'properties' => array( 'deleted' => array( 'type' => 'boolean' ), 'id' => array( 'type' => 'integer' ) ),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			$result = mcp_wc_get_coupon_or_error( (int) $input['id'], 'delete' );
			if ( ! $result['success'] ) { return $result; }

			$force   = (bool) ( $input['force'] ?? true );
			$success = $result['coupon']->delete( $force );
			return array( 'deleted' => (bool) $success, 'id' => (int) $input['id'] );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'manage_woocommerce' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		),
	) );
}
