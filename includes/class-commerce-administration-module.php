<?php
/**
 * Coherent administration operations for WooCommerce infrastructure.
 *
 * @package MCP_Abilities_WooCommerce
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MCP_WC_Commerce_Administration_Module {
	/** @return array<string,mixed>|WP_Error */
	public static function update_store_settings( array $input ) {
		$guard = self::guard( $input, 'woocommerce-mcp/store-settings-update' );
		if ( $guard ) { return $guard; }
		$country_options = array(
			'selling_country_codes'  => 'woocommerce_specific_allowed_countries',
			'excluded_country_codes' => 'woocommerce_all_except_countries',
			'shipping_country_codes' => 'woocommerce_specific_ship_to_countries',
		);
		$normalized_country_options = array();
		foreach ( $country_options as $field => $option ) {
			if ( ! array_key_exists( $field, $input ) ) { continue; }
			$raw_codes = array_values( array_unique( array_map( static fn( $code ): string => strtoupper( sanitize_text_field( (string) $code ) ), (array) $input[ $field ] ) ) );
			$codes     = self::sanitize_country_codes( $raw_codes );
			if ( count( $codes ) !== count( $raw_codes ) ) {
				return mcp_wc_error( 'mcp_wc_invalid_country_codes', 'Every country code must identify a WooCommerce country.' );
			}
			$normalized_country_options[ $option ] = $codes;
		}
		if ( 'specific' === ( $input['selling_countries'] ?? null ) && empty( $normalized_country_options['woocommerce_specific_allowed_countries'] ) ) {
			return mcp_wc_error( 'mcp_wc_selling_countries_required', 'Specific selling countries require selling_country_codes.' );
		}
		if ( 'all_except' === ( $input['selling_countries'] ?? null ) && empty( $normalized_country_options['woocommerce_all_except_countries'] ) ) {
			return mcp_wc_error( 'mcp_wc_excluded_countries_required', 'All-except selling countries require excluded_country_codes.' );
		}
		if ( 'specific' === ( $input['shipping_countries'] ?? null ) && empty( $normalized_country_options['woocommerce_specific_ship_to_countries'] ) ) {
			return mcp_wc_error( 'mcp_wc_shipping_countries_required', 'Specific shipping countries require shipping_country_codes.' );
		}
		if ( isset( $input['store_address']['country'] ) && ! in_array( strtoupper( (string) $input['store_address']['country'] ), array_keys( WC()->countries->get_countries() ), true ) ) {
			return mcp_wc_error( 'mcp_wc_invalid_store_country', 'The store address country is not registered by WooCommerce.' );
		}

		$map = array(
			'currency'                  => 'woocommerce_currency',
			'currency_position'         => 'woocommerce_currency_pos',
			'thousand_separator'        => 'woocommerce_price_thousand_sep',
			'decimal_separator'         => 'woocommerce_price_decimal_sep',
			'number_of_decimals'        => 'woocommerce_price_num_decimals',
			'dimension_unit'            => 'woocommerce_dimension_unit',
			'weight_unit'               => 'woocommerce_weight_unit',
			'selling_countries'         => 'woocommerce_allowed_countries',
			'shipping_countries'        => 'woocommerce_ship_to_countries',
			'default_customer_location' => 'woocommerce_default_customer_address',
			'enable_coupons'            => 'woocommerce_enable_coupons',
			'calc_taxes'                => 'woocommerce_calc_taxes',
			'enable_guest_checkout'     => 'woocommerce_enable_guest_checkout',
		);
		foreach ( $map as $field => $option ) {
			if ( ! array_key_exists( $field, $input ) ) { continue; }
			$value = 'number_of_decimals' === $field ? min( 6, max( 0, (int) $input[ $field ] ) ) : sanitize_text_field( (string) $input[ $field ] );
			update_option( $option, $value );
		}
		foreach ( $normalized_country_options as $option => $codes ) { update_option( $option, $codes ); }
		if ( isset( $input['store_address'] ) && is_array( $input['store_address'] ) ) {
			$address_map = array(
				'address_1' => 'woocommerce_store_address', 'address_2' => 'woocommerce_store_address_2',
				'city' => 'woocommerce_store_city', 'postcode' => 'woocommerce_store_postcode',
			);
			foreach ( $address_map as $field => $option ) {
				if ( isset( $input['store_address'][ $field ] ) ) { update_option( $option, sanitize_text_field( (string) $input['store_address'][ $field ] ) ); }
			}
			if ( isset( $input['store_address']['country'] ) ) {
				$country = strtoupper( sanitize_text_field( (string) $input['store_address']['country'] ) );
				$state   = strtoupper( sanitize_text_field( (string) ( $input['store_address']['state'] ?? '' ) ) );
				update_option( 'woocommerce_default_country', '' !== $state ? $country . ':' . $state : $country );
			}
		}
		return array( 'updated' => true );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function save_tax_rate( array $input ) {
		$guard = self::guard( $input, 'woocommerce-mcp/tax-rate-save' );
		if ( $guard ) { return $guard; }
		$rate = array(
			'tax_rate_country'  => strtoupper( sanitize_text_field( (string) ( $input['country'] ?? '' ) ) ),
			'tax_rate_state'    => strtoupper( sanitize_text_field( (string) ( $input['state'] ?? '' ) ) ),
			'tax_rate'          => wc_format_decimal( $input['rate'] ?? '0' ),
			'tax_rate_name'     => sanitize_text_field( (string) ( $input['name'] ?? '' ) ),
			'tax_rate_priority' => max( 1, (int) ( $input['priority'] ?? 1 ) ),
			'tax_rate_compound' => ! empty( $input['compound'] ) ? 1 : 0,
			'tax_rate_shipping' => ! empty( $input['shipping'] ) ? 1 : 0,
			'tax_rate_order'    => max( 0, (int) ( $input['order'] ?? 0 ) ),
			'tax_rate_class'    => sanitize_title( (string) ( $input['class'] ?? '' ) ),
		);
		$id = (int) ( $input['id'] ?? 0 );
		if ( $id > 0 ) {
			if ( ! \WC_Tax::_get_tax_rate( $id ) ) { return mcp_wc_error( 'mcp_wc_tax_rate_not_found', 'Tax rate not found.' ); }
			\WC_Tax::_update_tax_rate( $id, $rate );
		} else {
			$id = (int) \WC_Tax::_insert_tax_rate( $rate );
		}
		\WC_Tax::_update_tax_rate_postcodes( $id, implode( ';', array_map( 'wc_clean', (array) ( $input['postcodes'] ?? array() ) ) ) );
		\WC_Tax::_update_tax_rate_cities( $id, implode( ';', array_map( 'wc_clean', (array) ( $input['cities'] ?? array() ) ) ) );
		return array( 'tax_rate' => mcp_wc_format_tax_rate( (array) \WC_Tax::_get_tax_rate( $id ) ) );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function delete_tax_rate( array $input ) {
		$guard = self::guard( $input, 'woocommerce-mcp/tax-rate-delete' );
		if ( $guard ) { return $guard; }
		$id = (int) ( $input['id'] ?? 0 );
		if ( ! \WC_Tax::_get_tax_rate( $id ) ) { return mcp_wc_error( 'mcp_wc_tax_rate_not_found', 'Tax rate not found.' ); }
		\WC_Tax::_delete_tax_rate( $id );
		return array( 'deleted' => true, 'id' => $id );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function save_shipping_zone( array $input ) {
		$guard = self::guard( $input, 'woocommerce-mcp/shipping-zone-save' );
		if ( $guard ) { return $guard; }
		$id = array_key_exists( 'id', $input ) ? (int) $input['id'] : null;
		if ( 0 === $id ) { return mcp_wc_error( 'mcp_wc_rest_of_world_immutable', 'The Rest of the world zone cannot be created or renamed; manage its methods separately.' ); }
		$zone = null === $id ? new \WC_Shipping_Zone() : \WC_Shipping_Zones::get_zone( $id );
		if ( ! $zone ) { return mcp_wc_error( 'mcp_wc_shipping_zone_not_found', 'Shipping zone not found.' ); }
		$zone->set_zone_name( sanitize_text_field( (string) ( $input['name'] ?? $zone->get_zone_name() ) ) );
		$zone->set_zone_order( max( 0, (int) ( $input['order'] ?? $zone->get_zone_order() ) ) );
		if ( isset( $input['locations'] ) ) {
			$locations = array();
			foreach ( (array) $input['locations'] as $location ) {
				$locations[] = array( 'code' => strtoupper( sanitize_text_field( (string) $location['code'] ) ), 'type' => sanitize_key( (string) $location['type'] ) );
			}
			$zone->set_locations( $locations );
		}
		$zone->save();
		return array( 'zone' => mcp_wc_format_shipping_zone( $zone ) );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function delete_shipping_zone( array $input ) {
		$guard = self::guard( $input, 'woocommerce-mcp/shipping-zone-delete' );
		if ( $guard ) { return $guard; }
		$id = (int) ( $input['id'] ?? 0 );
		if ( $id < 1 || ! \WC_Shipping_Zones::get_zone( $id ) ) { return mcp_wc_error( 'mcp_wc_shipping_zone_not_found', 'Shipping zone not found.' ); }
		\WC_Shipping_Zones::delete_zone( $id );
		return array( 'deleted' => true, 'id' => $id );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function add_shipping_method( array $input ) {
		$guard = self::guard( $input, 'woocommerce-mcp/shipping-method-add' );
		if ( $guard ) { return $guard; }
		$zone = new \WC_Shipping_Zone( (int) ( $input['zone_id'] ?? 0 ) );
		$instance_id = (int) $zone->add_shipping_method( sanitize_key( (string) $input['method_id'] ) );
		if ( $instance_id < 1 ) { return mcp_wc_error( 'mcp_wc_shipping_method_add_failed', 'The shipping method could not be added to this zone.' ); }
		return array( 'zone' => mcp_wc_format_shipping_zone( $zone ), 'instance_id' => $instance_id );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function update_shipping_method( array $input ) {
		$guard = self::guard( $input, 'woocommerce-mcp/shipping-method-update' );
		if ( $guard ) { return $guard; }
		$method = \WC_Shipping_Zones::get_shipping_method( (int) ( $input['instance_id'] ?? 0 ) );
		if ( ! $method ) { return mcp_wc_error( 'mcp_wc_shipping_method_not_found', 'Shipping method instance not found.' ); }
		$fields   = $method->get_instance_form_fields();
		$settings = get_option( $method->get_instance_option_key(), array() );
		$settings = is_array( $settings ) ? $settings : array();
		foreach ( (array) ( $input['settings'] ?? array() ) as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( ! array_key_exists( $key, $fields ) || is_array( $value ) || is_object( $value ) ) { continue; }
			$settings[ $key ] = sanitize_text_field( (string) $value );
		}
		if ( isset( $input['enabled'] ) ) { $settings['enabled'] = $input['enabled'] ? 'yes' : 'no'; }
		update_option( $method->get_instance_option_key(), $settings );
		$method->init_instance_settings();
		return array( 'updated' => true, 'instance_id' => (int) $method->get_instance_id() );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function delete_shipping_method( array $input ) {
		$guard = self::guard( $input, 'woocommerce-mcp/shipping-method-delete' );
		if ( $guard ) { return $guard; }
		$instance_id = (int) ( $input['instance_id'] ?? 0 );
		$zone = \WC_Shipping_Zones::get_zone_by( 'instance_id', $instance_id );
		if ( ! $zone ) { return mcp_wc_error( 'mcp_wc_shipping_method_not_found', 'Shipping method instance not found.' ); }
		$zone->delete_shipping_method( $instance_id );
		return array( 'deleted' => true, 'instance_id' => $instance_id );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function update_payment_gateway( array $input ) {
		$guard = self::guard( $input, 'woocommerce-mcp/payment-gateway-update' );
		if ( $guard ) { return $guard; }
		$gateway = WC()->payment_gateways()->payment_gateways()[ sanitize_key( (string) ( $input['id'] ?? '' ) ) ] ?? null;
		if ( ! $gateway ) { return mcp_wc_error( 'mcp_wc_payment_gateway_not_found', 'Payment gateway not found.' ); }
		$fields   = is_array( $gateway->form_fields ) ? $gateway->form_fields : array();
		$settings = get_option( $gateway->get_option_key(), array() );
		$settings = is_array( $settings ) ? $settings : array();
		foreach ( (array) ( $input['settings'] ?? array() ) as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( ! array_key_exists( $key, $fields ) || is_array( $value ) || is_object( $value ) ) { continue; }
			$settings[ $key ] = sanitize_text_field( (string) $value );
		}
		if ( isset( $input['enabled'] ) ) { $settings['enabled'] = $input['enabled'] ? 'yes' : 'no'; }
		update_option( $gateway->get_option_key(), $settings );
		$gateway->init_settings();
		return array( 'updated' => true, 'id' => (string) $gateway->id, 'enabled' => 'yes' === ( $settings['enabled'] ?? 'no' ) );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function save_shipping_class( array $input ) {
		$guard = self::guard( $input, 'woocommerce-mcp/shipping-class-save' );
		if ( $guard ) { return $guard; }
		$args = array( 'description' => sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ) );
		if ( isset( $input['name'] ) ) { $args['name'] = sanitize_text_field( (string) $input['name'] ); }
		if ( isset( $input['slug'] ) ) { $args['slug'] = sanitize_title( (string) $input['slug'] ); }
		$id = (int) ( $input['id'] ?? 0 );
		$result = $id > 0 ? wp_update_term( $id, 'product_shipping_class', $args ) : wp_insert_term( $args['name'] ?? '', 'product_shipping_class', $args );
		if ( is_wp_error( $result ) ) { return $result; }
		$term = get_term( (int) $result['term_id'], 'product_shipping_class' );
		return array( 'shipping_class' => self::format_term( $term ) );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function delete_shipping_class( array $input ) {
		$guard = self::guard( $input, 'woocommerce-mcp/shipping-class-delete' );
		if ( $guard ) { return $guard; }
		$id = (int) ( $input['id'] ?? 0 );
		if ( ! get_term( $id, 'product_shipping_class' ) ) { return mcp_wc_error( 'mcp_wc_shipping_class_not_found', 'Shipping class not found.' ); }
		$result = wp_delete_term( $id, 'product_shipping_class' );
		if ( is_wp_error( $result ) ) { return $result; }
		return array( 'deleted' => true, 'id' => $id );
	}

	private static function guard( array $input, string $ability ): ?WP_Error {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return mcp_wc_error( 'mcp_wc_forbidden_administration', 'You do not have permission to manage WooCommerce infrastructure.' ); }
		return MCP_WC_Ability_Execution_Module::require_confirmation( $input, $ability );
	}

	/** @return array<int,string> */
	private static function sanitize_country_codes( array $raw_codes ): array {
		$available = array_keys( WC()->countries->get_countries() );
		$codes     = array();
		foreach ( $raw_codes as $raw_code ) {
			$code = strtoupper( sanitize_text_field( (string) $raw_code ) );
			if ( in_array( $code, $available, true ) ) { $codes[] = $code; }
		}
		return array_values( array_unique( $codes ) );
	}

	/** @return array<string,mixed> */
	private static function format_term( WP_Term $term ): array {
		return array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'count' => (int) $term->count );
	}
}
