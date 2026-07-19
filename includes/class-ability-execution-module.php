<?php
/**
 * Shared execution policy for WooCommerce MCP abilities.
 *
 * @package MCP_Abilities_WooCommerce
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MCP_WC_Ability_Execution_Module {
	private const CANONICAL_NAMESPACE = 'woocommerce-mcp/';

	/** @var array<string,string> */
	private static array $skipped_aliases = array();

	/**
	 * Register the canonical ability and, when safe, its legacy alias.
	 *
	 * @param string              $requested_name Previously public ability name.
	 * @param array<string,mixed> $args Ability definition.
	 */
	public static function register( string $requested_name, array $args ): void {
		$short_name     = preg_replace( '#^(?:woocommerce|woocommerce-mcp)/#', '', $requested_name );
		$canonical_name = self::CANONICAL_NAMESPACE . ( is_string( $short_name ) ? $short_name : $requested_name );

		if ( self::has_ability( $canonical_name ) ) {
			self::$skipped_aliases[ $canonical_name ] = 'canonical name already registered';
			return;
		}

		$args = self::with_execution_policy( $canonical_name, $args );
		wp_register_ability( $canonical_name, $args );

		if ( $requested_name === $canonical_name ) {
			return;
		}

		if ( self::has_ability( $requested_name ) ) {
			self::$skipped_aliases[ $requested_name ] = 'legacy alias owned by another plugin';
			return;
		}

		$alias_args                       = $args;
		$alias_args['label']              = (string) ( $args['label'] ?? $short_name ) . ' (legacy alias)';
		$alias_args['description']        = (string) ( $args['description'] ?? '' ) . ' Deprecated alias; use ' . $canonical_name . '.';
		$alias_args['meta']               = is_array( $args['meta'] ?? null ) ? $args['meta'] : array();
		$alias_args['meta']['deprecated'] = true;
		$alias_args['meta']['replacedBy'] = $canonical_name;
		wp_register_ability( $requested_name, $alias_args );
	}

	/**
	 * Normalize callback failures before the Abilities API validates success output.
	 *
	 * @param array<string,mixed> $args Ability definition.
	 * @return array<string,mixed>
	 */
	private static function with_execution_policy( string $ability_name, array $args ): array {
		$input_schema = is_array( $args['input_schema'] ?? null ) ? $args['input_schema'] : array();
		if ( 'object' === ( $input_schema['type'] ?? null ) && empty( $input_schema['required'] ) ) {
			$input_schema['type']   = array( 'object', 'null' );
			$input_schema['default'] = null;
			$args['input_schema']    = $input_schema;
		}

		$permission_callback = $args['permission_callback'] ?? null;
		if ( is_callable( $permission_callback ) ) {
			$args['permission_callback'] = static function ( $input = array() ) use ( $permission_callback ) {
				return call_user_func( $permission_callback, is_array( $input ) ? $input : array() );
			};
		}

		$callback = $args['execute_callback'] ?? null;
		if ( ! is_callable( $callback ) ) {
			return $args;
		}

		$output_properties = is_array( $args['output_schema']['properties'] ?? null )
			? $args['output_schema']['properties']
			: array();

		$args['execute_callback'] = static function ( $input = array() ) use ( $ability_name, $callback, $output_properties ) {
			try {
				$result = call_user_func( $callback, is_array( $input ) ? $input : array() );
			} catch ( Throwable $throwable ) {
				return self::error(
					'mcp_wc_execution_failed',
					'The WooCommerce operation failed. Review the target data and retry.',
					array( 'ability' => $ability_name )
				);
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( is_array( $result ) && isset( $result['error'] ) ) {
				$message = is_scalar( $result['error'] ) ? (string) $result['error'] : 'The WooCommerce operation failed.';
				return self::error( 'mcp_wc_operation_failed', $message, array( 'ability' => $ability_name ) );
			}

			if (
				is_array( $result )
				&& array_key_exists( 'success', $result )
				&& false === $result['success']
				&& ! array_key_exists( 'success', $output_properties )
			) {
				$message = is_scalar( $result['message'] ?? null ) ? (string) $result['message'] : 'The WooCommerce operation failed.';
				return self::error( 'mcp_wc_operation_failed', $message, array( 'ability' => $ability_name ) );
			}

			return $result;
		};

		return $args;
	}

	private static function has_ability( string $name ): bool {
		return function_exists( 'wp_has_ability' ) && wp_has_ability( $name );
	}

	/** @return array<string,string> */
	public static function skipped_aliases(): array {
		return self::$skipped_aliases;
	}

	/**
	 * Return a machine-readable execution failure that bypasses success-schema validation.
	 *
	 * @param array<string,mixed> $data Optional safe diagnostic data.
	 */
	public static function error( string $code, string $message, array $data = array() ): WP_Error {
		return new WP_Error( sanitize_key( $code ), $message, $data );
	}

	/**
	 * Require an exact confirmation token for an externally visible or destructive action.
	 */
	public static function require_confirmation( array $input, string $ability_name ): ?WP_Error {
		$provided = isset( $input['confirm_dangerous_action'] ) && is_string( $input['confirm_dangerous_action'] )
			? sanitize_text_field( $input['confirm_dangerous_action'] )
			: '';

		if ( hash_equals( $ability_name, $provided ) ) {
			return null;
		}

		return self::error(
			'mcp_wc_confirmation_required',
			'Set confirm_dangerous_action to "' . $ability_name . '" after reviewing the target and effect.'
		);
	}

	/** @return array<string,mixed> */
	public static function confirmation_schema( string $ability_name ): array {
		return array(
			'type'        => 'string',
			'const'       => $ability_name,
			'description' => 'Exact confirmation token required for live execution.',
		);
	}

	/**
	 * Authorize a product operation against the exact product object.
	 */
	public static function can_read_product( int $product_id ): bool {
		return function_exists( 'wc_rest_check_post_permissions' )
			? wc_rest_check_post_permissions( 'product', 'read', $product_id )
			: current_user_can( 'edit_post', $product_id );
	}

	public static function can_edit_product( int $product_id ): bool {
		return function_exists( 'wc_rest_check_post_permissions' )
			? wc_rest_check_post_permissions( 'product', 'edit', $product_id )
			: current_user_can( 'edit_post', $product_id );
	}

	public static function can_delete_product( int $product_id ): bool {
		return function_exists( 'wc_rest_check_post_permissions' )
			? wc_rest_check_post_permissions( 'product', 'delete', $product_id )
			: current_user_can( 'delete_post', $product_id );
	}

	/**
	 * Authorize an Order operation against its exact object ID.
	 */
	public static function can_edit_order( int $order_id ): bool {
		return function_exists( 'wc_rest_check_post_permissions' )
			? wc_rest_check_post_permissions( 'shop_order', 'edit', $order_id )
			: current_user_can( 'edit_shop_orders' ) && current_user_can( 'edit_post', $order_id );
	}

	public static function can_delete_order( int $order_id ): bool {
		return function_exists( 'wc_rest_check_post_permissions' )
			? wc_rest_check_post_permissions( 'shop_order', 'delete', $order_id )
			: current_user_can( 'delete_shop_orders' ) && current_user_can( 'delete_post', $order_id );
	}

	public static function can_edit_customer( int $user_id ): bool {
		return function_exists( 'wc_rest_check_user_permissions' )
			? wc_rest_check_user_permissions( 'edit', $user_id )
			: current_user_can( 'edit_users' ) && current_user_can( 'edit_user', $user_id );
	}

	public static function can_delete_customer( int $user_id ): bool {
		return function_exists( 'wc_rest_check_user_permissions' )
			? wc_rest_check_user_permissions( 'delete', $user_id )
			: current_user_can( 'delete_users' ) && current_user_can( 'delete_user', $user_id );
	}

	public static function can_manage_taxonomy( string $taxonomy ): bool {
		$taxonomy_object = get_taxonomy( $taxonomy );
		return $taxonomy_object && isset( $taxonomy_object->cap->manage_terms )
			&& current_user_can( $taxonomy_object->cap->manage_terms );
	}

	/**
	 * Only WooCommerce customer accounts belong to the customer interface.
	 */
	public static function is_customer_user( WP_User $user ): bool {
		return in_array( 'customer', (array) $user->roles, true );
	}

	/**
	 * Validate a persistent outbound destination.
	 *
	 * Blocks non-HTTPS destinations and hosts that resolve to private, reserved,
	 * loopback, link-local, or otherwise non-public IP addresses.
	 *
	 * @return string|WP_Error Normalized URL or failure.
	 */
	public static function validate_outbound_https_url( string $raw_url ) {
		$url = esc_url_raw( $raw_url, array( 'https' ) );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( '' === $url || 'https' !== $scheme || ! wp_http_validate_url( $url ) ) {
			return self::error( 'mcp_wc_invalid_outbound_url', 'A valid public HTTPS delivery URL is required.' );
		}

		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
		if ( '' === $host || 'localhost' === $host || str_ends_with( $host, '.localhost' ) ) {
			return self::error( 'mcp_wc_unsafe_outbound_host', 'The delivery URL host is not public.' );
		}

		$addresses = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$addresses[] = $host;
		} else {
			$ipv4 = gethostbynamel( $host );
			if ( is_array( $ipv4 ) ) {
				$addresses = array_merge( $addresses, $ipv4 );
			}
			if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
				$ipv6 = dns_get_record( $host, DNS_AAAA );
				if ( is_array( $ipv6 ) ) {
					foreach ( $ipv6 as $record ) {
						if ( isset( $record['ipv6'] ) ) {
							$addresses[] = (string) $record['ipv6'];
						}
					}
				}
			}
		}

		if ( array() === $addresses ) {
			return self::error( 'mcp_wc_unresolved_outbound_host', 'The delivery URL host could not be resolved.' );
		}

		foreach ( array_unique( $addresses ) as $address ) {
			$public = filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
			if ( false === $public ) {
				return self::error( 'mcp_wc_unsafe_outbound_host', 'The delivery URL must resolve only to public IP addresses.' );
			}
		}

		return $url;
	}
}

function mcp_wc_error( string $code, string $message, array $data = array() ): WP_Error {
	return MCP_WC_Ability_Execution_Module::error( $code, $message, $data );
}
