<?php
/**
 * WooCommerce product review abilities.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function mcp_wc_register_review_abilities(): void {
	if ( ! mcp_wc_is_active() ) {
		return;
	}

	mcp_wc_register_reviews_query();
	mcp_wc_register_review_update_status();
}

// ─── Reviews Query ───────────────────────────────────────────────────────────

function mcp_wc_register_reviews_query(): void {
	mcp_wc_register_ability( 'woocommerce/reviews-query', array(
		'label'               => 'Query reviews',
		'description'         => 'List product reviews with optional filters.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'         => array( 'type' => 'integer', 'minimum' => 1 ),
				'product_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'status'     => array( 'type' => 'string', 'enum' => array( 'hold', 'approve', 'spam', 'trash' ) ),
				'rating'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 5 ),
				'page'       => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page'   => array( 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100 ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'reviews'     => array( 'type' => 'array', 'items' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer' ),
						'product_id'  => array( 'type' => 'integer' ),
						'product_name' => array( 'type' => 'string' ),
						'status'      => array( 'type' => 'string' ),
						'reviewer'    => array( 'type' => 'string' ),
						'email'       => array( 'type' => 'string', 'format' => 'email' ),
						'rating'      => array( 'type' => array( 'integer', 'null' ) ),
						'review'      => array( 'type' => 'string' ),
						'date_created' => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
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
			if ( ! current_user_can( 'moderate_comments' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			if ( isset( $input['id'] ) ) {
				$review = get_comment( (int) $input['id'] );
				if ( ! $review || 'review' !== $review->comment_type ) {
					return array( 'reviews' => array(), 'total_pages' => 0, 'page' => 1, 'per_page' => (int) ( $input['per_page'] ?? 10 ) );
				}
				return array( 'reviews' => array( mcp_wc_format_review( $review ) ), 'total_pages' => 1, 'page' => 1, 'per_page' => 1 );
			}

			$page     = (int) ( $input['page'] ?? 1 );
			$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );
			$args     = array(
				'type'    => 'review',
				'number'  => $per_page,
				'offset'  => ( $page - 1 ) * $per_page,
				'status'  => $input['status'] ?? 'approve',
			);

			if ( isset( $input['product_id'] ) ) {
				$args['post_id'] = (int) $input['product_id'];
			}

			$query   = new \WP_Comment_Query( $args );
			$reviews = array();
			foreach ( $query->comments as $comment ) {
				$formatted = mcp_wc_format_review( $comment );
				if ( isset( $input['rating'] ) && $formatted['rating'] !== (int) $input['rating'] ) {
					continue;
				}
				$reviews[] = $formatted;
			}

			$total_count = wp_count_comments()->total_comments ?? 0;

			return array(
				'reviews'     => $reviews,
				'total_pages' => max( 1, (int) ceil( $total_count / $per_page ) ),
				'page'        => $page,
				'per_page'    => $per_page,
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'moderate_comments' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}

function mcp_wc_format_review( \WP_Comment $comment ): array {
	return array(
		'id'           => (int) $comment->comment_ID,
		'product_id'   => (int) $comment->comment_post_ID,
		'product_name' => get_the_title( $comment->comment_post_ID ),
		'status'       => wp_get_comment_status( $comment->comment_ID ),
		'reviewer'     => $comment->comment_author,
		'email'        => $comment->comment_author_email,
		'rating'       => (int) get_comment_meta( $comment->comment_ID, 'rating', true ) ?: null,
		'review'       => $comment->comment_content,
		'date_created' => $comment->comment_date_gmt ? gmdate( 'Y-m-d\TH:i:s', strtotime( $comment->comment_date_gmt ) ) : null,
	);
}

// ─── Review Update Status ────────────────────────────────────────────────────

function mcp_wc_register_review_update_status(): void {
	mcp_wc_register_ability( 'woocommerce/review-update-status', array(
		'label'               => 'Update review status',
		'description'         => 'Approve, unapprove, spam, or trash a product review.',
		'category'            => 'site',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'id'     => array( 'type' => 'integer', 'minimum' => 1 ),
				'status' => array( 'type' => 'string', 'enum' => array( 'approve', 'hold', 'spam', 'trash' ) ),
			),
			'required'             => array( 'id', 'status' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'review' => array( 'type' => 'object', 'properties' => array(
					'id'     => array( 'type' => 'integer' ),
					'status' => array( 'type' => 'string' ),
					'rating' => array( 'type' => array( 'integer', 'null' ) ),
				), 'additionalProperties' => false ),
			),
			'additionalProperties' => false,
		),
		'execute_callback'    => function ( array $input ): array {
			if ( ! current_user_can( 'moderate_comments' ) ) {
				return array( 'error' => 'Permission denied.' );
			}

			$review = get_comment( (int) $input['id'] );
			if ( ! $review || 'review' !== $review->comment_type ) {
				return array( 'error' => 'Review not found.' );
			}

			$result = wp_set_comment_status( $review->comment_ID, sanitize_text_field( $input['status'] ), true );

			if ( ! $result || is_wp_error( $result ) ) {
				return array( 'error' => is_wp_error( $result ) ? $result->get_error_message() : 'Failed to update review status.' );
			}

			return array(
				'review' => array(
					'id'     => (int) $review->comment_ID,
					'status' => wp_get_comment_status( $review->comment_ID ),
					'rating' => (int) get_comment_meta( $review->comment_ID, 'rating', true ) ?: null,
				),
			);
		},
		'permission_callback' => function (): bool {
			return current_user_can( 'moderate_comments' );
		},
		'meta'                => array(
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		),
	) );
}
