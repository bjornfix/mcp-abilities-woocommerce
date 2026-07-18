<?php
/**
 * WooCommerce customer abilities.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function mcp_wc_register_customer_abilities(): void {
	if ( ! mcp_wc_is_active() ) {
		return;
	}

	mcp_wc_register_customers_query();
}

// ─── Customers Query ─────────────────────────────────────────────────────────

function mcp_wc_register_customers_query(): void {
	mcp_wc_register_ability( 'woocommerce/customers-query', array(
		'label'               => 'Query customers',
		'description'         => 'Find customers by ID, email, search, or date filters.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'           => array( 'type' => 'integer', 'minimum' => 1 ),
				'email'        => array( 'type' => 'string', 'format' => 'email' ),
				'search'       => array( 'type' => 'string', 'description' => 'Search by name or email.' ),
				'role'         => array( 'type' => 'string', 'description' => 'Filter by WordPress user role.' ),
				'date_after'   => array( 'type' => 'string', 'format' => 'date-time' ),
				'date_before'  => array( 'type' => 'string', 'format' => 'date-time' ),
				'orderby'      => array( 'type' => 'string', 'enum' => array( 'id', 'name', 'email', 'registered_date', 'total_spent', 'order_count' ) ),
				'order'        => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
				'page'         => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page'     => array( 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100 ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'customers'   => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'               => array( 'type' => 'integer' ),
						'email'            => array( 'type' => 'string', 'format' => 'email' ),
						'first_name'       => array( 'type' => 'string' ),
						'last_name'        => array( 'type' => 'string' ),
						'display_name'     => array( 'type' => 'string' ),
						'username'         => array( 'type' => 'string' ),
						'role'             => array( 'type' => 'string' ),
						'billing'          => array( 'type' => 'object', 'properties' => array(
							'first_name' => array( 'type' => 'string' ), 'last_name' => array( 'type' => 'string' ),
							'company'    => array( 'type' => 'string' ), 'address_1'  => array( 'type' => 'string' ),
							'address_2'  => array( 'type' => 'string' ), 'city'       => array( 'type' => 'string' ),
							'state'      => array( 'type' => 'string' ), 'postcode'   => array( 'type' => 'string' ),
							'country'    => array( 'type' => 'string' ), 'phone'      => array( 'type' => 'string' ),
							'email'      => array( 'type' => 'string', 'format' => 'email' ),
						), 'additionalProperties' => false ),
						'shipping'        => array( 'type' => 'object', 'properties' => array(
							'first_name' => array( 'type' => 'string' ), 'last_name' => array( 'type' => 'string' ),
							'company'    => array( 'type' => 'string' ), 'address_1'  => array( 'type' => 'string' ),
							'address_2'  => array( 'type' => 'string' ), 'city'       => array( 'type' => 'string' ),
							'state'      => array( 'type' => 'string' ), 'postcode'   => array( 'type' => 'string' ),
							'country'    => array( 'type' => 'string' ),
						), 'additionalProperties' => false ),
						'total_spent'     => array( 'type' => 'string' ),
						'order_count'     => array( 'type' => 'integer' ),
						'date_created'    => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
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
			if ( ! current_user_can( 'list_users' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			if ( isset( $input['id'] ) ) {
				$user = get_userdata( (int) $input['id'] );
				if ( ! $user ) {
					return array( 'customers' => array(), 'total_pages' => 0, 'page' => 1, 'per_page' => (int) ( $input['per_page'] ?? 10 ) );
				}
				return array( 'customers' => array( mcp_wc_format_customer( $user ) ), 'total_pages' => 1, 'page' => 1, 'per_page' => 1 );
			}

			$page     = (int) ( $input['page'] ?? 1 );
			$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );
			$args     = array(
				'number' => $per_page,
				'paged'  => $page,
			);

			if ( ! empty( $input['search'] ) ) {
				$args['search'] = '*' . sanitize_text_field( $input['search'] ) . '*';
			}
			if ( ! empty( $input['role'] ) ) {
				$args['role'] = sanitize_text_field( $input['role'] );
			}
			if ( ! empty( $input['orderby'] ) ) {
				$args['orderby'] = sanitize_text_field( $input['orderby'] );
			}
			if ( ! empty( $input['order'] ) ) {
				$args['order'] = strtoupper( sanitize_text_field( $input['order'] ) );
			}

			if ( ! empty( $input['email'] ) ) {
				$user = get_user_by( 'email', sanitize_email( $input['email'] ) );
				if ( ! $user ) {
					return array( 'customers' => array(), 'total_pages' => 0, 'page' => 1, 'per_page' => $per_page );
				}
				return array( 'customers' => array( mcp_wc_format_customer( $user ) ), 'total_pages' => 1, 'page' => 1, 'per_page' => 1 );
			}

			$query  = new \WP_User_Query( $args );
			$customers = array();
			foreach ( $query->get_results() as $user ) {
				$customers[] = mcp_wc_format_customer( $user );
			}

			$total = $query->get_total();
			return array(
				'customers'   => $customers,
				'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
				'page'        => $page,
				'per_page'    => $per_page,
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'list_users' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

function mcp_wc_format_customer( \WP_User $user ): array {
	$customer = new \WC_Customer( $user->ID );

	$billing_fields  = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email' );
	$shipping_fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

	$billing  = array();
	foreach ( $billing_fields as $field ) {
		$method = "get_billing_{$field}";
		$billing[ $field ] = $customer->$method();
	}

	$shipping = array();
	foreach ( $shipping_fields as $field ) {
		$method = "get_shipping_{$field}";
		$shipping[ $field ] = $customer->$method();
	}

	return array(
		'id'              => $user->ID,
		'email'           => $user->user_email,
		'first_name'      => $user->first_name,
		'last_name'       => $user->last_name,
		'display_name'    => $user->display_name,
		'username'        => $user->user_login,
		'role'            => $user->roles[0] ?? '',
		'billing'         => $billing,
		'shipping'        => $shipping,
		'total_spent'     => (string) wc_get_customer_total_spent( $user->ID ),
		'order_count'     => (int) wc_get_customer_order_count( $user->ID ),
		'date_created'    => $user->user_registered,
	);
}
