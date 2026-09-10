# Database - Promotion Feature

## Tables

### 1. `promotions` Table

**Migration:** `packages/marvel/database/migrations/2020_04_29_000001_create_promotions_table.php`

| Column | Type | Constraints | Default |
|--------|------|-------------|---------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT | |
| `name` | `varchar(255)` | NOT NULL | |
| `slug` | `varchar(255)` | NOT NULL | |
| `code` | `varchar(255)` | NOT NULL, UNIQUE | |
| `type` | `enum('price','quantity')` | NOT NULL | |
| `type_amount` | `enum('fixed_rate','percentage','gift')` | NOT NULL | |
| `value` | `decimal(10,2)` | NOT NULL | `0.00` |
| `discount` | `decimal(10,2)` | NULLABLE | |
| `max_discount_amount` | `decimal(10,2)` | NULLABLE | |
| `required_quantity_type` | `int` | NULLABLE | |
| `minimum_order_amount` | `decimal(10,2)` | NOT NULL | `0.00` |
| `apply_to` | `varchar(255)` | NOT NULL | `'specific_products'` |
| `limiter` | `int` | NULLABLE | |
| `usage` | `int` | NOT NULL | `0` |
| `start_at` | `date` | NULLABLE | |
| `end_at` | `date` | NULLABLE | |
| `status` | `tinyint(1)` | NOT NULL | `1` |
| `created_at` | `timestamp` | NULLABLE | |
| `updated_at` | `timestamp` | NULLABLE | |

**Indexes:**

| Index Name | Columns | Type |
|-----------|---------|------|
| PRIMARY | `id` | BTREE |
| `promotions_code_unique` | `code` | UNIQUE |
| `promotions_validity_index` | `(status, start_at, end_at)` | BTREE |
| `promotions_usage_limiter_index` | `(usage, limiter)` | BTREE |

**Foreign Keys:** None

### 2. `promotion_product` Pivot Table

**Migration:** `packages/marvel/database/migrations/2026_05_03_111116_create_promotion_product_table.php`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT |
| `promotion_id` | `bigint unsigned` | FOREIGN KEY → `promotions(id)` ON DELETE CASCADE |
| `product_id` | `bigint unsigned` | FOREIGN KEY → `products(id)` ON DELETE CASCADE |
| `created_at` | `timestamp` | NULLABLE |
| `updated_at` | `timestamp` | NULLABLE |

**Indexes:**

| Index Name | Columns | Type |
|-----------|---------|------|
| PRIMARY | `id` | BTREE |
| `promotion_product_promotion_id_product_id_unique` | `(promotion_id, product_id)` | UNIQUE |

### 3. `promotion_gift_products` Pivot Table

**Migration:** `packages/marvel/database/migrations/2026_05_17_000001_add_selected_promotion_checkout_fields.php`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT |
| `promotion_id` | `bigint unsigned` | FOREIGN KEY → `promotions(id)` ON DELETE CASCADE |
| `product_id` | `bigint unsigned` | FOREIGN KEY → `products(id)` ON DELETE CASCADE |
| `product_variant_id` | `bigint unsigned` | NULLABLE, FOREIGN KEY → `product_variants(id)` ON DELETE CASCADE |
| `quantity` | `unsigned int` | DEFAULT `1` |
| `created_at` | `timestamp` | NULLABLE |
| `updated_at` | `timestamp` | NULLABLE |

**Indexes:**

| Index Name | Columns | Type |
|-----------|---------|------|
| PRIMARY | `id` | BTREE |
| `promotion_gift_products_promotion_id_product_id_unique` | `(promotion_id, product_id)` | UNIQUE |
| `promotion_gift_products_product_id_index` | `product_id` | BTREE |
| `promotion_gift_products_product_variant_id_index` | `product_variant_id` | BTREE |

### 4. `promotion_shop` Pivot Table

**Model:** `packages/marvel/src/Database/Models/promotionShop.php`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | `bigint unsigned` | PRIMARY KEY |
| `promotion_id` | `bigint unsigned` | FOREIGN KEY |
| `shop_id` | `bigint unsigned` | FOREIGN KEY |
| `deleted_at` | `timestamp` | NULLABLE |

**Traits:** `SoftDeletes`
**Timestamps:** `false`

### 5. Cart & Order Promotion Fields

**`cart_items` table:**
- `promotion_id` (nullable, unsignedBigInt)
- `is_gift` (boolean, default false)
- `discount_amount` (decimal 10,2, default 0)

**`orders` table:**
- `promotion_id` (nullable)
- `promotion_code` (nullable)
- `promotion_type` (nullable)
- `promotion_discount` (decimal 10,2, nullable)

**`order_products` table:**
- `promotion_id` (nullable)
- `promotion_discount_amount` (decimal 10,2, default 0)

---

## Migration History

| File | Description |
|------|-------------|
| `2020_04_29_000001_create_promotions_table.php` | Creates `promotions` table with validity + usage indexes |
| `2026_05_03_111116_create_promotion_product_table.php` | Creates `promotion_product` pivot |
| `2026_05_17_000001_add_selected_promotion_checkout_fields.php` | Creates `promotion_gift_products` table, adds promotion fields to cart_items/orders |
| `2026_07_18_000001_make_promotion_gift_product_variant_nullable.php` | Makes product_variant_id nullable in gift products |

---

## Query Patterns

### Read Patterns

| Use Case | Query | Notes |
|----------|-------|-------|
| Public listing | `Promotion::active()->get()` | Active only |
| Public by slug | `Promotion::active()->where('slug', $slug)->with('products')->first()` | With products |
| Admin listing | `Promotion::with(['products', 'giftProducts'])->paginate()` | All with relations |
| Valid promotions | `Promotion::valid()->get()` | Scope: status + limiter + date range |
| Eligible for cart | `Promotion::valid()->where('apply_to', 'all_products')->orWhereHas('products', fn($q) => ...)` | Complex eligibility query |

### Write Patterns

| Use Case | Type | Transaction |
|----------|------|-------------|
| Create promotion | INSERT | Yes |
| Update promotion | UPDATE | Yes |
| Delete promotion | DELETE | No |
| Sync products | INSERT/DELETE (pivot) | In create/update transaction |
| Sync gift products | INSERT/DELETE (pivot) | In create/update transaction |
| Increment usage | UPDATE | No (locked via DB) |
| Decrement usage | UPDATE | No |

---

## Performance Notes

- **Validity Index:** `(status, start_at, end_at)` composite index covers the most common query filter pattern
- **Usage Index:** `(usage, limiter)` composite index supports the `usage < limiter` eligibility check
- **Unique Codes:** `code` column has UNIQUE constraint — DB-level collision prevention
- **Pivot Indexes:** Both pivot tables have unique composite indexes preventing duplicate associations; gift products also have single-column indexes on `product_id` and `product_variant_id`
- **Cascade Deletes:** All pivot tables use `ON DELETE CASCADE` — when a promotion is deleted, all pivot records are cleaned up automatically
- **Eligibility Queries:** The `valid()` scope uses `whereNull('deleted_at')` (SoftDeletes), `where('status', 1)`, date range checks, and `whereColumn('usage', '<', 'limiter')` — all covered by existing indexes
