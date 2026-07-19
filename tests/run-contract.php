<?php
/** Lightweight contract checks that do not require a WordPress bootstrap. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

final class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_message(): string { return $this->message; }
}
class WP_User { public array $roles = array(); }

$abilities = array(); $existing = array(); $caps = array();
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function esc_url_raw( $value, $protocols = null ) { return filter_var( $value, FILTER_VALIDATE_URL ) ? (string) $value : ''; }
function wp_http_validate_url( $value ) { return false !== filter_var( $value, FILTER_VALIDATE_URL ); }
function wp_parse_url( $value, $component = -1 ) { return parse_url( $value, $component ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $capability, ...$args ) { global $caps; return ! empty( $caps[ $capability . ':' . implode( ':', $args ) ] ) || ! empty( $caps[ $capability ] ); }
function get_taxonomy( $taxonomy ) { return (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_' . $taxonomy ) ); }
function wp_has_ability( $name ) { global $abilities, $existing; return isset( $abilities[ $name ] ) || isset( $existing[ $name ] ); }
function wp_register_ability( $name, $args ) { global $abilities; $abilities[ $name ] = $args; }

require dirname( __DIR__ ) . '/includes/class-ability-execution-module.php';

$failures = array();
function expect_true( bool $condition, string $message ): void { global $failures; if ( ! $condition ) { $failures[] = $message; } }
function source( string $file ): string { return (string) file_get_contents( dirname( __DIR__ ) . '/' . $file ); }

MCP_WC_Ability_Execution_Module::register( 'woocommerce/sample', array(
	'label' => 'Sample', 'description' => 'Sample', 'input_schema' => array( 'type' => 'object' ),
	'output_schema' => array( 'type' => 'object', 'properties' => array( 'value' => array( 'type' => 'string' ) ) ),
	'execute_callback' => static fn(): array => array( 'value' => 'ok' ), 'permission_callback' => static fn(): bool => true,
) );
expect_true( isset( $abilities['woocommerce-mcp/sample'], $abilities['woocommerce/sample'] ), 'Canonical ability and legacy alias must both register.' );
expect_true( true === $abilities['woocommerce/sample']['meta']['deprecated'], 'Legacy alias must be marked deprecated.' );

MCP_WC_Ability_Execution_Module::register( 'woocommerce/optional-input', array(
	'label' => 'Optional input', 'description' => 'Optional input', 'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
	'output_schema' => array( 'type' => 'object' ), 'execute_callback' => static fn( array $input ): array => array( 'normalized' => $input ),
	'permission_callback' => static fn( array $input ): bool => array() === $input,
) );
$optional = $abilities['woocommerce-mcp/optional-input'];
expect_true( array( 'object', 'null' ) === $optional['input_schema']['type'], 'Optional object input must accept the null value emitted by MCP Adapter.' );
expect_true( true === $optional['permission_callback']( null ), 'Permission callbacks must normalize null optional input to an array.' );
expect_true( array( 'normalized' => array() ) === $optional['execute_callback']( null ), 'Execution callbacks must normalize null optional input to an array.' );

$existing['woocommerce/collision'] = true;
MCP_WC_Ability_Execution_Module::register( 'woocommerce/collision', array( 'label' => 'Collision', 'output_schema' => array( 'type' => 'object' ), 'execute_callback' => static fn(): array => array() ) );
expect_true( isset( $abilities['woocommerce-mcp/collision'] ) && ! isset( $abilities['woocommerce/collision'] ), 'A foreign legacy name must not suppress the canonical ability.' );

MCP_WC_Ability_Execution_Module::register( 'woocommerce/error-case', array( 'label' => 'Error', 'output_schema' => array( 'type' => 'object', 'properties' => array( 'value' => array( 'type' => 'string' ) ) ), 'execute_callback' => static fn(): array => array( 'error' => 'Expected failure.' ) ) );
$error = $abilities['woocommerce-mcp/error-case']['execute_callback']( array() );
expect_true( $error instanceof WP_Error && 'Expected failure.' === $error->get_error_message(), 'Legacy error arrays must become WP_Error before output validation.' );

MCP_WC_Ability_Execution_Module::register( 'woocommerce/throw-case', array( 'label' => 'Throw', 'output_schema' => array( 'type' => 'object' ), 'execute_callback' => static function(): array { throw new RuntimeException( 'secret /srv/site.php:99' ); } ) );
$error = $abilities['woocommerce-mcp/throw-case']['execute_callback']( array() );
expect_true( $error instanceof WP_Error && false === str_contains( $error->get_error_message(), '/srv/' ), 'Thrown exceptions must not expose paths or exception text.' );

expect_true( MCP_WC_Ability_Execution_Module::require_confirmation( array(), 'woocommerce-mcp/delete' ) instanceof WP_Error, 'Dangerous operations must reject absent confirmation.' );
expect_true( null === MCP_WC_Ability_Execution_Module::require_confirmation( array( 'confirm_dangerous_action' => 'woocommerce-mcp/delete' ), 'woocommerce-mcp/delete' ), 'Exact confirmation token must pass.' );
expect_true( MCP_WC_Ability_Execution_Module::validate_outbound_https_url( 'http://example.com/hook' ) instanceof WP_Error, 'Outbound URLs must require HTTPS.' );
expect_true( MCP_WC_Ability_Execution_Module::validate_outbound_https_url( 'https://127.0.0.1/hook' ) instanceof WP_Error, 'Outbound URLs must reject loopback targets.' );

$orders = source( 'includes/abilities-orders.php' ); $lifecycle = source( 'includes/class-order-lifecycle-module.php' );
$products = source( 'includes/abilities-products.php' ); $settings = source( 'includes/abilities-settings.php' );
$reports = source( 'includes/abilities-reports.php' ); $customers = source( 'includes/abilities-customers.php' );
$administration = source( 'includes/class-commerce-administration-module.php' ); $administration_abilities = source( 'includes/abilities-administration.php' );
$main = source( 'mcp-abilities-for-woocommerce.php' );
expect_true( str_contains( $lifecycle, "'order_id'      => \$order_id" ), 'Refund creation must pass order_id to wc_create_refund.' );
expect_true( str_contains( $products, "get_term( \$id, \$taxonomy )" ), 'Attribute term mutations must bind the term to the requested taxonomy.' );
expect_true( false === str_contains( $products, 'getFile()' ) && false === str_contains( $products, 'getLine()' ), 'Product failures must not expose filenames or line numbers.' );
expect_true( 0 === preg_match( "/'secret'\s*=>\s*\$webhook->get_secret/", $settings ), 'Webhook output must never expose the signing secret.' );
expect_true( str_contains( $settings, 'validate_outbound_https_url' ), 'Persistent webhook destinations must pass outbound URL validation.' );
expect_true( false === str_contains( $reports, "'limit'       => -1" ) && str_contains( $reports, "'max_orders'" ), 'Reports must be bounded.' );
expect_true( str_contains( $reports, "'refunded'" ) && str_contains( $reports, "'next_cursor_page'" ), 'Reports must include fully refunded orders and expose a continuation cursor.' );
expect_true( str_contains( $reports, 'get_total_refunded_for_item' ) && str_contains( $reports, 'get_qty_refunded_for_item' ), 'Product reports must account for refunded revenue and quantity.' );
expect_true( str_contains( $customers, 'is_customer_user' ), 'Customer mutations and direct lookups must enforce the customer role boundary.' );
expect_true( str_contains( $customers, 'wp_delete_user( (int) $user_id )' ), 'Failed customer creation must remove the partial user account.' );
expect_true( str_contains( $orders, "confirmation_schema( 'woocommerce-mcp/order-refund-create' )" ), 'Refunds must require explicit confirmation.' );
expect_true( str_contains( $lifecycle, 'get_qty_refunded_for_item' ) && str_contains( $lifecycle, 'get_remaining_refund_amount' ), 'Refund validation must use remaining refundable quantities and amounts.' );
expect_true( str_contains( $settings, 'WC_REST_Taxes_Controller' ) && false === str_contains( $settings, 'WC_Tax::find_rates' ), 'Administrative tax listing must use WooCommerce administrative pagination, not location matching.' );
expect_true( str_contains( $administration, 'sanitize_country_codes' ) && str_contains( $administration_abilities, 'shipping_country_codes' ), 'Store-country modes must persist explicit validated country lists.' );
expect_true( str_contains( $products, "confirmation_schema( 'woocommerce-mcp/product-update' )" ) && str_contains( $products, 'set_category_ids' ), 'Catalog mutations must use confirmed WooCommerce CRUD writes.' );
expect_true( false === str_contains( $main, "add_filter( 'woocommerce_currency_symbol'" ), 'The generic MCP plugin must not override storefront currency presentation.' );
expect_true( str_contains( $main, 'Version: 0.2.11' ) && str_contains( source( 'readme.txt' ), 'Stable tag: 0.2.11' ), 'Runtime and package versions must stay aligned.' );
expect_true( str_contains( $main, 'Requires Plugins: woocommerce' ) && false === str_contains( $main, 'woocommerce, abilities-api' ), 'WordPress 6.9 core Abilities support must not be declared as a separate plugin dependency.' );

$readme = source( 'README.md' );
$inventory_start = strpos( $readme, '## Complete Ability Inventory' );
$inventory_end   = strpos( $readme, '## Usage Examples' );
expect_true( false !== $inventory_start && false !== $inventory_end && $inventory_end > $inventory_start, 'README must contain a bounded complete ability inventory.' );
if ( false !== $inventory_start && false !== $inventory_end && $inventory_end > $inventory_start ) {
	$inventory = substr( $readme, $inventory_start, $inventory_end - $inventory_start );
	preg_match_all( '/`(woocommerce-mcp\/[a-z0-9-]+)`/', $inventory, $documented_matches );
	$documented = array_values( array_unique( $documented_matches[1] ?? array() ) );
	expect_true( 79 === count( $documented ), 'README inventory must contain exactly 79 unique canonical abilities.' );

	$registration_source = implode( "\n", array( $orders, $products, source( 'includes/abilities-coupons.php' ), $reports, $customers, $settings, source( 'includes/abilities-reviews.php' ), $administration_abilities ) );
	foreach ( $documented as $ability_name ) {
		$short_name = substr( $ability_name, strlen( 'woocommerce-mcp/' ) );
		expect_true( str_contains( $registration_source, "'woocommerce-mcp/{$short_name}'" ) || str_contains( $registration_source, "'woocommerce/{$short_name}'" ) || str_contains( $administration_abilities, "'{$short_name}'" ), 'Documented ability must have a source registration: ' . $ability_name );
	}
}
expect_true( str_contains( $readme, '**Stable version:** 0.2.11' ) && str_contains( $readme, '**Tested with WordPress:** 7.0' ), 'README release metadata must stay aligned.' );
expect_true( str_contains( $readme, '**Tags:** woocommerce, mcp, abilities, ai, automation' ), 'README tags must stay aligned with readme.txt.' );

if ( $failures ) { fwrite( STDERR, "Contract failures:\n- " . implode( "\n- ", $failures ) . "\n" ); exit( 1 ); }
echo "All contract checks passed.\n";
