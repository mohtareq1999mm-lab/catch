# Database - Slider Feature

## Tables

### 1. `sliders` Table

**Migration:** `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php`

| Column | Type | Constraints | Default |
|--------|------|-------------|---------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT | |
| `title` | `varchar(255)` | NULLABLE | |
| `slug` | `varchar(255)` | NOT NULL | |
| `order` | `int` | NOT NULL | |
| `status` | `tinyint(1)` | NOT NULL | `0` |
| `created_at` | `timestamp` | NULLABLE | |
| `updated_at` | `timestamp` | NULLABLE | |
| `deleted_at` | `timestamp` | NULLABLE (Soft Deletes) | |

**Indexes:**

| Index Name | Columns | Type |
|-----------|---------|------|
| PRIMARY | `id` | BTREE |

**Foreign Keys:** None

### 2. `slider_product` Pivot Table

**Migration:** `database/migrations/2026_06_17_000003_create_slider_product_table.php`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT |
| `slider_id` | `bigint unsigned` | FOREIGN KEY → `sliders(id)` ON DELETE CASCADE |
| `product_id` | `bigint unsigned` | FOREIGN KEY → `products(id)` ON DELETE CASCADE |
| `created_at` | `timestamp` | NULLABLE |
| `updated_at` | `timestamp` | NULLABLE |

**Indexes:**

| Index Name | Columns | Type |
|-----------|---------|------|
| PRIMARY | `id` | BTREE |
| `slider_product_slider_id_product_id_unique` | `(slider_id, product_id)` | UNIQUE |

**Foreign Keys:**

| Constraint | Columns | References | On Delete |
|-----------|---------|-----------|-----------|
| `slider_product_slider_id_foreign` | `slider_id` | `sliders(id)` | CASCADE |
| `slider_product_product_id_foreign` | `product_id` | `products(id)` | CASCADE |

---

## Migration History

| File | Description |
|------|-------------|
| `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php` | Creates `sliders` table |
| `database/migrations/2026_06_17_000003_create_slider_product_table.php` | Creates `slider_product` pivot with unique constraint |

---

## Query Patterns

### Read Patterns

| Use Case | Query | Notes |
|----------|-------|-------|
| Public listing | `Slider::active()->orderBy('order')->limit($limit)->get()` | Active only |
| Public by slug | `Slider::active()->where('slug', $slug)->with('products')->first()` | Eager loads products |
| Admin listing | `Slider::with('products')->paginate($limit)` | All sliders |
| Admin show | `Slider::with('products')->findOrFail($id)` | Single with products |
| Status filter | `Slider::where('status', $status)->paginate()` | Filterable |

### Write Patterns

| Use Case | Type | Transaction |
|----------|------|-------------|
| Create slider | INSERT | Yes |
| Update slider | UPDATE | Yes |
| Delete slider | UPDATE (soft) | No |
| Toggle status | UPDATE | No |
| Reorder | UPDATE (bulk) | No |
| Sync products | INSERT/DELETE (pivot) | Within create/update transaction |

---

## Performance Notes

- **Index Coverage:** Only primary key on `sliders` table. `slug` is searched but not indexed — consider adding a unique index on `slug` if lookup performance becomes an issue.
- **Pivot Indexing:** The `slider_product` table has a unique composite index on `(slider_id, product_id)` — good for preventing duplicates.
- **Soft Delete Impact:** Laravel automatically adds `WHERE deleted_at IS NULL` to queries via `SoftDeletes` trait.
- **N+1 Prevention:** The public `getSliderBySlug()` method uses `with('products')` for eager loading.
- **Cache:** Repository uses `CacheableRepository` trait — slider queries may be cached depending on repository configuration.

## Scalability Notes

- The `sliders` table is expected to remain small (tens to low hundreds of records) — no scalability concerns.
- The `slider_product` pivot could grow large if many products are associated with many sliders. The unique constraint prevents duplicates.
- `ON DELETE CASCADE` on the pivot table ensures clean cleanup when sliders or products are force-deleted.
- No foreign key on `sliders` table itself — simple, standalone table.
