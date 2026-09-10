# Pages Module — Database

## Tables

### `content_pages`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| title | json (translatable) | JSON object with locale keys (en, ar) |
| slug | varchar(255) | URL-friendly identifier (auto-generated from title.en) |
| is_active | tinyint(1), default 1 | Visibility toggle |
| created_at | timestamp | |
| updated_at | timestamp | |

**Indexes:**
- Primary: `id`
- Unique: `slug`

### `sections`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| type | varchar(255) | Section type key (matches section_types.type) |
| title | json (translatable) | JSON object with locale keys |
| order | int(11) | Sortable order (Spatie SortableTrait) |
| endpoint | varchar(255), nullable | Custom endpoint override |
| content_page_id | bigint(20) UNSIGNED, nullable | FK to content_pages |
| is_active | tinyint(1), default 1 | Visibility toggle |
| title_visible | tinyint(1), default 1 | Whether title is shown in frontend |
| setting | json, nullable | Section-level settings override |
| created_at | timestamp | |
| updated_at | timestamp | |

**Indexes:**
- Primary: `id`
- Index: `content_page_id`
- Index: `order`

### `section_types`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| type | varchar(255) | Unique type key (e.g., "banners") — also route key |
| created_at | timestamp | |
| updated_at | timestamp | |

**Indexes:**
- Primary: `id`
- Unique: `type`

### `section_type_settings`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| section_type_id | bigint(20) UNSIGNED | FK to section_types |
| setting_key | varchar(255) | e.g., "front" or "back" |
| value | json | Setting value (can be any structure) |
| created_at | timestamp | |
| updated_at | timestamp | |

**Indexes:**
- Primary: `id`
- Unique: `section_type_id`, `setting_key`

## Entity Relationships

```
ContentPage (1) ──── (N) Section
Section (N) ──── (1) ContentPage

SectionType (1) ──── (N) Section (via type string)
SectionType (1) ──── (N) SectionTypeSetting
```

## Query Patterns

### Get Public Page with Active Sections
```sql
SELECT * FROM content_pages WHERE slug = ? LIMIT 1;
SELECT * FROM sections WHERE content_page_id = ? AND is_active = 1 ORDER BY `order` ASC;
```

### Get All Sections Ordered
```sql
SELECT * FROM sections ORDER BY `order` ASC;
```

### Get Section Type Settings
```sql
SELECT * FROM section_types WHERE type = ? LIMIT 1;
SELECT * FROM section_type_settings WHERE section_type_id = ?;
```

### Reorder Sections
```sql
UPDATE sections SET `order` = ? WHERE id = ?;  -- called per section
```

## Performance

- **List pages:** 2 queries (pages pagination + sections with ordering)
- **Show page:** 2 queries (page by slug + sections with ordering)
- **Create page:** 1 INSERT + auto-generate slug
- **Attach sections:** N UPDATE queries (one per section)
- **Reorder sections:** N UPDATE queries (one per section in order)
- **Section settings:** 2 queries (section type + settings) — cached per SectionResource instance
