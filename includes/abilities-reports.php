<?php
/**
 * WooCommerce report and analytics abilities.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function mcp_wc_register_report_abilities(): void {
	if ( ! mcp_wc_is_active() ) {
		return;
	}

	mcp_wc_register_sales_overview();
	mcp_wc_register_product_report();
	mcp_wc_register_customer_report();
	mcp_wc_register_stock_report();
}

function mcp_wc_report_permission(): bool {
	return current_user_can( 'view_woocommerce_reports' );
}

// ─── Sales Overview ──────────────────────────────────────────────────────────

function mcp_wc_register_sales_overview(): void {
	mcp_wc_register_ability( 'woocommerce/sales-overview', array(
		'label'               => 'Sales overview',
		'description'         => 'Get sales overview statistics for a date range.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'date_after'  => array( 'type' => 'string', 'format' => 'date-time' ),
				'date_before' => array( 'type' => 'string', 'format' => 'date-time' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'total_sales'       => array( 'type' => 'string' ),
				'net_sales'         => array( 'type' => 'string' ),
				'average_sales'     => array( 'type' => 'string' ),
				'total_orders'      => array( 'type' => 'integer' ),
				'total_items'       => array( 'type' => 'integer' ),
				'total_tax'         => array( 'type' => 'string' ),
				'total_shipping'    => array( 'type' => 'string' ),
				'total_refunds'     => array( 'type' => 'integer' ),
				'total_customers'   => array( 'type' => 'integer' ),
				'currency'          => array( 'type' => 'string' ),
				'currency_symbol'   => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! mcp_wc_report_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			$date_after  = ! empty( $input['date_after'] ) ? gmdate( 'Y-m-d', strtotime( $input['date_after'] ) ) : gmdate( 'Y-m-01' );
			$date_before = ! empty( $input['date_before'] ) ? gmdate( 'Y-m-d', strtotime( $input['date_before'] ) ) : gmdate( 'Y-m-d' );

			$orders = wc_get_orders( array(
				'date_after'  => $date_after,
				'date_before' => $date_before . ' 23:59:59',
				'status'      => array( 'completed', 'processing', 'on-hold' ),
				'limit'       => -1,
			) );

			$total_sales    = 0.0;
			$total_tax      = 0.0;
			$total_shipping = 0.0;
			$total_items    = 0;
			$total_refunds  = 0;
			$customer_ids   = array();

			foreach ( $orders as $order ) {
				if ( 'refunded' === $order->get_status() ) {
					++$total_refunds;
					continue;
				}
				$total_sales    += (float) $order->get_total();
				$total_tax      += (float) $order->get_total_tax();
				$total_shipping += (float) $order->get_shipping_total();
				$total_items    += $order->get_item_count();
				if ( $order->get_customer_id() ) {
					$customer_ids[] = $order->get_customer_id();
				}
			}

			$total_orders    = count( $orders ) - $total_refunds;
			$total_customers = count( array_unique( $customer_ids ) );

			return array(
				'total_sales'     => wc_format_decimal( $total_sales, 2 ),
				'net_sales'       => wc_format_decimal( $total_sales - $total_tax - $total_shipping, 2 ),
				'average_sales'   => $total_orders > 0 ? wc_format_decimal( $total_sales / $total_orders, 2 ) : '0.00',
				'total_orders'    => $total_orders,
				'total_items'     => $total_items,
				'total_tax'       => wc_format_decimal( $total_tax, 2 ),
				'total_shipping'  => wc_format_decimal( $total_shipping, 2 ),
				'total_refunds'   => $total_refunds,
				'total_customers' => $total_customers,
				'currency'        => mcp_wc_get_currency(),
				'currency_symbol' => mcp_wc_get_currency_symbol(),
			);
		},
		'permission_callback' => 'mcp_wc_report_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

// ─── Product Report ──────────────────────────────────────────────────────────

function mcp_wc_register_product_report(): void {
	mcp_wc_register_ability( 'woocommerce/product-report', array(
		'label'               => 'Product sales report',
		'description'         => 'Get product sales statistics ranked by quantity sold or revenue.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'date_after'  => array( 'type' => 'string', 'format' => 'date-time' ),
				'date_before' => array( 'type' => 'string', 'format' => 'date-time' ),
				'order_by'    => array( 'type' => 'string', 'enum' => array( 'total_sales', 'net_revenue', 'items_sold' ), 'default' => 'items_sold' ),
				'order'       => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
				'limit'       => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'products' => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id'   => array( 'type' => 'integer' ),
						'name'         => array( 'type' => 'string' ),
						'sku'          => array( 'type' => 'string' ),
						'items_sold'   => array( 'type' => 'integer' ),
						'total_sales'  => array( 'type' => 'string' ),
						'net_revenue'  => array( 'type' => 'string' ),
						'orders_count' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				) ),
				'currency'  => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! mcp_wc_report_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			$date_after  = ! empty( $input['date_after'] ) ? gmdate( 'Y-m-d', strtotime( $input['date_after'] ) ) : gmdate( 'Y-m-01' );
			$date_before = ! empty( $input['date_before'] ) ? gmdate( 'Y-m-d', strtotime( $input['date_before'] ) ) : gmdate( 'Y-m-d' );
			$order_by    = in_array( $input['order_by'] ?? '', array( 'total_sales', 'net_revenue', 'items_sold' ), true ) ? $input['order_by'] : 'items_sold';
			$order_desc  = 'asc' !== ( $input['order'] ?? 'desc' );
			$limit       = min( 100, max( 1, (int) ( $input['limit'] ?? 25 ) ) );

			$orders = wc_get_orders( array(
				'date_after'  => $date_after,
				'date_before' => $date_before . ' 23:59:59',
				'status'      => array( 'completed', 'processing', 'on-hold' ),
				'limit'       => -1,
				'type'        => 'shop_order',
			) );

			$aggregated = array();
			foreach ( $orders as $order ) {
				foreach ( $order->get_items( 'line_item' ) as $item ) {
					$pid = $item->get_product_id();
					if ( $pid <= 0 ) {
						continue;
					}
					if ( ! isset( $aggregated[ $pid ] ) ) {
						$product = wc_get_product( $pid );
						$aggregated[ $pid ] = array(
							'product_id'   => $pid,
							'name'         => $product ? $product->get_name() : $item->get_name(),
							'sku'          => $product ? $product->get_sku() : '',
							'items_sold'   => 0,
							'total_sales'  => 0.0,
							'net_revenue'  => 0.0,
							'order_ids'    => array(),
						);
					}
					$aggregated[ $pid ]['items_sold']  += $item->get_quantity();
					$aggregated[ $pid ]['total_sales'] += (float) $item->get_total();
					$aggregated[ $pid ]['net_revenue'] += (float) $item->get_subtotal();
					$aggregated[ $pid ]['order_ids'][]  = $order->get_id();
				}
			}

			foreach ( $aggregated as &$data ) {
				$data['orders_count'] = count( array_unique( $data['order_ids'] ) );
				$data['total_sales']  = wc_format_decimal( $data['total_sales'], 2 );
				$data['net_revenue']  = wc_format_decimal( $data['net_revenue'], 2 );
				unset( $data['order_ids'] );
			}
			unset( $data );

			usort( $aggregated, function ( $a, $b ) use ( $order_by, $order_desc ) {
				if ( 'items_sold' === $order_by ) {
					$cmp = $a['items_sold'] <=> $b['items_sold'];
				} elseif ( 'net_revenue' === $order_by ) {
					$cmp = (float) $a['net_revenue'] <=> (float) $b['net_revenue'];
				} else {
					$cmp = (float) $a['total_sales'] <=> (float) $b['total_sales'];
				}
				return $order_desc ? -$cmp : $cmp;
			} );

			return array(
				'products' => array_slice( $aggregated, 0, $limit ),
				'currency' => mcp_wc_get_currency(),
			);
		},
		'permission_callback' => 'mcp_wc_report_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

// ─── Customer Report ─────────────────────────────────────────────────────────

function mcp_wc_register_customer_report(): void {
	mcp_wc_register_ability( 'woocommerce/customer-report', array(
		'label'               => 'Customer report',
		'description'         => 'Get top customers by total spent or order count.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'order_by' => array( 'type' => 'string', 'enum' => array( 'total_spent', 'order_count' ), 'default' => 'total_spent' ),
				'order'    => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
				'limit'    => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'customers' => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'customer_id'      => array( 'type' => 'integer' ),
						'name'             => array( 'type' => 'string' ),
						'email'            => array( 'type' => 'string', 'format' => 'email' ),
						'total_spent'      => array( 'type' => 'string' ),
						'order_count'      => array( 'type' => 'integer' ),
						'average_order'    => array( 'type' => 'string' ),
						'last_order_date'  => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
					),
					'additionalProperties' => false,
				) ),
				'currency' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! mcp_wc_report_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			$limit      = min( 100, max( 1, (int) ( $input['limit'] ?? 25 ) ) );
			$order_by   = in_array( $input['order_by'] ?? '', array( 'total_spent', 'order_count' ), true ) ? $input['order_by'] : 'total_spent';
			$order_desc = 'asc' !== ( $input['order'] ?? 'desc' );

			$orders = wc_get_orders( array(
				'status' => array( 'completed', 'processing', 'on-hold' ),
				'limit'  => -1,
				'type'   => 'shop_order',
			) );

			$customers = array();
			foreach ( $orders as $order ) {
				$cid = $order->get_customer_id();
				if ( $cid <= 0 ) {
					continue;
				}
				if ( ! isset( $customers[ $cid ] ) ) {
					$customers[ $cid ] = array(
						'customer_id'     => $cid,
						'total_spent'     => (float) wc_get_customer_total_spent( $cid ),
						'order_count'     => wc_get_customer_order_count( $cid ),
						'last_order_date' => null,
					);
				}
				$order_date = $order->get_date_created();
				if ( $order_date ) {
					$ts = $order_date->getTimestamp();
					$existing = $customers[ $cid ]['last_order_date'];
					if ( null === $existing || $ts > strtotime( $existing ) ) {
						$customers[ $cid ]['last_order_date'] = mcp_wc_date_to_iso( $order_date );
					}
				}
			}

			usort( $customers, function ( $a, $b ) use ( $order_by, $order_desc ) {
				if ( 'order_count' === $order_by ) {
					$cmp = $a['order_count'] <=> $b['order_count'];
				} else {
					$cmp = $a['total_spent'] <=> $b['total_spent'];
				}
				return $order_desc ? -$cmp : $cmp;
			} );

			$result = array();
			foreach ( array_slice( $customers, 0, $limit ) as $data ) {
				$user = get_userdata( $data['customer_id'] );
				if ( ! $user ) {
					continue;
				}
				$avg_order = $data['order_count'] > 0
					? wc_format_decimal( $data['total_spent'] / $data['order_count'], 2 )
					: '0.00';

				$result[] = array(
					'customer_id'     => $data['customer_id'],
					'name'            => $user->display_name,
					'email'           => $user->user_email,
					'total_spent'     => wc_format_decimal( $data['total_spent'], 2 ),
					'order_count'     => $data['order_count'],
					'average_order'   => $avg_order,
					'last_order_date' => $data['last_order_date'],
				);
			}

			return array( 'customers' => $result, 'currency' => mcp_wc_get_currency() );
		},
		'permission_callback' => 'mcp_wc_report_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

// ─── Stock Report ────────────────────────────────────────────────────────────

function mcp_wc_register_stock_report(): void {
	mcp_wc_register_ability( 'woocommerce/stock-report', array(
		'label'               => 'Stock report',
		'description'         => 'Get stock status overview.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'stock_status' => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ), 'description' => 'Filter by stock status.' ),
				'low_stock'    => array( 'type' => 'boolean', 'description' => 'Only return products with low stock.' ),
				'page'         => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page'     => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'products'    => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'             => array( 'type' => 'integer' ),
						'name'           => array( 'type' => 'string' ),
						'sku'            => array( 'type' => 'string' ),
						'stock_status'   => array( 'type' => 'string' ),
						'stock_quantity' => array( 'type' => array( 'integer', 'null' ) ),
						'manage_stock'   => array( 'type' => 'boolean' ),
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
			if ( ! mcp_wc_report_permission() ) {
				return array( 'error' => 'Permission denied.' );
			}

			$page     = (int) ( $input['page'] ?? 1 );
			$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 25 ) ) );
			$args     = array(
				'status'   => 'publish',
				'page'     => $page,
				'limit'    => $per_page,
				'paginate' => true,
			);

			if ( ! empty( $input['stock_status'] ) ) {
				$args['stock_status'] = sanitize_text_field( $input['stock_status'] );
			}
			if ( ! empty( $input['low_stock'] ) ) {
				$args['low_in_stock'] = true;
			}

			$results  = wc_get_products( $args );
			$products = array();
			foreach ( $results->products as $product ) {
				$products[] = array(
					'id'             => $product->get_id(),
					'name'           => $product->get_name(),
					'sku'            => $product->get_sku(),
					'stock_status'   => $product->get_stock_status(),
					'stock_quantity' => $product->get_manage_stock() ? $product->get_stock_quantity() : null,
					'manage_stock'   => $product->get_manage_stock(),
				);
			}

			return array(
				'products'    => $products,
				'total_pages' => (int) $results->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			);
		},
		'permission_callback' => 'mcp_wc_report_permission',
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}
