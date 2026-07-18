<?php
/**
 * WooCommerce order abilities.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function mcp_wc_register_order_abilities(): void {
	if ( ! mcp_wc_is_active() ) {
		return;
	}

	mcp_wc_register_orders_query();
	mcp_wc_register_order_create();
	mcp_wc_register_order_update_status();
	mcp_wc_register_order_add_note();
	mcp_wc_register_order_delete();
}

function mcp_wc_get_order_or_error( int $id, string $action ): array {
	$order = wc_get_order( $id );
	if ( ! $order ) {
		return array( 'success' => false, 'message' => 'Order not found with ID: ' . $id );
	}
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		return array( 'success' => false, 'message' => 'You do not have permission to ' . $action . ' orders.' );
	}
	return array( 'success' => true, 'order' => $order );
}

// ─── Orders Query ────────────────────────────────────────────────────────────

function mcp_wc_register_orders_query(): void {
	mcp_wc_register_ability( 'woocommerce/orders-query', array(
		'label'               => 'Query orders',
		'description'         => 'Find orders by ID or common order filters.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                  => array( 'type' => 'integer', 'minimum' => 1 ),
				'status'              => array( 'type' => 'string', 'enum' => mcp_wc_allowed_order_statuses() ),
				'customer_id'         => array( 'type' => 'integer', 'minimum' => 0 ),
				'billing_email'       => array( 'type' => 'string', 'format' => 'email' ),
				'parent'              => array( 'type' => 'integer', 'minimum' => 1 ),
				'date_after'          => array( 'type' => 'string', 'format' => 'date-time' ),
				'date_before'         => array( 'type' => 'string', 'format' => 'date-time' ),
				'modified_after'      => array( 'type' => 'string', 'format' => 'date-time' ),
				'modified_before'     => array( 'type' => 'string', 'format' => 'date-time' ),
				'orderby'             => array( 'type' => 'string', 'enum' => array( 'id', 'date', 'date_modified', 'total' ) ),
				'order'               => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
				'include_line_items'  => array( 'type' => 'boolean', 'default' => false ),
				'page'                => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page'            => array( 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100 ),
			),
			'additionalProperties' => false,
			'default'              => array(),
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'orders'      => array( 'type' => 'array', 'items' => mcp_wc_order_output_schema() ),
				'total_pages' => array( 'type' => 'integer' ),
				'page'        => array( 'type' => 'integer' ),
				'per_page'    => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'edit_shop_orders' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$include_line_items = (bool) ( $input['include_line_items'] ?? false );

			if ( isset( $input['id'] ) ) {
				$order = wc_get_order( (int) $input['id'] );
				if ( ! $order ) {
					return array( 'orders' => array(), 'total_pages' => 0, 'page' => 1, 'per_page' => (int) ( $input['per_page'] ?? 10 ) );
				}
				return array(
					'orders'      => array( mcp_wc_format_order( $order, $include_line_items ) ),
					'total_pages' => 1,
					'page'        => 1,
					'per_page'    => 1,
				);
			}

			$page     = (int) ( $input['page'] ?? 1 );
			$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );
			$args     = array(
				'limit'    => $per_page,
				'page'     => $page,
				'paginate' => true,
			);

			if ( ! empty( $input['status'] ) ) { $args['status'] = sanitize_text_field( $input['status'] ); }
			if ( isset( $input['customer_id'] ) ) { $args['customer_id'] = (int) $input['customer_id']; }
			if ( ! empty( $input['billing_email'] ) ) { $args['billing_email'] = sanitize_email( $input['billing_email'] ); }
			if ( isset( $input['parent'] ) ) { $args['parent'] = (int) $input['parent']; }
			if ( ! empty( $input['orderby'] ) ) { $args['orderby'] = sanitize_text_field( $input['orderby'] ); }
			if ( ! empty( $input['order'] ) ) { $args['order'] = strtoupper( sanitize_text_field( $input['order'] ) ); }
			if ( ! empty( $input['date_after'] ) ) { $args['date_after'] = sanitize_text_field( $input['date_after'] ); }
			if ( ! empty( $input['date_before'] ) ) { $args['date_before'] = sanitize_text_field( $input['date_before'] ); }
			if ( ! empty( $input['modified_after'] ) ) { $args['date_modified'] = sanitize_text_field( $input['modified_after'] ); }
			if ( ! empty( $input['modified_before'] ) ) { $args['date_modified'] = sanitize_text_field( $input['modified_before'] ); }

			$results = wc_get_orders( $args );
			$orders  = array();
			foreach ( $results->orders as $order ) {
				$orders[] = mcp_wc_format_order( $order, $include_line_items );
			}

			return array(
				'orders'      => $orders,
				'total_pages' => (int) $results->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_shop_orders' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

// ─── Order Create ────────────────────────────────────────────────────────────

function mcp_wc_register_order_create(): void {
	mcp_wc_register_ability( 'woocommerce/order-create', array(
		'label'               => 'Create order',
		'description'         => 'Create a new order manually.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'customer_id'        => array( 'type' => 'integer', 'description' => 'Existing customer/user ID. Use 0 for guest.' ),
				'status'             => array( 'type' => 'string', 'enum' => mcp_wc_allowed_order_statuses(), 'default' => 'pending' ),
				'line_items'         => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'quantity'   => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
						'variation_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'product_id' ),
					'additionalProperties' => false,
				) ),
				'billing'            => array( 'type' => 'object', 'properties' => array(
					'first_name' => array( 'type' => 'string' ), 'last_name' => array( 'type' => 'string' ),
					'company'    => array( 'type' => 'string' ), 'address_1'  => array( 'type' => 'string' ),
					'address_2'  => array( 'type' => 'string' ), 'city'       => array( 'type' => 'string' ),
					'state'      => array( 'type' => 'string' ), 'postcode'   => array( 'type' => 'string' ),
					'country'    => array( 'type' => 'string' ), 'email'      => array( 'type' => 'string', 'format' => 'email' ),
					'phone'      => array( 'type' => 'string' ),
				), 'additionalProperties' => false ),
				'shipping'           => array( 'type' => 'object', 'properties' => array(
					'first_name' => array( 'type' => 'string' ), 'last_name' => array( 'type' => 'string' ),
					'company'    => array( 'type' => 'string' ), 'address_1'  => array( 'type' => 'string' ),
					'address_2'  => array( 'type' => 'string' ), 'city'       => array( 'type' => 'string' ),
					'state'      => array( 'type' => 'string' ), 'postcode'   => array( 'type' => 'string' ),
					'country'    => array( 'type' => 'string' ),
				), 'additionalProperties' => false ),
				'note'               => array( 'type' => 'string', 'description' => 'Optional order note.' ),
				'payment_method'     => array( 'type' => 'string' ),
				'payment_method_title' => array( 'type' => 'string' ),
			),
			'required'             => array( 'line_items' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'order' => mcp_wc_order_output_schema(),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'edit_shop_orders' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$order = wc_create_order( array(
				'customer_id' => isset( $input['customer_id'] ) ? (int) $input['customer_id'] : 0,
				'status'      => $input['status'] ?? 'pending',
			) );

			if ( is_wp_error( $order ) ) {
				return array( 'error' => $order->get_error_message() );
			}

			if ( isset( $input['line_items'] ) && is_array( $input['line_items'] ) ) {
				foreach ( $input['line_items'] as $item ) {
					$product_id   = (int) $item['product_id'];
					$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
					$quantity     = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;

					if ( $variation_id ) {
						$product = wc_get_product( $variation_id );
					} else {
						$product = wc_get_product( $product_id );
					}
					if ( ! $product ) { continue; }

					$order->add_product( $product, $quantity );
				}
			}

			if ( isset( $input['billing'] ) && is_array( $input['billing'] ) ) {
				foreach ( $input['billing'] as $key => $value ) {
					if ( is_string( $value ) ) {
						$order->{"set_billing_{$key}"}( sanitize_text_field( $value ) );
					}
				}
			}
			if ( isset( $input['shipping'] ) && is_array( $input['shipping'] ) ) {
				foreach ( $input['shipping'] as $key => $value ) {
					if ( is_string( $value ) ) {
						$order->{"set_shipping_{$key}"}( sanitize_text_field( $value ) );
					}
				}
			}
			if ( ! empty( $input['payment_method'] ) ) {
				$order->set_payment_method( sanitize_text_field( $input['payment_method'] ) );
			}
			if ( ! empty( $input['payment_method_title'] ) ) {
				$order->set_payment_method_title( sanitize_text_field( $input['payment_method_title'] ) );
			}

			$order->calculate_totals();
			$order->save();

			if ( ! empty( $input['note'] ) ) {
				$order->add_order_note( sanitize_textarea_field( $input['note'] ) );
			}

			return array( 'order' => mcp_wc_format_order( $order, true ) );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_shop_orders' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		),
	) );
}

// ─── Order Update Status ─────────────────────────────────────────────────────

function mcp_wc_register_order_update_status(): void {
	mcp_wc_register_ability( 'woocommerce/order-update-status', array(
		'label'               => 'Update order status',
		'description'         => 'Update an order status.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'     => array( 'type' => 'integer', 'minimum' => 1 ),
				'status' => array( 'type' => 'string', 'enum' => mcp_wc_allowed_order_statuses() ),
				'note'   => array( 'type' => 'string', 'description' => 'Optional status change note.' ),
			),
			'required'             => array( 'id', 'status' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'order' => mcp_wc_order_output_schema(),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			$result = mcp_wc_get_order_or_error( (int) $input['id'], 'update status of' );
			if ( ! $result['success'] ) { return $result; }

			$order  = $result['order'];
			$status = sanitize_text_field( $input['status'] );

			$note = isset( $input['note'] ) ? sanitize_textarea_field( $input['note'] ) : '';
			$order->update_status( $status, '' !== $note ? $note : '' );

			return array( 'order' => mcp_wc_format_order( $order, true ) );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_shop_orders' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		),
	) );
}

// ─── Order Add Note ──────────────────────────────────────────────────────────

function mcp_wc_register_order_add_note(): void {
	mcp_wc_register_ability( 'woocommerce/order-add-note', array(
		'label'               => 'Add order note',
		'description'         => 'Add a note to an order.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'            => array( 'type' => 'integer', 'minimum' => 1 ),
				'note'          => array( 'type' => 'string', 'minLength' => 1, 'pattern' => '\\S' ),
				'customer_note' => array( 'type' => 'boolean', 'default' => false ),
			),
			'required'             => array( 'id', 'note' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'note_id' => array( 'type' => 'integer' ),
				'order'   => mcp_wc_order_output_schema(),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			$result = mcp_wc_get_order_or_error( (int) $input['id'], 'add notes to' );
			if ( ! $result['success'] ) { return $result; }

			$order         = $result['order'];
			$customer_note = (bool) ( $input['customer_note'] ?? false );
			$note_id       = $order->add_order_note( sanitize_textarea_field( $input['note'] ), $customer_note ? 1 : 0, false );

			return array( 'note_id' => $note_id, 'order' => mcp_wc_format_order( $order, true ) );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_shop_orders' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
		),
	) );
}

// ─── Order Delete ────────────────────────────────────────────────────────────

function mcp_wc_register_order_delete(): void {
	mcp_wc_register_ability( 'woocommerce/order-delete', array(
		'label'               => 'Delete order',
		'description'         => 'Delete or trash an order.',
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
			$result = mcp_wc_get_order_or_error( (int) $input['id'], 'delete' );
			if ( ! $result['success'] ) { return $result; }

			$success = $result['order']->delete( (bool) ( $input['force'] ?? false ) );
			return array( 'deleted' => (bool) $success, 'id' => (int) $input['id'] );
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'edit_shop_orders' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		),
	) );
}
