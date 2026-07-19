<?php
/** WooCommerce product review abilities. */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) { exit; }

function mcp_wc_register_review_abilities(): void {
	if ( ! mcp_wc_is_active() ) { return; }
	mcp_wc_register_reviews_query();
	mcp_wc_register_review_create();
	mcp_wc_register_review_update();
	mcp_wc_register_review_delete();
}

function mcp_wc_review_schema(): array {
	return array( 'type' => 'object', 'properties' => array(
		'id' => array( 'type' => 'integer' ), 'product_id' => array( 'type' => 'integer' ), 'product_name' => array( 'type' => 'string' ),
		'status' => array( 'type' => 'string' ), 'reviewer' => array( 'type' => 'string' ), 'email' => array( 'type' => 'string', 'format' => 'email' ),
		'rating' => array( 'type' => array( 'integer', 'null' ) ), 'review' => array( 'type' => 'string' ),
		'date_created' => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
	), 'additionalProperties' => false );
}

function mcp_wc_register_reviews_query(): void {
	mcp_wc_register_ability( 'woocommerce-mcp/reviews-query', array(
		'label' => 'Query product reviews', 'description' => 'List product reviews with filters applied before pagination.', 'category' => 'site',
		'input_schema' => array( 'type' => 'object', 'properties' => array(
			'id' => array( 'type' => 'integer', 'minimum' => 1 ), 'product_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'status' => array( 'type' => 'string', 'enum' => array( 'all', 'hold', 'approve', 'spam', 'trash' ), 'default' => 'approve' ),
			'rating' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 5 ), 'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
			'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ),
		), 'additionalProperties' => false, 'default' => array() ),
		'output_schema' => array( 'type' => 'object', 'properties' => array(
			'reviews' => array( 'type' => 'array', 'items' => mcp_wc_review_schema() ), 'total_pages' => array( 'type' => 'integer' ),
			'page' => array( 'type' => 'integer' ), 'per_page' => array( 'type' => 'integer' ),
		), 'additionalProperties' => false ),
		'execute_callback' => function( array $input ) {
			$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );
			if ( isset( $input['id'] ) ) {
				$review = get_comment( (int) $input['id'] );
				return array( 'reviews' => $review && 'review' === $review->comment_type ? array( mcp_wc_format_review( $review ) ) : array(), 'total_pages' => $review ? 1 : 0, 'page' => 1, 'per_page' => 1 );
			}
			$page = max( 1, (int) ( $input['page'] ?? 1 ) );
			$args = array( 'type' => 'review', 'number' => $per_page, 'offset' => ( $page - 1 ) * $per_page, 'status' => $input['status'] ?? 'approve', 'count' => false );
			if ( isset( $input['product_id'] ) ) { $args['post_id'] = (int) $input['product_id']; }
			if ( isset( $input['rating'] ) ) {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- WooCommerce review ratings are comment meta; the administrative query is paginated to at most 100 records.
				$args['meta_key'] = 'rating'; $args['meta_value'] = (string) (int) $input['rating'];
			}
			$query = new WP_Comment_Query();
			$comments = $query->query( $args );
			$count_args = $args; $count_args['count'] = true; unset( $count_args['number'], $count_args['offset'] );
			$total = (int) ( new WP_Comment_Query() )->query( $count_args );
			return array( 'reviews' => array_map( 'mcp_wc_format_review', $comments ), 'total_pages' => (int) ceil( $total / $per_page ), 'page' => $page, 'per_page' => $per_page );
		},
		'permission_callback' => static fn(): bool => current_user_can( 'moderate_comments' ),
		'meta' => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
	) );
}

function mcp_wc_register_review_create(): void {
	mcp_wc_register_ability( 'woocommerce-mcp/review-create', array(
		'label' => 'Create product review', 'description' => 'Create a product review.', 'category' => 'site',
		'input_schema' => array( 'type' => 'object', 'properties' => array(
			'product_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'reviewer' => array( 'type' => 'string' ), 'email' => array( 'type' => 'string', 'format' => 'email' ),
			'review' => array( 'type' => 'string', 'minLength' => 1 ), 'rating' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 5 ),
			'status' => array( 'type' => 'string', 'enum' => array( 'hold', 'approve' ), 'default' => 'hold' ),
			'confirm_dangerous_action' => MCP_WC_Ability_Execution_Module::confirmation_schema( 'woocommerce-mcp/review-create' ),
		), 'required' => array( 'product_id', 'reviewer', 'email', 'review', 'rating', 'confirm_dangerous_action' ), 'additionalProperties' => false ),
		'output_schema' => array( 'type' => 'object', 'properties' => array( 'review' => mcp_wc_review_schema() ), 'additionalProperties' => false ),
		'execute_callback' => function( array $input ) {
			$confirmation = MCP_WC_Ability_Execution_Module::require_confirmation( $input, 'woocommerce-mcp/review-create' ); if ( $confirmation ) { return $confirmation; }
			$product = wc_get_product( (int) $input['product_id'] ); if ( ! $product ) { return mcp_wc_error( 'mcp_wc_product_not_found', 'Product not found.' ); }
			$id = wp_insert_comment( array( 'comment_post_ID' => $product->get_id(), 'comment_type' => 'review', 'comment_author' => sanitize_text_field( $input['reviewer'] ), 'comment_author_email' => sanitize_email( $input['email'] ), 'comment_content' => sanitize_textarea_field( $input['review'] ), 'comment_approved' => 'approve' === ( $input['status'] ?? 'hold' ) ? 1 : 0, 'user_id' => get_current_user_id() ) );
			if ( ! $id ) { return mcp_wc_error( 'mcp_wc_review_create_failed', 'The review could not be created.' ); }
			update_comment_meta( $id, 'rating', (int) $input['rating'] );
			return array( 'review' => mcp_wc_format_review( get_comment( $id ) ) );
		},
		'permission_callback' => static fn(): bool => current_user_can( 'moderate_comments' ),
		'meta' => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ) ),
	) );
}

function mcp_wc_register_review_update(): void {
	mcp_wc_register_ability( 'woocommerce-mcp/review-update', array(
		'label' => 'Update product review', 'description' => 'Update review content, rating, or moderation status.', 'category' => 'site',
		'input_schema' => array( 'type' => 'object', 'properties' => array(
			'id' => array( 'type' => 'integer', 'minimum' => 1 ), 'review' => array( 'type' => 'string' ), 'rating' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 5 ),
			'status' => array( 'type' => 'string', 'enum' => array( 'hold', 'approve', 'spam', 'trash' ) ),
			'confirm_dangerous_action' => MCP_WC_Ability_Execution_Module::confirmation_schema( 'woocommerce-mcp/review-update' ),
		), 'required' => array( 'id', 'confirm_dangerous_action' ), 'additionalProperties' => false ),
		'output_schema' => array( 'type' => 'object', 'properties' => array( 'review' => mcp_wc_review_schema() ), 'additionalProperties' => false ),
		'execute_callback' => function( array $input ) {
			$confirmation = MCP_WC_Ability_Execution_Module::require_confirmation( $input, 'woocommerce-mcp/review-update' ); if ( $confirmation ) { return $confirmation; }
			$review = get_comment( (int) $input['id'] ); if ( ! $review || 'review' !== $review->comment_type ) { return mcp_wc_error( 'mcp_wc_review_not_found', 'Review not found.' ); }
			if ( isset( $input['review'] ) ) { $result = wp_update_comment( array( 'comment_ID' => $review->comment_ID, 'comment_content' => sanitize_textarea_field( $input['review'] ) ), true ); if ( is_wp_error( $result ) ) { return $result; } }
			if ( isset( $input['rating'] ) ) { update_comment_meta( $review->comment_ID, 'rating', (int) $input['rating'] ); }
			if ( isset( $input['status'] ) ) { $result = wp_set_comment_status( $review->comment_ID, sanitize_key( $input['status'] ), true ); if ( is_wp_error( $result ) || ! $result ) { return is_wp_error( $result ) ? $result : mcp_wc_error( 'mcp_wc_review_update_failed', 'Review status could not be updated.' ); } }
			return array( 'review' => mcp_wc_format_review( get_comment( $review->comment_ID ) ) );
		},
		'permission_callback' => static function( array $input ): bool { return isset( $input['id'] ) && current_user_can( 'edit_comment', (int) $input['id'] ); },
		'meta' => array( 'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ) ),
	) );
}

function mcp_wc_register_review_delete(): void {
	mcp_wc_register_ability( 'woocommerce-mcp/review-delete', array(
		'label' => 'Delete product review', 'description' => 'Trash or permanently delete a product review.', 'category' => 'site',
		'input_schema' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ), 'force' => array( 'type' => 'boolean', 'default' => false ), 'confirm_dangerous_action' => MCP_WC_Ability_Execution_Module::confirmation_schema( 'woocommerce-mcp/review-delete' ) ), 'required' => array( 'id', 'confirm_dangerous_action' ), 'additionalProperties' => false ),
		'output_schema' => array( 'type' => 'object', 'properties' => array( 'deleted' => array( 'type' => 'boolean' ), 'id' => array( 'type' => 'integer' ) ), 'additionalProperties' => false ),
		'execute_callback' => function( array $input ) { $confirmation = MCP_WC_Ability_Execution_Module::require_confirmation( $input, 'woocommerce-mcp/review-delete' ); if ( $confirmation ) { return $confirmation; } $review = get_comment( (int) $input['id'] ); if ( ! $review || 'review' !== $review->comment_type ) { return mcp_wc_error( 'mcp_wc_review_not_found', 'Review not found.' ); } return array( 'deleted' => (bool) wp_delete_comment( $review->comment_ID, ! empty( $input['force'] ) ), 'id' => (int) $review->comment_ID ); },
		'permission_callback' => static function( array $input ): bool { return isset( $input['id'] ) && current_user_can( 'delete_comment', (int) $input['id'] ); },
		'meta' => array( 'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ) ),
	) );
}

function mcp_wc_format_review( WP_Comment $comment ): array {
	return array( 'id' => (int) $comment->comment_ID, 'product_id' => (int) $comment->comment_post_ID, 'product_name' => get_the_title( $comment->comment_post_ID ), 'status' => wp_get_comment_status( $comment->comment_ID ), 'reviewer' => $comment->comment_author, 'email' => $comment->comment_author_email, 'rating' => (int) get_comment_meta( $comment->comment_ID, 'rating', true ) ?: null, 'review' => $comment->comment_content, 'date_created' => $comment->comment_date_gmt ? gmdate( 'Y-m-d\TH:i:s', strtotime( $comment->comment_date_gmt ) ) : null );
}
