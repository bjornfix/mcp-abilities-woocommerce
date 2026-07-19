# MCP Abilities for WooCommerce

[![Latest release](https://img.shields.io/github/v/release/bjornfix/mcp-abilities-for-woocommerce?sort=semver)](https://github.com/bjornfix/mcp-abilities-for-woocommerce/releases)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-21759b.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)](https://www.php.net/)

Secure, structured WooCommerce management for MCP clients through the WordPress Abilities API.

**Stable version:** 0.2.11<br>
**Tested with WordPress:** 7.0<br>
**License:** GPL-2.0-or-later<br>
**Tags:** woocommerce, mcp, abilities, ai, automation

Version 0.2.11 exposes 79 canonical abilities under `woocommerce-mcp/*`. They cover products, orders, customers, coupons, reviews, reports, store configuration, tax, shipping, payment gateways, webhooks, and operational diagnostics.

## What It Does

The plugin turns WooCommerce's management surface into typed, discoverable abilities for compatible MCP clients. An authorized operator can search a catalogue, create or update commercial records, inspect operations, and administer core store infrastructure without relying on fragile screen automation.

The abilities use WooCommerce CRUD and query APIs, apply object-level authorization, validate input and output contracts, and return normalized errors that an MCP client can act on reliably.

## The Real Workflow

1. An MCP client discovers the `woocommerce-mcp/*` abilities and their JSON schemas.
2. The client selects the narrow ability that matches the task.
3. WordPress checks the current user's capability against the exact target object where applicable.
4. High-impact operations require the exact confirmation token declared by that ability.
5. WooCommerce performs the operation through its native data APIs.
6. The ability returns a bounded, structured result or a machine-readable `WP_Error`.

## Why This Feels Different

- One canonical namespace makes tool discovery predictable.
- Exact schemas replace loosely structured requests.
- Native WooCommerce authorization protects the actual product, order, customer, review, or taxonomy target.
- Confirmation contracts make externally visible and destructive actions explicit.
- Bounded collections and resumable reports remain usable on larger stores.
- High-Performance Order Storage is supported because order work uses WooCommerce APIs rather than direct post-table queries.
- Legacy `woocommerce/*` names remain available only when no other plugin owns them, preventing silent name collisions.

## Before vs After

| Before | With MCP Abilities for WooCommerce |
|---|---|
| Client-specific, undocumented store calls | Discoverable abilities with input and output schemas |
| Broad role checks for sensitive mutations | Native authorization against the exact object |
| Destructive calls can look like ordinary writes | Exact confirmation tokens for high-impact operations |
| Large queries can run without a clear bound | Pagination, hard limits, scan caps, and resumable report cursors |
| Integration failures leak inconsistent result shapes | Normalized `WP_Error` failures before output validation |
| Direct order table assumptions | WooCommerce CRUD/query APIs compatible with HPOS |

## Who It Is For

- WooCommerce operators connecting an MCP-compatible automation client.
- Developers building reviewed store-management workflows on the WordPress Abilities API.
- Agencies that need a consistent, capability-aware interface across WooCommerce sites.
- Operations teams that want structured catalogue, order, reporting, and configuration tools without browser automation.

## Requirements

- WordPress 6.9 or newer
- PHP 8.0 or newer
- WooCommerce
- An Abilities API-compatible MCP adapter or client integration

## Documentation

- [Plugin documentation and overview](https://devenia.com/plugins/mcp-abilities-for-woocommerce/)
- [GitHub releases](https://github.com/bjornfix/mcp-abilities-for-woocommerce/releases)
- [Stable plugin download](https://downloads.devenia.com/mcp-abilities-for-woocommerce.zip)
- [WordPress Abilities API](https://developer.wordpress.org/news/2025/11/introducing-the-wordpress-abilities-api/)

## Start Here

1. Install and activate WooCommerce. WordPress 6.9 or newer already includes the server-side Abilities API.
2. Install and activate this plugin.
3. Connect an Abilities API-compatible MCP adapter.
4. Discover abilities under `woocommerce-mcp/*`.
5. Begin with a read-only query such as `woocommerce-mcp/products-query`.
6. Review the schema and exact confirmation value before enabling any mutation workflow.

## Complete Ability Inventory

### Products, variations, taxonomy, metadata, and stock (28)

- `woocommerce-mcp/products-query`
- `woocommerce-mcp/product-create`
- `woocommerce-mcp/product-update`
- `woocommerce-mcp/product-delete`
- `woocommerce-mcp/variations-query`
- `woocommerce-mcp/variation-create`
- `woocommerce-mcp/variation-update`
- `woocommerce-mcp/variation-delete`
- `woocommerce-mcp/categories-query`
- `woocommerce-mcp/category-create`
- `woocommerce-mcp/category-update`
- `woocommerce-mcp/category-delete`
- `woocommerce-mcp/tags-query`
- `woocommerce-mcp/tag-create`
- `woocommerce-mcp/tag-update`
- `woocommerce-mcp/tag-delete`
- `woocommerce-mcp/attributes-query`
- `woocommerce-mcp/attribute-create`
- `woocommerce-mcp/attribute-update`
- `woocommerce-mcp/attribute-delete`
- `woocommerce-mcp/attribute-terms-query`
- `woocommerce-mcp/attribute-term-create`
- `woocommerce-mcp/attribute-term-update`
- `woocommerce-mcp/attribute-term-delete`
- `woocommerce-mcp/product-meta-query`
- `woocommerce-mcp/product-meta-update`
- `woocommerce-mcp/product-duplicate`
- `woocommerce-mcp/products-bulk-stock`

### Orders (9)

- `woocommerce-mcp/orders-query`
- `woocommerce-mcp/order-create`
- `woocommerce-mcp/order-update-status`
- `woocommerce-mcp/order-delete`
- `woocommerce-mcp/order-refunds-query`
- `woocommerce-mcp/order-refund-create`
- `woocommerce-mcp/order-notes-query`
- `woocommerce-mcp/order-items-update`
- `woocommerce-mcp/order-resend-email`

### Coupons (4)

- `woocommerce-mcp/coupons-query`
- `woocommerce-mcp/coupon-create`
- `woocommerce-mcp/coupon-update`
- `woocommerce-mcp/coupon-delete`

### Customers (4)

- `woocommerce-mcp/customers-query`
- `woocommerce-mcp/customer-create`
- `woocommerce-mcp/customer-update`
- `woocommerce-mcp/customer-delete`

### Reports (4)

- `woocommerce-mcp/sales-overview`
- `woocommerce-mcp/product-report`
- `woocommerce-mcp/customer-report`
- `woocommerce-mcp/stock-report`

### Store and operational settings (15)

- `woocommerce-mcp/store-settings`
- `woocommerce-mcp/tax-rates-query`
- `woocommerce-mcp/shipping-zones-query`
- `woocommerce-mcp/shipping-methods-query`
- `woocommerce-mcp/payment-gateways-query`
- `woocommerce-mcp/webhooks-query`
- `woocommerce-mcp/webhook-create`
- `woocommerce-mcp/webhook-update`
- `woocommerce-mcp/webhook-delete`
- `woocommerce-mcp/shipping-classes-query`
- `woocommerce-mcp/tax-classes-query`
- `woocommerce-mcp/system-status`
- `woocommerce-mcp/system-tools-query`
- `woocommerce-mcp/system-tool-run`
- `woocommerce-mcp/email-settings`

### Store infrastructure mutations (11)

- `woocommerce-mcp/store-settings-update`
- `woocommerce-mcp/tax-rate-save`
- `woocommerce-mcp/tax-rate-delete`
- `woocommerce-mcp/shipping-zone-save`
- `woocommerce-mcp/shipping-zone-delete`
- `woocommerce-mcp/shipping-method-add`
- `woocommerce-mcp/shipping-method-update`
- `woocommerce-mcp/shipping-method-delete`
- `woocommerce-mcp/payment-gateway-update`
- `woocommerce-mcp/shipping-class-save`
- `woocommerce-mcp/shipping-class-delete`

### Product reviews (4)

- `woocommerce-mcp/reviews-query`
- `woocommerce-mcp/review-create`
- `woocommerce-mcp/review-update`
- `woocommerce-mcp/review-delete`

## Usage Examples

### Query published products

```json
{
  "ability": "woocommerce-mcp/products-query",
  "input": {
    "status": "publish",
    "per_page": 25,
    "page": 1
  }
}
```

### Create a product with explicit confirmation

```json
{
  "ability": "woocommerce-mcp/product-create",
  "input": {
    "name": "Replacement hydraulic valve",
    "type": "simple",
    "regular_price": "249.00",
    "status": "draft",
    "confirm_dangerous_action": "woocommerce-mcp/product-create"
  }
}
```

### Create a partial refund

```json
{
  "ability": "woocommerce-mcp/order-refund-create",
  "input": {
    "order_id": 123,
    "amount": "25.00",
    "reason": "Agreed price adjustment",
    "confirm_dangerous_action": "woocommerce-mcp/order-refund-create"
  }
}
```

### Continue a bounded sales report

```json
{
  "ability": "woocommerce-mcp/sales-overview",
  "input": {
    "currency": "EUR",
    "date_after": "2026-01-01T00:00:00Z",
    "max_orders": 1000,
    "cursor_page": 1
  }
}
```

When `has_more` is `true`, pass `next_cursor_page` as the next request's `cursor_page`.

## Safety and Ownership Boundaries

- WordPress authentication and WooCommerce capabilities remain the authorization source of truth.
- Product, order, customer, review, and taxonomy mutations use exact-object or native WooCommerce permission checks where applicable.
- Externally visible and destructive operations require the exact `confirm_dangerous_action` token declared in their input schema.
- Persistent outbound URLs for webhooks, external products, and downloads must use public HTTPS hosts. Private, reserved, loopback, unresolved, and mixed public/private destinations are rejected.
- Webhook secrets are accepted when required but never returned by read abilities.
- Product metadata is limited to public keys by default. Protected keys require the `mcp_wc_allowed_protected_product_meta_keys` filter.
- WooCommerce system tools are disabled by default. Approved tool IDs must be added through `mcp_wc_allowed_system_tools`.
- Collection abilities are paginated and bounded. Reports cap their scan and expose a continuation cursor.
- The plugin manages WooCommerce data and settings only; the MCP adapter owns transport, authentication handoff, and client discovery.
- New integrations should use `woocommerce-mcp/*`. Deprecated `woocommerce/*` aliases are registered only when another component does not already own the name.

## Installation

### WordPress admin

1. Download the [stable ZIP](https://downloads.devenia.com/mcp-abilities-for-woocommerce.zip).
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the ZIP and activate the plugin.
4. Confirm that WooCommerce is active and WordPress is version 6.9 or newer.

### WP-CLI

```bash
wp plugin install mcp-abilities-for-woocommerce.zip --activate
```

## Development and Verification

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
php tests/run-contract.php
git diff --check
```

Release candidates must also pass WordPress Plugin Check on a development WordPress site with WooCommerce and the Abilities API active.

## Recent Changes

### 0.2.11

- Added a shared execution-policy boundary with canonical naming, safe compatibility aliases, normalized errors, and adapter-safe optional inputs.
- Added exact-object authorization and customer-role boundaries.
- Rebuilt order mutations and refunds around coherent WooCommerce CRUD operations.
- Added bounded, currency-specific, refund-aware reports with resumable cursors.
- Added store, tax, shipping, payment-gateway, shipping-class, review, stock, and protected-meta management coverage.
- Hardened persistent outbound destinations, webhook secrets, system tools, and collection limits.
- Added executable contract checks and aligned package metadata.

See [all releases](https://github.com/bjornfix/mcp-abilities-for-woocommerce/releases) for the complete history.

## Contributing

Issues and focused pull requests are welcome. Include a reproducible case, preserve backward compatibility where practical, and add contract coverage for changes to schemas, permissions, confirmation rules, or output shapes. Every release must pass PHP lint, the executable contract suite, WordPress Plugin Check, and a development-site runtime check.

## License

Licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html).

## Author

[basicus](https://profiles.wordpress.org/basicus/)

## Links

- [Plugin page](https://devenia.com/plugins/mcp-abilities-for-woocommerce/)
- [Source repository](https://github.com/bjornfix/mcp-abilities-for-woocommerce)
- [Releases](https://github.com/bjornfix/mcp-abilities-for-woocommerce/releases)
- [Stable download](https://downloads.devenia.com/mcp-abilities-for-woocommerce.zip)
