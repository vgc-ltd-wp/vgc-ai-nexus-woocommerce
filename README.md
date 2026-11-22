# VGC AI Nexus for WooCommerce

Extends **[VGC AI Nexus](https://github.com/vgc-ltd-wp/vgc-ai-nexus)** with WooCommerce tools. Gives AI agents the ability to manage products, product categories, product tags, orders, customers and coupons through MCP.

**Version 1.12.0.**

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.0+ |
| PHP | 8.0+ |
| WooCommerce | 7.0+ |
| [MCP Adapter](https://github.com/WordPress/mcp-adapter) | Latest |
| [VGC AI Nexus](https://github.com/vgc-ltd-wp/vgc-ai-nexus) | 2.6.0+ |

## Installation

1. Ensure **MCP Adapter** and **VGC AI Nexus** are installed and active.
2. Upload the `vgc-ai-nexus-woocommerce` folder to `/wp-content/plugins/`.
3. Activate **VGC AI Nexus for WooCommerce** in *Plugins → Installed Plugins*.
4. Go to **AI Nexus → Extensions → WooCommerce** to enable or disable tool groups.

---

## Abilities

### Product Management

| Tool | Description |
|---|---|
| `woocommerce-list-products` | List products with filters for status (trash too), SKU (exact, several comma-separated), type, category, tag, stock and search (which also matches part of a SKU); `fields` returns only the fields named |
| `woocommerce-get-product` | Retrieve full product details including variants and attributes |
| `woocommerce-create-product` | Create a simple, grouped or external product |
| `woocommerce-update-product` | Update product details, pricing, stock and status |
| `woocommerce-delete-product` | Trash or permanently delete a product |

**A big product list says how to get a small one (1.12.0).** With VGC AI Nexus 2.48.0 or later, a list of whole products (no `fields`) of 10,000 characters or more has one line in `note`: its size, and the size of the same list with `"fields": ["id", "name"]`, worked out from the products themselves. For 100 products on the test site: 29,068 characters, against 5,688 with `["id", "name"]`. An AI may hold this tool's description from before 1.11.0 (a claude.ai connector keeps the first one it saw) and not know `fields` exists; the answer tells it. The products are unchanged, and lists under 10,000 characters (the default 20 products) get no note. Where the answer already has a `note` (a list limited to the products the account may see), this line follows it.

**Smaller product lists (1.11.0).** `fields` names the fields to return (`["id", "name", "sku"]`); each product then has `id` plus only those. For 100 products, `["id", "name", "sku"]` is 7,718 characters instead of 29,019 (3.8 times smaller), and the address of each product is worked out only when `permalink` is named. Names that are not product fields are left out and said in `fields_note`, which also lists the fields that can be named. Without `fields` the answer is unchanged. It uses VGC AI Nexus 2.47.0's list helpers; with an older core the products stay whole and `fields_note` says why.

**Finding products (1.10.0).** `sku` finds products by their exact SKU (several: comma-separated), and `search` also matches part of a SKU; both also find a variable product by one of its variations' SKUs. `status` `trash` lists the products in the trash. Any other status is refused: before 1.10.0 it quietly listed the published products, so a search of the trash looked empty.

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

**Each object is checked too (1.9.0).** Besides the tool's capability, every product, order, coupon and customer is checked against the caller's permission on that one object, the way WooCommerce's own screens do. Administrators and shop managers see no change; it matters for roles that may handle only their own products or orders (multi-vendor plugins, custom roles). Something the caller may not read answers like a missing one and is left out of lists.

- Products: reading follows AI Nexus core's rule (published products are readable; drafts, pending, private and trashed ones need `edit_post`); updating needs `edit_post`, deleting or trashing `delete_post`, and publishing or making private `publish_post`. A variation is checked on its parent product.
- Orders: `read_shop_order` / `edit_shop_order` on that order, or `manage_woocommerce` (WooCommerce's rule for its order screen, also with HPOS).
- Coupons: listed only when the caller may edit that coupon.
- Customers: `list_users` (what WooCommerce's REST API asks), or permission to edit that user.

- **Read-only mode and the input schema inside every call (1.9.1).** With AI Nexus's tool consolidation off, this extension registers each ability and MCP tool itself. Both now refuse a write in read-only mode and check the call against the tool's schema (types, required fields) right before running, as AI Nexus core's own tools do, also when nothing checked permission first. With consolidation on, core's group tool does both, as before.
- **Updates come only from the VGC update channel (1.9.1).** With AI Nexus 2.37.0 or later active, this extension uses core's updater: a package from another address, or one whose sha256 does not match the channel's manifest, is refused, and only an administrator's "Check again" on the Updates or Plugins screen skips the cached manifest. Without AI Nexus, or with an older one, the bundled updater works as before.

`woocommerce-delete-product` without `force` moves the product to the trash since 1.9.0; earlier versions deleted it for good (`wp_delete_post()` trashes only posts and pages).

Input validation on creation/update:
- Coupon type must be `percent`, `fixed_cart`, or `fixed_product`
- Percentage coupons cannot exceed 100%
- Order status values are restricted to valid WooCommerce states (1.9.0: `completed` and `cancelled` work again; earlier versions refused them and accepted the misspelt `ompleted`, which WooCommerce turns into Pending payment)
- Date filter values are validated before querying; `date_after` and `date_before` are whole days in site time, the day itself not included (`date_before` and `search` work since 1.9.0)

---

## Documentation

- [All Abilities Reference](docs/abilities.md)

---

## License

GPL v2 or later — https://www.gnu.org/licenses/gpl-2.0.html
