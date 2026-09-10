# Static Pages Module

The Static Pages module manages the **fixed** marketing/legal pages of the store — About Us,
Terms & Conditions, Privacy Policy — plus their structured content sections.

Unlike the dynamic `ContentPage`/`Section` system (Pages module), static pages are seeded and
immutable: the page set and slugs never change at runtime. Admins can only edit the page title /
visibility and manage ordered typed content sections within each page.

## Key Entities

- **StaticPage** — A fixed, seeded page. Translatable `title`, immutable `slug`, `is_active` flag. Has many static sections ordered by `order`.
- **StaticSection** — A typed content block within a page. Fields: `type` (`text|image|video|screenshot` via `StaticSectionType` enum), translatable `title`, translatable free-form `content` (JSON object per locale), nullable `config` JSON, sortable `order` scoped per page, `is_active` flag, plus Spatie Media Library media (`static-section-image` for image+screenshot, `static-section-video` for video).

## Key Features

- Fixed page set seeded via `StaticPageSeeder` (`about-us`, `terms-and-conditions`, `privacy-policy`), localized EN/AR, all active, idempotent and never destructive
- No create/delete endpoints for pages (405) — pages are never added or removed at runtime
- Full admin CRUD + reorder for typed sections (5 dedicated permissions)
- Typed sections: `text` (JSON content only), `image` (image file + alt/caption), `screenshot` (semantic image alias, same infrastructure), `video` (video file + optional config), future types extensible via `StaticSectionType`
- Sections are strictly scoped to their page (cross-page access returns 404)
- Translatable titles + free-form translatable content via Spatie Translatable; `config` nullable JSON per-type metadata
- Sortable ordering per page via Spatie Eloquent Sortable (`buildSortQuery` scoped by `static_page_id`)
- Spatie Media Library `HasMedia` with `static-section-image` + `static-section-video` collections on `static-pages` disk, `singleFile()`, thumb conversion for images
- Public endpoints cached (`static_pages` tag, `md5(fullUrl)` key, models+media cached not rendered)
- Cache invalidated on every mutation by controller + observers (including reorder, which fires no model events)
- Backward compatible: legacy `type=NULL` → `text`, `is_active` defaults true; `POST` without `type` creates `text`

## Permissions

| Permission | Endpoint(s) |
|------------|-------------|
| `view-static-pages` | Admin list + show |
| `update-static-pages` | Update page |
| `create-static-sections` | Create section |
| `update-static-sections` | Update + reorder sections |
| `delete-static-sections` | Delete section |

## Seeded Pages

| Slug | EN title | AR title |
|------|----------|----------|
| about-us | About Us | من نحن |
| terms-and-conditions | Terms and Conditions | الشروط والأحكام |
| privacy-policy | Privacy Policy | سياسة الخصوصية |

## Section Type Semantics

| Type | Media | Content example | Collection |
|------|-------|-----------------|------------|
| `text` | none (prohibited) | `content.en = { "body": "About us..." }` | — |
| `image` | required image `max 5MB` `jpeg,png,webp,gif` | `content.en = { "alt": "...", "caption": "..." }` | `static-section-image` |
| `screenshot` | required image same as `image` | same | `static-section-image` |
| `video` | required video `max 20MB` `mp4,webm,ogg,mov,avi` | `content.en = { "caption": "..." }`, `config = { "poster": "..." }` | `static-section-video` |

Media replacement: `media` file supplied → clear both collections + attach to resolved type collection. `remove_media=true` → clear both. Omission → keep.

## Routes (7 admin + 2 public)

Admin `auth:sanctum+throttle:admin` in `packages/marvel/src/Rest/Routes.php`:
`GET static-pages`, `GET static-pages/{slug}`, `PUT static-pages/{slug}`, `POST static-pages/{slug}/sections`, `POST static-pages/{slug}/sections/reorder` (before `sections/{id}`), `PUT static-pages/{slug}/sections/{id}`, `DELETE static-pages/{slug}/sections/{id}`

Public `v1/general` no auth in `routes/api.php`:
`GET static-pages`, `GET static-pages/{slug}` (filtered `is_active=true` for pages and sections, with `media`)
