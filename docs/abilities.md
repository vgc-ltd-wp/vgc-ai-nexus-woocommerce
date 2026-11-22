# Abilities Reference — VGC AI Nexus for WooCommerce

All tools are prefixed `woocommerce-` in the MCP server.

---

## Product Management

### `woocommerce-list-products`
List WooCommerce products with optional filters.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `status` | string | No | `publish`, `draft`, `private`, `pending`, `trash`, `any` (default: `publish`; `any` is every status but trash). Any other value is refused (1.10.0; before, it quietly listed the published products) |
| `sku` | string | No | Exact SKU, or several separated by commas. A variation's SKU finds its parent product (1.10.0) |
| `product_type` | string | No | `simple`, `grouped`, `external` |
| `per_page` | integer | No | Max 100 (default: 20) |
| `page` | integer | No | Page number (default: 1) |
| `category_id` | integer | No | Filter by product category term ID |
| `tag_id` | integer | No | Filter by product tag term ID |
| `stock` | string | No | `instock`, `outofstock`, `onbackorder` |
| `search` | string | No | Words in the name or description, or part of a SKU (1.10.0) |
| `fields` | array | No | Only these fields of each product, plus `id`: `id`, `name`, `sku`, `type`, `status`, `price`, `regular_price`, `sale_price`, `stock_status`, `stock_qty`, `permalink`. An unknown name is left out and said in `fields_note`; with no known name the products are whole. Needs VGC AI Nexus 2.47.0 or later (1.11.0) |

**Returns:** `{ products: [...], total: N }`, plus `fields_note` when a name in `fields` did nothing, and `note` when the list is limited to the products the account may see. Without `fields` the products are the same as before 1.11.0; since 1.12.0 (with VGC AI Nexus 2.48.0 or later) a list of 10,000 characters or more also has one line in `note` with its size and the size of the same list with `"fields": ["id", "name"]`.

---

### `woocommerce-get-product`
Retrieve full product details.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Product ID |

**Returns:** Full product object including pricing, stock, attributes, variations summary, categories, tags, images, and meta.

---

### `woocommerce-create-product`
Create a new WooCommerce product.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `name` | string | **Yes** | Product name |
| `type` | string | No | `simple`, `grouped`, `external` (default: `simple`) |
| `status` | string | No | `publish`, `draft`, `private`, `pending` (default: `draft`) |
| `description` | string | No | Full description |
| `short_description` | string | No | Short description |
| `regular_price` | number | No | Regular price |
| `sale_price` | number | No | Sale price |
| `sku` | string | No | Stock Keeping Unit |
| `manage_stock` | boolean | No | Enable stock management |
| `stock_quantity` | integer | No | Stock quantity (requires `manage_stock: true`) |
| `stock_status` | string | No | `instock`, `outofstock`, `onbackorder` |
| `category_ids` | array | No | Array of product category term IDs |
| `tag_ids` | array | No | Array of product tag term IDs |
| `image_id` | integer | No | Featured image attachment ID |

> Note: `variable` products cannot be created via this tool — use the WooCommerce admin to create variable products and manage their variations.

**Returns:** `{ id: N }`

---

### `woocommerce-update-product`
Update an existing product.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Product ID |
| `name` | string | No | New name |
| `status` | string | No | `publish`, `draft`, `private`, `pending`, `trash` |
| `description` | string | No | New full description |
| `short_description` | string | No | New short description |
| `regular_price` | number | No | New regular price |
| `sale_price` | number | No | New sale price |
| `sku` | string | No | New SKU |
| `manage_stock` | boolean | No | Toggle stock management |
| `stock_quantity` | integer | No | New stock quantity |
| `stock_status` | string | No | `instock`, `outofstock`, `onbackorder` |
| `category_ids` | array | No | Replace category assignments |
| `tag_ids` | array | No | Replace tag assignments |

**Returns:** `{ id: N }`

---

### `woocommerce-delete-product`
Trash or permanently delete a product.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Product ID |
| `force` | boolean | No | Permanently delete (default: `false`) |

---

## Product Taxonomy

### `woocommerce-list-product-categories`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `per_page` | integer | No | Max 100 (default: 50) |
| `search` | string | No | Filter by name |
| `parent_id` | integer | No | Filter by parent category ID (`0` for top-level only) |

**Returns:** `{ categories: [{ id, name, slug, parent, count }] }`

---

### `woocommerce-create-product-category`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `name` | string | **Yes** | Category name |
| `slug` | string | No | URL slug |
| `description` | string | No | Category description |
| `parent_id` | integer | No | Parent category ID |

---

### `woocommerce-update-product-category`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Category term ID |
| `name` | string | No | New name |
| `slug` | string | No | New slug |
| `description` | string | No | New description |
| `parent_id` | integer | No | New parent ID |

---

### `woocommerce-delete-product-category`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Category term ID |

---

### `woocommerce-list-product-tags`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `per_page` | integer | No | Max 100 (default: 50) |
| `search` | string | No | Filter by name |

---

### `woocommerce-create-product-tag`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `name` | string | **Yes** | Tag name |
| `slug` | string | No | URL slug |
| `description` | string | No | Tag description |

---

### `woocommerce-update-product-tag`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Tag term ID |
| `name` | string | No | New name |
| `slug` | string | No | New slug |
| `description` | string | No | New description |

---

### `woocommerce-delete-product-tag`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Tag term ID |

---

## Order Management

### `woocommerce-list-orders`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `status` | string | No | `any`, `pending`, `processing`, `on-hold`, `completed`, `cancelled`, `refunded`, `failed` (default: `any`) |
| `per_page` | integer | No | Max 100 (default: 20) |
| `page` | integer | No | Page number (default: 1) |
| `customer_id` | integer | No | Filter by customer user ID |
| `date_after` | string | No | ISO8601 date — orders placed after this day (site time, the day itself not included) |
| `date_before` | string | No | ISO8601 date — orders placed before this day (site time, the day itself not included) |
| `search` | string | No | Search by order number, billing name or email |

**Returns:** `{ orders: [{ id, number, status, date_created, total, currency, customer_id, billing_email, billing_name, items_count, payment_method }] }`

Orders the account may not read (`read_shop_order` on the order, or `manage_woocommerce`) are left out; `get-order`, `update-order-status` and `add-order-note` answer "Order not found." for them (1.9.0).

---

### `woocommerce-get-order`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Order ID |

**Returns:** Full order including billing, shipping, all line items with SKUs, totals, tax, shipping cost, payment method, and customer note.

---

### `woocommerce-update-order-status`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Order ID |
| `status` | string | **Yes** | `pending`, `processing`, `on-hold`, `completed`, `cancelled`, `refunded`, `failed` |
| `note` | string | No | Optional order note added with the status change |

---

### `woocommerce-add-order-note`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Order ID |
| `note` | string | **Yes** | Note content |
| `customer_note` | boolean | No | `true` to make the note visible to the customer (default: `false`) |

**Returns:** `{ note_id: N }`

---

## Customer Management

### `woocommerce-get-customer`
Retrieve customer details by ID or email.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | No | Customer user ID |
| `email` | string | No | Customer email address |

Provide `id` or `email` — at least one is required.

**Returns:** User ID, email, name, billing address, shipping address, registration date, order count and total spend.

---

## Coupon Management

### `woocommerce-list-coupons`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `per_page` | integer | No | Max 100 (default: 20) |
| `search` | string | No | Search by coupon code |

**Returns:** `{ coupons: [{ id, code, type, amount, description, expiry_date, usage_count, usage_limit, minimum_amount, maximum_amount, free_shipping }], total: N }`

---

### `woocommerce-create-coupon`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `code` | string | **Yes** | Unique coupon code |
| `amount` | number | **Yes** | Discount amount |
| `type` | string | No | `percent`, `fixed_cart`, `fixed_product` (default: `percent`) |
| `description` | string | No | Internal description |
| `expiry_date` | string | No | Expiry date in `YYYY-MM-DD` format |
| `usage_limit` | integer | No | Max total uses (`0` = unlimited) |
| `usage_limit_per_user` | integer | No | Max uses per customer |
| `minimum_amount` | number | No | Minimum order amount |
| `maximum_amount` | number | No | Maximum order amount |
| `free_shipping` | boolean | No | Grant free shipping (default: `false`) |

**Validation:**
- `type` must be `percent`, `fixed_cart`, or `fixed_product`
- Percentage amount cannot exceed `100`
- Amount cannot be negative

**Returns:** `{ id: N, code: "..." }`
