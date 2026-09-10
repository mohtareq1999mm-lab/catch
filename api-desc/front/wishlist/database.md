# Wishlist Module — Database (Authenticated API)

## Tables

### `wishlists`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| user_id | bigint(20) UNSIGNED | FK to users (cascade on delete) |
| product_id | bigint(20) UNSIGNED | FK to products (cascade on delete) |
| product_variant_id | bigint(20) UNSIGNED, nullable | FK to product_variants (cascade on delete) |
| created_at | timestamp | |
| updated_at | timestamp | |

**Indexes:**
- Primary: `id`
- FK indexes: `user_id`, `product_id`, `product_variant_id`

> ⚠️ No unique constraint on `(user_id, product_id, product_variant_id)`. Duplicate rows are prevented at the application layer (`WishlistRepository::findUserWishlistItem`). A DB-level unique index would NOT fully work with nullable `product_variant_id` (MySQL treats NULLs as distinct in unique indexes) — it would require a generated column or a sentinel value. See bug report recommendation.

**Migration source:** `packages/marvel/database/migrations/2021_10_12_193855_create_reviews_table.php` (lines 32-41)

## Query Patterns

### Find user's wishlist rows
```sql
SELECT * FROM `wishlists`
WHERE `user_id` = ?;
```

### Duplicate check — simple product (null variant)
```sql
SELECT * FROM `wishlists`
WHERE `user_id` = ? AND `product_id` = ? AND `product_variant_id` IS NULL
LIMIT 1;
```

### Duplicate check — variant product
```sql
SELECT * FROM `wishlists`
WHERE `user_id` = ? AND `product_id` = ? AND `product_variant_id` = ?
LIMIT 1;
```

> Note: the previous implementation used `WHERE product_variant_id = NULL`, which never matches in MySQL/SQLite — this was the root cause of duplicate rows (fixed).

### Index products (list wishlist)
```sql
SELECT * FROM `products`
WHERE `id` IN (?)
ORDER BY `id` ASC
LIMIT ? OFFSET ?;
```

### My wishlists (paginated products)
```sql
SELECT * FROM `products`
WHERE `id` IN (SELECT `product_id` FROM `wishlists` WHERE `user_id` = ?)
ORDER BY `id` ASC
LIMIT ? OFFSET ?;
```

## N+1 Prevention

- **Index wishlist:** eager loads `variations` (filtered to wishlisted variant ids) + `variations.attributeProducts.attributeValue.attribute`
- **My wishlists:** returns through the Product repository paginator; ProductResource relies on Product model accessors that delegate to `ProductPricingService` (single authority, no ad-hoc SQL)
- **Pricing:** `current_price`, `price_after_discount`, `price_after_flash_sale` are accessors calling `ProductPricingService` — consistent with the Frozen runtime-pricing architecture

## Performance Notes

- **Index:** 2 queries (wishlist rows + paginated products with eager loads) + pricing service calls during serialization
- **Store/Toggle:** 1-2 queries (duplicate check + insert/delete)
- **Destroy:** 2 queries (product lookup + wishlist row lookup) + 1 delete
- Each product serialization may trigger pricing service flash-sale lookups when `has_flash_sale` is true (guarded by the service's `runSafely` fallback)
