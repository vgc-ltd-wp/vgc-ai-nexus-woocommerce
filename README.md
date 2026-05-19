# VGC AI Nexus for WooCommerce

Extends **[VGC AI Nexus](https://github.com/vgc-ltd-wp/vgc-ai-nexus)** with WooCommerce tools. Gives AI agents the ability to manage products, product categories, product tags, orders, customers and coupons through MCP.

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.0+ |
| PHP | 8.0+ |
| WooCommerce | 7.0+ |
| [MCP Adapter](https://wordpress.org/plugins/mcp-server/) | Latest |
| [VGC AI Nexus](https://github.com/vgc-ltd-wp/vgc-ai-nexus) | 2.6.0+ |

## Installation

1. Ensure **MCP Adapter** and **VGC AI Nexus** are installed and active.
2. Upload the `mcp-abilities-woocommerce` folder to `/wp-content/plugins/`.
3. Activate **VGC AI Nexus for WooCommerce** in *Plugins → Installed Plugins*.
4. Go to **AI Nexus → Extensions → WooCommerce** to enable or disable tool groups.

---

## Abilities

### Product Management

| Tool | Description |
|---|---|
| `woocommerce-list-products` | List products with filters for status, type, category, tag, stock and search |
| `woocommerce-get-product` | Retrieve full product details including variants and attributes |
| `woocommerce-create-product` | Create a simple, grouped or external product |
| `woocommerce-update-product` | Update product details, pricing, stock and status |
| `woocommerce-delete-product` | Trash or permanently delete a product |

### Product Taxonomy

| Tool | Description |
|---|---|
| `woocommerce-list-product-categories` | List product categories with parent/child hierarchy |
| `woocommerce-create-product-category` | Create a new product category |
| `woocommerce-update-product-category` | Update category name, slug or description |
| `woocommerce-delete-product-category` | Delete a product category |
| `woocommerce-list-product-tags` | List product tags |
| `woocommerce-create-product-tag` | Create a new product tag |
| `woocommerce-update-product-tag` | Update tag name or description |
| `woocommerce-delete-product-tag` | Delete a product tag |

### Order Management

| Tool | Description |
|---|---|
| `woocommerce-list-orders` | List orders with status, date, customer and search filters |
| `woocommerce-get-order` | Retrieve full order details including line items, billing and shipping |
| `woocommerce-update-order-status` | Change order status with an optional note |
| `woocommerce-add-order-note` | Add an internal or customer-facing note to an order |

### Customer Management *(privacy notice)*

| Tool | Description |
|---|---|
| `woocommerce-get-customer` | Retrieve customer profile, addresses and order stats by ID or email |

> ⚠️ Customer tools expose full PII including billing address, phone, email and spend history. Only enable for accounts that genuinely need this access.

### Coupon Management

| Tool | Description |
|---|---|
| `woocommerce-list-coupons` | List coupons with optional search |
| `woocommerce-create-coupon` | Create a percentage, fixed cart or fixed product coupon |

---

## Security

All tools enforce WooCommerce's own capability system:

- Product/taxonomy tools require `manage_woocommerce` or `edit_products`
- Order tools require `edit_shop_orders`
- Coupon tools require `manage_woocommerce`

Input validation on creation/update:
- Coupon type must be `percent`, `fixed_cart`, or `fixed_product`
- Percentage coupons cannot exceed 100%
- Order status values are restricted to valid WooCommerce states
- Date filter values are validated before querying

---

## Documentation

- [All Abilities Reference](docs/abilities.md)

---

## License

GPL v2 or later — https://www.gnu.org/licenses/gpl-2.0.html
