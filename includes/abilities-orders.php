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
	mcp_wc_register_order_delete();
	mcp_wc_register_order_refunds_query();
	mcp_wc_register_order_refund_create();
	mcp_wc_register_order_notes_query();
	mcp_wc_register_order_items_update();
	mcp_wc_register_order_resend_email();
}

function mcp_wc_get_order_or_error( int $id, string $action ): array {
	$order = wc_get_order( $id );
	if ( ! $order ) {
		return array( 'success' => false, 'message' => 'Order not found with ID: ' . $id );
	}
	$allowed = 'delete' === $action
		? MCP_WC_Ability_Execution_Module::can_delete_order( $id )
		: MCP_WC_Ability_Execution_Module::can_edit_order( $id );
	if ( ! $allowed ) {
		return array( 'success' => false, 'message' => 'You do not have permission to ' . $action . ' orders.' );
	}
	return array( 'success' => true, 'order' => $order );
}

// ─── Orders Query ────────────────────────────────────────────────────────────

function mcp_wc_register_orders_query(): void {
	mcp_wc_register_ability( 'woocommerce-mcp/orders-query', array(
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
		'execute_callback'    => function ( array $input ) {
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
			if ( ! empty( $input['modified_after'] ) || ! empty( $input['modified_before'] ) ) {
				$after  = ! empty( $input['modified_after'] ) ? mcp_wc_parse_date( $input['modified_after'] ) : null;
				$before = ! empty( $input['modified_before'] ) ? mcp_wc_parse_date( $input['modified_before'] ) : null;
				if ( $after && $before ) {
					$args['date_modified'] = $after->getTimestamp() . '...' . $before->getTimestamp();
				} elseif ( $after ) {
					$args['date_modified'] = '>' . $after->getTimestamp();
				} elseif ( $before ) {
					$args['date_modified'] = '<' . $before->getTimestamp();
				}
			}

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
			'permission_callback' => function ( array $input ): bool {
				return isset( $input['id'] )
					? MCP_WC_Ability_Execution_Module::can_edit_order( (int) $input['id'] )
					: current_user_can( 'edit_shop_orders' );
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
					'line_items'         => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => array(
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
			'coupon_lines'        => array( 'type' => 'array', 'maxItems' => 100, 'items' => array(
				'type'       => 'object',
				'properties' => array(
					'code'   => array( 'type' => 'string', 'description' => 'Coupon code to apply.' ),
				),
				'required'   => array( 'code' ),
				'additionalProperties' => false,
			), 'description' => 'Coupons to apply to the order.' ),
			'fee_lines'           => array( 'type' => 'array', 'maxItems' => 100, 'items' => array(
				'type'       => 'object',
				'properties' => array(
					'name'   => array( 'type' => 'string' ),
					'total'  => array( 'type' => 'string' ),
					'tax_class' => array( 'type' => 'string' ),
				),
				'required'   => array( 'name', 'total' ),
				'additionalProperties' => false,
			), 'description' => 'Fee line items.' ),
			'shipping_lines'      => array( 'type' => 'array', 'maxItems' => 100, 'items' => array(
				'type'       => 'object',
				'properties' => array(
					'method_title' => array( 'type' => 'string' ),
					'method_id'    => array( 'type' => 'string' ),
					'total'        => array( 'type' => 'string' ),
				),
				'required'   => array( 'method_title', 'total' ),
				'additionalProperties' => false,
			), 'description' => 'Shipping line items.' ),
			'customer_note'       => array( 'type' => 'string', 'description' => 'Note from customer, visible on the order.' ),
				'meta_data'           => array( 'type' => 'array', 'items' => array(
				'type'       => 'object',
				'properties' => array(
					'key'   => array( 'type' => 'string' ),
					'value' => array( 'type' => 'string' ),
				),
				'required'   => array( 'key', 'value' ),
				'additionalProperties' => false,
				), 'description' => 'Custom meta data for extensions.' ),
				'confirm_dangerous_action' => MCP_WC_Ability_Execution_Module::confirmation_schema( 'woocommerce-mcp/order-create' ),
			),
			'required'             => array( 'line_items', 'confirm_dangerous_action' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'order' => mcp_wc_order_output_schema(),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => array( MCP_WC_Order_Lifecycle_Module::class, 'create' ),
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
	mcp_wc_register_ability( 'woocommerce-mcp/order-update-status', array(
		'label'               => 'Update order status',
		'description'         => 'Update an order status, billing/shipping address, and optional order note.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'            => array( 'type' => 'integer', 'minimum' => 1 ),
				'status'        => array( 'type' => 'string', 'enum' => mcp_wc_allowed_order_statuses(), 'description' => 'Optional new registered Order status.' ),
				'note'          => array( 'type' => 'string', 'description' => 'Optional status change note.' ),
				'customer_note' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Make the note visible to the customer.' ),
				'billing'       => array( 'type' => 'object', 'properties' => array(
					'first_name' => array( 'type' => 'string' ), 'last_name' => array( 'type' => 'string' ),
					'company'    => array( 'type' => 'string' ), 'address_1'  => array( 'type' => 'string' ),
					'address_2'  => array( 'type' => 'string' ), 'city'       => array( 'type' => 'string' ),
					'state'      => array( 'type' => 'string' ), 'postcode'   => array( 'type' => 'string' ),
					'country'    => array( 'type' => 'string' ), 'email'      => array( 'type' => 'string', 'format' => 'email' ),
					'phone'      => array( 'type' => 'string' ),
				), 'additionalProperties' => false, 'description' => 'Update billing address fields.' ),
				'shipping'      => array( 'type' => 'object', 'properties' => array(
					'first_name' => array( 'type' => 'string' ), 'last_name' => array( 'type' => 'string' ),
					'company'    => array( 'type' => 'string' ), 'address_1'  => array( 'type' => 'string' ),
					'address_2'  => array( 'type' => 'string' ), 'city'       => array( 'type' => 'string' ),
					'state'      => array( 'type' => 'string' ), 'postcode'   => array( 'type' => 'string' ),
					'country'    => array( 'type' => 'string' ),
				), 'additionalProperties' => false, 'description' => 'Update shipping address fields.' ),
				'confirm_dangerous_action' => MCP_WC_Ability_Execution_Module::confirmation_schema( 'woocommerce-mcp/order-update-status' ),
			),
			'required'             => array( 'id', 'confirm_dangerous_action' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'order'   => mcp_wc_order_output_schema(),
				'note_id' => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => array( MCP_WC_Order_Lifecycle_Module::class, 'update' ),
		'permission_callback' => function ( array $input ): bool {
			return isset( $input['id'] ) && MCP_WC_Ability_Execution_Module::can_edit_order( (int) $input['id'] );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
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
				'confirm_dangerous_action' => MCP_WC_Ability_Execution_Module::confirmation_schema( 'woocommerce-mcp/order-delete' ),
			),
			'required'             => array( 'id', 'confirm_dangerous_action' ),
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
		'execute_callback'    => function ( array $input ) {
			$confirmation = MCP_WC_Ability_Execution_Module::require_confirmation( $input, 'woocommerce-mcp/order-delete' );
			if ( $confirmation ) { return $confirmation; }
			$result = mcp_wc_get_order_or_error( (int) $input['id'], 'delete' );
			if ( ! $result['success'] ) { return $result; }

			$success = $result['order']->delete( (bool) ( $input['force'] ?? false ) );
			return array( 'deleted' => (bool) $success, 'id' => (int) $input['id'] );
		},
		'permission_callback' => function ( array $input ): bool {
			return isset( $input['id'] ) && MCP_WC_Ability_Execution_Module::can_delete_order( (int) $input['id'] );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
		),
	) );
}

// ─── Order Refunds Query ─────────────────────────────────────────────────────

function mcp_wc_format_refund( \WC_Order_Refund $refund ): array {
	$items = array();
	foreach ( $refund->get_items() as $item ) {
		$items[] = array(
			'id'           => $item->get_id(),
			'name'         => $item->get_name(),
			'product_id'   => $item->get_product_id(),
			'quantity'     => $item->get_quantity(),
			'refund_total' => $refund->get_item_total( $item, false, false ),
		);
	}

	return array(
		'id'           => $refund->get_id(),
		'reason'       => $refund->get_reason() ?: '',
		'amount'       => $refund->get_amount(),
		'date_created' => mcp_wc_date_to_iso( $refund->get_date_created() ),
		'refunded_by'  => (int) $refund->get_refunded_by(),
		'line_items'   => $items,
	);
}

function mcp_wc_register_order_refunds_query(): void {
	mcp_wc_register_ability( 'woocommerce/order-refunds-query', array(
		'label'               => 'Query order refunds',
		'description'         => 'List refunds for an order.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'order_id'  => array( 'type' => 'integer', 'minimum' => 1 ),
				'refund_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			),
			'required'             => array( 'order_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'refunds' => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'              => array( 'type' => 'integer' ),
						'reason'          => array( 'type' => 'string' ),
						'amount'          => array( 'type' => 'string' ),
						'date_created'    => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
						'refunded_by'     => array( 'type' => 'integer' ),
						'line_items'      => array( 'type' => 'array', 'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'           => array( 'type' => 'integer' ),
								'name'         => array( 'type' => 'string' ),
								'product_id'   => array( 'type' => 'integer' ),
								'quantity'     => array( 'type' => 'integer' ),
								'refund_total' => array( 'type' => 'string' ),
							),
							'additionalProperties' => false,
						) ),
					),
					'additionalProperties' => false,
				) ),
			),
			'additionalProperties' => false,
		),
			'execute_callback'    => function ( array $input ) {
				$order = wc_get_order( (int) $input['order_id'] );
			if ( ! $order ) {
				return array( 'error' => 'Order not found.' );
			}

			if ( isset( $input['refund_id'] ) ) {
				$refund = wc_get_order( (int) $input['refund_id'] );
				if ( ! $refund || 'shop_order_refund' !== $refund->get_type() || (int) $refund->get_parent_id() !== (int) $order->get_id() ) {
					return array( 'refunds' => array() );
				}
				return array( 'refunds' => array( mcp_wc_format_refund( $refund ) ) );
			}

			$refunds = array();
			foreach ( $order->get_refunds() as $refund ) {
				$refunds[] = mcp_wc_format_refund( $refund );
			}
			return array( 'refunds' => $refunds );
		},
			'permission_callback' => function ( array $input ): bool {
				return isset( $input['order_id'] ) && MCP_WC_Ability_Execution_Module::can_edit_order( (int) $input['order_id'] );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

// ─── Order Refund Create ─────────────────────────────────────────────────────

function mcp_wc_register_order_refund_create(): void {
	mcp_wc_register_ability( 'woocommerce/order-refund-create', array(
		'label'               => 'Create order refund',
		'description'         => 'Create a refund for an order. Supports line-item and amount-based refunds.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'order_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'amount'   => array( 'type' => 'string', 'description' => 'Refund amount. If omitted, refunds all line items fully.' ),
				'reason'   => array( 'type' => 'string', 'description' => 'Reason for the refund.' ),
				'line_items' => array( 'type' => 'array', 'maxItems' => 100, 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'integer', 'description' => 'Order line item ID.' ),
						'quantity' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
						'total'    => array( 'type' => 'string', 'description' => 'Amount to refund for this item.' ),
					),
					'required'   => array( 'id' ),
					'additionalProperties' => false,
				) ),
				'restock_items' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Restore refunded product quantities to stock.' ),
				'refund_payment' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Attempt the refund through the original payment gateway.' ),
				'confirm_dangerous_action' => MCP_WC_Ability_Execution_Module::confirmation_schema( 'woocommerce-mcp/order-refund-create' ),
			),
			'required'             => array( 'order_id', 'confirm_dangerous_action' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'refund' => array( 'type' => 'object' ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => array( MCP_WC_Order_Lifecycle_Module::class, 'create_refund' ),
		'permission_callback' => function ( array $input ): bool {
			return isset( $input['order_id'] )
				&& MCP_WC_Ability_Execution_Module::can_edit_order( (int) $input['order_id'] )
				&& current_user_can( 'manage_woocommerce' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		),
	) );
}

// ─── Order Notes Query ───────────────────────────────────────────────────────

function mcp_wc_register_order_notes_query(): void {
	mcp_wc_register_ability( 'woocommerce/order-notes-query', array(
		'label'               => 'Query order notes',
		'description'         => 'List notes for an order.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'order_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'type'     => array( 'type' => 'string', 'enum' => array( 'any', 'customer', 'internal' ), 'default' => 'any' ),
				'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
			),
			'required'             => array( 'order_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'notes'       => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array( 'type' => 'integer' ),
						'content'      => array( 'type' => 'string' ),
						'date_created' => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
						'customer_note' => array( 'type' => 'boolean' ),
						'added_by'     => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				) ),
					'has_more'    => array( 'type' => 'boolean' ),
				'page'        => array( 'type' => 'integer' ),
				'per_page'    => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
		),
			'execute_callback'    => function ( array $input ) {
				$order = wc_get_order( (int) $input['order_id'] );
			if ( ! $order ) {
				return array( 'error' => 'Order not found.' );
			}

			$type = $input['type'] ?? 'any';
			$args = array(
				'order_id' => $order->get_id(),
				'type'     => 'any' === $type ? 'order_note' : ( 'customer' === $type ? 'customer' : 'internal' ),
					'limit'    => min( 100, max( 1, (int) ( $input['per_page'] ?? 25 ) ) ) + 1,
				'offset'   => ( (int) ( $input['page'] ?? 1 ) - 1 ) * min( 100, max( 1, (int) ( $input['per_page'] ?? 25 ) ) ),
			);

				$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 25 ) ) );
				$notes     = wc_get_order_notes( $args );
				$has_more  = count( $notes ) > $per_page;
				$notes     = array_slice( $notes, 0, $per_page );
			$items = array();
			foreach ( $notes as $note ) {
				$items[] = array(
					'id'            => (int) $note->id,
					'content'       => $note->content,
					'date_created'  => $note->date_created->date( 'Y-m-d\TH:i:s' ),
					'customer_note' => (bool) $note->customer_note,
					'added_by'      => $note->added_by,
				);
			}

			return array(
				'notes'       => $items,
					'has_more'    => $has_more,
				'page'        => (int) ( $input['page'] ?? 1 ),
				'per_page'    => $per_page,
			);
		},
			'permission_callback' => function ( array $input ): bool {
				return isset( $input['order_id'] ) && MCP_WC_Ability_Execution_Module::can_edit_order( (int) $input['order_id'] );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

// ─── Order Items Update ──────────────────────────────────────────────────────

function mcp_wc_register_order_items_update(): void {
	mcp_wc_register_ability( 'woocommerce/order-items-update', array(
		'label'               => 'Update order items',
		'description'         => 'Add or remove line items from an existing order. Recalculates totals after changes.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
				'properties'           => array(
					'order_id'    => array( 'type' => 'integer', 'minimum' => 1 ),
					'add_items'   => array( 'type' => 'array', 'maxItems' => 100, 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
						'variation_id' => array( 'type' => 'integer' ),
						'quantity'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					),
					'required'   => array( 'product_id' ),
					'additionalProperties' => false,
				) ),
					'remove_items' => array( 'type' => 'array', 'maxItems' => 100, 'items' => array( 'type' => 'integer', 'minimum' => 1 ), 'description' => 'Line item IDs to remove.' ),
					'confirm_dangerous_action' => MCP_WC_Ability_Execution_Module::confirmation_schema( 'woocommerce-mcp/order-items-update' ),
				),
				'required'             => array( 'order_id', 'confirm_dangerous_action' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'order' => mcp_wc_order_output_schema(),
			),
			'additionalProperties' => false,
		),
			'execute_callback'    => array( MCP_WC_Order_Lifecycle_Module::class, 'update_items' ),
			'permission_callback' => function ( array $input ): bool {
				return isset( $input['order_id'] ) && MCP_WC_Ability_Execution_Module::can_edit_order( (int) $input['order_id'] );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		),
	) );
}

// ─── Order Resend Email ─────────────────────────────────────────────────────

function mcp_wc_register_order_resend_email(): void {
	mcp_wc_register_ability( 'woocommerce/order-resend-email', array(
		'label'               => 'Resend order email',
		'description'         => 'Resend a WooCommerce order notification email (processing, completed, customer_invoice, etc.).',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
				'properties'           => array(
					'order_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'type'     => array( 'type' => 'string', 'description' => 'Email type. Common: customer_processing_order, customer_completed_order, customer_invoice, customer_refunded_order, customer_note, new_order.' ),
					'confirm_dangerous_action' => MCP_WC_Ability_Execution_Module::confirmation_schema( 'woocommerce-mcp/order-resend-email' ),
				),
				'required'             => array( 'order_id', 'type', 'confirm_dangerous_action' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		),
			'execute_callback'    => function ( array $input ) {
				$confirmation = MCP_WC_Ability_Execution_Module::require_confirmation( $input, 'woocommerce-mcp/order-resend-email' );
				if ( $confirmation ) { return $confirmation; }
				$result = mcp_wc_get_order_or_error( (int) $input['order_id'], 'send emails for' );
			if ( ! $result['success'] ) { return $result; }

			$order = $result['order'];
			$type  = sanitize_text_field( $input['type'] );

			$mailer = WC()->mailer();
			$emails = $mailer->get_emails();
			$found  = false;

			foreach ( $emails as $email ) {
				if ( $email->id === $type ) {
					$found = true;
					$email->trigger( $order->get_id(), $order );

					return array(
						'success' => true,
						'message' => sprintf( 'Email "%s" sent for order #%d.', $email->get_title(), $order->get_id() ),
					);
				}
			}

				return mcp_wc_error( 'mcp_wc_email_type_not_found', sprintf( 'Email type "%s" was not found.', $type ) );
			},
			'permission_callback' => function ( array $input ): bool {
				return isset( $input['order_id'] ) && MCP_WC_Ability_Execution_Module::can_edit_order( (int) $input['order_id'] );
		},
		'meta'                => array(
				'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false, 'externalAction' => true ),
		),
	) );
}
