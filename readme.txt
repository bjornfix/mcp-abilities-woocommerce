=== MCP Abilities for WooCommerce ===
Contributors: basicus
Tags: woocommerce, mcp, abilities, ai, automation
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 0.2.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure, structured WooCommerce management abilities for MCP clients through the WordPress Abilities API.

== Description ==

MCP Abilities for WooCommerce provides 79 canonical abilities under the `woocommerce-mcp/*` namespace.

Coverage includes:

* Products, variations, stock, metadata, categories, tags, attributes, and terms
* Orders, line items, notes, refunds, status changes, and transactional email resend
* Customers, coupons, and product reviews
* Currency-specific sales, product, customer, and stock reports
* Store settings, tax rates/classes, shipping zones/methods/classes, and payment gateways
* Webhooks, email settings, system status, and explicitly allowlisted system tools

The plugin uses WooCommerce CRUD/query APIs, supports High-Performance Order Storage, performs object-specific authorization, normalizes failures before schema validation, requires explicit confirmation for high-impact operations, and bounds collection/report workloads.

Persistent outbound URLs must use public HTTPS hosts. Webhook secrets are never returned. Protected product metadata and system tools are denied unless explicitly allowlisted with WordPress filters.

Historical `woocommerce/*` names remain deprecated compatibility aliases only when another component does not already own the name. New integrations should use `woocommerce-mcp/*`.

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate this plugin.
3. Connect an Abilities API-compatible MCP adapter and discover `woocommerce-mcp/*` abilities.

== Frequently Asked Questions ==

= Does this support HPOS? =

Yes. Order reads and reports use WooCommerce order APIs rather than direct post-table queries.

= Why do some writes require confirm_dangerous_action? =

Externally visible or destructive operations require the exact token declared by that ability. This prevents an MCP client from turning an unreviewed intent into a live mutation.

= Can the plugin expose protected product metadata? =

Not by default. Add only specific approved keys through the `mcp_wc_allowed_protected_product_meta_keys` filter.

= Can an MCP client run WooCommerce system tools? =

System tools are disabled by default. Explicitly allow only required tool IDs through the `mcp_wc_allowed_system_tools` filter.

== Changelog ==

= 0.2.11 =

* Added a shared execution-policy boundary with canonical naming and normalized errors.
* Added exact-object authorization, customer-role enforcement, and explicit confirmation contracts.
* Rebuilt order creation, updates, item changes, refunds, and related validation.
* Added bounded, currency-specific, refund-aware reports with resumable cursors.
* Added missing store, tax, shipping, payment-gateway, review, stock, and metadata management abilities.
* Hardened outbound URLs, webhook secrets, system tools, and collection limits.
* Added executable contract checks and synchronized package metadata.

= 0.2.0 =

* Previous public baseline.

== Upgrade Notice ==

= 0.2.11 =

Use the canonical `woocommerce-mcp/*` namespace. Review confirmation requirements and any protected-meta or system-tool allowlists before updating MCP workflows.
