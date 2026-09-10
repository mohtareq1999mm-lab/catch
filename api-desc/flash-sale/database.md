# Database — Flash Sale Module

## Table: `flash_sales`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| title | text | | NOT NULL | Translatable JSON |
| slug | varchar(255) | | NOT NULL | Auto-generated from English title |
| description | text | NULL | | Translatable JSON, nullable |
| start_date | date | now() | | Sale start date |
| end_date | date | | NOT NULL | Sale end date |
| status | tinyint(1) | 1 | | 0 = inactive, 1 = active |
| type | enum | 'percentage' | | percentage, fixed_rate, final_price |
| discount | decimal(10,2) | NULL | | Discount amount/value |
| max_discount_amount | decimal(10,2) | NULL | | Max cap (required for percentage) |
| order | int | 0 | | Sortable order column |
| deleted_at | timestamp | NULL | | Soft deletes |
| created_at | timestamp | NULL | | |
| updated_at | timestamp | NULL | | |

### Indexes
- Primary: `id`
- No unique index on `slug`

### Translatable Columns
- `title` — stored as JSON: `{"en": "Summer Sale", "ar": "تخفيضات الصيف"}`
- `description` — stored as JSON: `{"en": "Description", "ar": "الوصف"}`

### Sortable
- `order` column managed by Spatie Eloquent Sortable
- `sort_when_creating` = true (new flash sales get next order number)

---

## Table: `flash_sale_products` (Pivot)

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| flash_sale_id | bigint unsigned | | FK → flash_sales.id ON DELETE CASCADE | |
| product_id | bigint unsigned | | FK → products.id ON DELETE CASCADE | |

### Indexes
- Unique: `(flash_sale_id, product_id)` — prevents duplicate associations

### Foreign Keys
- `flash_sale_id` → `flash_sales.id` ON DELETE CASCADE
- `product_id` → `products.id` ON DELETE CASCADE

---

## Table: `flash_sale_requests` (Vendor Requests)

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| flash_sale_id | bigint unsigned | | FK → flash_sales.id | |
| title | varchar(255) | | | Vendor's title for the request |
| note | text | NULL | | Vendor's note |
| request_status | tinyint(1) | 0 | | 0 = pending, 1 = approved |
| deleted_at | timestamp | NULL | | Soft deletes |
| created_at | timestamp | NULL | | |
| updated_at | timestamp | NULL | | |

Also has `flash_sale_requests_products` pivot table linking requests to products.

---

## Table: `flash_sale_shop` (Pivot)

| Column | Type | Constraints |
|--------|------|-------------|
| flash_sale_id | bigint unsigned | FK → flash_sales.id |
| shop_id | bigint unsigned | FK → shops.id |

Uses `SoftDeletes`, no timestamps.

---

## Related Tables

| Table | Relation | Column |
|-------|----------|--------|
| `products` | BelongsToMany (via flash_sale_products) | `flash_sale_products.product_id` → `products.id` |
| `shops` | BelongsToMany (via flash_sale_shop) | `flash_sale_shop.shop_id` → `shops.id` |
| `media` | MorphMany | model_type=`Marvel\Database\Models\FlashSale` |

---

## Media Collections

| Collection | Disk | Purpose |
|------------|------|---------|
| `flash-sales-desktop` | `flashSales` | Desktop flash sale image |
| `flash-sales-mobile` | `flashSales` | Mobile flash sale image |

### Disk Configuration (`config/filesystems.php`)
```php
'flashSales' => [
    'driver' => 'local',
    'root' => storage_path('app/public/flashSales'),
    'url' => env('APP_URL') . '/public/storage/flashSales',
    'visibility' => 'public',
],
```

---

## Soft Deletes

- `flash_sales` uses `SoftDeletes` — `delete()` sets `deleted_at`
- `flash_sale_requests` uses `SoftDeletes`
- `flash_sale_shop` uses `SoftDeletes`
- Pivot records in `flash_sale_products` are **hard-deleted** on flash sale delete (FK `ON DELETE CASCADE`)
- No admin API endpoint exists for restore or force delete

---

## Fillable Mass Assignment

```php
protected $fillable = [
    'title', 'slug', 'description', 'start_date', 'end_date',
    'status', 'type', 'discount', 'max_discount_amount', 'order',
];
```

---

## Migration Files

| File | Table |
|------|-------|
| `packages/marvel/database/migrations/2023_08_14_173253_create_flash_sales_table.php` | `flash_sales`, `flash_sale_products` |
