# Database — Promotion Module

## Table: `promotions`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| name | string (text) | | NOT NULL | Translatable JSON |
| slug | string(255) | | NOT NULL | Auto-generated from English name via Sluggable |
| code | string(255) | | NOT NULL, UNIQUE | Auto-generated (ALL/PRO prefix + random) |
| type | enum('price','quantity') | | NOT NULL | PromotionType enum |
| type_amount | enum('fixed_rate','percentage','gift') | | NOT NULL | PromotionMountType enum |
| value | decimal(10,2) | | NOT NULL | Discount value (synced with discount) |
| max_discount_amount | decimal(10,2) | NULL | | Cap for percentage discounts |
| required_quantity_type | integer | NULL | | Min quantity required |
| minimum_order_amount | decimal(10,2) | 0 | | Min order subtotal required |
| apply_to | string(255) | 'specific_products' | | `all_products` or `specific_products` |
| limiter | integer | NULL | | Max usage limit (null = unlimited) |
| usage | integer | 0 | | Current usage count |
| start_at | date | NULL | | Promotion start date |
| end_at | date | NULL | | Promotion end date |
| status | tinyint(1) | 1 | | 0 = inactive, 1 = active |
| discount | decimal(10,2) | NULL | | Synced with value (backward compat) |
| created_at | timestamp | NULL | | |
| updated_at | timestamp | NULL | | |

### Indexes
- Primary: `id`
- Unique: `code`
- Composite: `(status, start_at, end_at)` as `promotions_validity_index` — for `valid()` scope queries
- Composite: `(usage, limiter)` as `promotions_usage_limiter_index` — for usage tracking queries

### Translatable Columns
- `name` — stored as JSON: `{"en": "Summer Sale", "ar": "تخفيضات الصيف"}`

### Global Scope
- Default order: `created_at desc`

### Model Events
- `creating`: Auto-generates `code` if empty via `generateUniqueCode()` (prefix: ALL for all_products, PRO for specific_products)
- `saving`: Syncs `value` ⟷ `discount` (both fields always equal)

---

## Table: `promotion_product` (Pivot)

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| promotion_id | bigint unsigned | | FK → promotions.id ON DELETE CASCADE | |
| product_id | bigint unsigned | | FK → products.id ON DELETE CASCADE | |

### Indexes
- Primary: `id`
- Unique: `(promotion_id, product_id)` — prevents duplicate associations

### Foreign Keys
- `promotion_id` → `promotions.id` ON DELETE CASCADE
- `product_id` → `products.id` ON DELETE CASCADE

---

## Table: `promotion_gift_products` (Pivot)

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| promotion_id | bigint unsigned | | FK → promotions.id ON DELETE CASCADE | |
| product_id | bigint unsigned | | FK → products.id ON DELETE CASCADE | |
| product_variant_id | bigint unsigned | NULL | FK → product_variants.id ON DELETE CASCADE | Nullable (simple product or variant) |
| quantity | unsigned integer | 1 | | Gift item quantity |
| created_at | timestamp | NULL | | |
| updated_at | timestamp | NULL | | |

### Indexes
- Primary: `id`
- Unique: `(promotion_id, product_id)`
- Index: `product_id`
- Index: `product_variant_id`

### Foreign Keys
- `promotion_id` → `promotions.id` ON DELETE CASCADE
- `product_id` → `products.id` ON DELETE CASCADE
- `product_variant_id` → `product_variants.id` ON DELETE CASCADE (nullable)

---

## Related Tables

| Table | Relation | Column |
|-------|----------|--------|
| `products` | BelongsToMany (via promotion_product) | `promotion_product.product_id` → `products.id` |
| `giftProducts` | BelongsToMany (via promotion_gift_products) | `promotion_gift_products.product_id` → `products.id` |
| `product_variants` | Indirect (via promotion_gift_products) | `promotion_gift_products.product_variant_id` → `product_variants.id` |
| `media` | MorphMany | model_type=`Marvel\Database\Models\Promotion` |
| `cart_items` | HasMany (cart items with promotion_id) | `promotion_id` → `promotions.id` |
| `orders` | HasMany (orders with promotion_id) | `promotion_id` → `promotions.id` |

---

## Media Collections

| Collection | Disk | Purpose |
|------------|------|---------|
| `promotions-desktop` | `promotions` | Desktop promotion image |
| `promotions-mobile` | `promotions` | Mobile promotion image |

### Disk Configuration (`config/filesystems.php`)
The `promotions` disk is named `promotions` in the `MediaManager` trait upload methods, configured as a local disk with public visibility.

---

## Fillable Mass Assignment

```php
protected $fillable = [
    'name', 'slug', 'type', 'type_amount', 'value', 'discount',
    'max_discount_amount', 'code', 'required_quantity_type',
    'minimum_order_amount', 'apply_to', 'limiter', 'usage',
    'start_at', 'end_at', 'status'
];
```

---

## Casts

| Column | Cast Type |
|--------|-----------|
| start_at | date |
| end_at | date |
| status | boolean |
| usage | integer |
| limiter | integer |
| required_quantity_type | integer |
| value | float |
| discount | float |
| minimum_order_amount | float |
| max_discount_amount | float |

---

## Migration Files

| File | Table |
|------|-------|
| `packages/marvel/database/migrations/2020_04_29_000001_create_promotions_table.php` | `promotions` |
| `packages/marvel/database/migrations/2026_05_03_111116_create_promotion_product_table.php` | `promotion_product` |
| `packages/marvel/database/migrations/2026_05_17_000001_add_selected_promotion_checkout_fields.php` | `promotion_gift_products` |
| `packages/marvel/database/migrations/2026_07_18_000001_make_promotion_gift_product_variant_nullable.php` | Empty stub |
