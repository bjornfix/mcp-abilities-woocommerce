<?php
/** WooCommerce infrastructure mutation abilities. */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) { exit; }

function mcp_wc_register_administration_abilities(): void {
	$module = MCP_WC_Commerce_Administration_Module::class;
	$register = static function ( string $name, string $label, array $properties, array $required, string $method, array $output ) use ( $module ): void {
		$ability = 'woocommerce-mcp/' . $name;
		$properties['confirm_dangerous_action'] = MCP_WC_Ability_Execution_Module::confirmation_schema( $ability );
		$required[] = 'confirm_dangerous_action';
		mcp_wc_register_ability( $ability, array(
			'label' => $label, 'description' => $label . ' through WooCommerce native data APIs.', 'category' => 'site',
			'input_schema' => array( 'type' => 'object', 'properties' => $properties, 'required' => array_values( array_unique( $required ) ), 'additionalProperties' => false ),
			'output_schema' => array( 'type' => 'object', 'properties' => $output, 'additionalProperties' => false ),
			'execute_callback' => array( $module, $method ),
			'permission_callback' => static fn(): bool => current_user_can( 'manage_woocommerce' ),
			'meta' => array( 'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ) ),
		) );
	};

	$register( 'store-settings-update', 'Update store settings', array(
		'currency' => array( 'type' => 'string', 'enum' => mcp_wc_currency_codes() ),
		'currency_position' => array( 'type' => 'string', 'enum' => array( 'left', 'right', 'left_space', 'right_space' ) ),
		'thousand_separator' => array( 'type' => 'string', 'maxLength' => 1 ), 'decimal_separator' => array( 'type' => 'string', 'maxLength' => 1 ),
		'number_of_decimals' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 6 ),
		'dimension_unit' => array( 'type' => 'string', 'enum' => array( 'm', 'cm', 'mm', 'in', 'yd' ) ),
		'weight_unit' => array( 'type' => 'string', 'enum' => array( 'kg', 'g', 'lbs', 'oz' ) ),
		'selling_countries' => array( 'type' => 'string', 'enum' => array( 'all', 'all_except', 'specific' ) ),
		'selling_country_codes' => array( 'type' => 'array', 'maxItems' => 249, 'items' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 2 ) ),
		'excluded_country_codes' => array( 'type' => 'array', 'maxItems' => 249, 'items' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 2 ) ),
		'shipping_countries' => array( 'type' => 'string', 'enum' => array( '', 'all', 'specific', 'disabled' ) ),
		'shipping_country_codes' => array( 'type' => 'array', 'maxItems' => 249, 'items' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 2 ) ),
		'default_customer_location' => array( 'type' => 'string' ),
		'enable_coupons' => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ) ), 'calc_taxes' => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ) ),
		'enable_guest_checkout' => array( 'type' => 'string', 'enum' => array( 'yes', 'no' ) ),
		'store_address' => array( 'type' => 'object', 'properties' => array(
			'address_1' => array( 'type' => 'string' ), 'address_2' => array( 'type' => 'string' ), 'city' => array( 'type' => 'string' ),
			'state' => array( 'type' => 'string' ), 'postcode' => array( 'type' => 'string' ), 'country' => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 2 ),
		), 'additionalProperties' => false ),
	), array(), 'update_store_settings', array( 'updated' => array( 'type' => 'boolean' ) ) );

	$register( 'tax-rate-save', 'Create or update a tax rate', array(
		'id' => array( 'type' => 'integer', 'minimum' => 1 ), 'country' => array( 'type' => 'string' ), 'state' => array( 'type' => 'string' ),
		'rate' => array( 'type' => 'string', 'pattern' => '^\\d+(?:\\.\\d+)?$' ), 'name' => array( 'type' => 'string' ),
		'priority' => array( 'type' => 'integer', 'minimum' => 1 ), 'compound' => array( 'type' => 'boolean' ), 'shipping' => array( 'type' => 'boolean' ),
		'order' => array( 'type' => 'integer', 'minimum' => 0 ), 'class' => array( 'type' => 'string' ),
		'postcodes' => array( 'type' => 'array', 'maxItems' => 500, 'items' => array( 'type' => 'string' ) ),
		'cities' => array( 'type' => 'array', 'maxItems' => 500, 'items' => array( 'type' => 'string' ) ),
	), array( 'rate', 'name' ), 'save_tax_rate', array( 'tax_rate' => array( 'type' => 'object' ) ) );
	$register( 'tax-rate-delete', 'Delete a tax rate', array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'id' ), 'delete_tax_rate', array( 'deleted' => array( 'type' => 'boolean' ), 'id' => array( 'type' => 'integer' ) ) );

	$register( 'shipping-zone-save', 'Create or update a shipping zone', array(
		'id' => array( 'type' => 'integer', 'minimum' => 1 ), 'name' => array( 'type' => 'string', 'minLength' => 1 ), 'order' => array( 'type' => 'integer', 'minimum' => 0 ),
		'locations' => array( 'type' => 'array', 'maxItems' => 500, 'items' => array( 'type' => 'object', 'properties' => array(
			'code' => array( 'type' => 'string' ), 'type' => array( 'type' => 'string', 'enum' => array( 'continent', 'country', 'state', 'postcode' ) ),
		), 'required' => array( 'code', 'type' ), 'additionalProperties' => false ) ),
	), array( 'name' ), 'save_shipping_zone', array( 'zone' => array( 'type' => 'object' ) ) );
	$register( 'shipping-zone-delete', 'Delete a shipping zone', array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'id' ), 'delete_shipping_zone', array( 'deleted' => array( 'type' => 'boolean' ), 'id' => array( 'type' => 'integer' ) ) );
	$register( 'shipping-method-add', 'Add a shipping method instance', array( 'zone_id' => array( 'type' => 'integer', 'minimum' => 0 ), 'method_id' => array( 'type' => 'string' ) ), array( 'zone_id', 'method_id' ), 'add_shipping_method', array( 'zone' => array( 'type' => 'object' ), 'instance_id' => array( 'type' => 'integer' ) ) );
	$register( 'shipping-method-update', 'Update a shipping method instance', array( 'instance_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'enabled' => array( 'type' => 'boolean' ), 'settings' => array( 'type' => 'object', 'additionalProperties' => array( 'type' => array( 'string', 'number', 'integer', 'boolean' ) ) ) ), array( 'instance_id' ), 'update_shipping_method', array( 'updated' => array( 'type' => 'boolean' ), 'instance_id' => array( 'type' => 'integer' ) ) );
	$register( 'shipping-method-delete', 'Delete a shipping method instance', array( 'instance_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'instance_id' ), 'delete_shipping_method', array( 'deleted' => array( 'type' => 'boolean' ), 'instance_id' => array( 'type' => 'integer' ) ) );
	$register( 'payment-gateway-update', 'Update a payment gateway', array( 'id' => array( 'type' => 'string' ), 'enabled' => array( 'type' => 'boolean' ), 'settings' => array( 'type' => 'object', 'additionalProperties' => array( 'type' => array( 'string', 'number', 'integer', 'boolean' ) ) ) ), array( 'id' ), 'update_payment_gateway', array( 'updated' => array( 'type' => 'boolean' ), 'id' => array( 'type' => 'string' ), 'enabled' => array( 'type' => 'boolean' ) ) );
	$term = array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ), 'name' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ), 'count' => array( 'type' => 'integer' ) ), 'additionalProperties' => false );
	$register( 'shipping-class-save', 'Create or update a shipping class', array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ), 'name' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ) ), array( 'name' ), 'save_shipping_class', array( 'shipping_class' => $term ) );
	$register( 'shipping-class-delete', 'Delete a shipping class', array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'id' ), 'delete_shipping_class', array( 'deleted' => array( 'type' => 'boolean' ), 'id' => array( 'type' => 'integer' ) ) );
}
