<?php
/**
 * Coherent Order lifecycle operations for WooCommerce abilities.
 *
 * @package MCP_Abilities_WooCommerce
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MCP_WC_Order_Lifecycle_Module {
	/** @return array<string,mixed>|WP_Error */
	public static function create( array $input ) {
		$confirmation = MCP_WC_Ability_Execution_Module::require_confirmation( $input, 'woocommerce-mcp/order-create' );
		if ( $confirmation ) {
			return $confirmation;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return mcp_wc_error( 'mcp_wc_forbidden_order', 'You do not have permission to create Orders.' );
		}

		$line_items = self::resolve_line_items( $input['line_items'] ?? array() );
		if ( is_wp_error( $line_items ) ) {
			return $line_items;
		}

		$customer_id = (int) ( $input['customer_id'] ?? 0 );
		$customer_user = $customer_id > 0 ? get_user_by( 'id', $customer_id ) : false;
		if ( $customer_id > 0 && ( ! $customer_user || ! MCP_WC_Ability_Execution_Module::is_customer_user( $customer_user ) ) ) {
			return mcp_wc_error( 'mcp_wc_customer_not_found', 'The selected account is not a WooCommerce customer.' );
		}

		$order = null;
		try {
			$order = wc_create_order(
				array(
					'customer_id' => $customer_id,
					'status'      => sanitize_key( $input['status'] ?? 'pending' ),
				)
			);
			if ( is_wp_error( $order ) ) {
				return $order;
			}

			foreach ( $line_items as $line_item ) {
				$order->add_product( $line_item['product'], $line_item['quantity'] );
			}

			self::apply_addresses( $order, $input );
			self::apply_commercial_lines( $order, $input );
			$order->calculate_totals();
			$order->save();

			if ( ! empty( $input['note'] ) ) {
				$order->add_order_note( sanitize_textarea_field( $input['note'] ) );
			}

			return array( 'order' => mcp_wc_format_order( $order, true ) );
		} catch ( Throwable $throwable ) {
			if ( $order instanceof WC_Order && $order->get_id() > 0 ) {
				try { $order->delete( true ); } catch ( Throwable $cleanup_error ) { /* Cleanup is best-effort; keep the public failure generic. */ }
			}
			return mcp_wc_error( 'mcp_wc_order_create_failed', 'The Order could not be created; no partial Order was retained.' );
		}
	}

	/** @return array<string,mixed>|WP_Error */
	public static function update( array $input ) {
		$order_id = (int) ( $input['id'] ?? 0 );
		if ( ! MCP_WC_Ability_Execution_Module::can_edit_order( $order_id ) ) {
			return mcp_wc_error( 'mcp_wc_forbidden_order', 'You do not have permission to edit this Order.' );
		}
		$confirmation = MCP_WC_Ability_Execution_Module::require_confirmation( $input, 'woocommerce-mcp/order-update-status' );
		if ( $confirmation ) {
			return $confirmation;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return mcp_wc_error( 'mcp_wc_order_not_found', 'Order not found.' );
		}

		$status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : '';
		if ( '' !== $status && ! in_array( $status, mcp_wc_allowed_order_statuses(), true ) ) {
			return mcp_wc_error( 'mcp_wc_invalid_order_status', 'The requested Order status is not registered.' );
		}

		self::apply_addresses( $order, $input );
		if ( '' !== $status ) {
			$order->set_status( $status );
		}
		$order->save();

		$note_id = 0;
		if ( ! empty( $input['note'] ) ) {
			$note_id = (int) $order->add_order_note(
				sanitize_textarea_field( $input['note'] ),
				! empty( $input['customer_note'] ) ? 1 : 0,
				false
			);
		}

		$result = array( 'order' => mcp_wc_format_order( $order, true ) );
		if ( $note_id > 0 ) {
			$result['note_id'] = $note_id;
		}
		return $result;
	}

	/** @return array<string,mixed>|WP_Error */
	public static function update_items( array $input ) {
		$order_id = (int) ( $input['order_id'] ?? 0 );
		if ( ! MCP_WC_Ability_Execution_Module::can_edit_order( $order_id ) ) {
			return mcp_wc_error( 'mcp_wc_forbidden_order', 'You do not have permission to edit this Order.' );
		}
		$confirmation = MCP_WC_Ability_Execution_Module::require_confirmation( $input, 'woocommerce-mcp/order-items-update' );
		if ( $confirmation ) {
			return $confirmation;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return mcp_wc_error( 'mcp_wc_order_not_found', 'Order not found.' );
		}

		$resolved = self::resolve_line_items( $input['add_items'] ?? array(), true );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$remove_ids = array_map( 'absint', is_array( $input['remove_items'] ?? null ) ? $input['remove_items'] : array() );
		$existing   = $order->get_items( 'line_item' );
		foreach ( $remove_ids as $item_id ) {
			if ( ! isset( $existing[ $item_id ] ) ) {
				return mcp_wc_error( 'mcp_wc_order_item_not_found', 'A requested line item does not belong to this Order.' );
			}
		}

		foreach ( $remove_ids as $item_id ) {
			$order->remove_item( $item_id );
		}
		foreach ( $resolved as $line_item ) {
			$order->add_product( $line_item['product'], $line_item['quantity'] );
		}
		$order->calculate_totals();
		$order->save();

		return array( 'order' => mcp_wc_format_order( $order, true ) );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function create_refund( array $input ) {
		$order_id = (int) ( $input['order_id'] ?? 0 );
		if ( ! MCP_WC_Ability_Execution_Module::can_edit_order( $order_id ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return mcp_wc_error( 'mcp_wc_forbidden_refund', 'You do not have permission to refund this Order.' );
		}
		$confirmation = MCP_WC_Ability_Execution_Module::require_confirmation( $input, 'woocommerce-mcp/order-refund-create' );
		if ( $confirmation ) {
			return $confirmation;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return mcp_wc_error( 'mcp_wc_order_not_found', 'Order not found.' );
		}

		$requested_lines = is_array( $input['line_items'] ?? null ) ? $input['line_items'] : array();
		$args = array(
			'order_id'      => $order_id,
			'amount'        => isset( $input['amount'] ) ? (float) wc_format_decimal( $input['amount'] ) : ( array() === $requested_lines ? (float) $order->get_remaining_refund_amount() : 0.0 ),
			'reason'        => isset( $input['reason'] ) ? sanitize_text_field( $input['reason'] ) : '',
			'restock_items' => ! empty( $input['restock_items'] ),
			'refund_payment' => ! empty( $input['refund_payment'] ),
			'line_items'    => array(),
		);
		if ( $args['amount'] < 0 || $args['amount'] > (float) $order->get_remaining_refund_amount() ) {
			return mcp_wc_error( 'mcp_wc_invalid_refund_amount', 'The refund amount exceeds the remaining refundable amount.' );
		}

		$existing_items = $order->get_items( array( 'line_item', 'fee', 'shipping' ) );
		foreach ( $requested_lines as $item ) {
			$item_id = (int) ( $item['id'] ?? 0 );
			if ( ! isset( $existing_items[ $item_id ] ) ) {
				return mcp_wc_error( 'mcp_wc_refund_item_not_found', 'A refund line item does not belong to this Order.' );
			}
			$ordered_quantity = max( 1, abs( (int) $existing_items[ $item_id ]->get_quantity() ) );
			$already_refunded = abs( (int) $order->get_qty_refunded_for_item( $item_id, $existing_items[ $item_id ]->get_type() ) );
			$remaining_quantity = max( 0, $ordered_quantity - $already_refunded );
			$quantity = (int) ( $item['quantity'] ?? $remaining_quantity );
			if ( $quantity < 1 || $quantity > $remaining_quantity ) { return mcp_wc_error( 'mcp_wc_invalid_refund_quantity', 'A refund quantity exceeds the remaining refundable quantity.' ); }
			$remaining_line_total = max( 0.0, (float) $existing_items[ $item_id ]->get_total() - (float) $order->get_total_refunded_for_item( $item_id, $existing_items[ $item_id ]->get_type() ) );
			$refund_total = isset( $item['total'] ) ? (float) wc_format_decimal( $item['total'] ) : ( (float) $existing_items[ $item_id ]->get_total() / $ordered_quantity ) * $quantity;
			if ( $refund_total < 0 || $refund_total > $remaining_line_total ) { return mcp_wc_error( 'mcp_wc_invalid_refund_line_total', 'A refund line total exceeds the remaining refundable line amount.' ); }
			$refund_tax = array();
			if ( ! isset( $item['total'] ) ) {
				$taxes = $existing_items[ $item_id ]->get_taxes();
				foreach ( (array) ( $taxes['total'] ?? array() ) as $tax_id => $tax_total ) { $refund_tax[ $tax_id ] = ( (float) $tax_total / $ordered_quantity ) * $quantity; }
			}
			$args['line_items'][ $item_id ] = array(
				'qty'          => $quantity,
				'refund_total' => $refund_total,
				'refund_tax'   => $refund_tax,
			);
			if ( ! isset( $input['amount'] ) ) { $args['amount'] += $refund_total + array_sum( $refund_tax ); }
		}
		if ( $args['amount'] > (float) $order->get_remaining_refund_amount() ) {
			return mcp_wc_error( 'mcp_wc_invalid_refund_amount', 'The calculated refund exceeds the remaining refundable amount.' );
		}

		$refund = wc_create_refund( $args );
		if ( is_wp_error( $refund ) ) {
			return $refund;
		}
		return array( 'refund' => mcp_wc_format_refund( $refund ) );
	}

	/**
	 * @param mixed $raw_items
	 * @return array<int,array{product:WC_Product,quantity:int}>|WP_Error
	 */
	private static function resolve_line_items( $raw_items, bool $allow_empty = false ) {
		$items = is_array( $raw_items ) ? $raw_items : array();
		if ( ! $allow_empty && array() === $items ) {
			return mcp_wc_error( 'mcp_wc_empty_order', 'At least one valid line item is required.' );
		}
		if ( count( $items ) > 100 ) {
			return mcp_wc_error( 'mcp_wc_too_many_order_items', 'At most 100 line items may be changed in one request.' );
		}

		$resolved = array();
		foreach ( $items as $item ) {
			$product_id   = (int) ( $item['product_id'] ?? 0 );
			$variation_id = (int) ( $item['variation_id'] ?? 0 );
			$product      = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
			if ( ! $product ) {
				return mcp_wc_error( 'mcp_wc_product_not_found', 'Every line item must reference an existing product.' );
			}
			if ( $variation_id > 0 && (int) $product->get_parent_id() !== $product_id ) {
				return mcp_wc_error( 'mcp_wc_variation_parent_mismatch', 'A variation does not belong to the supplied parent product.' );
			}
			$resolved[] = array(
				'product'  => $product,
				'quantity' => max( 1, (int) ( $item['quantity'] ?? 1 ) ),
			);
		}
		return $resolved;
	}

	private static function apply_addresses( WC_Order $order, array $input ): void {
		foreach ( array( 'billing', 'shipping' ) as $address_type ) {
			if ( ! isset( $input[ $address_type ] ) || ! is_array( $input[ $address_type ] ) ) {
				continue;
			}
			foreach ( $input[ $address_type ] as $key => $value ) {
				$method = "set_{$address_type}_{$key}";
				if ( ! is_string( $value ) || ! method_exists( $order, $method ) ) {
					continue;
				}
				$clean = 'email' === $key ? sanitize_email( $value ) : sanitize_text_field( $value );
				$order->{$method}( $clean );
			}
		}
	}

	private static function apply_commercial_lines( WC_Order $order, array $input ): void {
		foreach ( array( 'coupon_lines', 'fee_lines', 'shipping_lines' ) as $collection ) {
			if ( isset( $input[ $collection ] ) && is_array( $input[ $collection ] ) && count( $input[ $collection ] ) > 100 ) {
				throw new \LengthException( 'At most 100 commercial lines are allowed per collection.' );
			}
		}
		if ( ! empty( $input['payment_method'] ) ) {
			$order->set_payment_method( sanitize_key( $input['payment_method'] ) );
		}
		if ( ! empty( $input['payment_method_title'] ) ) {
			$order->set_payment_method_title( sanitize_text_field( $input['payment_method_title'] ) );
		}
		if ( ! empty( $input['coupon_lines'] ) ) {
			if ( 'yes' !== get_option( 'woocommerce_enable_coupons', 'yes' ) ) {
				throw new RuntimeException( 'Coupons are disabled.' );
			}
			foreach ( $input['coupon_lines'] as $coupon ) {
				$result = $order->apply_coupon( sanitize_text_field( $coupon['code'] ) );
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( 'A coupon could not be applied.' );
				}
			}
		}
		foreach ( is_array( $input['fee_lines'] ?? null ) ? $input['fee_lines'] : array() as $fee ) {
			$item = new WC_Order_Item_Fee();
			$item->set_name( sanitize_text_field( $fee['name'] ) );
			$item->set_total( wc_format_decimal( $fee['total'] ) );
			if ( ! empty( $fee['tax_class'] ) ) {
				$item->set_tax_class( sanitize_key( $fee['tax_class'] ) );
			}
			$order->add_item( $item );
		}
		foreach ( is_array( $input['shipping_lines'] ?? null ) ? $input['shipping_lines'] : array() as $shipping ) {
			$item = new WC_Order_Item_Shipping();
			$item->set_method_title( sanitize_text_field( $shipping['method_title'] ) );
			$item->set_method_id( sanitize_key( $shipping['method_id'] ?? '' ) );
			$item->set_total( wc_format_decimal( $shipping['total'] ) );
			$order->add_item( $item );
		}
		foreach ( is_array( $input['meta_data'] ?? null ) ? $input['meta_data'] : array() as $meta ) {
			$key = sanitize_key( $meta['key'] ?? '' );
			if ( '' === $key || str_starts_with( $key, '_' ) ) {
				throw new RuntimeException( 'Protected Order meta keys are not accepted.' );
			}
			$order->add_meta_data( $key, sanitize_text_field( $meta['value'] ?? '' ), true );
		}
		if ( isset( $input['customer_note'] ) ) {
			$order->set_customer_note( sanitize_textarea_field( $input['customer_note'] ) );
		}
	}
}
