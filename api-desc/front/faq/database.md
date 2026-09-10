# Database - FAQ Feature

## Tables

### `faqs` Table

**Migration:** `packages/marvel/database/migrations/2023_07_19_162433_create_faqs_table.php`

| Column | Type | Constraints | Default |
|--------|------|-------------|---------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT | |
| `faq_title` | `text` | NOT NULL | |
| `faq_description` | `text` | NOT NULL | |
| `status` | `boolean` | NOT NULL | `true` |
| `order` | `integer` | NOT NULL | `0` |
| `deleted_at` | `timestamp` | NULLABLE (Soft Deletes) | |
| `created_at` | `timestamp` | NULLABLE | |
| `updated_at` | `timestamp` | NULLABLE | |

**Indexes:**

| Index Name | Columns | Type |
|-----------|---------|------|
| PRIMARY | `id` | BTREE |

**Foreign Keys:** None

---

## Migration History

| File | Description |
|------|-------------|
| `2023_07_19_162433_create_faqs_table.php` | Creates `faqs` table |

---

## Query Patterns

### Read Patterns

| Use Case | Query | Notes |
|----------|-------|-------|
| Public listing | `Faqs::active()->orderBy('order')->get()` | All active, sorted |
| Admin listing | `Faqs::paginate()` | All FAQs, paginated |
| Admin search | `Faqs::where('faq_title', 'like', '%term%')->paginate()` | Searchable |
| Admin show | `Faqs::findOrFail($id)` | By ID |
| Role-based listing | `Faqs::when(role, fn($q) => ...)` | Scoped by shop |

### Write Patterns

| Use Case | Type |
|----------|------|
| Create FAQ | INSERT |
| Update FAQ | UPDATE |
| Delete FAQ | UPDATE (soft) |
| Reorder | UPDATE (bulk order) |

---

## Performance Notes

- **Simple table:** Only 7 columns, no foreign keys, single primary index
- **No additional indexes:** `status`, `order`, `faq_title` are not indexed — consider adding indexes if the table grows large
- **JSON Columns:** `faq_title` and `faq_description` are stored as text (Spatie Translatable stores translations as JSON in text fields)
- **Sorting:** `order` column managed by Spatie Sortable — efficient for small lists
