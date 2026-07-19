<?php
/**
 * WooCommerce settings, tax, shipping, and payment gateway abilities.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function mcp_wc_register_setting_abilities(): void {
	if ( ! mcp_wc_is_active() ) {
		return;
	}

	mcp_wc_register_store_settings();
	mcp_wc_register_tax_rates_query();
	mcp_wc_register_shipping_zones_query();
	mcp_wc_register_shipping_methods_query();
	mcp_wc_register_payment_gateways_query();
	mcp_wc_register_webhooks_query();
	mcp_wc_register_shipping_classes_query();
	mcp_wc_register_tax_classes_query();
	mcp_wc_register_webhook_create();
	mcp_wc_register_webhook_update();
	mcp_wc_register_webhook_delete();
	mcp_wc_register_system_status();
	mcp_wc_register_system_tools_query();
	mcp_wc_register_system_tool_run();
	mcp_wc_register_email_settings();
}

function mcp_wc_settings_permission(): bool {
	return current_user_can( 'manage_woocommerce' );
}

function mcp_wc_system_permission(): bool { return current_user_can( 'manage_options' ); }

function mcp_wc_redact_system_paths( $value ) {
	if ( is_array( $value ) ) { foreach ( $value as $key => $item ) { $value[ $key ] = mcp_wc_redact_system_paths( $item ); } return $value; }
	if ( is_string( $value ) ) {
		$replacements = array();
		if ( defined( 'ABSPATH' ) ) { $replacements[ untrailingslashit( ABSPATH ) ] = '[wordpress-root]'; }
		if ( defined( 'WP_CONTENT_DIR' ) ) { $replacements[ untrailingslashit( WP_CONTENT_DIR ) ] = '[content-dir]'; }
		return str_replace( array_keys( $replacements ), array_values( $replacements ), $value );
	}
	return $value;
}

function mcp_wc_allowed_system_tools(): array {
	$tools = apply_filters( 'mcp_wc_allowed_system_tools', array() );
	return is_array( $tools ) ? array_values( array_filter( array_map( 'sanitize_key', $tools ) ) ) : array();
}

// ─── Store Settings ──────────────────────────────────────────────────────────

function mcp_wc_register_store_settings(): void {
	mcp_wc_register_ability( 'woocommerce/store-settings', array(
		'label'               => 'Get store settings',
		'description'         => 'Get general WooCommerce store settings.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'store_address'         => array( 'type' => 'object', 'properties' => array(
					'address_1' => array( 'type' => 'string' ), 'address_2' => array( 'type' => 'string' ), 'city' => array( 'type' => 'string' ),
					'state' => array( 'type' => 'string' ), 'postcode' => array( 'type' => 'string' ), 'country' => array( 'type' => 'string' ),
				), 'additionalProperties' => false ),
				'general'               => array( 'type' => 'object', 'properties' => array(
					'selling_countries' => array( 'type' => 'string' ), 'shipping_countries' => array( 'type' => 'string' ),
					'default_customer_location' => array( 'type' => 'string' ), 'enable_coupons' => array( 'type' => 'string' ),
					'calc_taxes' => array( 'type' => 'string' ), 'enable_guest_checkout' => array( 'type' => 'string' ),
					'enable_signup_and_login_from_checkout' => array( 'type' => 'string' ),
				), 'additionalProperties' => false ),
				'currency'              => array( 'type' => 'string' ),
				'currency_symbol'       => array( 'type' => 'string' ),
				'currency_position'     => array( 'type' => 'string' ),
				'thousand_separator'    => array( 'type' => 'string' ),
				'decimal_separator'     => array( 'type' => 'string' ),
				'number_of_decimals'    => array( 'type' => 'integer' ),
				'dimension_unit'        => array( 'type' => 'string' ),
				'weight_unit'           => array( 'type' => 'string' ),
				'allowed_countries'     => array( 'type' => 'string' ),
				'selling_country_codes' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'excluded_country_codes' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'shipping_countries'    => array( 'type' => 'string' ),
				'shipping_country_codes' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'default_customer_location' => array( 'type' => 'string' ),
				'enable_guest_checkout' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ) {
			if ( ! mcp_wc_settings_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			return array(
				'store_address'              => array(
					'address_1' => WC()->countries->get_base_address(),
					'address_2' => WC()->countries->get_base_address_2(),
					'city'      => WC()->countries->get_base_city(),
					'state'     => WC()->countries->get_base_state(),
					'postcode'  => WC()->countries->get_base_postcode(),
					'country'   => WC()->countries->get_base_country(),
				),
				'general'                    => array(
					'selling_countries'    => get_option( 'woocommerce_allowed_countries', 'all' ),
					'shipping_countries'   => get_option( 'woocommerce_ship_to_countries', '' ),
					'default_customer_location' => get_option( 'woocommerce_default_customer_address', 'base' ),
					'enable_coupons'       => get_option( 'woocommerce_enable_coupons', 'yes' ),
					'calc_taxes'           => get_option( 'woocommerce_calc_taxes', 'no' ),
					'enable_guest_checkout' => get_option( 'woocommerce_enable_guest_checkout', 'yes' ),
					'enable_signup_and_login_from_checkout' => get_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' ),
				),
				'currency'                   => mcp_wc_get_currency(),
				'currency_symbol'            => mcp_wc_get_currency_symbol(),
				'currency_position'          => get_option( 'woocommerce_currency_pos', 'left' ),
				'thousand_separator'         => get_option( 'woocommerce_price_thousand_sep', ',' ),
				'decimal_separator'          => get_option( 'woocommerce_price_decimal_sep', '.' ),
				'number_of_decimals'         => (int) get_option( 'woocommerce_price_num_decimals', 2 ),
				'dimension_unit'             => get_option( 'woocommerce_dimension_unit', 'cm' ),
				'weight_unit'                => get_option( 'woocommerce_weight_unit', 'kg' ),
				'allowed_countries'          => get_option( 'woocommerce_allowed_countries', 'all' ),
				'selling_country_codes'      => array_values( array_filter( (array) get_option( 'woocommerce_specific_allowed_countries', array() ) ) ),
				'excluded_country_codes'     => array_values( array_filter( (array) get_option( 'woocommerce_all_except_countries', array() ) ) ),
				'shipping_countries'         => get_option( 'woocommerce_ship_to_countries', '' ),
				'shipping_country_codes'     => array_values( array_filter( (array) get_option( 'woocommerce_specific_ship_to_countries', array() ) ) ),
				'default_customer_location'  => get_option( 'woocommerce_default_customer_address', 'base' ),
				'enable_guest_checkout'      => get_option( 'woocommerce_enable_guest_checkout', 'yes' ),
			);
		},
		'permission_callback' => 'mcp_wc_settings_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

// ─── Tax Rates ───────────────────────────────────────────────────────────────

function mcp_wc_register_tax_rates_query(): void {
	mcp_wc_register_ability( 'woocommerce/tax-rates-query', array(
		'label'               => 'Query tax rates',
		'description'         => 'List tax rates with bounded pagination through WooCommerce\'s administrative tax controller.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'      => array( 'type' => 'integer', 'minimum' => 1 ),
				'class'   => array( 'type' => 'string' ),
				'page'    => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => mcp_wc_paginated_schema( array(
			'type'       => 'object',
			'properties' => array(
				'id'              => array( 'type' => 'integer' ),
				'country'         => array( 'type' => 'string' ),
				'state'           => array( 'type' => 'string' ),
				'postcode'        => array( 'type' => 'string' ),
				'city'            => array( 'type' => 'string' ),
				'rate'            => array( 'type' => 'string' ),
				'name'            => array( 'type' => 'string' ),
				'priority'        => array( 'type' => 'integer' ),
				'compound'        => array( 'type' => 'boolean' ),
				'shipping'        => array( 'type' => 'boolean' ),
				'tax_class'       => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		) ),
		'execute_callback'    => function ( array $input ) {
			if ( ! mcp_wc_settings_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			$page     = (int) ( $input['page'] ?? 1 );
			$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 25 ) ) );
			if ( isset( $input['id'] ) ) {
				$rate = \WC_Tax::_get_tax_rate( (int) $input['id'] );
				if ( ! $rate ) {
					return array( 'items' => array(), 'total_pages' => 0, 'page' => 1, 'per_page' => $per_page );
				}
				return array( 'items' => array( mcp_wc_format_tax_rate( $rate ) ), 'total_pages' => 1, 'page' => 1, 'per_page' => 1 );
			}

			if ( ! class_exists( 'WC_REST_Taxes_Controller' ) ) {
				return mcp_wc_error( 'mcp_wc_tax_controller_unavailable', 'WooCommerce tax administration is unavailable.' );
			}

			$request = new \WP_REST_Request( 'GET', '/wc/v3/taxes' );
			$request->set_query_params( array(
				'page'     => $page,
				'per_page' => $per_page,
				'order'    => 'asc',
				'orderby'  => 'order',
				'class'    => sanitize_text_field( (string) ( $input['class'] ?? '' ) ),
				'context'  => 'view',
			) );
			$response = ( new \WC_REST_Taxes_Controller() )->get_items( $request );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$response = rest_ensure_response( $response );
			$headers  = $response->get_headers();
			$items    = array_map( 'mcp_wc_format_rest_tax_rate', (array) $response->get_data() );

			return array(
				'items'       => $items,
				'total_pages' => (int) ( $headers['X-WP-TotalPages'] ?? 0 ),
				'page'        => $page,
				'per_page'    => $per_page,
			);
		},
		'permission_callback' => 'mcp_wc_settings_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

function mcp_wc_format_rest_tax_rate( array $rate ): array {
	return array(
		'id'        => (int) ( $rate['id'] ?? 0 ),
		'country'   => (string) ( $rate['country'] ?? '' ),
		'state'     => (string) ( $rate['state'] ?? '' ),
		'postcode'  => isset( $rate['postcodes'] ) ? implode( ';', (array) $rate['postcodes'] ) : (string) ( $rate['postcode'] ?? '' ),
		'city'      => isset( $rate['cities'] ) ? implode( ';', (array) $rate['cities'] ) : (string) ( $rate['city'] ?? '' ),
		'rate'      => (string) ( $rate['rate'] ?? '' ),
		'name'      => (string) ( $rate['name'] ?? '' ),
		'priority'  => (int) ( $rate['priority'] ?? 0 ),
		'compound'  => (bool) ( $rate['compound'] ?? false ),
		'shipping'  => (bool) ( $rate['shipping'] ?? false ),
		'tax_class' => (string) ( $rate['class'] ?? '' ),
	);
}

function mcp_wc_format_tax_rate( array $rate ): array {
	return array(
		'id'        => (int) ( $rate['tax_rate_id'] ?? 0 ),
		'country'   => $rate['tax_rate_country'] ?? '',
		'state'     => $rate['tax_rate_state'] ?? '',
		'postcode'  => $rate['tax_rate_postcode'] ?? '',
		'city'      => $rate['tax_rate_city'] ?? '',
		'rate'      => $rate['tax_rate'] ?? '',
		'name'      => $rate['tax_rate_name'] ?? '',
		'priority'  => (int) ( $rate['tax_rate_priority'] ?? 0 ),
		'compound'  => (bool) ( $rate['tax_rate_compound'] ?? false ),
		'shipping'  => (bool) ( $rate['tax_rate_shipping'] ?? false ),
		'tax_class' => $rate['tax_rate_class'] ?? '',
	);
}

// ─── Shipping Zones ──────────────────────────────────────────────────────────

function mcp_wc_register_shipping_zones_query(): void {
	mcp_wc_register_ability( 'woocommerce/shipping-zones-query', array(
		'label'               => 'Query shipping zones',
		'description'         => 'List shipping zones and their methods.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer', 'minimum' => 0 ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'zones' => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'               => array( 'type' => 'integer' ),
						'name'             => array( 'type' => 'string' ),
						'order'            => array( 'type' => 'integer' ),
						'zone_locations'   => array( 'type' => 'array' ),
						'shipping_methods' => array( 'type' => 'array' ),
					),
					'additionalProperties' => false,
				) ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ) {
			if ( ! mcp_wc_settings_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			if ( isset( $input['id'] ) ) {
				$zone = new \WC_Shipping_Zone( (int) $input['id'] );
				if ( 0 !== (int) $input['id'] && ! $zone->get_id() ) {
					return array( 'zones' => array() );
				}
				return array( 'zones' => array( mcp_wc_format_shipping_zone( $zone ) ) );
			}

			$zones = \WC_Shipping_Zones::get_zones();
			$items = array();
			foreach ( $zones as $zone ) {
				$items[] = mcp_wc_format_shipping_zone_data( $zone );
			}
			$items[] = mcp_wc_format_shipping_zone( new \WC_Shipping_Zone( 0 ) );
			return array( 'zones' => $items );
		},
		'permission_callback' => 'mcp_wc_settings_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

function mcp_wc_format_shipping_zone( \WC_Shipping_Zone $zone ): array {
	$methods = array();
	foreach ( $zone->get_shipping_methods() as $method ) {
		$methods[] = array(
			'id'        => $method->get_instance_id(),
			'method_id' => $method->get_rate_id(),
			'title'     => $method->get_title(),
			'enabled'   => 'yes' === $method->get_option( 'enabled', 'yes' ),
		);
	}

	return array(
		'id'               => $zone->get_id(),
		'name'             => $zone->get_zone_name(),
		'order'            => $zone->get_zone_order(),
		'zone_locations'   => $zone->get_zone_locations(),
		'shipping_methods' => $methods,
	);
}

function mcp_wc_format_shipping_zone_data( array $zone ): array {
	$z        = new \WC_Shipping_Zone( $zone['zone_id'] ?? $zone['id'] ?? 0 );
	$methods = array();
	foreach ( $z->get_shipping_methods() as $method ) {
		$methods[] = array(
			'id'        => $method->get_instance_id(),
			'method_id' => $method->get_rate_id(),
			'title'     => $method->get_title(),
			'enabled'   => 'yes' === $method->get_option( 'enabled', 'yes' ),
		);
	}

	return array(
		'id'               => (int) $z->get_id(),
		'name'             => $z->get_zone_name(),
		'order'            => $z->get_zone_order(),
		'zone_locations'   => $z->get_zone_locations(),
		'shipping_methods' => $methods,
	);
}

// ─── Shipping Methods ────────────────────────────────────────────────────────

function mcp_wc_register_shipping_methods_query(): void {
	mcp_wc_register_ability( 'woocommerce/shipping-methods-query', array(
		'label'               => 'Query shipping methods',
		'description'         => 'List all available shipping methods.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'methods' => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'string' ),
						'title'       => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'enabled'     => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				) ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ) {
			if ( ! mcp_wc_settings_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			$shipping = WC()->shipping();
			if ( ! $shipping ) {
				return array( 'methods' => array() );
			}

			$methods = array();
			foreach ( $shipping->get_shipping_methods() as $id => $method ) {
				$methods[] = array(
					'id'          => $id,
					'title'       => $method->method_title ?? $id,
					'description' => $method->method_description ?? '',
					'enabled'     => 'yes' === $method->enabled,
				);
			}

			return array( 'methods' => $methods );
		},
		'permission_callback' => 'mcp_wc_settings_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

// ─── Payment Gateways ────────────────────────────────────────────────────────

function mcp_wc_register_payment_gateways_query(): void {
	mcp_wc_register_ability( 'woocommerce/payment-gateways-query', array(
		'label'               => 'Query payment gateways',
		'description'         => 'List all payment gateways with status.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'gateways' => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'string' ),
						'title'       => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'enabled'     => array( 'type' => 'boolean' ),
						'method_title' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				) ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ) {
			if ( ! mcp_wc_settings_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			$gateways = WC()->payment_gateways();
			if ( ! $gateways ) {
				return array( 'gateways' => array() );
			}

			$items = array();
			foreach ( $gateways->payment_gateways() as $gateway ) {
				$items[] = array(
					'id'           => $gateway->id,
					'title'        => $gateway->get_title(),
					'description'  => $gateway->get_description(),
					'enabled'      => 'yes' === $gateway->enabled,
					'method_title' => $gateway->get_method_title(),
				);
			}

			return array( 'gateways' => $items );
		},
		'permission_callback' => 'mcp_wc_settings_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

// ─── Webhooks ────────────────────────────────────────────────────────────────

function mcp_wc_register_webhooks_query(): void {
	mcp_wc_register_ability( 'woocommerce/webhooks-query', array(
		'label'               => 'Query webhooks',
		'description'         => 'List WooCommerce webhooks.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'     => array( 'type' => 'integer', 'minimum' => 1 ),
				'status' => array( 'type' => 'string', 'enum' => array( 'active', 'paused', 'disabled' ) ),
				'page'   => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'items' => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
				'id'            => array( 'type' => 'integer' ),
				'name'          => array( 'type' => 'string' ),
				'topic'         => array( 'type' => 'string' ),
				'delivery_url'  => array( 'type' => 'string', 'format' => 'uri' ),
				'status'        => array( 'type' => 'string' ),
				'date_created'  => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
					),
					'additionalProperties' => false,
				) ),
				'has_more' => array( 'type' => 'boolean' ),
				'page'     => array( 'type' => 'integer' ),
				'per_page' => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ) {
			if ( ! mcp_wc_settings_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			$page       = (int) ( $input['page'] ?? 1 );
			$per_page   = min( 100, max( 1, (int) ( $input['per_page'] ?? 25 ) ) );

			if ( isset( $input['id'] ) ) {
				$webhook = wc_get_webhook( (int) $input['id'] );
				if ( ! $webhook ) {
					return array( 'items' => array(), 'has_more' => false, 'page' => 1, 'per_page' => $per_page );
				}
				return array( 'items' => array( mcp_wc_format_webhook( $webhook ) ), 'has_more' => false, 'page' => 1, 'per_page' => 1 );
			}

			$args = array( 'limit' => $per_page + 1, 'offset' => ( $page - 1 ) * $per_page );
			if ( ! empty( $input['status'] ) ) {
				$args['status'] = sanitize_text_field( $input['status'] );
			}

			$webhooks = wc_get_webhooks( 'ASC', $args );
			$has_more = count( $webhooks ) > $per_page;
			$webhooks = array_slice( $webhooks, 0, $per_page );

			$items = array();
			foreach ( $webhooks as $webhook ) {
				$items[] = mcp_wc_format_webhook( $webhook );
			}

			return array(
				'items'       => $items,
				'has_more'    => $has_more,
				'page'        => $page,
				'per_page'    => $per_page,
			);
		},
		'permission_callback' => 'mcp_wc_settings_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

function mcp_wc_format_webhook( \WC_Webhook $webhook ): array {
	return array(
		'id'           => $webhook->get_id(),
		'name'         => $webhook->get_name(),
		'topic'        => $webhook->get_topic(),
		'delivery_url' => $webhook->get_delivery_url(),
		'status'       => $webhook->get_status(),
		'date_created' => mcp_wc_date_to_iso( $webhook->get_date_created() ),
	);
}

// ─── Webhook Mutations ──────────────────────────────────────────────────────

function mcp_wc_register_webhook_create(): void {
	mcp_wc_register_ability( 'woocommerce/webhook-create', array(
		'label'               => 'Create webhook',
		'description'         => 'Create a new WooCommerce webhook.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'name'         => array( 'type' => 'string' ),
				'topic'        => array( 'type' => 'string', 'description' => 'Webhook topic, e.g. order.created, product.updated, coupon.created.' ),
				'delivery_url' => array( 'type' => 'string', 'format' => 'uri' ),
				'secret'       => array( 'type' => 'string', 'description' => 'Webhook secret for HMAC verification.' ),
				'status'       => array( 'type' => 'string', 'enum' => array( 'active', 'paused', 'disabled' ), 'default' => 'paused' ),
				'confirm_dangerous_action' => MCP_WC_Ability_Execution_Module::confirmation_schema( 'woocommerce-mcp/webhook-create' ),
			),
			'required'             => array( 'name', 'topic', 'delivery_url', 'confirm_dangerous_action' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'webhook' => array( 'type' => 'object' ) ),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ) {
			if ( ! mcp_wc_settings_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			$confirmation = MCP_WC_Ability_Execution_Module::require_confirmation( $input, 'woocommerce-mcp/webhook-create' );
			if ( $confirmation ) { return $confirmation; }
			$delivery_url = MCP_WC_Ability_Execution_Module::validate_outbound_https_url( (string) $input['delivery_url'] );
			if ( is_wp_error( $delivery_url ) ) { return $delivery_url; }

			$webhook = new \WC_Webhook();
			$webhook->set_name( sanitize_text_field( $input['name'] ) );
			$webhook->set_topic( sanitize_text_field( $input['topic'] ) );
			$webhook->set_delivery_url( $delivery_url );
			if ( isset( $input['secret'] ) ) {
				$webhook->set_secret( sanitize_text_field( $input['secret'] ) );
			}
			$webhook->set_status( $input['status'] ?? 'paused' );

			$webhook_id = $webhook->save();
			if ( ! $webhook_id ) {
				return array( 'error' => 'Failed to create webhook.' );
			}

			return array( 'webhook' => mcp_wc_format_webhook( wc_get_webhook( $webhook_id ) ) );
		},
		'permission_callback' => 'mcp_wc_settings_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false, 'externalAction' => true ),
		),
	) );
}

function mcp_wc_register_webhook_update(): void {
	mcp_wc_register_ability( 'woocommerce/webhook-update', array(
		'label'               => 'Update webhook',
		'description'         => 'Update an existing WooCommerce webhook.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'           => array( 'type' => 'integer', 'minimum' => 1 ),
				'name'         => array( 'type' => 'string' ),
				'topic'        => array( 'type' => 'string' ),
				'delivery_url' => array( 'type' => 'string', 'format' => 'uri' ),
				'secret'       => array( 'type' => 'string' ),
				'status'       => array( 'type' => 'string', 'enum' => array( 'active', 'paused', 'disabled' ) ),
				'confirm_dangerous_action' => MCP_WC_Ability_Execution_Module::confirmation_schema( 'woocommerce-mcp/webhook-update' ),
			),
			'required'             => array( 'id', 'confirm_dangerous_action' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'webhook' => array( 'type' => 'object' ) ),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ) {
			if ( ! mcp_wc_settings_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			$confirmation = MCP_WC_Ability_Execution_Module::require_confirmation( $input, 'woocommerce-mcp/webhook-update' );
			if ( $confirmation ) { return $confirmation; }
			$webhook = wc_get_webhook( (int) $input['id'] );
			if ( ! $webhook ) {
				return array( 'error' => 'Webhook not found.' );
			}

			if ( isset( $input['name'] ) ) { $webhook->set_name( sanitize_text_field( $input['name'] ) ); }
			if ( isset( $input['topic'] ) ) { $webhook->set_topic( sanitize_text_field( $input['topic'] ) ); }
			if ( isset( $input['delivery_url'] ) ) {
				$delivery_url = MCP_WC_Ability_Execution_Module::validate_outbound_https_url( (string) $input['delivery_url'] );
				if ( is_wp_error( $delivery_url ) ) { return $delivery_url; }
				$webhook->set_delivery_url( $delivery_url );
			}
			if ( isset( $input['secret'] ) ) { $webhook->set_secret( sanitize_text_field( $input['secret'] ) ); }
			if ( isset( $input['status'] ) ) { $webhook->set_status( sanitize_text_field( $input['status'] ) ); }

			$webhook->save();

			return array( 'webhook' => mcp_wc_format_webhook( wc_get_webhook( $webhook->get_id() ) ) );
		},
		'permission_callback' => 'mcp_wc_settings_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false, 'externalAction' => true ),
		),
	) );
}

function mcp_wc_register_webhook_delete(): void {
	mcp_wc_register_ability( 'woocommerce/webhook-delete', array(
		'label'               => 'Delete webhook',
		'description'         => 'Delete a WooCommerce webhook.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array( 'type' => 'integer', 'minimum' => 1 ),
				'force' => array( 'type' => 'boolean', 'default' => true ),
				'confirm_dangerous_action' => MCP_WC_Ability_Execution_Module::confirmation_schema( 'woocommerce-mcp/webhook-delete' ),
			),
			'required'             => array( 'id', 'confirm_dangerous_action' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array( 'deleted' => array( 'type' => 'boolean' ), 'id' => array( 'type' => 'integer' ) ),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ) {
			if ( ! mcp_wc_settings_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			$confirmation = MCP_WC_Ability_Execution_Module::require_confirmation( $input, 'woocommerce-mcp/webhook-delete' );
			if ( $confirmation ) { return $confirmation; }
			$webhook = wc_get_webhook( (int) $input['id'] );
			if ( ! $webhook ) {
				return array( 'error' => 'Webhook not found.' );
			}

			$success = $webhook->delete( (bool) ( $input['force'] ?? true ) );
			return array( 'deleted' => (bool) $success, 'id' => (int) $input['id'] );
		},
		'permission_callback' => 'mcp_wc_settings_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		),
	) );
}

// ─── Shipping Classes ────────────────────────────────────────────────────────

function mcp_wc_register_shipping_classes_query(): void {
	mcp_wc_register_ability( 'woocommerce/shipping-classes-query', array(
		'label'               => 'Query shipping classes',
		'description'         => 'List WooCommerce shipping classes.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'       => array( 'type' => 'integer', 'minimum' => 1 ),
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
			),
			'additionalProperties' => false,
		) ),
		'execute_callback'    => function ( array $input ) {
			if ( ! mcp_wc_settings_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			if ( isset( $input['id'] ) ) {
				$term = get_term( (int) $input['id'], 'product_shipping_class' );
				if ( ! $term || is_wp_error( $term ) ) {
					return array( 'items' => array(), 'total_pages' => 0, 'page' => 1, 'per_page' => (int) ( $input['per_page'] ?? 25 ) );
				}
				return array( 'items' => array( array( 'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'count' => (int) $term->count ) ), 'total_pages' => 1, 'page' => 1, 'per_page' => 1 );
			}

			$page     = (int) ( $input['page'] ?? 1 );
			$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 25 ) ) );
			$terms    = get_terms( array(
				'taxonomy'   => 'product_shipping_class',
				'hide_empty' => false,
				'number'     => $per_page,
				'offset'     => ( $page - 1 ) * $per_page,
			) );
			if ( is_wp_error( $terms ) ) { return array( 'error' => $terms->get_error_message() ); }

			$items = array();
			foreach ( $terms as $term ) {
				$items[] = array( 'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'count' => (int) $term->count );
			}

			$total = wp_count_terms( array( 'taxonomy' => 'product_shipping_class', 'hide_empty' => false ) );
			if ( is_wp_error( $total ) ) { $total = 0; }

			return array(
				'items'       => $items,
				'total_pages' => max( 1, (int) ceil( (int) $total / $per_page ) ),
				'page'        => $page,
				'per_page'    => $per_page,
			);
		},
		'permission_callback' => 'mcp_wc_settings_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

// ─── Tax Classes ──────────────────────────────────────────────────────────────

function mcp_wc_register_tax_classes_query(): void {
	mcp_wc_register_ability( 'woocommerce/tax-classes-query', array(
		'label'               => 'Query tax classes',
		'description'         => 'List WooCommerce tax classes.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'tax_classes' => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'slug' => array( 'type' => 'string' ),
						'name' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				) ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ) {
			if ( ! mcp_wc_settings_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			$classes   = \WC_Tax::get_tax_classes();
			$items     = array();
			$items[]   = array( 'slug' => 'standard', 'name' => 'Standard' );
			foreach ( $classes as $class ) {
				$items[] = array( 'slug' => sanitize_title( $class ), 'name' => $class );
			}

			return array( 'tax_classes' => $items );
		},
		'permission_callback' => 'mcp_wc_settings_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

// ─── System Status ───────────────────────────────────────────────────────────

function mcp_wc_register_system_status(): void {
	mcp_wc_register_ability( 'woocommerce/system-status', array(
		'label'               => 'System status',
		'description'         => 'Get the WooCommerce system status report with environment, database, and plugin information.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'section' => array( 'type' => 'string', 'description' => 'Specific section: environment, database, active_plugins, theme, settings, security, pages. Leave empty for all.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'environment'      => array( 'type' => 'object', 'additionalProperties' => true ),
				'database'         => array( 'type' => 'object', 'additionalProperties' => true ),
				'active_plugins'   => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'theme'            => array( 'type' => 'object', 'additionalProperties' => true ),
				'settings'         => array( 'type' => 'object', 'additionalProperties' => true ),
				'security'         => array( 'type' => 'object', 'additionalProperties' => true ),
				'pages'            => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ) {
			if ( ! mcp_wc_settings_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			if ( ! class_exists( 'WC_REST_System_Status_Controller' ) ) {
				require_once WC_ABSPATH . 'includes/rest-api/Controllers/Version3/class-wc-rest-system-status-controller.php';
			}

			$controller = new \WC_REST_System_Status_Controller();
			$response   = array();

			if ( empty( $input['section'] ) || 'environment' === $input['section'] ) {
				$env = $controller->get_environment_info();
				$response['environment'] = $env->get_data();
			}
			if ( empty( $input['section'] ) || 'database' === $input['section'] ) {
				$db = $controller->get_database_info();
				$response['database'] = $db->get_data();
			}
			if ( empty( $input['section'] ) || 'active_plugins' === $input['section'] ) {
				$ap = $controller->get_active_plugins();
				$response['active_plugins'] = $ap->get_data();
			}
			if ( empty( $input['section'] ) || 'theme' === $input['section'] ) {
				$th = $controller->get_theme_info();
				$response['theme'] = $th->get_data();
			}
			if ( empty( $input['section'] ) || 'settings' === $input['section'] ) {
				$st = $controller->get_settings();
				$response['settings'] = $st->get_data();
			}
			if ( empty( $input['section'] ) || 'security' === $input['section'] ) {
				$se = $controller->get_security_info();
				$response['security'] = $se->get_data();
			}
			if ( empty( $input['section'] ) || 'pages' === $input['section'] ) {
				$pg = $controller->get_pages();
				$response['pages'] = $pg->get_data();
			}

			return mcp_wc_redact_system_paths( $response );
		},
		'permission_callback' => 'mcp_wc_system_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

// ─── System Tools ────────────────────────────────────────────────────────────

function mcp_wc_register_system_tools_query(): void {
	mcp_wc_register_ability( 'woocommerce/system-tools-query', array(
		'label'               => 'Query system tools',
		'description'         => 'List available WooCommerce system tools.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'tools' => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'string' ),
						'name'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'action'      => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				) ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ) {
			if ( ! mcp_wc_settings_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			if ( ! class_exists( 'WC_REST_System_Status_Tools_Controller' ) ) {
				require_once WC_ABSPATH . 'includes/rest-api/Controllers/Version3/class-wc-rest-system-status-tools-controller.php';
			}

			$controller = new \WC_REST_System_Status_Tools_Controller();
			$tools_data = $controller->get_items( new \WP_REST_Request() );
			$tools      = array();

			$allowed = mcp_wc_allowed_system_tools();
			foreach ( $tools_data->get_data() as $tool ) {
				if ( ! in_array( sanitize_key( $tool['id'] ), $allowed, true ) ) { continue; }
				$tools[] = array(
					'id'          => $tool['id'],
					'name'        => $tool['name'],
					'description' => $tool['description'],
					'action'      => $tool['action'],
				);
			}

			return array( 'tools' => $tools );
		},
		'permission_callback' => 'mcp_wc_system_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

function mcp_wc_register_system_tool_run(): void {
	mcp_wc_register_ability( 'woocommerce/system-tool-run', array(
		'label'               => 'Run system tool',
		'description'         => 'Execute a WooCommerce system tool by ID.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'string', 'description' => 'Server-allowlisted tool ID from system-tools-query.' ),
				'confirm_dangerous_action' => MCP_WC_Ability_Execution_Module::confirmation_schema( 'woocommerce-mcp/system-tool-run' ),
			),
			'required'             => array( 'id', 'confirm_dangerous_action' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'success'  => array( 'type' => 'boolean' ),
				'message'  => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ) {
			if ( ! mcp_wc_settings_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			if ( ! class_exists( 'WC_REST_System_Status_Tools_Controller' ) ) {
				require_once WC_ABSPATH . 'includes/rest-api/Controllers/Version3/class-wc-rest-system-status-tools-controller.php';
			}

			$confirmation = MCP_WC_Ability_Execution_Module::require_confirmation( $input, 'woocommerce-mcp/system-tool-run' );
			if ( $confirmation ) { return $confirmation; }
			$tool_id = sanitize_key( $input['id'] );
			if ( ! in_array( $tool_id, mcp_wc_allowed_system_tools(), true ) ) { return mcp_wc_error( 'mcp_wc_system_tool_not_allowed', 'This system tool is not enabled for MCP execution.' ); }

			if ( ! class_exists( 'WC_REST_System_Status_Tools_Controller' ) ) {
				require_once WC_ABSPATH . 'includes/rest-api/Controllers/Version3/class-wc-rest-system-status-tools-controller.php';
			}

			$request = new \WP_REST_Request( 'POST', '/wc/v3/system_status/tools/' . $tool_id . '/execute' );
			$request->set_url_params( array( 'id' => $tool_id ) );

			$controller = new \WC_REST_System_Status_Tools_Controller();
			$response   = $controller->execute_tool( $request );

			if ( is_wp_error( $response ) ) {
				return array( 'success' => false, 'message' => $response->get_error_message() );
			}

			$data = $response->get_data();
			return array(
				'success' => ! empty( $data['success'] ),
				'message' => $data['message'] ?? '',
			);
		},
		'permission_callback' => 'mcp_wc_system_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		),
	) );
}

// ─── Email Settings ──────────────────────────────────────────────────────────

function mcp_wc_register_email_settings(): void {
	mcp_wc_register_ability( 'woocommerce/email-settings', array(
		'label'               => 'Get email settings',
		'description'         => 'Get WooCommerce email configuration and notification settings.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'mailer'                => array( 'type' => 'object', 'additionalProperties' => true ),
				'from_name'             => array( 'type' => 'string' ),
				'from_address'          => array( 'type' => 'string' ),
				'header_image'          => array( 'type' => 'string' ),
				'footer_text'           => array( 'type' => 'string' ),
				'base_color'            => array( 'type' => 'string' ),
				'background_color'      => array( 'type' => 'string' ),
				'body_background_color' => array( 'type' => 'string' ),
				'body_text_color'       => array( 'type' => 'string' ),
				'emails'                => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'string' ),
						'title'       => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'enabled'     => array( 'type' => 'string' ),
						'recipient'   => array( 'type' => 'string' ),
						'subject'     => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				) ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ) {
			if ( ! mcp_wc_settings_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			$mailer          = WC()->mailer();
			$email_templates = $mailer->get_emails();

			$emails = array();
			foreach ( $email_templates as $email ) {
				$emails[] = array(
					'id'          => $email->id,
					'title'       => $email->get_title(),
					'description' => $email->get_description(),
					'enabled'     => $email->is_enabled() ? 'yes' : 'no',
					'recipient'   => $email->get_recipient(),
					'subject'     => $email->get_subject(),
				);
			}

			return array(
				'mailer'                => array( 'enabled' => true ),
				'from_name'             => get_option( 'woocommerce_email_from_name', '' ),
				'from_address'          => get_option( 'woocommerce_email_from_address', '' ),
				'header_image'          => get_option( 'woocommerce_email_header_image', '' ),
				'footer_text'           => get_option( 'woocommerce_email_footer_text', '' ),
				'base_color'            => get_option( 'woocommerce_email_base_color', '' ),
				'background_color'      => get_option( 'woocommerce_email_background_color', '' ),
				'body_background_color' => get_option( 'woocommerce_email_body_background_color', '' ),
				'body_text_color'       => get_option( 'woocommerce_email_body_text_color', '' ),
				'emails'                => $emails,
			);
		},
		'permission_callback' => 'mcp_wc_settings_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}
