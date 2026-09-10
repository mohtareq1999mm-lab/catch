# Database - Category Feature

## Tables

### 1. `categories` Table

**Migration:** `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php`

| Column | Type | Constraints | Default |
|--------|------|-------------|---------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT | |
| `name` | `json` | NOT NULL | |
| `slug` | `json` | NOT NULL | |
| `details` | `json` | NULLABLE | |
| `parent_id` | `bigint unsigned` | NULLABLE, FOREIGN KEY → `categories.id` ON DELETE RESTRICT | `NULL` |
| `status` | `tinyint(1)` | NOT NULL | `1` (active) |
| `is_featured` | `tinyint(1)` | NOT NULL | `0` (false) |
| `level` | `smallint unsigned` | NOT NULL, INDEXED | `1` |
| `created_at` | `timestamp` | NULLABLE | |
| `updated_at` | `timestamp` | NULLABLE | |
| `deleted_at` | `timestamp` | NULLABLE (Soft Deletes) | |

**Indexes:**

| Index Name | Columns | Type |
|-----------|---------|------|
| PRIMARY | `id` | BTREE |
| `categories_level_index` | `level` | BTREE |
| `categories_name_index` | `name` (MySQL index on JSON column) | BTREE |

**Foreign Keys:**

| Constraint | Columns | References | On Delete |
|-----------|---------|-----------|-----------|
| `categories_parent_id_foreign` | `parent_id` | `categories(id)` | `RESTRICT` |

### 2. `category_product` Pivot Table

**Migration:** Same file as above

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT |
| `product_id` | `bigint unsigned` | FOREIGN KEY → `products(id)` ON DELETE CASCADE |
| `category_id` | `bigint unsigned` | FOREIGN KEY → `categories(id)` ON DELETE CASCADE |

**Indexes:**

| Index Name | Columns | Type |
|-----------|---------|------|
| PRIMARY | `id` | BTREE |
| `cat_prod_unique` | `(category_id, product_id)` | UNIQUE |
| `idx_cat_prod_product_category` | `(product_id, category_id)` | BTREE |
| `idx_cat_prod_category_product` | `(category_id, product_id)` | BTREE |

**Foreign Keys:**

| Constraint | Columns | References | On Delete |
|-----------|---------|-----------|-----------|
| `category_product_category_id_foreign` | `category_id` | `categories(id)` | CASCADE |
| `category_product_product_id_foreign` | `product_id` | `products(id)` | CASCADE |

### 3. `category_shop` Pivot Table

**Model:** `packages/marvel/src/Database/Models/CategoryShop.php`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | `bigint unsigned` | PRIMARY KEY |
| `category_id` | `bigint unsigned` | FOREIGN KEY |
| `shop_id` | `bigint unsigned` | FOREIGN KEY |

**Traits:** `SoftDeletes`
**Timestamps:** `false`

---

## Migration History

| File | Description |
|------|-------------|
| `2020_06_02_051901_create_marvel_tables.php` | Creates categories, category_product tables |
| `2026_07_18_000002_add_unique_constraint_to_category_product.php` | Cleans up duplicates and adds unique constraint on (category_id, product_id) |

---

## Query Patterns

### Read Patterns

| Use Case | Query | Cache |
|----------|-------|-------|
| Public category listing | `Category::active()->whereNull('parent_id')->paginate()` | No |
| Category detail by slug | `Category::active()->where('slug', $slug)->with('children', 'products')->first()` | No |
| Admin listing | `Category::with('children')->paginate()` | No |
| Featured categories | `Category::active()->where('is_featured', true)->withCount('products')->orderBy('products_count', 'desc')->limit(10)` | No |
| Dashboard stats | `Category::withCount('products')->get()` | 5 min |
| Dashboard analytics | `Category::withCount('products')->get()` with revenue joins | 5 min |

### Write Patterns

| Use Case | Type | Transaction |
|----------|------|-------------|
| Create category | INSERT | Yes |
| Update category | UPDATE | Yes |
| Delete category | UPDATE (soft) | No |
| Force delete | DELETE | No |
| Toggle featured | UPDATE | No |

---

## Performance Notes

- **Index Coverage:** Both `level` and `name` are indexed. Pivot table has covering indexes for both directions.
- **N+1 Prevention:** The hierarchy service uses `loadRecursiveChildren` with eager loading (BFS approach).
- **Soft Delete Impact:** `deleted_at` is indexed implicitly by Laravel scopes.
- **JSON Columns:** `name`, `slug`, `details` are JSON columns (for Spatie Translatable). Queries on translated fields should use the `scopeSearch` method with JSON path extraction.
- **Caching:** Dashboard queries are cached for 5 minutes. Public listing queries are NOT cached — could benefit from caching for high-traffic scenarios.

## Scalability Notes

- The self-referencing hierarchy (`parent_id`) works well for trees up to a few thousand categories.
- For very deep hierarchies (10+ levels), the recursive level update on `parent_id` change could be optimized.
- The unique constraint on `(category_id, product_id)` prevents duplicate associations at the database level.
- `ON DELETE RESTRICT` on `parent_id` prevents accidental deletion of parent categories that have children.
- `ON DELETE CASCADE` on pivot table ensures clean removal when a category or product is force-deleted.
