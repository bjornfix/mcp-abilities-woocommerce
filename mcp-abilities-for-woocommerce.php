<?php
/**
 * Plugin Name: MCP Abilities for WooCommerce
 * Plugin URI: https://devenia.com/plugins/mcp-abilities-for-woocommerce/
 * Description: Comprehensive WooCommerce abilities for MCP. Products, orders, coupons, customers, reports, settings, reviews, shipping, tax, and webhooks.
 * Version: 1.0.3
 * Author: basicus
 * Author URI: https://profiles.wordpress.org/basicus/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Text Domain: mcp-abilities-for-woocommerce
 *
 * @package MCP_Abilities_WooCommerce
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if Abilities API is available.
 */
function mcp_wc_check_dependencies(): bool {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>MCP Abilities for WooCommerce</strong> requires the <a href="https://github.com/WordPress/abilities-api">Abilities API</a> plugin to be installed and activated.</p></div>';
		} );
		return false;
	}
	return true;
}

/**
 * Check if WooCommerce is active.
 */
function mcp_wc_is_active(): bool {
	return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
}

function mcp_wc_get_currency(): string {
	return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
}

function mcp_wc_get_currency_symbol(): string {
	return function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
}

/**
 * WC order status slugs for input (without wc- prefix), derived from WC's registered statuses.
 *
 * @return array<int,string>
 */
function mcp_wc_allowed_order_statuses(): array {
	if ( ! function_exists( 'wc_get_order_statuses' ) ) {
		return array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
	}
	$statuses = array();
	foreach ( array_keys( wc_get_order_statuses() ) as $status ) {
		$statuses[] = str_replace( 'wc-', '', $status );
	}
	return $statuses;
}

/**
 * WC product statuses — always the standard WordPress post statuses available for products.
 *
 * @return array<int,string>
 */
function mcp_wc_allowed_product_statuses(): array {
	return array( 'draft', 'pending', 'private', 'publish', 'future' );
}

/**
 * WC order statuses for output, derived from WC's registered statuses.
 *
 * @return array<int,string>
 */
function mcp_wc_all_order_statuses(): array {
	if ( ! function_exists( 'wc_get_order_statuses' ) ) {
		return array( 'pending', 'failed', 'on-hold', 'completed', 'processing', 'refunded', 'cancelled', 'trash' );
	}
	return array_keys( wc_get_order_statuses() );
}

/**
 * WC product types — derived from WC's registered product types.
 *
 * @return array<int,string>
 */
function mcp_wc_product_types(): array {
	if ( ! function_exists( 'wc_get_product_types' ) ) {
		return array( 'simple', 'grouped', 'external', 'variable' );
	}
	return array_keys( wc_get_product_types() );
}

/**
 * WC currency codes, derived from WC's registered currencies.
 *
 * @return array<int,string>
 */
function mcp_wc_currency_codes(): array {
	if ( ! function_exists( 'get_woocommerce_currencies' ) ) {
		return array( 'USD', 'EUR', 'GBP', 'NOK', 'SEK', 'DKK' );
	}
	return array_keys( get_woocommerce_currencies() );
}

/**
 * Map agent-facing product type alias to WC product type.
 */
function mcp_wc_map_product_type_alias( string $alias ): string {
	$aliases = mcp_wc_product_type_aliases();
	return $aliases[ $alias ] ?? $alias;
}

/**
 * Format a WC_Product for output.
 *
 * @param \WC_Product $product
 * @return array<string,mixed>
 */
function mcp_wc_format_product( \WC_Product $product ): array {
	$id = $product->get_id();

	return array(
		'id'                 => $id,
		'name'               => $product->get_name(),
		'slug'               => $product->get_slug(),
		'permalink'          => get_permalink( $id ) ?: null,
		'type'               => $product->get_type(),
		'status'             => $product->get_status(),
		'sku'                => $product->get_sku(),
		'currency'           => mcp_wc_get_currency(),
		'currency_symbol'    => mcp_wc_get_currency_symbol(),
		'price'              => $product->get_price(),
		'regular_price'      => $product->get_regular_price(),
		'sale_price'         => $product->get_sale_price(),
		'stock_status'       => $product->get_stock_status(),
		'stock_quantity'     => $product->get_manage_stock() ? $product->get_stock_quantity() : null,
		'manage_stock'       => $product->get_manage_stock(),
		'virtual'            => $product->is_virtual(),
		'downloadable'       => $product->is_downloadable(),
		'external_url'       => $product->get_type() === 'external' ? $product->get_product_url() : null,
		'button_text'        => $product->get_type() === 'external' ? $product->get_button_text() : null,
		'grouped_products'   => $product->get_type() === 'grouped' ? $product->get_children() : array(),
		'date_created'       => mcp_wc_date_to_iso( $product->get_date_created() ),
		'date_created_gmt'   => mcp_wc_date_to_iso( $product->get_date_created(), true ),
		'date_modified'      => mcp_wc_date_to_iso( $product->get_date_modified() ),
		'date_modified_gmt'  => mcp_wc_date_to_iso( $product->get_date_modified(), true ),
	);
}

/**
 * Format a WC_Order for output.
 */
function mcp_wc_format_order( \WC_Order $order, bool $include_line_items = false ): array {
	$data = array(
		'id'                    => $order->get_id(),
		'status'                => $order->get_status(),
		'currency'              => $order->get_currency(),
		'currency_symbol'       => html_entity_decode( get_woocommerce_currency_symbol( $order->get_currency() ) ),
		'total'                 => $order->get_total(),
		'customer_id'           => $order->get_customer_id(),
		'billing_email'         => $order->get_billing_email() ?: null,
		'payment_method'        => $order->get_payment_method(),
		'payment_method_title'  => $order->get_payment_method_title(),
		'date_created'          => mcp_wc_date_to_iso( $order->get_date_created() ),
		'date_created_gmt'      => mcp_wc_date_to_iso( $order->get_date_created(), true ),
		'date_modified'         => mcp_wc_date_to_iso( $order->get_date_modified() ),
		'date_modified_gmt'     => mcp_wc_date_to_iso( $order->get_date_modified(), true ),
	);

	if ( $include_line_items ) {
		$data['line_items'] = mcp_wc_format_order_line_items( $order );
	}

	return $data;
}

/**
 * Format line items for an order.
 */
function mcp_wc_format_order_line_items( \WC_Order $order ): array {
	$items = array();
	foreach ( $order->get_items() as $item ) {
		$items[] = array(
			'id'           => $item->get_id(),
			'name'         => $item->get_name(),
			'product_id'   => $item->get_product_id(),
			'variation_id' => $item->get_variation_id(),
			'quantity'     => $item->get_quantity(),
			'subtotal'     => $order->get_item_subtotal( $item ),
			'total'        => $order->get_item_total( $item ),
		);
	}
	return $items;
}

/**
 * Format a WC_Coupon for output.
 */
function mcp_wc_format_coupon( \WC_Coupon $coupon ): array {
	$expiry = $coupon->get_date_expires();

	return array(
		'id'                       => $coupon->get_id(),
		'code'                     => $coupon->get_code(),
		'description'              => $coupon->get_description(),
		'discount_type'            => $coupon->get_discount_type(),
		'amount'                   => $coupon->get_amount(),
		'individual_use'           => $coupon->get_individual_use(),
		'product_ids'              => $coupon->get_product_ids(),
		'excluded_product_ids'     => $coupon->get_excluded_product_ids(),
		'product_categories'       => $coupon->get_product_categories(),
		'excluded_product_categories' => $coupon->get_excluded_product_categories(),
		'usage_limit'              => $coupon->get_usage_limit(),
		'usage_limit_per_user'     => $coupon->get_usage_limit_per_user(),
		'usage_count'              => $coupon->get_usage_count(),
		'minimum_amount'           => $coupon->get_minimum_amount(),
		'maximum_amount'           => $coupon->get_maximum_amount(),
		'free_shipping'            => $coupon->get_free_shipping(),
		'date_expires'             => mcp_wc_date_to_iso( $expiry ),
		'date_expires_gmt'         => $expiry ? mcp_wc_date_to_iso( $expiry, true ) : null,
		'date_created'             => mcp_wc_date_to_iso( $coupon->get_date_created() ),
		'date_created_gmt'         => mcp_wc_date_to_iso( $coupon->get_date_created(), true ),
		'date_modified'            => mcp_wc_date_to_iso( $coupon->get_date_modified() ),
		'date_modified_gmt'        => mcp_wc_date_to_iso( $coupon->get_date_modified(), true ),
	);
}

/**
 * Convert WC_DateTime to ISO 8601 string.
 */
function mcp_wc_date_to_iso( $date, bool $gmt = false ): ?string {
	if ( ! $date instanceof \WC_DateTime ) {
		return null;
	}
	try {
		return $gmt
			? gmdate( 'Y-m-d\TH:i:s', $date->getOffsetTimestamp() )
			: $date->date( 'Y-m-d\TH:i:s' );
	} catch ( \Exception $e ) {
		return null;
	}
}

/**
 * Parse ISO 8601 datetime string for WC_DateTime.
 */
function mcp_wc_parse_date( string $date ): ?\WC_DateTime {
	try {
		return new \WC_DateTime( $date );
	} catch ( \Exception $e ) {
		return null;
	}
}

/**
 * Common product output schema.
 */
function mcp_wc_product_output_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'id'                 => array( 'type' => 'integer' ),
			'name'               => array( 'type' => 'string' ),
			'slug'               => array( 'type' => 'string' ),
			'permalink'          => array( 'type' => array( 'string', 'null' ), 'format' => 'uri' ),
			'type'               => array( 'type' => 'string', 'enum' => mcp_wc_product_types() ),
			'status'             => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'private', 'publish', 'future', 'auto-draft', 'trash' ) ),
			'sku'                => array( 'type' => 'string' ),
			'currency'           => array( 'type' => 'string', 'enum' => mcp_wc_currency_codes() ),
			'currency_symbol'    => array( 'type' => 'string' ),
			'price'              => array( 'type' => 'string' ),
			'regular_price'      => array( 'type' => 'string' ),
			'sale_price'         => array( 'type' => 'string' ),
			'stock_status'       => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
			'stock_quantity'     => array( 'type' => array( 'integer', 'null' ) ),
			'manage_stock'       => array( 'type' => 'boolean' ),
			'virtual'            => array( 'type' => 'boolean' ),
			'downloadable'       => array( 'type' => 'boolean' ),
			'external_url'       => array( 'type' => array( 'string', 'null' ), 'format' => 'uri' ),
			'button_text'        => array( 'type' => array( 'string', 'null' ) ),
			'grouped_products'   => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			'date_created'       => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
			'date_created_gmt'   => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
			'date_modified'      => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
			'date_modified_gmt'  => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
		),
		'additionalProperties' => false,
	);
}

/**
 * Common order output schema.
 */
function mcp_wc_order_output_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'id'                    => array( 'type' => 'integer' ),
			'status'                => array( 'type' => 'string', 'enum' => mcp_wc_all_order_statuses() ),
			'currency'              => array( 'type' => 'string', 'enum' => mcp_wc_currency_codes() ),
			'currency_symbol'       => array( 'type' => 'string' ),
			'total'                 => array( 'type' => 'string' ),
			'customer_id'           => array( 'type' => 'integer' ),
			'billing_email'         => array( 'type' => array( 'string', 'null' ), 'format' => 'email' ),
			'payment_method'        => array( 'type' => 'string' ),
			'payment_method_title'  => array( 'type' => 'string' ),
			'date_created'          => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
			'date_created_gmt'      => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
			'date_modified'         => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
			'date_modified_gmt'     => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
			'line_items'            => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array( 'type' => 'integer' ),
						'name'         => array( 'type' => 'string' ),
						'product_id'   => array( 'type' => 'integer' ),
						'variation_id' => array( 'type' => 'integer' ),
						'quantity'     => array( 'type' => 'integer' ),
						'subtotal'     => array( 'type' => 'string' ),
						'total'        => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
			),
		),
		'additionalProperties' => false,
	);
}

/**
 * Paginated response schema.
 */
function mcp_wc_paginated_schema( array $items_schema ): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'items'       => array( 'type' => 'array', 'items' => $items_schema ),
			'total_pages' => array( 'type' => 'integer' ),
			'page'        => array( 'type' => 'integer' ),
			'per_page'    => array( 'type' => 'integer' ),
		),
		'additionalProperties' => false,
	);
}

/**
 * Register an ability, skipping if the name is already registered by another plugin.
 *
 * Public plugins that register abilities under shared namespaces (such as
 * `woocommerce/`) must coexist with abilities registered by WooCommerce core,
 * other MCP add-ons, or site-specific plugins. This wrapper checks
 * wp_has_ability() before calling wp_register_ability() so the plugin never
 * breaks a site by re-registering a name that already exists.
 *
 * @param string               $name Ability name.
 * @param array<string,mixed>  $args Registration arguments.
 * @return void
 */
function mcp_wc_register_ability( string $name, array $args ): void {
	if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
		return;
	}
	wp_register_ability( $name, $args );
}

require_once __DIR__ . '/includes/abilities-products.php';
require_once __DIR__ . '/includes/abilities-orders.php';
require_once __DIR__ . '/includes/abilities-coupons.php';
require_once __DIR__ . '/includes/abilities-customers.php';
require_once __DIR__ . '/includes/abilities-reports.php';
require_once __DIR__ . '/includes/abilities-settings.php';
require_once __DIR__ . '/includes/abilities-reviews.php';

/**
 * Register WooCommerce abilities.
 */
function mcp_register_woocommerce_abilities(): void {
	if ( ! mcp_wc_check_dependencies() ) {
		return;
	}

	if ( ! mcp_wc_is_active() ) {
		return;
	}

	mcp_wc_register_product_abilities();
	mcp_wc_register_order_abilities();
	mcp_wc_register_coupon_abilities();
	mcp_wc_register_customer_abilities();
	mcp_wc_register_report_abilities();
	mcp_wc_register_setting_abilities();
	mcp_wc_register_review_abilities();
}
add_action( 'wp_abilities_api_init', 'mcp_register_woocommerce_abilities' );
