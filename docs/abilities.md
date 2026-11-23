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
| `product_type` | string | No | A product type of this shop: `simple`, `variable`, `grouped`, `external`, or one a plugin adds (subscription, bundle). Written as `examples`, not an enum; an unknown type is refused with the shop's types (1.14.0; before, an empty list) |
| `per_page` | integer | No | 1 to 100 (default: 20); `-1` gives 100 with a `note`; 0 and other negatives are refused (1.14.0) |
| `page` | integer | No | Page number, 1 or more (default: 1) |
| `category` | string | No | Product category slug. An unknown slug is refused, pointing to `wc_list_product_categories`; an ID or a name is refused with its slug (1.14.0) |
| `tag` | string | No | Product tag slug; an unknown one is refused (1.14.0) |
| `stock` | string | No | A stock status of this shop: `instock`, `outofstock`, `onbackorder`, or one a plugin adds. Written as `examples`; an unknown one is refused (1.14.0) |
| `min_price` / `max_price` | number | No | Price range, inclusive. `max_price: 0` lists the free products (1.14.0; it was ignored) |
| `search` | string | No | Words in the name or description, or part of a SKU (1.10.0) |
| `fields` | array | No | Only these fields of each product, plus `id`: `id`, `name`, `sku`, `type`, `status`, `price`, `regular_price`, `sale_price`, `stock_status`, `stock_qty`, `permalink`. An unknown name is left out and said in `fields_note`; with no known name the products are whole. Needs VGC AI Nexus 2.47.0 or later (1.11.0) |

**Returns:** `{ products: [...], total: N }`, plus `fields_note` when a name in `fields` did nothing, and `note` when the list is limited to the products the account may see, when products the account may not read were left out of the page (1.14.0), or when `per_page` was changed. Without `fields` the products are the same as before 1.11.0; since 1.12.0 (with VGC AI Nexus 2.48.0 or later) a list of 10,000 characters or more also has one line in `note` with its size and the size of the same list with `"fields": ["id", "name"]`.

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
| `type` | string | No | `simple`, `grouped`, `external` (default: `simple`); an enum since 1.14.0 |
| `status` | string | No | `publish`, `draft`, `private`, `pending` (default: `draft`) |
| `description` | string | No | Full description |
| `short_description` | string | No | Short description |
| `regular_price` | string | No | A plain number with "." for decimals, e.g. `"19.99"` ("," too on a shop that writes decimals with ","). Anything else is refused (1.14.0: "19,99" became 1999, "abc" erased the price). A grouped product's price is refused |
| `sale_price` | string | No | Written like `regular_price` |
| `sku` | string | No | Stock Keeping Unit; one another product has is refused by WooCommerce |
| `manage_stock` | boolean | No | Track the stock quantity |
| `stock_qty` | integer | No | Stock quantity; needs `manage_stock: true`, else refused (1.14.0: WooCommerce erased it) |
| `stock_status` | string | No | An enum of this shop's stock statuses (`instock`, `outofstock`, `onbackorder`, and any a plugin adds); another value is refused (1.14.0: it was dropped). With stock tracked, WooCommerce sets the status from the quantity |
| `categories` | array | No | Product category term IDs; an ID that is no product category is refused (1.14.0) |
| `tags` | array | No | Product tag term IDs; likewise |

> Note: `variable` products cannot be created via this tool — use the WooCommerce admin to create variable products and manage their variations.

**Returns:** `{ id, permalink, edit_url }`, plus `note` when markup was removed from a description (1.13.0), and since 1.14.0 the stored value of each price and stock field sent (`regular_price`, `sale_price`, `price`, `stock_status`, `stock_qty`, `manage_stock`, `sku`) with a `note` for any WooCommerce did not keep as sent.

**Descriptions (1.13.0).** `description` and `short_description` are stored as sent (style, script and form fields included) for an account that may post unfiltered HTML, such as an administrator, the rule VGC AI Nexus 2.49.0 uses for pages and posts. For anyone else (a shop manager has no `unfiltered_html` by default), or while raw code is locked on the site (`MCP_ABILITIES_DISABLE_CUSTOM_CODE` or the `mcp_abilities_custom_code_enabled` filter), script and other markup WordPress does not allow in posts is removed and `note` says so. The name, description and short description keep their backslashes.

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
| `regular_price` | string | No | New regular price, written as in `woocommerce-create-product`; `""` clears it |
| `sale_price` | string | No | New sale price; `""` ends the sale |
| `sku` | string | No | New SKU |
| `manage_stock` | boolean | No | Toggle stock management |
| `stock_qty` | integer | No | New stock quantity (below 0 for backorders); needs stock tracked |
| `stock_status` | string | No | An enum of this shop's stock statuses since 1.14.0; another value is refused (it was saved as `instock`) |
| `categories` | array | No | Replace category assignments (term IDs, checked) |
| `tags` | array | No | Replace tag assignments (term IDs, checked) |

A variation's `name`, `short_description`, `categories` and `tags`, and a variable or grouped product's prices, are refused: WooCommerce makes or takes them from elsewhere (1.14.0).

**Returns:** `{ message }`, plus `note` when markup was removed from a description (1.13.0), and since 1.14.0 the stored value of each price and stock field sent, with a `note` (and a message saying so) for any WooCommerce did not keep as sent, such as a stock status on a product that tracks stock. Descriptions follow the same rule as in `woocommerce-create-product`. A change to the name or status alone no longer takes the backslashes out of the description, short description and name (1.12.0 and earlier: WooCommerce rewrites the whole post then, unslashed).

---

### `woocommerce-delete-product`
Trash or permanently delete a product.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Product ID |
| `force` | boolean | No | Permanently delete (default: `false`) |

A trash or delete that WordPress or a plugin stopped is an error, and where the trash is turned off (`EMPTY_TRASH_DAYS` 0) a trash is refused, since it would delete for good; a product already in the trash answers `already_in_trash: true` (1.14.0).

---

## Product Taxonomy

### `woocommerce-list-product-categories`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `per_page` | integer | No | 1 to 200 (default: 50); `-1` gives 200 with a `note` |
| `page` | integer | No | Page number (default: 1) |
| `hide_empty` | boolean | No | Leave out categories with no products |
| `parent` | integer | No | Filter by parent category ID (`0` for top-level only) |

**Returns:** `{ categories: [{ id, name, slug, description, parent_id, count }], total: N }`; `total` is every matching category since 1.14.0 (it was the page's size).

Category and tag descriptions (create and update) keep the markup WordPress keeps in a term description (links, bold, italic, code, quotes) and their backslashes; when WordPress removes something (a `<p>`), the answer has a `note` and the stored `description` (1.14.0; before, every tag was taken out with no word).

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
| `per_page` | integer | No | 1 to 200 (default: 50); `-1` gives 200 with a `note` |
| `page` | integer | No | Page number (default: 1) |
| `search` | string | No | Filter by name |

**Returns:** `{ tags: [...], total: N }`; `total` is every matching tag since 1.14.0.

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
| `status` | string | No | `any` (every status, `checkout-draft` included) or an order status of this shop: `pending`, `processing`, `on-hold`, `completed`, `cancelled`, `refunded`, `failed`, `checkout-draft`, or one the shop adds (default: `any`). Written as `examples` since 1.14.0; an unknown status is refused with the shop's |
| `per_page` | integer | No | 1 to 100 (default: 20); `-1` gives 100 with a `note` |
| `page` | integer | No | Page number (default: 1) |
| `customer_id` | integer | No | Filter by customer user ID; `0` lists the guest orders (1.14.0; it listed every order); a negative ID is refused |
| `date_after` | string | No | A day, YYYY-MM-DD — orders placed after this day (site time, the day itself not included). A time of day is not used, and `note` says so (1.14.0) |
| `date_before` | string | No | A day, YYYY-MM-DD — orders placed before this day (site time, the day itself not included) |
| `search` | string | No | Search by order number, billing name or email |

**Returns:** `{ orders: [{ id, number, status, date_created, total, currency, customer_id, billing_email, billing_name, items_count, payment_method }], total: N }` (`total` since 1.14.0), plus `note` when orders the account may not read were left out of the page.

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
| `status` | string | **Yes** | An order status of this shop: `pending`, `processing`, `on-hold`, `completed`, `cancelled`, `refunded`, `failed`, or one the shop adds (`examples` since 1.14.0); an unknown one is refused |
| `note` | string | No | Optional order note added with the status change |

**Returns:** `{ message, status, changed }` (1.14.0). An order that already has the status keeps it, and the note is still added (`changed: false`; WooCommerce dropped the note); a change WooCommerce or a plugin refused is an error.

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

### `woocommerce-list-customer-orders`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | **Yes** | Customer user ID |
| `per_page` | integer | No | 1 to 50 (default: 10); `-1` gives 50 with a `note` |
| `page` | integer | No | Page number (default: 1; since 1.14.0) |

**Returns:** `{ orders: [...], total: N }` (`total` and `page` since 1.14.0: the list stopped at 50 with no way on).

---

## Coupon Management

### `woocommerce-list-coupons`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `per_page` | integer | No | 1 to 100 (default: 20); `-1` gives 100 with a `note` |
| `page` | integer | No | Page number (default: 1; since 1.14.0) |
| `search` | string | No | Search by coupon code |
| `status` | string | No | `publish`, `draft`, `pending`, `private`, `future`, `trash`, `any` (default: `publish`; since 1.14.0 — only published coupons were listed, with no word) |

**Returns:** `{ coupons: [{ id, code, type, amount, description, expiry_date, usage_count, usage_limit, minimum_amount, maximum_amount, free_shipping }], total: N }`

---

### `woocommerce-create-coupon`
| Parameter | Type | Required | Description |
|---|---|---|---|
| `code` | string | **Yes** | Unique coupon code |
| `amount` | number | **Yes** | Discount amount |
| `type` | string | No | `percent`, `fixed_cart`, `fixed_product` (default: `percent`) |
| `description` | string | No | Internal description |
| `expiry_date` | string | No | Expiry date, `YYYY-MM-DD`; the coupon expires at 00:00 (site time) at the start of that day, as in WooCommerce |
| `usage_limit` | integer | No | Max total uses (`0` = unlimited) |
| `usage_limit_per_user` | integer | No | Max uses per customer (`0` = unlimited) |
| `minimum_amount` | number | No | Minimum order amount |
| `maximum_amount` | number | No | Maximum order amount |
| `free_shipping` | boolean | No | Grant free shipping (default: `false`) |

**Validation:**
- `type` must be `percent`, `fixed_cart`, or `fixed_product`
- Percentage amount cannot exceed `100`
- Amount cannot be negative
- A code another coupon has is refused (1.14.0: a second coupon was made)
- An `expiry_date` that is not a real YYYY-MM-DD date is refused (1.14.0: it made the coupon already expired, 1970); a date that is not after today is taken, with a `note`
- `usage_limit` and `usage_limit_per_user` below 0 are refused (1.14.0: -1 became 1 use)

**Returns:** `{ id: N, code: "..." }`, plus the stored `expiry_date` when one was sent.
