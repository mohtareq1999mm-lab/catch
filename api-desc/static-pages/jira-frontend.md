# Static Pages Module — Frontend Jira Tasks

---

## Task 1: Public Static Page Renderer (typed, media-aware)

**Priority:** High
**Component:** Frontend — Public Pages
**Story Points:** 5

**Description:** Build the public renderer that fetches and displays a fixed static page (About Us, Terms & Conditions, Privacy Policy) with its ordered typed content sections including media.

**API Endpoints:**
- `GET /api/v1/general/static-pages`
- `GET /api/v1/general/static-pages/{slug}`

**Response Shape (per page):**
```json
{
  "id": 1,
  "slug": "about-us",
  "title": "About Us",
  "is_active": true,
  "sections": [
    { "id": 1, "static_page_id": 1, "type": "text", "title": "Our Story", "content": { "en": { "body": "Welcome" } }, "config": null, "order": 1, "is_active": true, "media": null },
    { "id": 2, "static_page_id": 1, "type": "image", "title": "Hero", "content": { "en": { "alt": "Team" } }, "order": 2, "is_active": true, "media": { "url": "...", "thumb_url": "...", "mime_type": "image/jpeg" } },
    { "id": 3, "static_page_id": 1, "type": "video", "title": "Intro", "content": { "en": { "caption": "Hi" } }, "config": { "poster": "..." }, "order": 3, "is_active": true, "media": { "url": "...", "mime_type": "video/mp4" } }
  ]
}
```
Public returns only `is_active=true` pages+sections, with `media` eager loaded.

**Acceptance Criteria:**
- [ ] Fetch page by slug from URL route (e.g., `/pages/about-us`) with `lang` header
- [ ] Render sections ordered by `order`, branching on `type` (text|image|video|screenshot)
- [ ] `title` localized (plain string), `content` map per locale + fallback to `en`, `media` for image/video
- [ ] Inactive pages (404) show not-found
- [ ] **Loading:** Skeleton per section; **Empty:** "No content"; **Error:** Toast with retry

---

## Task 2: Admin — Static Pages List & Edit

**Priority:** High
**Component:** Frontend — Admin Panel
**Story Points:** 5

**Description:** Admin screen listing the fixed static pages with an edit form for the localized title and active status. Slugs immutable — no create/delete UI (405).

**API Endpoints:**
- `GET /api/v1/static-pages`
- `GET /api/v1/static-pages/{slug}`
- `PUT /api/v1/static-pages/{slug}`

**Request Body (update):**
```json
{
  "title": { "en": "About Us", "ar": "من نحن" },
  "is_active": true
}
```

**Acceptance Criteria:**
- [ ] Table listing 3 fixed pages: slug (read-only), localized title, active badge, is_active toggle
- [ ] Edit form: title EN/AR, active toggle; slug read-only
- [ ] Partial locale maps merged server-side
- [ ] **Loading:** skeleton; **Error:** toast + 422 inline

---

## Task 3: Admin — Typed Sections Manager per Page

**Priority:** High
**Component:** Frontend — Admin Panel
**Story Points:** 8

**Description:** Manage the ordered typed content sections of a page: list, create, edit, delete, and drag-and-drop reorder with media lifecycle.

**API Endpoints:**
- `POST /api/v1/static-pages/{slug}/sections` (JSON for text, multipart for media)
- `PUT /api/v1/static-pages/{slug}/sections/{id}` (JSON or multipart + remove_media)
- `DELETE /api/v1/static-pages/{slug}/sections/{id}`
- `POST /api/v1/static-pages/{slug}/sections/reorder`

**Request Body — Text (JSON):**
```json
{
  "type": "text",
  "title": { "en": "Our Story", "ar": "قصتنا" },
  "content": { "en": { "body": "Welcome" }, "ar": { "body": "مرحبا" } },
  "config": null,
  "is_active": true
}
```
**Request Body — Image (multipart):**
```
type=image
title[en]=Hero
content[en][alt]=Our team
media=@photo.jpg (jpeg,png,webp,gif max 5MB)
is_active=1
```
**Screenshot:** same as image (`type=screenshot`). **Video:** `type=video` + `media=@a.mp4` (mp4,webm,ogg,mov,avi max 20MB) + optional `config[poster]`.

**Request Body (reorder):** `{ "sections": [3, 1, 2] }`

**Acceptance Criteria:**
- [ ] Per-page list ordered by `order`, showing `type` badge, `is_active` toggle, media thumb
- [ ] Create form: `type` select (text|image|video|screenshot), `is_active`, title EN/AR, content per locale (alt/caption/body), `media` file input (required for image/video/screenshot), `config` for video
- [ ] Edit: JSON for text/config/is_active, multipart for media replace, `remove_media` checkbox clears without new file
- [ ] Type change `image→text` clears media; `image→video` without file → 422 unless target had media
- [ ] Drag-and-drop sends full ordered id array
- [ ] Delete confirmation, handles cross-page 404 gracefully
- [ ] **Loading/Empty/Error** states

---

## Task 4: Admin — Localized Free-form Content Editor (typed)

**Priority:** Medium
**Component:** Frontend — Admin Panel
**Story Points:** 8

**Description:** Content editor for `content` free-form object per locale + `config` JSON. Shape per `type` is conventional: text→{body,heading}, image/screenshot→{alt,caption}, video→{caption}+config.poster.

**Acceptance Criteria:**
- [ ] Tab per locale (`en`/`ar`), editing only that locale (partial map)
- [ ] `type` selector shows relevant fields (alt/caption for image, body for text)
- [ ] Structural values: strings, numbers, booleans, nested objects, arrays
- [ ] Top-level `content` must remain object — list rejected 422 `STATIC_SECTION_CONTENT_INVALID`
- [ ] JSON validation inline
- [ ] **Loading/Empty/Error** states

---

## Task 5: Locale & Media Handling Across the Module

**Priority:** Medium
**Component:** Frontend — API layer (shared)
**Story Points:** 3

**Description:** Standardize locale and media so titles resolve correctly and media URLs are locale-independent.

**Acceptance Criteria:**
- [ ] Public requests send `lang` header from active UI locale; `media` URLs same for all langs
- [ ] Re-fetch on locale change (do not trust cached state); admin sends both locales on create
- [ ] Fallback if active locale absent from `content` → `en`
- [ ] Image preview uses `media.thumb_url` if present
