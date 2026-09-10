# Database — Coupon Module

## Table: `coupons`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| name | string (text) | | NOT NULL | Translatable JSON |
| slug | string(255) | | NOT NULL | Auto-generated |
| code | string(255) | | NOT NULL | Auto-generated `coupon_` + 7 random uppercase |
| discount_type | string(255) | | NOT NULL | `fixed_rate`, `percentage`, or `free_shipping` |
| discount | decimal(10,2) | | NOT NULL | Discount value |
| max_discount_amount | decimal(10,2) | NULL | | Cap for percentage discounts |
| is_approve | tinyint(1) | 0 | | Approval status (super admin) |
| start_date | date | NULL | | Coupon start date |
| end_date | date | NULL | | Coupon end date |
| limiter | integer | NULL | | Max usage limit (null = unlimited) |
| used | integer | 0 | | Current usage count |
| status | tinyint(1) | 1 | | 0 = inactive, 1 = active |
| border_color | string(50) | NULL | | Hex color for UI display |
| borderless | tinyint(1) | 0 | | Whether coupon card has border |
| created_at | timestamp | NULL | | |
| updated_at | timestamp | NULL | | |

### Indexes
- Primary: `id`
- Unique: `slug`
- Composite: `(status, start_date, end_date)` — for `valid()` scope queries

### Translatable Columns
- `name` — stored as JSON: `{"en": "Summer Sale", "ar": "تخفيضات الصيف"}`

### Global Scope
- Default order: `updated_at desc`

### Model Events
- `creating`: Auto-generates `code` if empty via `generateUniqueCode()` (prefix: `coupon_` + 7 random uppercase characters)

---

## Table: `coupon_product` (Pivot)

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| coupon_id | bigint unsigned | | FK → coupons.id ON DELETE CASCADE | |
| product_id | bigint unsigned | | FK → products.id ON DELETE CASCADE | |

### Indexes
- Primary: `id`

### Foreign Keys
- `coupon_id` → `coupons.id` ON DELETE CASCADE
- `product_id` → `products.id` ON DELETE CASCADE

---

## Table: `coupon_usages`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| coupon_id | bigint unsigned | | FK → coupons.id | |
| user_id | bigint unsigned | | FK → users.id | |
| order_id | bigint unsigned | | FK → orders.id | |
| used_at | timestamp | NULL | | When the coupon was used |

### Indexes
- Unique: `(coupon_id, user_id)` — one usage per user per coupon

### Foreign Keys
- `coupon_id` → `coupons.id`
- `user_id` → `users.id`
- `order_id` → `orders.id`

---

## Table: `coupon_assignments`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| coupon_id | bigint unsigned | | FK → coupons.id ON DELETE CASCADE | |
| user_id | bigint unsigned | | FK → users.id ON DELETE CASCADE | |
| max_uses | integer | 1 | | Per-user usage quota |
| used | integer | 0 | | Times this user has used it |
| assigned_at | datetime | NULL | | When the assignment was created |
| expires_at | datetime | NULL | | When the assignment expires |
| created_at | timestamp | NULL | | |
| updated_at | timestamp | NULL | | |

### Casts
| Column | Cast |
|--------|------|
| max_uses | integer |
| used | integer |
| assigned_at | datetime |
| expires_at | datetime |

### Foreign Keys
- `coupon_id` → `coupons.id` ON DELETE CASCADE
- `user_id` → `users.id` ON DELETE CASCADE

---

## Table: `coupon_assignment_usages`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| coupon_assignment_id | bigint unsigned | | FK → coupon_assignments.id ON DELETE CASCADE | |
| order_id | bigint unsigned | | FK → orders.id ON DELETE CASCADE | |
| used_at | timestamp | NULL | | When the assignment was consumed |

### Foreign Keys
- `coupon_assignment_id` → `coupon_assignments.id` ON DELETE CASCADE
- `order_id` → `orders.id` ON DELETE CASCADE

---

## Table: `coupon_shop` (Pivot)

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| coupon_id | bigint unsigned | | FK → coupons.id | |
| shop_id | bigint unsigned | | FK → shops.id | |
| deleted_at | timestamp | NULL | | Soft deletes |

### Traits
- SoftDeletes (no timestamps)

---

## Related Tables

| Table | Relation | Column |
|-------|----------|--------|
| `products` | BelongsToMany (via coupon_product) | `coupon_product.product_id` → `products.id` |
| `users` | BelongsToMany (via coupon_usages) | `coupon_usages.user_id` → `users.id` |
| `orders` | HasMany | `orders.coupon` matches `coupons.code` |
| `media` | MorphMany | model_type=`Marvel\Database\Models\Coupon` |

---

## Media Collections

| Collection | Disk | Purpose |
|------------|------|---------|
| `coupons-desktop` | `coupons` | Desktop coupon image |
| `coupons-mobile` | `coupons` | Mobile coupon image |

---

## Fillable Mass Assignment

```php
protected $fillable = [
    'code', 'slug', 'name', 'discount_type', 'discount',
    'max_discount_amount', 'start_date', 'end_date', 'limiter',
    'used', 'status', 'border_color', 'borderless'
];
```

---

## Casts

| Column | Cast Type |
|--------|-----------|
| status | boolean |
| start_date | date |
| end_date | date |
| borderless | boolean |

---

## Migration Files

| File | Table |
|------|-------|
| `packages/marvel/database/migrations/2024_12_27_000001_create_coupon_usages_table.php` | `coupon_usages` |
| `database/migrations/2026_06_17_000001_create_coupon_product_table.php` | `coupon_product` |
| `database/migrations/2026_07_12_000002_add_free_shipping_to_coupons_discount_type.php` | `coupons` |
| `database/migrations/2026_07_15_000003_create_coupon_assignments_table.php` | `coupon_assignments` |
| `database/migrations/2026_07_15_000004_create_coupon_assignment_usages_table.php` | `coupon_assignment_usages` |
