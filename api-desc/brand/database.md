# Database — Brand Module

## Table: `brands`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| name | text | | NOT NULL | Translatable JSON |
| slug | varchar(255) | | NOT NULL | Auto-generated from English name |
| details | text | NULL | | Translatable JSON, nullable |
| status | tinyint(1) | 1 | | 0 = inactive, 1 = active |
| order | int | 0 | | Sortable order column |
| deleted_at | timestamp | NULL | | Soft deletes |
| created_at | timestamp | NULL | | |
| updated_at | timestamp | NULL | | |

### Indexes
- Primary: `id`
- No unique index on `slug` (duplicate slugs are possible)

### Translatable Columns
- `name` — stored as JSON: `{"en": "Apple", "ar": "أبل"}`
- `details` — stored as JSON: `{"en": "Description", "ar": "الوصف"}`

### Sortable
- `order` column managed by Spatie Eloquent Sortable
- `sort_when_creating` = true (new brands get next order number)
- Default query scope: `ordered()` (ascending by `order`)

---

## Table: `brand_product` (Pivot)

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| brand_id | bigint unsigned | | FK → brands.id ON DELETE CASCADE | |
| product_id | bigint unsigned | | FK → products.id ON DELETE CASCADE | |

### Indexes
- Primary: `id`
- Unique: `(brand_id, product_id)` — prevents duplicate associations
- Composite: `(brand_id, product_id)` — query performance

### Foreign Keys
- `brand_id` → `brands.id` ON DELETE CASCADE
- `product_id` → `products.id` ON DELETE CASCADE

---

## Related Tables

| Table | Relation | Column |
|-------|----------|--------|
| `products` | BelongsToMany (via brand_product) | `brand_product.product_id` → `products.id` |
| `media` | MorphMany | model_type=`Marvel\Database\Models\Brand` |

---

## Media Collections

| Collection | Disk | Purpose |
|------------|------|---------|
| `brands-desktop` | `brands` | Desktop brand image |
| `brands-mobile` | `brands` | Mobile brand image |

### Disk Configuration (`config/filesystems.php`)
```php
'brands' => [
    'driver' => 'local',
    'root' => storage_path('app/public/brands'),
    'url' => env('APP_URL') . '/public/storage/brands',
    'visibility' => 'public',
],
```

---

## Soft Deletes

The `brands` table uses Laravel's `SoftDeletes` trait:
- `delete()` sets `deleted_at` (row remains in database)
- Pivot records in `brand_product` are preserved on soft delete
- On force delete, pivot records are removed (FK ON DELETE CASCADE)
- No admin API endpoint exists for restore or force delete

---

## Fillable Mass Assignment

```php
protected $fillable = [
    'name', 'details', 'slug', 'status', 'order',
];
```

---

## Migration Files

| File | Table |
|------|-------|
| `packages/marvel/database/migrations/2026_05_09_000001_create_brands_table.php` | `brands` |
| `packages/marvel/database/migrations/2026_05_09_000002_create_brand_product_table.php` | `brand_product` |
