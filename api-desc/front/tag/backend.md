# Tag Module — Backend Architecture (Public API)

## Endpoints

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/general/tags` | Public | List all tags |
| GET | `/api/v1/general/tags/{slug}` | Public | Get tag by slug |

## Route Definitions

**File:** `routes/api.php` (lines 52-53)

```php
Route::prefix('v1/general')->middleware('api')->group(function () {
    Route::get('tags', [TagController::class, 'index']);
    Route::get('tags/{slug}', [TagController::class, 'show']);
});
```

## Middleware

The `api` middleware group: `throttle:api`, `SubstituteBindings`, `ChannelMiddleware`. No authentication.

## Request Flow

### Flow 1: List Tags

```
Client → GET /api/v1/general/tags
         ↓
    [api] middleware group
         ↓
    TagController@index(Request)
         ↓
    Tag::query()->get()
         ↓
    Collection of Tag models
         ↓
    TagResource::collection($tags)
        → For each tag: id, name, slug, details, image, icon,
          language, translated_languages, type (lazy loaded)
         ↓
    Response: { status:200, message, success:true, data: [...] }
```

### Flow 2: Get Tag by Slug

```
Client → GET /api/v1/general/tags/organic
         ↓
    TagController@show(Request, 'organic')
         ↓
    Tag::query()->where('slug', 'organic')->first()
         ↓
    Found?
    ├─ YES: TagResource::make($tag) → Response: 200
    └─ NO:  Response: 404
```

## Model: Tag

| Column | Type | Description |
|--------|------|-------------|
| id | bigint UNSIGNED | Primary key |
| name | string | Tag name |
| slug | string | URL slug (auto-generated via Sluggable) |
| details | text, nullable | Description |
| image | json, nullable | Image data |
| icon | string, nullable | Icon identifier |
| language | string | Language code |
| type_id | int UNSIGNED, nullable | FK to `types.id` |
| created_at | timestamp | |
| updated_at | timestamp | |

Relations:
- `type()` → BelongsTo `Type`
- `products()` → BelongsToMany via `product_tag`

## Key Classes

| Class | Method | Responsibility |
|-------|--------|----------------|
| `TagController` | `index()` | Return all tags |
| `TagController` | `show()` | Return single tag by slug |
| `TagResource` | `toArray()` | Transform tag with type relationship |
| `Tag` | — | Model with Sluggable, TranslationTrait |

## Dependencies

- **Tag** model → `Type`, `Product` (via pivot)
- **TagResource** → `getResourceData()` helper for type loading

## Known Bug

Eager loading of `type` is missing on both `index()` and `show()`, causing N+1 queries (BUG-TAG-003, BUG-TAG-004).
