# Tag Module — Backend Architecture

## Overview

The Tag module manages product tags — simple labels that can be associated with products via a many-to-many relationship. Tags support translatable names, optional image/icon uploads, and slug auto-generation. The module provides a full admin CRUD API.

## Endpoints

### Admin API (`/api/v1/tags`)

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| GET | `/api/v1/tags` | `auth:sanctum` | `view-tags` | List tags (paginated, language filter) |
| POST | `/api/v1/tags` | `auth:sanctum` | `create-tags` | Create a new tag |
| GET | `/api/v1/tags/{id}` | `auth:sanctum` | `view-tags` | Show tag by ID or slug |
| PUT | `/api/v1/tags/{id}` | `auth:sanctum` | `update-tags` | Update tag |
| DELETE | `/api/v1/tags/{id}` | `auth:sanctum` | `delete-tags` | Hard-delete tag |

## Route Definitions

**File:** `packages/marvel/src/Rest/Routes.php`

```
Line 405: Route::apiResource('tags', TagController::class, ['only' => ['index', 'show']]);   // Public (no auth)
Line 797: Route::apiResource('tags', TagController::class, ['only' => ['store', 'update', 'destroy']]); // Authenticated
```

The public group (line 405) registers read-only endpoints without auth middleware. The authenticated group (line 797) registers write endpoints with auth + permissions.

## Middleware

### Controller (`Marvel\Http\Controllers\TagController`)

| Method | Middleware |
|--------|-----------|
| `index` | `permission:view-tags` |
| `show` | `permission:view-tags` |
| `store` | `permission:create-tags` |
| `update` | `permission:update-tags` |
| `destroy` | `permission:delete-tags` |

Auth (`auth:sanctum`) is applied at the route group level for authenticated routes.

## Controller Flow

**File:** `packages/marvel/src/Http/Controllers/TagController.php`

```
GET /tags
  → TagController@index(Request)
    → Filter by language ($request->language ?? DEFAULT_LANGUAGE)
    → with(['type'])
    → paginate($limit)
    → TagResource::collection($tags)
    → Extract pagination meta from resource response
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...pagination data...])

POST /tags
  → TagController@store(TagCreateRequest)
    → $validatedData = $request->validated()
    → Generate slug via makeSlug($request)
    → $this->repository->create(['slug' => $slug, 'name' => $validatedData['name']])
    → If products provided: $tag->products()->sync($products) (validated IDs)
    → If image: uploadSingleImage($request, 'image', $tag, 'tags', 'tags')
    → If icon: uploadSingleImage($request, 'icon', $tag, 'tags', 'tags')
    → $tag->load('products')
    → return apiResponse(TAG_CREATED_SUCCESSFULLY, 201, true, TagResource::make($tag))
    → On MarvelException: throw MarvelException(COULD_NOT_CREATE_THE_RESOURCE)

GET /tags/{id or slug}
  → TagController@show(Request, $params)
    → If numeric: find by id with ['products']
    → If non-numeric: find by slug with ['products']
    → return apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, TagResource::make($tag))
    → On MarvelException: throw MarvelException(NOT_FOUND)

PUT /tags/{id}
  → TagController@update(TagUpdateRequest, $id)
    → $request->merge(['id' => $id])
    → tagUpdate($request) [public]
      → $this->repository->findOrFail($request->id)
      → $this->repository->updateTag($request, $tag)
        → $data = $request->only(['name', 'slug', 'icon', 'image'])
        → If name changed: regenerate slug via makeSlug() with update ID
        → $tag->update($data)
        → If products provided: $tag->products()->sync($products)
        → If image: updateSingleImage() [clears + uploads]
        → If icon: updateSingleImage() [clears + uploads]
        → return $this->findOrFail($tag->id)
      → $updatedTag->load('products')
      → return apiResponse(TAG_UPDATED_SUCCESSFULLY, 200, true, TagResource::make($updatedTag))
    → On MarvelException: throw MarvelException(COULD_NOT_UPDATE_THE_RESOURCE)

DELETE /tags/{id}
  → TagController@destroy($id)
    → $this->repository->findOrFail($id)->delete()
    → return apiResponse(TAG_DELETED_SUCCESSFULLY, 200, true, true)
    → On ModelNotFoundException: MarvelException(NOT_FOUND)
    → On MarvelException: throw MarvelException(COULD_NOT_DELETE_THE_RESOURCE)
```

## Repository

**File:** `packages/marvel/src/Database/Repositories/TagRepository.php`
**Extends:** `BaseRepository`

| Method | Description |
|--------|-------------|
| `model()` | Returns `Tag::class` |
| `boot()` | Pushes `RequestCriteria` for search/filter |
| `updateTag($request, $tag)` | Updates tag data, regenerates slug on name change, syncs `products` relation, handles image/icon updates |

**Field searchable:** `name => 'like'`
**Data array:** `name, slug, icon, image`

### `updateTag()` Flow
```
1. $data = $request->only(['name', 'slug', 'icon', 'image'])
2. If name provided: regenerate slug via makeSlug($request, 'slug', $tag->id)
3. $tag->update($data)
4. If products provided: $tag->products()->sync($products) — replaces product_tag pivot rows
5. If image: updateSingleImage() [clears + uploads to 'tags' collection]
6. If icon: updateSingleImage() [clears + uploads to 'tags' collection]
7. Return $this->findOrFail($tag->id)

On error:
  - HttpException(422): "Logo upload failed, please check the file format or size."
```

## Model

**File:** `packages/marvel/src/Database/Models/Tag.php`
**Table:** `tags`
**Traits:** `Sluggable`, `HasTranslations`, `InteractsWithMedia`

| Property | Details |
|----------|---------|
| Translatable | `name` |
| Fillable | `name`, `slug`, `icon`, `image` |
| Casts | `image => json` |

### Relationships

| Relation | Type | Pivot | Notes |
|----------|------|-------|-------|
| `products()` | BelongsToMany | `product_tag` | Many-to-many with products |

### Sluggable Configuration

```php
'slug' => ['source' => 'name']
```

Uses `cviebrock/eloquent-sluggable` package. Since `name` is translatable (stored as JSON array), the sluggable package receives the raw array — slug generation relies on `makeSlug()` in the repository layer rather than the model's auto-slugging.

## Resource

### TagResource (`Marvel\Http\Resources\TagResource`)

**File:** `packages/marvel/src/Http/Resources/TagResource.php`

```json
{
  "id": "integer",
  "name": "translated string",
  "slug": "string",
  "image": "json|null",
  "icon": "string|null",
  "products": "array (only when relation loaded — id, name, slug, status, image.thumbnail)"
}
```

- Image is stored as a JSON object (not media library collection like categories)
- `products` is exposed only when the relation is eager-loaded (show endpoint, and store/update responses after `$tag->load('products')`)
- No `type` relationship data is included in the resource output (though eager-loaded)

## Request Validation

### TagCreateRequest (`Marvel\Http\Requests\TagCreateRequest`)

**File:** `packages/marvel/src/Http/Requests/TagCreateRequest.php`

| Field | Rules |
|-------|-------|
| `name` | `required`, `array` |
| `name.*` | `required`, `string`, `max:150`, `UniqueTranslationRule::for('tags')` |
| `products` | `nullable`, `array` |
| `products.*` | `integer`, `exists:products,id` |
| `icon` | `nullable`, `string` |
| `image` | `nullable`, `image` |

### TagUpdateRequest (`Marvel\Http\Requests\TagUpdateRequest`)

**File:** `packages/marvel/src/Http/Requests/TagUpdateRequest.php`

| Field | Rules |
|-------|-------|
| `name` | `sometimes`, `array` |
| `name.*` | `sometimes`, `string`, `max:150`, `UniqueTranslationRule::for('tags', 'name')->ignore($id)` |
| `products` | `nullable`, `array` |
| `products.*` | `integer`, `exists:products,id` |
| `icon` | `nullable`, `string` |
| `image` | `nullable`, `image` |

## Media Handling

**Trait:** `Marvel\Traits\MediaManager`

**Disk:** `tags` (local, `storage/app/public/tags`)

**Collection:** `tags`

| Operation | Method | Behavior |
|-----------|--------|----------|
| Create | `uploadSingleImage()` | Uploads to `tags` collection on `tags` disk |
| Update | `updateSingleImage()` | Clears entire `tags` collection, then uploads new file |

Both image and icon use the same `tags` collection.

## Database Schema

### Table: `tags`

**Migration:** `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK, auto-increment |
| `name` | json | NOT NULL (translatable) |
| `slug` | string | NOT NULL |
| `icon` | string | NULLABLE |
| `image` | json | NULLABLE |
| `created_at` | timestamp | NULLABLE |
| `updated_at` | timestamp | NULLABLE |

### Table: `product_tag` (pivot)

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK, auto-increment |
| `product_id` | bigint unsigned | FK → products.id ON DELETE CASCADE |
| `tag_id` | bigint unsigned | FK → tags.id ON DELETE CASCADE |

### Foreign Key Behavior

| FK | ON DELETE | ON UPDATE |
|----|-----------|-----------|
| `product_tag.product_id` → `products.id` | **CASCADE** | — |
| `product_tag.tag_id` → `tags.id` | **CASCADE** | — |

## Permissions

**Enum:** `Marvel\Enums\Permission`

| Constant | Value |
|----------|-------|
| `VIEW_TAGS` | `view-tags` |
| `CREATE_TAGS` | `create-tags` |
| `UPDATE_TAGS` | `update-tags` |
| `DELETE_TAGS` | `delete-tags` |

## Constants

**File:** `packages/marvel/config/constants.php`

```php
define('TAG_CREATED_SUCCESSFULLY', APP_NOTICE_DOMAIN . 'MESSAGE.TAG_CREATED_SUCCESSFULLY');
define('TAG_UPDATED_SUCCESSFULLY', APP_NOTICE_DOMAIN . 'MESSAGE.TAG_UPDATED_SUCCESSFULLY');
define('TAG_DELETED_SUCCESSFULLY', APP_NOTICE_DOMAIN . 'MESSAGE.TAG_DELETED_SUCCESSFULLY');
define('TAG_NOT_FOUND', APP_NOTICE_DOMAIN . 'ERROR.TAG_NOT_FOUND');
```

## Translation Keys Used

| Key | Context |
|-----|---------|
| `MESSAGE.FETCH_DATA_SUCCESSFULLY` | GET response |
| `MESSAGE.TAG_CREATED_SUCCESSFULLY` | POST response (201) |
| `MESSAGE.TAG_UPDATED_SUCCESSFULLY` | PUT response |
| `MESSAGE.TAG_DELETED_SUCCESSFULLY` | DELETE response |
| `ERROR.TAG_NOT_FOUND` | 404 error |
| `ERROR.COULD_NOT_CREATE_THE_RESOURCE` | POST/PUT/DELETE error fallback |
| `ERROR.COULD_NOT_UPDATE_THE_RESOURCE` | PUT error |
| `ERROR.COULD_NOT_DELETE_THE_RESOURCE` | DELETE error |
| `ERROR.NOT_FOUND` | Show not found |

## Known Issues & Technical Debt

1. **No soft deletes** — Tags are hard-deleted. Pivot records cascade.
2. **No type relationship in resource** — `type` is eager-loaded but never exposed in TagResource output.
3. **No observer** — No activity logging for tag CRUD operations.
4. **No public endpoints** — No `/general/tags` public API exists.
5. **Media on delete** — Media files are not cleaned up when a tag is deleted.
6. **Destroy response** — `destroy()` now returns `{ status, message, success, data: true }` via `apiResponse` (previously raw boolean).

## Dependencies

| File | Role |
|------|------|
| `packages/marvel/src/Rest/Routes.php` | Route definitions (lines 405, 797) |
| `packages/marvel/src/Http/Controllers/TagController.php` | Controller |
| `packages/marvel/src/Http/Requests/TagCreateRequest.php` | Create validation |
| `packages/marvel/src/Http/Requests/TagUpdateRequest.php` | Update validation |
| `packages/marvel/src/Http/Resources/TagResource.php` | API resource |
| `packages/marvel/src/Database/Models/Tag.php` | Model |
| `packages/marvel/src/Database/Repositories/TagRepository.php` | Repository |
| `packages/marvel/src/Database/Repositories/BaseRepository.php` | Base repository (makeSlug) |
| `packages/marvel/src/Enums/Permission.php` | Permissions enum |
| `packages/marvel/config/constants.php` | Response message constants |
| `packages/marvel/src/Traits/MediaManager.php` | Image upload trait |
| `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php` | Tags + pivot migration |
| `resources/lang/en/message.php` | English translations |
| `resources/lang/ar/message.php` | Arabic translations |
