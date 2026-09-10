# Database - Product Feature

## Tables

### `products` Table

**Migration:** `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php`

| Column | Type | Constraints | Default |
|--------|------|-------------|---------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT | |
| `name` | `text` | NOT NULL (JSON translatable) | |
| `slug` | `varchar(255)` | UNIQUE, NOT NULL | |
| `description` | `text` | NULLABLE (JSON translatable) | |
| `price` | `decimal(10,2)` | NULLABLE | |
| `product_type` | `varchar(50)` | NOT NULL | `simple` |
| `type_id` | `int unsigned` | NULLABLE, FK → types.id | |
| `sku` | `varchar(255)` | NULLABLE | |
| `stock_quantity` | `int` | NOT NULL | `0` |
| `reserved_quantity` | `int` | NOT NULL | `0` |
| `sold_quantity` | `int` | NOT NULL | `0` |
| `in_stock` | `tinyint(1)` | NOT NULL | `1` |
| `status` | `varchar(50)` | NOT NULL | `false` |
| `height` | `varchar(255)` | NULLABLE | |
| `width` | `varchar(255)` | NULLABLE | |
| `length` | `varchar(255)` | NULLABLE | |
| `weight` | `varchar(255)` | NULLABLE | |
| `has_flash_sale` | `tinyint(1)` | NOT NULL | `0` |
| `is_fast_shipping_available` | `tinyint(1)` | NOT NULL | `0` |
| `has_discount` | `tinyint(1)` | NOT NULL | `0` |
| `pieces` | `int` | NOT NULL | `1` |
| `discount_type` | `varchar(50)` | NULLABLE | |
| `discount_amount` | `decimal(10,2)` | NULLABLE | `0.00` |
| `discount_status` | `tinyint(1)` | NULLABLE | |
| `start_date` | `datetime` | NULLABLE | |
| `end_date` | `datetime` | NULLABLE | |
| `price_after_discount` | `decimal(10,2)` | NULLABLE | |
| `price_after_flash_sale` | `decimal(10,2)` | NULLABLE | |
| `deleted_at` | `timestamp` | NULLABLE | |
| `created_at` | `timestamp` | NULLABLE | |
| `updated_at` | `timestamp` | NULLABLE | |

**Indexes:**

| Index Name | Columns | Type |
|-----------|---------|------|
| PRIMARY | `id` | BTREE |
| `products_slug_unique` | `slug` | UNIQUE |
| `products_price_index` | `price` | BTREE |
| `products_sold_quantity_index` | `sold_quantity` | BTREE |
| `idx_products_status_deleted_price` | `status`, `deleted_at`, `price` | BTREE |
| `products_is_fast_shipping_available_index` | `is_fast_shipping_available` | BTREE |

**Foreign Keys:** None (type_id is logical FK, no constraint)

---

### `product_variants` Table

**Migration:** Same as products (marvel_tables)

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT |
| `sku` | `varchar(255)` | NULLABLE |
| `price` | `decimal(10,2)` | NULLABLE |
| `sale_price` | `decimal(10,2)` | NULLABLE |
| `in_stock` | `tinyint(1)` | NOT NULL |
| `quantity` | `int` | NOT NULL |
| `stock_quantity` | `int` | NOT NULL |
| `reserved_quantity` | `int` | NOT NULL |
| `sold_quantity` | `int` | NOT NULL |
| `height` | `varchar(255)` | NULLABLE |
| `width` | `varchar(255)` | NULLABLE |
| `length` | `varchar(255)` | NULLABLE |
| `weight` | `varchar(255)` | NULLABLE |
| `product_id` | `bigint unsigned` | FK → products.id |

---

### Pivot Tables

| Table | Columns | Purpose |
|-------|---------|---------|
| `category_product` | `product_id`, `category_id` (unique pair) | Product-category assignment |
| `product_shop` | `product_id`, `shop_id` | Product-shop assignment |
| `product_tag` | `product_id`, `tag_id` | Product-tag assignment |
| `brand_product` | `product_id`, `brand_id` | Product-brand assignment |
| `banner_product` | `product_id`, `banner_id` | Product-banner assignment |
| `slider_product` | `product_id`, `slider_id` | Product-slider assignment |
| `coupon_product` | `product_id`, `coupon_id` | Product-coupon assignment |
| `promotion_product` | `product_id`, `promotion_id` | Product-promotion assignment |
| `flash_sale_products` | `flash_sale_id`, `product_id` | Product-flash sale assignment |
| `attribute_product` | `attribute_value_id`, `product_variant_id` | Variant attribute values |
| `dropoff_location_product` | `product_id`, `resource_id` | Rental dropoff locations |
| `pickup_location_product` | `product_id`, `resource_id` | Rental pickup locations |
| `deposit_product` | `product_id`, `resource_id` | Rental deposits |
| `person_product` | `product_id`, `resource_id` | Rental persons |
| `feature_product` | `product_id`, `resource_id` | Rental features |

---

## Migration History

| File | Description |
|------|-------------|
| `2020_06_02_051901_create_marvel_tables.php` | Creates products, product_variants, category_product |
| `2023_08_15_061447_add_is_featured_column_to_products_table.php` | Empty (no-op) |
| `2026_05_03_111116_create_promotion_product_table.php` | Promotion pivot |
| `2026_05_09_000002_create_brand_product_table.php` | Brand pivot |
| `2026_06_13_094100_add_product_filter_indexes.php` | Empty (no-op) |
| `2026_06_17_000001_create_coupon_product_table.php` | Coupon pivot |
| `2026_06_17_000003_create_slider_product_table.php` | Slider pivot |
| `2026_06_23_000001_create_banner_product_table.php` | Banner pivot |
| `2026_07_15_000002_create_product_tag_table.php` | Tag pivot |
| `2026_07_18_000001_make_promotion_gift_product_variant_nullable.php` | Nullable fix |
| `2026_07_18_000002_add_unique_constraint_to_category_product.php` | Unique constraint |

---

## Query Patterns

### Read Patterns

| Use Case | Query | Notes |
|----------|-------|-------|
| Public listing | `ProductService::paginate()` | Strategy-based, filtered |
| Full-text search | `Product::search($term)->paginate()` | Meilisearch Scout |
| Single product | `Product::with('reviews','variants')->findBySlug($slug)` | Eager loaded |
| Admin listing | `Product::filter($request)->paginate()` | With sort/search |
| Best sellers | `ProductRepository::getBestSellingProducts()` | LEFT JOIN orders |
| Related products | `ProductRepository::fetchRelated($id, $limit)` | By same categories |

### Write Patterns

| Use Case | Type |
|----------|------|
| Create product | INSERT (transaction with variants, images, relations) |
| Update product | UPDATE + re-sync relations |
| Delete product | UPDATE (soft) |
| Bulk delete | UPDATE (soft, chunked) |
| Import products | Background Job (queue) |
| Export products | Background Job (queue) |

---

## Performance Notes

- **Complex indexing:** Composite index `idx_products_status_deleted_price` for common filtered queries
- **20+ pivot tables:** Many joins required for product detail — eager loading is critical
- **N+1 risk:** Product listing with 20+ relationships requires careful eager loading
- **Full-text search:** Meilisearch via Scout handles search, reducing DB load
- **JSON columns:** `name` and `description` stored as JSON (Spatie Translatable) — translatable searches use LIKE with locale suffix
- **Pricing computation:** `current_price` is computed at runtime via `ProductPricingService` — no stored column
- **Chunked operations:** `destroyAll` uses chunking for memory efficiency on large datasets
