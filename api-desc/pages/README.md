# Pages Module

The Pages module manages dynamic content pages composed of sections. A content page (e.g., "Home") contains multiple ordered sections (e.g., sliders, banners, promotions, categories, products). Each section has a type, translatable title, visibility toggle, and settings (front-end display config + back-end query params). Section types define reusable setting templates.

## Key Entities

- **ContentPage** — A page with a translatable title, slug, and active status. Has many sections.
- **Section** — A content block within a page. Has a type, order, translatable title, and settings. Supports sortable ordering via Spatie's SortableTrait.
- **SectionType** — A reusable type key (e.g., "banners", "sliders", "promotions"). Has settings (front/back).
- **SectionTypeSetting** — Key-value settings for a section type (front = display config, back = query params).

## Key Features

- Public API exposes pages with only active sections
- Admin API provides full CRUD + attach/detach sections + toggle active status
- Sections are sortable via drag-and-drop (reorder endpoint)
- Section settings cascade: section-level setting overrides type-level default
- Dynamic endpoint generation: `general/{type}?{back params}`
- Translatable titles (English/Arabic) via Spatie Translatable
- No caching currently implemented (commented-out Cache::remember in public controller)
