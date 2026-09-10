# Database — FAQ Module

## Table: `faqs`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| faq_title | text | | NOT NULL | Translatable JSON |
| faq_description | text | | NOT NULL | Translatable JSON |
| status | tinyint(1) | 1 | | 0 = inactive, 1 = active |
| order | integer | 0 | | Sort order (Spatie Sortable) |
| deleted_at | timestamp | NULL | | Soft deletes |
| created_at | timestamp | NULL | | |
| updated_at | timestamp | NULL | | |

### Indexes
- Primary: `id`
- No additional indexes defined

### Translatable Columns
- `faq_title` — stored as JSON: `{"en": "How to return?", "ar": "كيفية الإرجاع"}`
- `faq_description` — stored as JSON: `{"en": "Details...", "ar": "التفاصيل..."}`

### Soft Deletes
The model uses `Illuminate\Database\Eloquent\SoftDeletes`. Records are not physically removed from the database; `deleted_at` is set to the current timestamp on delete. Soft-deleted records are excluded from all queries by default.

### Sortable
The Spatie Sortable trait manages the `order` column:
- New records auto-assign an order value (`sort_when_creating: true`)
- Reordering uses `setNewOrder()` which updates the `order` column based on array position

---

## Fillable Mass Assignment

```php
protected $fillable = [
    'faq_title',
    'faq_description',
    'status',
    'order',
];
```

---

## Migration File

| File | Table |
|------|-------|
| `packages/marvel/database/migrations/2023_07_19_162433_create_faqs_table.php` | `faqs` |

### Migration Columns (Current)
The current migration creates only: `id`, `faq_title`, `faq_description`, `status`, `order`, `deleted_at`, `created_at`, `updated_at`.

### Removed/Deprecated Columns
The following columns appear in older seeders and GraphQL schemas but are NOT in the current migration:
- `user_id` — removed
- `shop_id` — removed
- `faq_type` — removed
- `issued_by` — removed
- `slug` — removed
- `language` — removed
- `translated_languages` — removed

---

## Related Tables

| Table | Relation | Column |
|-------|----------|--------|
| `users` | BelongsTo (not in current migration) | `faqs.user_id` → `users.id` |
| `shops` | BelongsTo (not in current migration) | `faqs.shop_id` → `shops.id` |

**Note:** The `user()` and `shop()` relations are still defined on the model but the foreign key columns are not present in the current migration. These are remnants from an older schema version.
