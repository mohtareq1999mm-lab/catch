# Database - Content Page Feature

## Tables

### 1. `content_pages` Table

**Migration:** `2026_06_01_124741_create_content_pages_table.php`

| Column | Type | Constraints | Default |
|--------|------|-------------|---------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT | |
| `title` | `json` | NOT NULL, UNIQUE | |
| `slug` | `json` | NOT NULL, UNIQUE | |
| `is_active` | `tinyint(1)` | NOT NULL | `1` |
| `created_at` | `timestamp` | NULLABLE | |
| `updated_at` | `timestamp` | NULLABLE | |

### 2. `sections` Table

**Migration:** `2026_06_01_135945_create_sections_table.php`

| Column | Type | Constraints | Default |
|--------|------|-------------|---------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT | |
| `type` | `varchar(255)` | NOT NULL | |
| `title` | `json` | NULLABLE | |
| `order` | `int` | NOT NULL | |
| `endpoint` | `varchar(255)` | NULLABLE | |
| `content_page_id` | `bigint unsigned` | FOREIGN KEY → `content_pages(id)` ON DELETE SET NULL | |
| `is_active` | `tinyint(1)` | NOT NULL | `1` |
| `title_visible` | `tinyint(1)` | NOT NULL | `1` |
| `setting` | `json` | NULLABLE | |
| `created_at` | `timestamp` | NULLABLE | |
| `updated_at` | `timestamp` | NULLABLE | |

**Indexes:**

| Index Name | Columns | Type |
|-----------|---------|------|
| PRIMARY | `id` | BTREE |
| `sections_content_page_id_index` | `content_page_id` | BTREE |

**Foreign Keys:**

| Constraint | Columns | References | On Delete |
|-----------|---------|-----------|-----------|
| `sections_content_page_id_foreign` | `content_page_id` | `content_pages(id)` | SET NULL |

### 3. `cms_pages` Table

**Migration:** Part of the main Marvel migration set

| Column | Type | Constraints | Default |
|--------|------|-------------|---------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT | |
| `path` | `varchar(191)` | NOT NULL | |
| `slug` | `varchar(191)` | NULLABLE | |
| `title` | `varchar(191)` | NOT NULL | |
| `content` | `json` | NULLABLE | |
| `data` | `json` | NULLABLE | |
| `meta` | `json` | NULLABLE | |
| `created_at` | `timestamp` | NULLABLE | |
| `updated_at` | `timestamp` | NULLABLE | |
| `deleted_at` | `timestamp` | NULLABLE (Soft Deletes) | |

### 4. `section_types` Table

**Migration:** `2026_06_17_000009_create_section_types_table.php`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT |
| `type` | `varchar(255)` | NOT NULL, UNIQUE |
| `created_at` | `timestamp` | NULLABLE |
| `updated_at` | `timestamp` | NULLABLE |

### 5. `section_type_settings` Table

**Migration:** `2026_06_17_000010_create_section_type_settings_table.php`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT |
| `section_type_id` | `bigint unsigned` | FOREIGN KEY → `section_types(id)` ON DELETE CASCADE |
| `setting_key` | `varchar(50)` | NOT NULL |
| `value` | `json` | NULLABLE |
| `created_at` | `timestamp` | NULLABLE |
| `updated_at` | `timestamp` | NULLABLE |

**Indexes:**

| Index Name | Columns | Type |
|-----------|---------|------|
| PRIMARY | `id` | BTREE |
| `section_type_settings_section_type_id_setting_key_unique` | `(section_type_id, setting_key)` | UNIQUE |

---

## Migration History

| File | Description |
|------|-------------|
| `2026_06_01_124741_create_content_pages_table.php` | Creates `content_pages` table |
| `2026_06_01_135945_create_sections_table.php` | Creates `sections` table with FK to content_pages |
| `2026_06_17_000009_create_section_types_table.php` | Creates `section_types` table |
| `2026_06_17_000010_create_section_type_settings_table.php` | Creates `section_type_settings` table |
| `2026_06_17_000011_drop_section_settings_and_unique_type.php` | Drops legacy `section_settings` table and old unique index |

---

## Query Patterns

### Read Patterns

| Use Case | Query | Notes |
|----------|-------|-------|
| Public page by slug | `ContentPage::where('slug', $slug)->where('is_active', true)->with('sections', fn($q) => $q->where('is_active', true)->ordered())->firstOrFail()` | Active only |
| Admin page listing | `ContentPage::with('sections')->paginate()` | All pages |
| Section types listing | `SectionType::with('settings')->get()` | All types with settings |
| Section type by type | `SectionType::where('type', $type)->with('settings')->first()` | Route-model binding by type string |
| CMS page by slug | `CmsPage::where('slug', $slug)->firstOrFail()` | Public |
| CMS page by path | `CmsPage::where('path', $path)->firstOrFail()` | Puck lookup |
| Component data | Various queries on Product, Category, Collection models | Limited by config |

### Write Patterns

| Use Case | Type | Transaction |
|----------|------|-------------|
| Create content page | INSERT | No |
| Update content page | UPDATE | No |
| Toggle active | UPDATE | No |
| Attach sections | SYNC (belongsToMany equivalent via custom method) | No |
| Create section | INSERT (Spatie Sortable auto-orders) | No |
| Reorder sections | UPDATE (bulk order) | No |
| Create CMS page | INSERT | Yes (CmsPageService) |
| Update CMS page | UPDATE | Yes |
| Delete CMS page | DELETE (soft) | Yes |
| Upsert Puck page | INSERT or UPDATE | Yes |

---

## Performance Notes

- **Index Coverage:** `content_page_id` indexed on `sections` table. `section_type_settings` has unique composite index on `(section_type_id, setting_key)`.
- **JSON Columns:** `title` on content_pages/sections is JSON (Spatie Translatable). `setting` on sections is JSON. `content`, `data`, `meta` on cms_pages are JSON.
- **Cascade/SET NULL:** Sections use `ON DELETE SET NULL` for `content_page_id` — deleting a page preserves sections but detaches them. `section_type_settings` uses `ON DELETE CASCADE`.
- **Sortable:** Section ordering managed by Spatie Sortable trait with `order` column.
- **Soft Deletes:** `cms_pages` uses `SoftDeletes` — deleted pages are hidden from queries but data persists.
