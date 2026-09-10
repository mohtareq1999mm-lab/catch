# Database — Review Module

## Table: `reviews`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| user_id | bigint unsigned | | FK → users.id ON DELETE CASCADE | |
| product_id | bigint unsigned | | FK → products.id ON DELETE CASCADE | |
| comment | longText | | NOT NULL | Plain text, not translatable |
| rating | double | NULL | | 1-5 scale, nullable |
| approved | tinyint(1) | 0 | | 0 = pending, 1 = approved |
| deleted_at | timestamp | NULL | | Soft deletes |
| created_at | timestamp | NULL | | |
| updated_at | timestamp | NULL | | |

### Indexes
- Primary: `id`
- Index: `rating` — for filtering by rating value
- Composite: `(rating, product_id)` — for common query pattern (reviews by product, sorted by rating)

### Foreign Keys
- `user_id` → `users.id` ON DELETE CASCADE
- `product_id` → `products.id` ON DELETE CASCADE

---

## Related Tables

| Table | Relation | Column |
|-------|----------|--------|
| `users` | BelongsTo | `reviews.user_id` → `users.id` |
| `products` | BelongsTo | `reviews.product_id` → `products.id` |
| `media` | MorphMany | model_type=`Marvel\Database\Models\Review` |
| `feedbacks` | MorphMany | model_type=`Marvel\Database\Models\Review` |
| `abusive_reports` | MorphMany | model_type=`Marvel\Database\Models\Review` |

---

## Media Collection

| Collection | Disk | Purpose |
|------------|------|---------|
| `reviews` | `reviews` | Review images (multiple, currently disabled in validation) |

**Note:** The `images` validation field is commented out in both `ReviewCreateRequest` and `ReviewUpdateRequest`. However, the repository `storeReview()` and `updateReview()` methods still check for `$request->has('images')` and attempt to upload if present.

---

## Soft Deletes

The `reviews` table uses Laravel's `SoftDeletes` trait:
- `delete()` sets `deleted_at` (row remains in database)
- Related feedbacks and abusive reports are preserved (morphMany, no cascade on soft delete)
- No admin API endpoint exists for restore or force delete

---

## Fillable Mass Assignment

```php
protected $fillable = [
    'user_id', 'product_id', 'comment', 'rating', 'approved',
];
```

---

## Migration File

| File | Table |
|------|-------|
| `packages/marvel/database/migrations/2021_10_12_193855_create_reviews_table.php` | `reviews` (+ `wishlists`) |
