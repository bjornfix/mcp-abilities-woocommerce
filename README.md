# MCP Abilities for WooCommerce

Comprehensive WooCommerce abilities for MCP. Products, orders, coupons, customers, categories, tags, attributes, variations, reports, settings, shipping, tax, payment gateways, reviews, and webhooks.

[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-orange)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Release](https://img.shields.io/badge/Stable-1.0.2-green)](https://github.com/bjornfix/mcp-abilities-woocommerce/releases)

**Tags**: woocommerce, mcp, api, automation, ecommerce, products, orders, coupons, customers, reports

## What It Does

This plugin exposes comprehensive WooCommerce store management through the MCP Abilities API. It gives AI agents programmatic access to products, orders, coupons, customers, reports, settings, tax, shipping, payment gateways, webhooks, and reviews — everything a WooCommerce store operator needs.

## The Real Workflow

Without this plugin, managing a WooCommerce store requires logging into `/wp-admin/`, navigating multiple screens, filling forms, and manually checking reports. With MCP Abilities for WooCommerce, an AI agent can:

- Query products by SKU, type, stock status, category, or tag
- Create physical, virtual, digital, affiliate, or grouped products
- Manage product variations with attributes
- Process orders — create, update status, add notes, delete
- Create and manage discount coupons with product/category restrictions
- Look up customers with billing/shipping details and order history
- Pull sales overviews, top products, and customer reports
- Inspect tax rates, shipping zones, payment gateways, and webhooks
- Moderate product reviews
- Manage product categories, tags, and attributes

## Before vs After

**Before**: Click through WooCommerce admin screens, fill forms, copy-paste data.  
**After**: Ask an AI agent to do it. One query, one result.

## Who It Is For

- WooCommerce store operators who want AI-assisted management
- Developers building automation on top of WooCommerce
- AI agents and MCP-based workflows that need store data access

## Requirements

- WordPress 6.9+
- PHP 8.0+
- [Abilities API](https://github.com/WordPress/abilities-api) plugin
- [WooCommerce](https://wordpress.org/plugins/woocommerce/) 9.0+

## Documentation

- [Devenia Plugin Page](https://devenia.com/plugins/mcp-abilities-woocommerce/)

## Complete Ability Inventory (37 abilities)

**Products & Catalog (21)**
| Ability | Description |
|---|---|
| `woocommerce/products-query` | Find products by filters |
| `woocommerce/product-create` | Create any product type |
| `woocommerce/product-update` | Update product fields |
| `woocommerce/product-delete` | Delete or trash a product |
| `woocommerce/variations-query` | List variations |
| `woocommerce/variation-create` | Create a variation |
| `woocommerce/variation-update` | Update a variation |
| `woocommerce/variation-delete` | Delete a variation |
| `woocommerce/categories-query` | List categories |
| `woocommerce/category-create` | Create a category |
| `woocommerce/category-update` | Update a category |
| `woocommerce/category-delete` | Delete a category |
| `woocommerce/tags-query` | List tags |
| `woocommerce/tag-create` | Create a tag |
| `woocommerce/tag-update` | Update a tag |
| `woocommerce/tag-delete` | Delete a tag |
| `woocommerce/attributes-query` | List global attributes |
| `woocommerce/attribute-terms-query` | List attribute terms |
| `woocommerce/attribute-term-create` | Create attribute term |
| `woocommerce/attribute-term-update` | Update attribute term |
| `woocommerce/attribute-term-delete` | Delete attribute term |

**Orders (5)**
| Ability | Description |
|---|---|
| `woocommerce/orders-query` | Find orders by filters |
| `woocommerce/order-create` | Create a new order |
| `woocommerce/order-update-status` | Update order status |
| `woocommerce/order-add-note` | Add note to order |
| `woocommerce/order-delete` | Delete or trash an order |

**Coupons (4)**
| Ability | Description |
|---|---|
| `woocommerce/coupons-query` | List coupons |
| `woocommerce/coupon-create` | Create a coupon |
| `woocommerce/coupon-update` | Update a coupon |
| `woocommerce/coupon-delete` | Delete a coupon |

**Customers (1)**
| Ability | Description |
|---|---|
| `woocommerce/customers-query` | Find customers |

**Reports (4)**
| Ability | Description |
|---|---|
| `woocommerce/sales-overview` | Sales statistics |
| `woocommerce/product-report` | Top products by sales |
| `woocommerce/customer-report` | Top customers |
| `woocommerce/stock-report` | Stock status overview |

**Reviews (2)**
| Ability | Description |
|---|---|
| `woocommerce/reviews-query` | List product reviews |
| `woocommerce/review-update-status` | Moderate a review |

**Settings & Infrastructure (6)**
| Ability | Description |
|---|---|
| `woocommerce/store-settings` | Store configuration |
| `woocommerce/tax-rates-query` | Tax rates listing |
| `woocommerce/shipping-zones-query` | Shipping zones |
| `woocommerce/shipping-methods-query` | Shipping methods |
| `woocommerce/payment-gateways-query` | Payment gateways |
| `woocommerce/webhooks-query` | WooCommerce webhooks |

## Usage Examples

### Create a physical product

```json
{
  "product_type_alias": "physical",
  "name": "Widget Pro",
  "sku": "WDG-001",
  "regular_price": "29.99",
  "description": "Professional-grade widget",
  "stock_status": "instock"
}
```

### Query orders by date and status

```json
{
  "status": "processing",
  "date_after": "2026-07-01T00:00:00",
  "include_line_items": true,
  "per_page": 10
}
```

### Create a coupon

```json
{
  "code": "SUMMER25",
  "discount_type": "percent",
  "amount": "25",
  "description": "Summer sale 25% off",
  "minimum_amount": "50.00",
  "date_expires": "2026-08-31T23:59:59"
}
```

### Get sales overview

```json
{
  "date_after": "2026-07-01T00:00:00",
  "date_before": "2026-07-18T23:59:59"
}
```

## Safety & Ownership

- Every ability requires appropriate WordPress capabilities: `edit_products`, `edit_shop_orders`, `manage_woocommerce`, `manage_product_terms`, `list_users`, `view_woocommerce_reports`, `moderate_comments`
- All input is sanitized: text fields, SKUs, emails, URLs, prices, postal codes
- Product price inputs include regex validation for decimal format
- Product descriptions and short descriptions are run through `wp_kses_post`
- `additionalProperties: false` on all input schemas prevents injection of unexpected fields
- The plugin only activates when both the Abilities API and WooCommerce are active

## Installation

1. Install and activate [Abilities API](https://github.com/WordPress/abilities-api)
2. Install and activate [WooCommerce](https://wordpress.org/plugins/woocommerce/)
3. Upload `mcp-abilities-woocommerce` to `/wp-content/plugins/`
4. Activate through the 'Plugins' menu

## Changelog

### 1.0.0

Initial release with 37 abilities covering the full WooCommerce domain model.

## Contributing

Contributions are welcome. Please open an issue or PR on GitHub.

## License

GPL-2.0+

## Author

[basicus](https://profiles.wordpress.org/basicus/)
