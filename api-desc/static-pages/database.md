# Database — Static Pages Module

## Migrations

- `2026_08_18_000001_create_static_pages_table`
- `2026_08_18_000002_create_static_sections_table`
- `2026_09_14_000001_add_type_config_is_active_to_static_sections_table` — adds `type, config, is_active` (TiDB-safe)

## static_pages

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | bigint | PK, auto-increment | |
| slug | string | UNIQUE, NOT NULL | Immutable, seeded (`about-us`, `terms-and-conditions`, `privacy-policy`) |
| title | json | NOT NULL | Translatable map `{ "en": "...", "ar": "..." }` |
| is_active | boolean | default true | Page visibility |
| created_at / updated_at | timestamp | | |

## static_sections

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | bigint | PK, auto-increment | |
| static_page_id | bigint | FK → static_pages.id, NOT NULL, `cascadeOnDelete`, indexed | Owns the section; ordering is scoped by this |
| type | string | NOT NULL, default `'text'` | Enum `text,image,video,screenshot` via `StaticSectionType` (TiDB string, not MySQL ENUM). Backfilled `text` for legacy `NULL` |
| title | json | NOT NULL | Translatable map |
| content | json | nullable | Translatable free-form map `{ "en": {...}, "ar": {...} }` (text body, image alt/caption, etc.) |
| config | json | nullable | Per-type metadata e.g. `{ "poster": "...", "autoplay": false }` |
| is_active | boolean | default true, NOT NULL | Section visibility — public filters `where is_active=true` |
| order | integer | default 1 | Sortable position within the page |
| created_at / updated_at | timestamp | | |

Indexes: `static_pages.slug` (unique), `static_sections.static_page_id` (regular index).

Media: Spatie Media Library `media` table polymorphic (`model_type=Marvel\Database\Models\StaticSection`, `collection_name=static-section-image|static-section-video`, disk `static-pages`). `StaticSection::COLLECTION_IMAGE|VIDEO` `singleFile()`.

## Relationships

- `StaticPage` hasMany `StaticSection` (ordered by `order` ASC) — `StaticPage::staticSections()`
- `StaticSection` belongsTo `StaticPage` — `StaticSection::staticPage()`
- `StaticSection` morphMany `Media` — `media` (eager loaded as `with('media')` to avoid N+1 for `getFirstMediaUrl`)

## Constraints / Integrity

- `static_page_id` is NOT NULL → a section can never be orphaned.
- `cascadeOnDelete` → deleting a page removes its sections (not reachable via API, but enforced at DB level).
- `type` default `text` ensures legacy rows valid after migration.
- No unique constraint on `order` — reorder/delete may leave gaps; new sections are placed at `max(order) + 1`.
- Media `singleFile()` enforces one file per collection at application layer; `clearMediaCollection` on type change/replace prevents stale collection.

## Seed Data

`StaticPageSeeder` (`firstOrCreate` by `slug`):

| slug | title.en | title.ar | is_active |
|------|----------|----------|-----------|
| about-us | About Us | من نحن | 1 |
| terms-and-conditions | Terms and Conditions | الشروط والأحكام | 1 |
| privacy-policy | Privacy Policy | سياسة الخصوصية | 1 |

The seeder is idempotent and **never** overwrites existing titles/`is_active`, never creates, updates or deletes sections. Legacy sections after `2026_09_14` migration remain `type=text, is_active=true`.

## Filesystem / Disk

`config/filesystems.php` disk `static-pages`:
```php
'static-pages' => [
  'driver' => 'local',
  'root' => storage_path('app/public/static-pages'),
  'url' => env('APP_URL').'/storage/static-pages',
  'visibility' => 'public',
]
```
Media Library collections use `useDisk('static-pages')`. Disk is `Storage::fake('static-pages')` in tests.

## Rollback

`down()` drops `type,config,is_active`. Safe: existing prod rows lose new columns but retain `title,content,order`.
