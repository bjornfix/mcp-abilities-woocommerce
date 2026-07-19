<?php
/** Bounded WooCommerce report abilities. */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) { exit; }

function mcp_wc_register_report_abilities(): void {
	if ( ! mcp_wc_is_active() ) { return; }
	mcp_wc_register_sales_overview(); mcp_wc_register_product_report(); mcp_wc_register_customer_report(); mcp_wc_register_stock_report();
}

function mcp_wc_report_permission(): bool { return current_user_can( 'view_woocommerce_reports' ); }

/**
 * Iterate a hard-bounded order window using WooCommerce's HPOS-compatible query API.
 *
 * @return array{orders:array<int,WC_Order>,has_more:bool,scanned:int,next_cursor_page:int|null}
 */
function mcp_wc_report_orders( array $input, bool $with_dates = true ): array {
	$max = min( 5000, max( 1, (int) ( $input['max_orders'] ?? 1000 ) ) );
	$page = max( 1, (int) ( $input['cursor_page'] ?? 1 ) );
	$batch_size = min( 100, $max );
	$args = array( 'status' => array( 'completed', 'processing', 'on-hold', 'refunded' ), 'type' => 'shop_order', 'limit' => $batch_size, 'page' => $page, 'paginate' => true, 'orderby' => 'date', 'order' => 'ASC' );
	if ( $with_dates ) {
		$after  = ! empty( $input['date_after'] ) ? strtotime( sanitize_text_field( $input['date_after'] ) ) : strtotime( gmdate( 'Y-m-01\T00:00:00\Z' ) );
		$before = ! empty( $input['date_before'] ) ? strtotime( sanitize_text_field( $input['date_before'] ) ) : strtotime( gmdate( 'Y-m-d\T23:59:59\Z' ) );
		$args['date_created'] = max( 0, (int) $after ) . '...' . max( 0, (int) $before );
	}
	$currency = strtoupper( sanitize_text_field( (string) ( $input['currency'] ?? mcp_wc_get_currency() ) ) );
	$orders = array(); $has_more = false; $scanned = 0;
	while ( count( $orders ) < $max ) {
		$args['page'] = $page;
		$result = wc_get_orders( $args );
		$scanned += count( $result->orders );
		foreach ( $result->orders as $order ) {
			if ( $order instanceof WC_Order && $order->get_currency() === $currency ) {
				$orders[] = $order;
				if ( count( $orders ) >= $max ) { break; }
			}
		}
		$has_more = $page < (int) $result->max_num_pages;
		if ( ! $has_more || count( $orders ) >= $max ) { break; }
		++$page;
	}
	return array( 'orders' => $orders, 'has_more' => $has_more, 'scanned' => $scanned, 'next_cursor_page' => $has_more ? $page + 1 : null );
}

function mcp_wc_report_input_schema( bool $dates = true ): array {
	$properties = array( 'currency' => array( 'type' => 'string', 'enum' => mcp_wc_currency_codes(), 'default' => mcp_wc_get_currency() ), 'max_orders' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000 ), 'cursor_page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ) );
	if ( $dates ) { $properties['date_after'] = array( 'type' => 'string', 'format' => 'date-time' ); $properties['date_before'] = array( 'type' => 'string', 'format' => 'date-time' ); }
	return array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false, 'default' => array() );
}

function mcp_wc_report_window_schema(): array { return array( 'has_more' => array( 'type' => 'boolean' ), 'next_cursor_page' => array( 'type' => array( 'integer', 'null' ) ), 'scanned_orders' => array( 'type' => 'integer' ), 'currency' => array( 'type' => 'string' ) ); }

function mcp_wc_register_sales_overview(): void {
	mcp_wc_register_ability( 'woocommerce-mcp/sales-overview', array(
		'label' => 'Sales overview', 'description' => 'Calculate currency-specific sales totals over a bounded HPOS-compatible Order window.', 'category' => 'site',
		'input_schema' => mcp_wc_report_input_schema(),
		'output_schema' => array( 'type' => 'object', 'properties' => array_merge( array(
			'gross_sales' => array( 'type' => 'string' ), 'net_sales' => array( 'type' => 'string' ), 'average_order_value' => array( 'type' => 'string' ),
			'total_orders' => array( 'type' => 'integer' ), 'total_items' => array( 'type' => 'integer' ), 'total_tax' => array( 'type' => 'string' ),
			'total_shipping' => array( 'type' => 'string' ), 'refund_amount' => array( 'type' => 'string' ), 'refund_count' => array( 'type' => 'integer' ),
			'total_customers' => array( 'type' => 'integer' ),
		), mcp_wc_report_window_schema() ), 'additionalProperties' => false ),
		'execute_callback' => function( array $input ) {
			$window = mcp_wc_report_orders( $input ); $gross = 0.0; $tax = 0.0; $shipping = 0.0; $refunds = 0.0; $refund_count = 0; $items = 0; $customers = array();
			foreach ( $window['orders'] as $order ) { $gross += (float) $order->get_total(); $tax += (float) $order->get_total_tax(); $shipping += (float) $order->get_shipping_total(); $items += (int) $order->get_item_count(); $refunds += (float) $order->get_total_refunded(); $refund_count += count( $order->get_refunds() ); if ( $order->get_customer_id() ) { $customers[] = (int) $order->get_customer_id(); } }
			$count = count( $window['orders'] ); $currency = strtoupper( (string) ( $input['currency'] ?? mcp_wc_get_currency() ) );
			return array( 'gross_sales' => wc_format_decimal( $gross, 2 ), 'net_sales' => wc_format_decimal( $gross - $refunds, 2 ), 'average_order_value' => wc_format_decimal( $count ? ( $gross - $refunds ) / $count : 0, 2 ), 'total_orders' => $count, 'total_items' => $items, 'total_tax' => wc_format_decimal( $tax, 2 ), 'total_shipping' => wc_format_decimal( $shipping, 2 ), 'refund_amount' => wc_format_decimal( $refunds, 2 ), 'refund_count' => $refund_count, 'total_customers' => count( array_unique( $customers ) ), 'currency' => $currency, 'has_more' => $window['has_more'], 'next_cursor_page' => $window['next_cursor_page'], 'scanned_orders' => $window['scanned'] );
		},
		'permission_callback' => 'mcp_wc_report_permission', 'meta' => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
	) );
}

function mcp_wc_register_product_report(): void {
	$input_schema = mcp_wc_report_input_schema(); $input_schema['properties']['order_by'] = array( 'type' => 'string', 'enum' => array( 'gross_revenue', 'net_revenue', 'items_sold' ), 'default' => 'items_sold' ); $input_schema['properties']['order'] = array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ); $input_schema['properties']['limit'] = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25 );
	mcp_wc_register_ability( 'woocommerce-mcp/product-report', array(
		'label' => 'Product sales report', 'description' => 'Aggregate currency-specific product sales over a bounded HPOS-compatible Order window.', 'category' => 'site', 'input_schema' => $input_schema,
		'output_schema' => array( 'type' => 'object', 'properties' => array_merge( array( 'products' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array(
			'product_id' => array( 'type' => 'integer' ), 'name' => array( 'type' => 'string' ), 'sku' => array( 'type' => 'string' ), 'items_sold' => array( 'type' => 'integer' ), 'gross_revenue' => array( 'type' => 'string' ), 'net_revenue' => array( 'type' => 'string' ), 'orders_count' => array( 'type' => 'integer' ),
		), 'additionalProperties' => false ) ) ), mcp_wc_report_window_schema() ), 'additionalProperties' => false ),
		'execute_callback' => function( array $input ) {
			$window = mcp_wc_report_orders( $input ); $rows = array();
			foreach ( $window['orders'] as $order ) { foreach ( $order->get_items( 'line_item' ) as $item ) { $id = (int) $item->get_product_id(); if ( $id < 1 ) { continue; } if ( ! isset( $rows[ $id ] ) ) { $product = wc_get_product( $id ); $rows[ $id ] = array( 'product_id' => $id, 'name' => $product ? $product->get_name() : $item->get_name(), 'sku' => $product ? $product->get_sku() : '', 'items_sold' => 0, 'gross_revenue' => 0.0, 'net_revenue' => 0.0, 'orders' => array() ); } $rows[ $id ]['items_sold'] += max( 0, (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item->get_id() ) ); $rows[ $id ]['gross_revenue'] += (float) $item->get_subtotal(); $rows[ $id ]['net_revenue'] += (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $item->get_id() ); $rows[ $id ]['orders'][] = $order->get_id(); } }
			foreach ( $rows as &$row ) { $row['orders_count'] = count( array_unique( $row['orders'] ) ); unset( $row['orders'] ); $row['gross_revenue'] = wc_format_decimal( $row['gross_revenue'], 2 ); $row['net_revenue'] = wc_format_decimal( $row['net_revenue'], 2 ); } unset( $row );
			$key = $input['order_by'] ?? 'items_sold'; $desc = 'asc' !== ( $input['order'] ?? 'desc' ); usort( $rows, static function( $a, $b ) use ( $key, $desc ) { $cmp = (float) $a[ $key ] <=> (float) $b[ $key ]; return $desc ? -$cmp : $cmp; } );
			return array( 'products' => array_slice( $rows, 0, min( 100, max( 1, (int) ( $input['limit'] ?? 25 ) ) ) ), 'currency' => strtoupper( (string) ( $input['currency'] ?? mcp_wc_get_currency() ) ), 'has_more' => $window['has_more'], 'next_cursor_page' => $window['next_cursor_page'], 'scanned_orders' => $window['scanned'] );
		},
		'permission_callback' => 'mcp_wc_report_permission', 'meta' => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
	) );
}

function mcp_wc_register_customer_report(): void {
	$input_schema = mcp_wc_report_input_schema( false ); $input_schema['properties']['order_by'] = array( 'type' => 'string', 'enum' => array( 'total_spent', 'order_count' ), 'default' => 'total_spent' ); $input_schema['properties']['order'] = array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ); $input_schema['properties']['limit'] = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25 );
	mcp_wc_register_ability( 'woocommerce-mcp/customer-report', array(
		'label' => 'Customer report', 'description' => 'Aggregate registered customers over a bounded currency-specific Order window.', 'category' => 'site', 'input_schema' => $input_schema,
		'output_schema' => array( 'type' => 'object', 'properties' => array_merge( array( 'customers' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'customer_id' => array( 'type' => 'integer' ), 'name' => array( 'type' => 'string' ), 'email' => array( 'type' => 'string', 'format' => 'email' ), 'total_spent' => array( 'type' => 'string' ), 'order_count' => array( 'type' => 'integer' ), 'average_order' => array( 'type' => 'string' ), 'last_order_date' => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ) ), 'additionalProperties' => false ) ) ), mcp_wc_report_window_schema() ), 'additionalProperties' => false ),
		'execute_callback' => function( array $input ) {
			$window = mcp_wc_report_orders( $input, false ); $rows = array();
			foreach ( $window['orders'] as $order ) { $id = (int) $order->get_customer_id(); if ( $id < 1 ) { continue; } if ( ! isset( $rows[ $id ] ) ) { $user = get_userdata( $id ); if ( ! $user || ! MCP_WC_Ability_Execution_Module::is_customer_user( $user ) ) { continue; } $rows[ $id ] = array( 'customer_id' => $id, 'name' => $user->display_name, 'email' => $user->user_email, 'total_spent' => 0.0, 'order_count' => 0, 'last_order_date' => null ); } $rows[ $id ]['total_spent'] += (float) $order->get_total() - (float) $order->get_total_refunded(); ++$rows[ $id ]['order_count']; $date = $order->get_date_created(); if ( $date && ( null === $rows[ $id ]['last_order_date'] || $date->getTimestamp() > strtotime( $rows[ $id ]['last_order_date'] ) ) ) { $rows[ $id ]['last_order_date'] = mcp_wc_date_to_iso( $date ); } }
			foreach ( $rows as &$row ) { $row['average_order'] = wc_format_decimal( $row['order_count'] ? $row['total_spent'] / $row['order_count'] : 0, 2 ); $row['total_spent'] = wc_format_decimal( $row['total_spent'], 2 ); } unset( $row ); $key = $input['order_by'] ?? 'total_spent'; $desc = 'asc' !== ( $input['order'] ?? 'desc' ); usort( $rows, static function( $a, $b ) use ( $key, $desc ) { $cmp = (float) $a[ $key ] <=> (float) $b[ $key ]; return $desc ? -$cmp : $cmp; } );
			return array( 'customers' => array_slice( $rows, 0, min( 100, max( 1, (int) ( $input['limit'] ?? 25 ) ) ) ), 'currency' => strtoupper( (string) ( $input['currency'] ?? mcp_wc_get_currency() ) ), 'has_more' => $window['has_more'], 'next_cursor_page' => $window['next_cursor_page'], 'scanned_orders' => $window['scanned'] );
		},
		'permission_callback' => 'mcp_wc_report_permission', 'meta' => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
	) );
}

function mcp_wc_register_stock_report(): void {
	mcp_wc_register_ability( 'woocommerce-mcp/stock-report', array(
		'label' => 'Stock report', 'description' => 'Query stock state through the WooCommerce product data store.', 'category' => 'site',
		'input_schema' => array( 'type' => 'object', 'properties' => array( 'status' => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ), 'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ), 'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25 ) ), 'additionalProperties' => false, 'default' => array() ),
		'output_schema' => array( 'type' => 'object', 'properties' => array( 'products' => array( 'type' => 'array', 'items' => mcp_wc_product_output_schema() ), 'total_pages' => array( 'type' => 'integer' ), 'page' => array( 'type' => 'integer' ), 'per_page' => array( 'type' => 'integer' ) ), 'additionalProperties' => false ),
		'execute_callback' => function( array $input ) { $page = max( 1, (int) ( $input['page'] ?? 1 ) ); $per = min( 100, max( 1, (int) ( $input['per_page'] ?? 25 ) ) ); $result = wc_get_products( array( 'stock_status' => $input['status'] ?? array( 'instock', 'outofstock', 'onbackorder' ), 'page' => $page, 'limit' => $per, 'paginate' => true ) ); return array( 'products' => array_map( 'mcp_wc_format_product', $result->products ), 'total_pages' => (int) $result->max_num_pages, 'page' => $page, 'per_page' => $per ); },
		'permission_callback' => 'mcp_wc_report_permission', 'meta' => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
	) );
}
