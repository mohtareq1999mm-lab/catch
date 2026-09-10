# Static Pages Module — Frontend Integration Guide

---

## Overview

Static pages are **fixed, seeded pages** (About Us, Terms & Conditions, Privacy Policy). The page
set and slugs never change — the frontend only renders pages and lets admins edit their typed content
sections. There is no create/delete page UI.

Every page has a translatable `title`, an `is_active` flag, and an ordered list of typed sections (`text|image|video|screenshot`). Each section has translatable `title`, free-form translatable `content` (alt/caption/body per locale), nullable `config` (e.g. video poster), `is_active`, `order`, and optional `media {url,thumb_url,mime_type,size}`.

---

## Locale Handling (Critical)

All titles and content are translated via the `lang` header (`en` / `ar`, default `en`).

- Public requests must send the header: `lang: en` or `lang: ar`
- The response `title` is **already localized** (a plain string) for the requested lang
- The response `content` is a **full locale map** `{ "en": {...}, "ar": {...} }` — pick the active locale key, fall back to `en` when missing
- The response `media` is locale-independent (same URL for all langs)
- When the UI locale changes, re-fetch (the server-side cache stores models, so a fresh request with the new `lang` header returns the correct localization)

```
fetch('/api/v1/general/static-pages', { headers: { lang: 'ar', Accept: 'application/json' } })
```

---

## Public Endpoints (No Auth)

### 1. GET /api/v1/general/static-pages — List Active Pages

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "slug": "about-us",
      "title": "About Us",
      "is_active": true,
      "sections": [
        {
          "id": 1,
          "static_page_id": 1,
          "type": "text",
          "title": "Our Story",
          "content": { "en": { "body": "Welcome" }, "ar": { "body": "مرحبا" } },
          "config": null,
          "order": 1,
          "is_active": true,
          "media": null
        },
        {
          "id": 2,
          "static_page_id": 1,
          "type": "image",
          "title": "Hero",
          "content": { "en": { "alt": "Team photo", "caption": "Caption" } },
          "config": null,
          "order": 2,
          "is_active": true,
          "media": {
            "id": 10,
            "url": "http://example.com/storage/static-pages/1/photo.jpg",
            "thumb_url": "http://example.com/storage/static-pages/1/conversions/photo-thumb.jpg",
            "collection_name": "static-section-image",
            "mime_type": "image/jpeg",
            "size": 123456
          }
        },
        {
          "id": 3,
          "static_page_id": 1,
          "type": "video",
          "title": "Intro",
          "content": { "en": { "caption": "Welcome video" } },
          "config": { "poster": "https://..." },
          "order": 3,
          "is_active": true,
          "media": {
            "url": "http://example.com/storage/static-pages/1/video.mp4",
            "mime_type": "video/mp4"
          }
        }
      ]
    }
  ]
}
```
Public filters `is_active=false` sections out; `media` null for `text`.

### 2. GET /api/v1/general/static-pages/{slug} — Show One Active Page

**Response 200:** Same StaticPageResource structure (only active sections).
**Response 404:** Slug not found OR the page is inactive.

> Inactive pages are invisible to the public — treat 404 as "page not available".
> Inactive sections are hidden — admin sees them, public does not.

---

## Admin Endpoints (Auth + Permission)

All admin responses use the same envelope and resources. Permissions are enforced server-side; the frontend should hide/disable actions the current user cannot perform.

| Endpoint | Permission | Use in UI |
|----------|------------|-----------|
| `GET /api/v1/static-pages` | view-static-pages | Page list |
| `GET /api/v1/static-pages/{slug}` | view-static-pages | Edit form (load) — includes inactive sections |
| `PUT /api/v1/static-pages/{slug}` | update-static-pages | Save title/active |
| `POST /api/v1/static-pages/{slug}/sections` | create-static-sections | Add typed section (JSON for text, multipart for media) |
| `PUT /api/v1/static-pages/{slug}/sections/{id}` | update-static-sections | Edit section (JSON or multipart) + remove_media |
| `DELETE /api/v1/static-pages/{slug}/sections/{id}` | delete-static-sections | Delete section |
| `POST /api/v1/static-pages/{slug}/sections/reorder` | update-static-sections | Drag-and-drop reorder |

---

## Frontend Flow — Public Page Rendering

```
Route: /pages/{slug}  (e.g. /pages/about-us)
    │
    ▼
1. GET /api/v1/general/static-pages/{slug}  (headers: lang + Accept)
    │
    ▼
2. 200 → page object (title localized, sections ordered by `order`, filtered is_active, with media)
   │
   ▼
3. Render <StaticPageRenderer>
   ├─ Page <h1>: title
   └─ For each section (sorted by `order`):
        switch(section.type)
          case 'text': <TextSection title, content[lang].body />
          case 'image': <ImageSection title, content[lang].alt, media.url, thumb_url />
          case 'screenshot': same as image (semantic label differs)
          case 'video': <VideoSection title, media.url, poster=config.poster, content[lang].caption />
        └─ content fallback: content[lang] ?? content.en
          media fallback: if media null and type image/video → placeholder
   │
   ▼
4. 404 → Not Found page (inactive or unknown)
```

### Typed Content Rendering

- `text`: `content[lang] = { body, heading, list, blocks }` free-form but now always accompanied by `type=text` and no media.
- `image`/`screenshot`: `content[lang] = { alt, caption }`, `media.url` is the image, `thumb_url` for preview, `config` null.
- `video`: `content[lang] = { caption }`, `media.url` video, `config` may hold `{ poster, autoplay, muted, controls }`.

Since `content` remains free-form, keep a small block renderer for unknown keys but branch on `type` for media.

```js
function renderSection(s, lang) {
  const c = s.content?.[lang] ?? s.content?.en ?? {};
  if (s.type === 'text') return <TextSection title={s.title} body={c.body} />;
  if (s.type === 'image' || s.type === 'screenshot') return <ImageSection alt={c.alt} caption={c.caption} url={s.media?.url} thumb={s.media?.thumb_url} />;
  if (s.type === 'video') return <VideoSection caption={c.caption} url={s.media?.url} poster={s.config?.poster} />;
}
```

---

## Frontend Flow — Admin Management

### Screen A: Static Pages List

1. `GET /api/v1/static-pages` → table of the 3 fixed pages
2. Columns: slug (read-only), localized title, active badge, actions (Edit)
3. No create/delete buttons — slugs are immutable (endpoints return 405)

### Screen B: Page Editor

1. `GET /api/v1/static-pages/{slug}` → load page + sections (including inactive, with media)
2. Title inputs per locale (EN + AR); active toggle
3. Save → `PUT /api/v1/static-pages/{slug}` with `{ "title": { "en": "...", "ar": "..." }, "is_active": true }`
   - Partial maps allowed: `{ "title": { "en": "..." } }` keeps the existing `ar`

### Screen C: Sections Manager (per page)

1. List sections sorted by `order` (show `type` badge, `is_active` toggle, thumb for image/video)
2. **Add section** — form with:
   - `type` select: text|image|video|screenshot
   - `title`: EN/AR inputs
   - `content`: per-locale alt/caption/body inputs based on type
   - `config` (video poster)
   - `is_active` toggle
   - `media` file input (required for image/video/screenshot, hidden for text)
3. Create:
   - `type=text` → `POST` JSON `{ type, title, content, config, is_active }`
   - `type=image|screenshot|video` → `POST` multipart `type,title[en],content[en][alt],media,is_active`
4. Edit → `PUT` JSON for text/config/is_active; multipart for `media` replacement; `remove_media` checkbox → `{ remove_media: true }` (clears media without new file)
5. Delete → `DELETE` (confirmation)
6. **Reorder** — drag-and-drop → `{ sections: [3,1,2] }` to `POST .../reorder`

---

## Data-Shape Contract Checklist

| Call | Payload | Content-Type |
|------|---------|--------------|
| Update page | `{ "title": { "en": "…", "ar": "…" }, "is_active": true }` | json |
| Create text | `{ "type":"text","title":{"en":"…"},"content":{"en":{"body":"…"}},"config":null,"is_active":true }` | json |
| Create image | `type=image, title[en]=…, content[en][alt]=…, media=@a.jpg` | multipart |
| Create screenshot | `type=screenshot, media=@a.png` | multipart |
| Create video | `type=video, media=@a.mp4, config[poster]=...` | multipart |
| Update section (text) | `{ "title":{"en":"…"},"content":{"en":{…}},"is_active":false }` | json |
| Update replace media | `media=@b.jpg` (+ `_method=PUT` if using POST) | multipart |
| Remove media | `{ "remove_media": true }` | json |
| Reorder | `{ "sections": [3, 1, 2] }` | json |

**Validation notes (422):**
- `type` in:text,image,video,screenshot (legacy missing → text)
- `title` required on create; `title.en` required string max:255
- `content` required for `type=text`, must be locale-keyed object (top-level list rejected)
- `media` required for image/video/screenshot (`image` max 5MB jpeg,png,webp,gif; `video` max 20MB mp4,webm,ogg,mov,avi), prohibited for `text`
- `remove_media` true,1,on,yes → clear; omission → keep
- 422 returns flat map (no `errors` wrapper)

---

## State Handling

| State | Behavior |
|-------|----------|
| Loading | Skeleton page + section placeholders |
| Empty | Page with no sections → "No content" placeholder |
| Error (page) | Toast with retry; 404 → not-found |
| Section loading | Skeleton per section |
| Section error | Show section error, keep others |
| Locale change | Re-fetch with new `lang` header |
| Save (admin) | Spinner; disable until 200 |
| 422 | Inline field errors + toast |
| Media upload | Progress bar, preview via `media.url`/`thumb_url` |

---

## Delivery Checklist

- [ ] API client helper that always sends `lang` + `Accept: application/json`; for multipart set `Content-Type: multipart/form-data` automatically
- [ ] Public `/pages/{slug}` route + typed renderer (`text|image|video|screenshot`) with media
- [ ] Admin: pages list, page editor (localized title + active), typed sections manager, reorder, delete, remove_media
- [ ] Permission-aware UI (hide actions the role cannot perform)
- [ ] Loading / empty / error states on every screen
- [ ] Locale fallback (`content[lang] → content.en`); media is locale-independent
