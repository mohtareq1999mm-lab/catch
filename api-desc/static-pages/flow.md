# Business Flow — Static Pages Module

## 1. Page Lifecycle (seeded, never created/deleted via API)

```
StaticPageSeeder::run()
   └─ for each slug (about-us, terms-and-conditions, privacy-policy):
        StaticPage::firstOrCreate(['slug' => $slug], ['title' => en/ar, 'is_active' => true])
```

- Re-running is a no-op for existing rows — titles, `is_active` and sections are preserved.
- There is **no** create or delete page endpoint, so the page set is stable for frontend links
  and SEO.

## 2. Public Read Flow (cached, media-aware, active-filtered)

```
GET /api/v1/general/static-pages        GET /api/v1/general/static-pages/{slug}
   │                                         │
   ├─ StaticPage::where('is_active', true)   └─ StaticPage::where slug + is_active(true)
   │   ->with(['staticSections'=>              ->with(['staticSections'=> 
   │       where is_active true               where is_active true
   │       ->with('media')                     ->with('media')
   │       ->orderBy('order')])               ->orderBy('order')])
   │       ->get()                             ->firstOrFail()
   │
   └─ remember(tag 'static_pages', md5(fullUrl), closure) — lazy
        • Cache HIT  → 0 DB queries (closure never runs)
        • Cache MISS → run query, cache models+media (NOT rendered resources)
   │
   └─ StaticPageResource::collection/make → localized title per `lang`, sections with media URLs
```

- The cached value is the model set with `media` eager loaded, so `lang: ar` still resolves Arabic `title` and full `content` map at render time even on hit. Inactive sections are excluded by the query, not filtered in resource.

## 3. Admin Mutation Flow (cache-invalidating, transactional, media lifecycle)

Every mutation flushes tag `static_pages` (controller `flushTag` + observers `StaticPageObserver|StaticSectionObserver` on created/updated/deleted). Reorder relies on controller flush (raw `setNewOrder` fires no events).

| Action | Endpoint | Service method | Details | Cache |
|--------|----------|----------------|---------|-------|
| Update page | `PUT /{slug}` | `updatePage` (title/is_active only) | `load('staticSections.media')` | controller+observer |
| Create text | `POST /{slug}/sections` type=text JSON | `createSection` | `DB::transaction` create, no media, validates `content` required, `media` prohibited | controller+observer |
| Create image/screenshot | `POST /{slug}/sections` multipart `media` | `createSection` | `DB::transaction` create, then `validateMediaMime` + `addMedia()->toMediaCollection(static-section-image)` singleFile, compensates delete on failure | controller+observer |
| Create video | `POST /{slug}/sections` multipart `media` | `createSection` | same → `static-section-video` max 20MB | controller+observer |
| Update section | `PUT /{slug}/sections/{id}` | `updateSection` | `assert ownership` → `DB::transaction` update fields, then media branch (see §4) | controller+observer |
| Delete section | `DELETE /{slug}/sections/{id}` | `deleteSection` | clears both collections then `delete()` (hard) | controller+observer |
| Reorder | `POST /{slug}/sections/reorder` | `reorderSections` | `count where static_page_id whereIn` vs `array_unique` →404 on foreign, `DB::transaction` + `setNewOrder(...,scope where static_page_id)` | controller only |

## 4. Media Lifecycle (type + collection)

```
type=image|screenshot → collection static-section-image (disk static-pages, singleFile, thumb 368x232)
type=video           → collection static-section-video  (disk static-pages, singleFile)
type=text            → no collection
```

**Create:** `media` required for `image|screenshot|video`, prohibited for `text`. Request validates `mimetypes` (image jpeg,png,webp,gif max 5MB; video mp4,webm,ogg,mov,avi max 20MB) + service `validateMediaMimeForType()`.

**Update replacement matrix (all 8 transitions):**
```
image→image, image→video, image→screenshot
video→video, video→image, video→screenshot
screenshot→image, screenshot→video
→ clear both collections, add to resolved collection, other empty, resource returns new media
```
Implemented as `clearMediaCollection(image)+clearMediaCollection(video)` then `addMedia` to `resolveCollectionForType(resolvedType)`.

**Removal:** `remove_media=true` (accepts true,1,on,yes) → clear both collections. Omission → keep. `remove_media` prohibited on create.

**Type change without file:** `image→text` or `video→text` → clear if no file and not already removing. `image→video` without file and no existing video media → 422 `media required when changing type`.

**Delete:** clears both collections before `delete()` to avoid orphan files.

## 5. Section Ownership Guard

```
updateSection / deleteSection
   └─ assertSectionBelongsToPage(page, section)
        └─ (int) $section->static_page_id !== (int) $page->id → throw ModelNotFoundException → 404

reorderSections
   └─ every id must belong to page (count check) → else 404
   └─ setNewOrder(ids,1,'id',fn($q)=>where static_page_id = page.id) → second safety layer scoped update
   └─ DB::transaction
```

## 6. Section Ordering

- New section → `order = max(order within page)+1` (Spatie `sort_when_creating` scoped by `buildSortQuery()`).
- Reorder → supplied id order becomes `1,2,3...` contiguous.
- Delete → remaining keep `order` (gaps allowed); next create `max+1`.

## 7. Validation Guard

- `type`: `in:text,image,video,screenshot` (store `sometimes` defaults `text` for legacy), `title` required array `title.en` required string max:255, `content` outer `array_is_list` rejected (`STATIC_SECTION_CONTENT_INVALID` 422), `type=text` requires non-empty `content`, `config` nullable array, `is_active` in:0,1, `remove_media` in:0,1 boolean, `media` per-type file rules above.
- TiDB-safe: `string` type not `ENUM`, `DB::transaction` without locking, `json` nullable.

## 8. Backward Compatibility

- Migration `type` default `text` backfills legacy `NULL/''` → `text`. Service `normalizeCreateData` defaults `type=text, is_active=true`. Resource defaults `type ?? text, is_active ?? true`. `Store` request `type` `sometimes` allows old `{title,content}` payloads → `text`. Existing 105 tests unchanged.
