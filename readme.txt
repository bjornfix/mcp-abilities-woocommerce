=== MCP Abilities for WooCommerce ===
Contributors: basicus
Tags: woocommerce, mcp, api, automation, ecommerce
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Comprehensive WooCommerce abilities for MCP. Products, orders, coupons, customers, reports, and more via the Abilities API.

== Description ==

This add-on plugin extends the MCP ecosystem with comprehensive WooCommerce functionality. It enables AI agents and automation tools to manage every aspect of a WooCommerce store.

= Requirements =

* [Abilities API](https://github.com/WordPress/abilities-api) plugin
* [WooCommerce](https://wordpress.org/plugins/woocommerce/)

= Abilities Included =

**Products & Catalog**
* `woocommerce/products-query` - Find products by ID, search, SKU, status, type, stock status, category, or tag
* `woocommerce/product-create` - Create any product type (physical, virtual, digital, affiliate, grouped)
* `woocommerce/product-update` - Update product fields, prices, stock, categories, tags, and metadata
* `woocommerce/product-delete` - Delete or trash a product
* `woocommerce/variations-query` - List variations for a variable product
* `woocommerce/variation-create` - Create a product variation with attributes
* `woocommerce/variation-update` - Update variation price, stock, SKU
* `woocommerce/variation-delete` - Delete a variation
* `woocommerce/categories-query` - List product categories with search and filters
* `woocommerce/category-create` - Create a product category
* `woocommerce/category-update` - Update a product category
* `woocommerce/category-delete` - Delete a product category
* `woocommerce/tags-query` - List product tags
* `woocommerce/tag-create` - Create a product tag
* `woocommerce/tag-update` - Update a product tag
* `woocommerce/tag-delete` - Delete a product tag
* `woocommerce/attributes-query` - List global product attributes
* `woocommerce/attribute-terms-query` - List terms for an attribute
* `woocommerce/attribute-term-create` - Create an attribute term
* `woocommerce/attribute-term-update` - Update an attribute term
* `woocommerce/attribute-term-delete` - Delete an attribute term

**Orders**
* `woocommerce/orders-query` - Find orders by ID, status, customer, email, date range
* `woocommerce/order-create` - Create a new order with line items, billing, shipping
* `woocommerce/order-update-status` - Update an order status with optional note
* `woocommerce/order-add-note` - Add a public or private note to an order
* `woocommerce/order-delete` - Delete or trash an order

**Coupons**
* `woocommerce/coupons-query` - List coupons by ID, code, search, or discount type
* `woocommerce/coupon-create` - Create a coupon with products, categories, limits
* `woocommerce/coupon-update` - Update coupon fields, products, or restrictions
* `woocommerce/coupon-delete` - Delete a coupon

**Customers**
* `woocommerce/customers-query` - Find customers by ID, email, search, role, or date

**Reports**
* `woocommerce/sales-overview` - Sales, orders, items, tax, shipping, refunds, customers
* `woocommerce/product-report` - Top products by items sold or revenue
* `woocommerce/customer-report` - Top customers by total spent or order count
* `woocommerce/stock-report` - Stock status overview with low stock filter

**Settings & Infrastructure**
* `woocommerce/store-settings` - General store settings, currency, address, countries
* `woocommerce/tax-rates-query` - List tax rates by country or class
* `woocommerce/shipping-zones-query` - List shipping zones with methods and locations
* `woocommerce/shipping-methods-query` - List all available shipping methods
* `woocommerce/payment-gateways-query` - List payment gateways with enabled status
* `woocommerce/webhooks-query` - List WooCommerce webhooks

**Reviews**
* `woocommerce/reviews-query` - List product reviews by product, status, or rating
* `woocommerce/review-update-status` - Approve, unapprove, spam, or trash a review

= Use Cases =

* Manage products and catalog via AI agents
* Process and fulfill orders programmatically
* Monitor store performance with sales reports
* Track inventory and stock levels
* Manage tax rates and shipping zones
* Create and manage discount coupons
* Moderate product reviews
* Inspect WooCommerce webhooks and payment gateways

== Installation ==

1. Install and activate the [Abilities API](https://github.com/WordPress/abilities-api) plugin
2. Install and activate [WooCommerce](https://wordpress.org/plugins/woocommerce/)
3. Upload `mcp-abilities-woocommerce` to `/wp-content/plugins/`
4. Activate through the 'Plugins' menu
5. The abilities are now available via the MCP endpoint

== Changelog ==

= 1.0.4 =
* Register abilities at priority 1 so expanded schemas take precedence over WooCommerce core's built-in abilities.
* Variable, catalog_visibility, and attributes fields now available in product-create and product-update.

= 1.0.3 =
* Dynamic product type support: creation and update schemas now use `wc_get_product_types()` for all registered types including extensions (Subscriptions, Bookings, Bundles, etc.).
* Added variable product creation with attributes.
* Single generic input schema replaces the previous hardcoded oneOf branches.
* Added catalog_visibility, virtual, downloadable, and attributes fields to product creation.
* All property setters use method_exists() guards for extension type compatibility.

= 1.0.2 =
* Plugin name changed to "MCP Abilities for WooCommerce" for WordPress.org trademark compliance.
* Reduced readme tags to the maximum of 5 allowed.
* Shortened the short description to fit within the 150-character limit.

= 1.0.1 =
* Dynamic ability coexistence guard: mcp_wc_register_ability() wrapper checks wp_has_ability() so the plugin coexists with WooCommerce core abilities and other add-ons without re-registering names.
* Status, product type, currency, and discount type enums are now derived from WooCommerce functions instead of hardcoded arrays.

= 1.0.0 =
* Initial release with 37 abilities covering products, orders, coupons, customers, categories, tags, attributes, variations, reports, settings, shipping, tax, payment gateways, reviews, and webhooks.
