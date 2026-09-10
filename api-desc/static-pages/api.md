# API Reference — Static Pages Module

Base URL: `/api/v1`
Public endpoints: no auth required.
Admin endpoints: require `auth:sanctum` + `email.verified` + explicit permission (spatie).

> Static pages are **fixed, seeded pages** (About Us, Terms & Conditions, Privacy Policy). The page
> set and their slugs are immutable at runtime: there are **no create/delete page endpoints**. Only
> their translatable `title` and `is_active` flag can be updated. All content lives in **static
> sections** which support full CRUD + reorder with typed content and media.

---

## Authentication & Permissions

| Endpoint | Required permission |
|----------|---------------------|
| `GET /static-pages` (admin) | `view-static-pages` |
| `GET /static-pages/{slug}` (admin) | `view-static-pages` |
| `PUT /static-pages/{slug}` | `update-static-pages` |
| `POST /static-pages/{slug}/sections` | `create-static-sections` |
| `PUT /static-pages/{slug}/sections/{id}` | `update-static-sections` |
| `DELETE /static-pages/{slug}/sections/{id}` | `delete-static-sections` |
| `POST /static-pages/{slug}/sections/reorder` | `update-static-sections` |

Public `general/*` endpoints require no auth.

---

## Standard Response Envelope

All endpoints use the `ApiResponse` envelope:

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {}
}
```

Error responses drop `data`:

```json
{
  "status": 404,
  "message": "Not Found",
  "success": false
}
```

Validation failures return **422** with a flat error map (no `errors` wrapper):

```json
{
  "title": ["The title field is required."],
  "title.en": ["The title (English) field is required."]
}
```

---

## Resource Shapes

### StaticPageResource

| Field | Type | Notes |
|-------|------|-------|
| id | int | |
| slug | string | Immutable. One of the seeded slugs. |
| title | string | Localized per `lang` header (`en`/`ar`, default `en`). |
| is_active | bool | |
| sections | array | StaticSectionResource[] — only present when relation is loaded. Ordered by `order`. Admin sees all sections; public sees only `is_active=true` sections. |

### StaticSectionResource

| Field | Type | Notes |
|-------|------|-------|
| id | int | |
| static_page_id | int | Owning page. |
| type | string | One of `text`, `image`, `video`, `screenshot`. Legacy rows default to `text`. |
| title | string | Localized per `lang` header. |
| content | object | **Full locale map** `{ "en": {...}, "ar": {...} }`. Free-form per type (text body, image alt/caption, etc.). |
| config | object\|null | Per-type metadata (e.g. video poster/controls). Nullable. |
| order | int | Sortable position within the page. |
| is_active | bool | Section visibility. Public API hides `is_active=false`. Default `true`. |
| media | object\|null | Media payload when section has media, else `null`. See below. |

**Media object (when present):**
| Field | Type | Notes |
|-------|------|-------|
| id | int | Media id |
| url | string | Public URL (`/storage/static-pages/...`) |
| thumb_url | string\|null | Thumb conversion URL (image only, 368x232) |
| collection_name | string | `static-section-image` or `static-section-video` |
| name | string | Media name |
| file_name | string | File name |
| mime_type | string | e.g. `image/jpeg`, `video/mp4` |
| size | int | Bytes |

---

## Public Endpoints

### GET /api/v1/general/static-pages

List all **active** static pages with their **active** sections (with media).

**Headers:**

| Header | Value | Description |
|--------|-------|-------------|
| lang | en, ar | Language code (default: en) |

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
          "content": { "en": { "alt": "Our team" } },
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
        }
      ]
    }
  ]
}
```

**Caching:** response is cached under tag `static_pages`, key `md5(request()->fullUrl())`. The
cached value is the **models with media**, locale still resolved at render time. Cache is invalidated by any admin mutation (page update, section
create/update/delete/reorder/media replace/remove).

**Quick Test:**
```bash
curl -X GET "http://example.com/api/v1/general/static-pages" \
  -H "Accept: application/json" \
  -H "lang: ar"
```

---

### GET /api/v1/general/static-pages/{slug}

Show one **active** static page by slug with its **active** sections (with media).

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| slug | string | Static page slug (`about-us`, `terms-and-conditions`, `privacy-policy`) |

**Response 200:** StaticPageResource (sections filtered `is_active=true`).
**Response 404:** If slug not found OR the page is inactive.

---

## Admin Endpoints

### GET /api/v1/static-pages

List all static pages (regardless of active status) with all sections (including inactive, with media, ordered by `order`).

**Permissions:** `view-static-pages`

**Response 200:** Array of StaticPageResource.

---

### GET /api/v1/static-pages/{slug}

Show one static page by slug with all sections (including inactive).

**Permissions:** `view-static-pages`

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| slug | string | Static page slug (route-model bound via slug, not id) |

**Response 200:** StaticPageResource.
**Response 404:** If slug not found.

---

### PUT /api/v1/static-pages/{slug}

Update a static page title / active status. Page identity (`slug`) can never be changed — any
`slug` in the payload is ignored.

**Permissions:** `update-static-pages`

**Request Body:**
```json
{
  "title": { "en": "Updated About Us", "ar": "من نحن محدث" },
  "is_active": true
}
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| title | sometimes, array |
| title.* | sometimes, string, max:255 |
| is_active | sometimes, in:0,1 |

**Behavior:** partially supplied locales are merged — a payload with only `title.en` preserves the
existing `title.ar`.

**Response 200:** Updated StaticPageResource.
**Message:** `STATIC_PAGE_UPDATED_SUCCESSFULLY` — "Static page updated successfully".
**Cache:** flushes tag `static_pages`.

---

### POST /api/v1/static-pages/{slug}/sections

Create a new section inside the given page. The section is assigned the next `order` within that
page (sortable, scoped per page).

**Permissions:** `create-static-sections`

**Content-Type:** `application/json` for text sections, `multipart/form-data` for media sections. Field `media` holds the file.

**Text section (JSON):**
```json
{
  "type": "text",
  "title": { "en": "Our Story", "ar": "قصتنا" },
  "content": {
    "en": { "body": "Welcome hello" },
    "ar": { "body": "مرحبا" }
  },
  "config": null,
  "is_active": true
}
```

**Image section (multipart):**
```
POST /api/v1/static-pages/about-us/sections
Content-Type: multipart/form-data
 type=image
 title[en]=Hero
 title[ar]=بطل
 content[en][alt]=Our team photo
 content[en][caption]=Caption
 media=@photo.jpg
 is_active=1
```

**Screenshot section (multipart, same as image):**
```
 type=screenshot
 title[en]=App Screenshot
 media=@screen.png
```

**Video section (multipart):**
```
 type=video
 title[en]=Intro Video
 content[en][caption]=Welcome
 config[poster]=https://...
 media=@intro.mp4
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| type | sometimes, string, in:`text,image,video,screenshot` (defaults to `text` for legacy) |
| title | required, array |
| title.en | required, string, max:255 |
| title.* | nullable, string, max:255 |
| content | nullable, array (required when `type=text`) |
| content.en | nullable, array |
| content.ar | nullable, array |
| config | nullable, array |
| is_active | nullable, in:0,1,true,false |
| media | required `file` for `image,screenshot,video`; `prohibited` for `text`. Image: `mimetypes:image/jpeg,png,jpg,webp,gif` `max:5120` (5MB). Video: `mimetypes:video/mp4,webm,ogg,quicktime,x-msvideo` `max:20480` (20MB). |
| remove_media | prohibited on create |

**Custom validation:** the top level of `content` must be an **associative object keyed by locale**.
A top-level JSON list is rejected with message `STATIC_SECTION_CONTENT_INVALID` ("The section
content must be an object keyed by locale"). `type=text` requires `content` non-empty.

**Response 200:** Created StaticSectionResource with `media` populated for media types.
**Message:** `STATIC_SECTION_CREATED_SUCCESSFULLY`.
**Cache:** flushes tag `static_pages`.

---

### PUT /api/v1/static-pages/{slug}/sections/{id}

Update a section belonging to the given page. Supports JSON or multipart (for media replacement).

**Permissions:** `update-static-sections`

**Text update (JSON):**
```json
{
  "title": { "en": "Updated Story" },
  "content": { "en": { "body": "New body" } },
  "config": { "foo": "bar" },
  "is_active": false
}
```

**Media replacement (multipart):**
```
PUT /api/v1/static-pages/about-us/sections/5
Content-Type: multipart/form-data
 media=@new-photo.jpg
```
*Note: Laravel multipart PUT is sent as `POST` with `_method=PUT` for file uploads; both are accepted.*

**Remove media (JSON):**
```json
{
  "remove_media": true
}
```
Accepted truthy values: `true, 1, on, yes`. Omission = keep existing media. `remove_media` clears both image and video collections.

**Type change:**
```json
{ "type": "video", "remove_media": false }
```
Changing `image→text` or `video→text` without new file clears media. Changing `image→video` or `video→image` without new file requires `media` (422) unless target already has media.

**Validation Rules:**

| Field | Rules |
|-------|-------|
| type | sometimes, string, in:`text,image,video,screenshot` |
| title | sometimes, array |
| title.en | sometimes, string, max:255 |
| title.* | sometimes, nullable, string, max:255 |
| content | sometimes, array |
| content.en | nullable, array |
| content.ar | nullable, array |
| config | sometimes, nullable, array |
| is_active | sometimes, nullable, in:0,1,true,false |
| remove_media | sometimes, nullable, in:0,1,true,false |
| media | sometimes `file` with same per-type mime/size as create; `prohibited` when resolved type is `text` |

**Behavior:**
- Partial locale maps are merged — existing locales not sent are preserved.
- `media` supplied ⇒ replace: old image+video collections cleared, new media attached to collection matching resolved `type`.
- `remove_media=true` ⇒ clear both collections.
- `type` changed to `text` ⇒ clear media if no new file.
- If the section id belongs to a **different** page, `404` is returned (existence is never leaked
  through another page's route).

**Response 200:** Updated StaticSectionResource (with updated `media`).
**Response 404:** Section not found or owned by another page.
**Message:** `STATIC_SECTION_UPDATED_SUCCESSFULLY`.
**Cache:** flushes tag `static_pages`.

---

### DELETE /api/v1/static-pages/{slug}/sections/{id}

Delete a section belonging to the given page. Deleting a middle section does not renumber the
remaining sections; the next created section gets `max(order) + 1`. Associated media files are deleted.

**Permissions:** `delete-static-sections`

**Response 200:** `{ "status": 200, "message": "...", "success": true }`.
**Response 404:** Section not found or owned by another page.
**Message:** `STATIC_SECTION_DELETED_SUCCESSFULLY`.
**Cache:** flushes tag `static_pages`.

---

### POST /api/v1/static-pages/{slug}/sections/reorder

Reorder the sections of the given page by supplying their IDs in the new order. The reorder is
strictly scoped to the page — a foreign section id results in `404`. Wrapped in `DB::transaction` for TiDB atomicity.

**Permissions:** `update-static-sections`

> Note: this route must be declared **before** `sections/{id}` so `reorder` is never captured by
> the parameterized route.

**Request Body:**
```json
{
  "sections": [3, 1, 2]
}
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| sections | required, array |
| sections.* | required, integer, distinct, exists:static_sections,id |

**Behavior:** every supplied id must belong to the given page, otherwise `404`. The DB update is
additionally scoped by `static_page_id` as a second safety layer (the sortable `setNewOrder` runs
raw, so the controller explicitly flushes cache). Resulting `order` values become `1,2,3` in supplied sequence.

**Response 200:** `{ "status": 200, "message": "...", "success": true }`.
**Response 404:** Any id does not belong to the page.
**Message:** `STATIC_SECTIONS_REORDERED_SUCCESSFULLY`.
**Cache:** flushes tag `static_pages`.

---

## Media & Storage Details

- Media handled by `spatie/laravel-medialibrary` (installed `10.14.0`). `StaticSection` implements `HasMedia` with `InteractsWithMedia`.
- Collections: `static-section-image` (for `image`+`screenshot`) and `static-section-video` (for `video`), both `useDisk('static-pages')` `singleFile()`. Disk defined in `config/filesystems.php` as `storage/app/public/static-pages` with public visibility.
- Image conversion `thumb` (368x232) generated non-queued on `static-section-image`.
- File field name is `media` for all media types. Legacy `nil` type defaults to `text` with `media` prohibited to keep backward compat.

---

## Fixed-Page Invariants

| Rule | Enforcement |
|------|-------------|
| Page set is fixed to `about-us`, `terms-and-conditions`, `privacy-policy` | `StaticPageSeeder` `firstOrCreate`, idempotent, never overwrites titles/`is_active`, never deletes sections |
| No page creation | No `POST /static-pages` route → `405` |
| No page deletion | No `DELETE /static-pages/{slug}` route → `405` |
| Slug immutable | `PUT` request only forwards `title` + `is_active` |
| Sections belong to one page | FK `static_page_id` NOT NULL + cascade delete; service `assertSectionBelongsToPage` + scoped reorder → `404` on cross-page |
| Legacy `type=NULL` | Migration defaults `type='text'` + backfill; resource defaults `type ?? 'text'`, validation `sometimes` for compat |
