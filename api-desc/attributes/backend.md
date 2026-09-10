# Attribute Module — Backend Architecture

## Overview

The Attribute module manages product attributes and their translatable values. Attributes serve as the foundation for product variations (e.g., Size → Small, Medium, Large). Values are created and managed inline within the attribute CRUD — no separate value endpoints.

## Endpoints (CRUD only)

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| GET | `/api/v1/attributes` | Controller middleware | `view-attributes` | List attributes (paginated, sortable, searchable) |
| POST | `/api/v1/attributes` | Controller middleware | `create-attribute` | Create attribute with optional values |
| GET | `/api/v1/attributes/{id}` | Controller middleware | `view-attributes` | Show attribute by ID or slug with values |
| PUT | `/api/v1/attributes/{id}` | Controller middleware | `update-attribute` | Update attribute name and/or sync values |
| DELETE | `/api/v1/attributes/{id}` | Controller middleware | `delete-attribute` | Delete attribute (hard delete, cascades to values) |

## Route Definitions

**File:** `packages/marvel/src/Rest/Routes.php`

```
Line 190: Route::apiResource('attributes', AttributeController::class);
Line 329: Route::apiResource('attributes', AttributeController::class, ['only' => ['index', 'show']]);
```

Routes are split across two groups:
- Line 190: Full CRUD (auth/permission via controller middleware)
- Line 329: Read-only public (index/show with view permission)

## Middleware

### AttributeController

| Method | Middleware |
|--------|-----------|
| `index` | `permission:view-attributes` (via constructor) |
| `show` | `permission:view-attributes` (via constructor) |
| `store` | `permission:create-attribute` (via constructor) |
| `update` | `permission:update-attribute` (via constructor) |
| `destroy` | `permission:delete-attribute` (via constructor) |

## Controller Flow

```
GET /attributes
  → AttributeController@index(Request)
    → $this->repository->with('values')
    → If order: orderBy($order, $sortedBy)
    → paginate($limit)
    → AttributeResource::collection($attributes)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, pagination data)

POST /attributes
  → AttributeController@store(AttributeRequest)
    → AttributeRepository::storeAttribute($request)
      → DB::beginTransaction()
        → Generate slug via makeSlug($request)
        → $this->create($request->only(['name', 'slug']))
        → If values[]: create AttributeValue for each
        → DB::commit()
      → On failure: HttpException(400)
    → $attribute->load(['values'])
    → AttributeResource::make($attribute)
    → $this->apiResponse(ATTRIBUTE_CREATED_SUCCESSFULLY, 201, true, ...)

GET /attributes/{id}
  → AttributeController@show(Request, $params)
    → If numeric: where('id', $params)
    → If string: where('slug', $params)
    → $this->repository->with('values')->firstOrFail()
    → AttributeResource::make($attribute)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)
    → On failure: MarvelException(NOT_FOUND)

PUT /attributes/{id}
  → AttributeController@update(AttributeRequest, $id)
    → $request->id = $id
    → updateAttribute($request) [private]
      → $this->repository->with('values')->findOrFail($request->id)
      → $this->repository->updateAttribute($request, $attribute)
        → Regenerate slug
        → $attribute->update($request->only(['name', 'slug']))
        → If values[]: sync (create new, remove missing)
    → AttributeResource::make($attribute)
    → $this->apiResponse(ATTRIBUTE_UPDATED_SUCCESSFULLY, 200, true, ...)

DELETE /attributes/{id}
  → AttributeController@destroy(Request, $id)
    → $request->id = $id
    → deleteAttribute($request)
      → $this->repository->findOrFail($request->id)->delete()
      → FK CASCADE removes attribute_values + pivot records
    → $this->apiResponse(ATTRIBUTE_DELETED_SUCCESSFULLY, 200, true)
```

## Repository

**File:** `packages/marvel/src/Database/Repositories/AttributeRepository.php`
**Extends:** `BaseRepository`

| Method | Description |
|--------|-------------|
| `model()` | Returns `Attribute::class` |
| `boot()` | Pushes `RequestCriteria` for search/filter |
| `storeAttribute($request)` | Transactional create with slug, values sync |
| `updateAttribute($request, $attribute)` | Update with slug regeneration, values sync |

**Field searchable:** `name => 'like'`
**Data array:** `name, slug`

### `storeAttribute()` Flow
```
1. DB::beginTransaction()
2. Generate slug via makeSlug($request)
3. $this->create($request->only(['name', 'slug']))
4. If values[]: create AttributeValue for each
5. DB::commit()
6. Return $attribute->load(['values'])
```

### `updateAttribute()` Flow
```
1. Regenerate slug via makeSlug($request, 'slug', $attribute->id)
2. $attribute->update($request->only(['name', 'slug']))
3. If values[]: sync (create new slugs, delete removed slugs)
4. Return $this->with(['values'])->findOrFail($attribute->id)
```

Note: `updateAttribute()` does NOT wrap in DB transaction.

## Model

### Attribute (`Marvel\Database\Models\Attribute`)
**Table:** `attributes`
**Traits:** `HasTranslations`, `Sluggable`

| Property | Details |
|----------|---------|
| Translatable | `name` |
| Fillable | `name`, `slug` |

### Relationships

| Relation | Type | FK | Notes |
|----------|------|----|-------|
| `values()` | HasMany | `attribute_id` | AttributeValue records |

### AttributeValue (`Marvel\Database\Models\AttributeValue`)
**Table:** `attribute_values`
**Traits:** `HasTranslations`, `Sluggable`

| Property | Details |
|----------|---------|
| Translatable | `value` |
| Fillable | `value`, `slug`, `attribute_id` |

## Resources

### AttributeResource
```json
{
  "id": "integer",
  "name": "translated (index) | raw (show)",
  "slug": "string",
  "values": "[{ id, value, slug }]"  // when loaded
}
```

## Request Validation

### AttributeRequest (create + update)
| Field | Rules |
|-------|-------|
| `name` | required, array |
| `name.en` | required, string, min:2, max:50, unique_translation:attributes->ignore($id) |
| `name.ar` | required, string, min:2, max:50, unique_translation:attributes->ignore($id) |
| `values` | sometimes, array |
| `values.*.value` | required, array |
| `values.*.value.en` | required, string, min:2, max:50 |
| `values.*.value.ar` | required, string, min:2, max:50 |

**Key difference from brands:** Both `name.en` and `name.ar` are individually required (not generic `name.*`).

## Permissions

| Constant | Value |
|----------|-------|
| `VIEW_ATTRIBUTES` | `view-attributes` |
| `CREATE_ATTRIBUTE` | `create-attribute` |
| `UPDATE_ATTRIBUTE` | `update-attribute` |
| `DELETE_ATTRIBUTE` | `delete-attribute` |

## Constants

```php
define('ATTRIBUTE_CREATED_SUCCESSFULLY', APP_NOTICE_DOMAIN . 'MESSAGE.ATTRIBUTE_CREATED_SUCCESSFULLY');
define('ATTRIBUTE_UPDATED_SUCCESSFULLY', APP_NOTICE_DOMAIN . 'MESSAGE.ATTRIBUTE_UPDATED_SUCCESSFULLY');
define('ATTRIBUTE_DELETED_SUCCESSFULLY', APP_NOTICE_DOMAIN . 'MESSAGE.ATTRIBUTE_DELETED_SUCCESSFULLY');
```

## Database Schema

### Table: `attributes`
| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK, auto-increment |
| `slug` | string | NOT NULL |
| `name` | string | NOT NULL (JSON for translations) |
| `created_at` | timestamp | NULLABLE |
| `updated_at` | timestamp | NULLABLE |

### Table: `attribute_values`
| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK, auto-increment |
| `slug` | string | NOT NULL |
| `attribute_id` | bigint unsigned | FK → attributes.id ON DELETE CASCADE |
| `value` | string | NOT NULL (JSON for translations) |
| `created_at` | timestamp | NULLABLE |
| `updated_at` | timestamp | NULLABLE |

**Unique:** `(attribute_id, slug)`

### Table: `attribute_product` (pivot)
| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK |
| `attribute_value_id` | bigint unsigned | FK → attribute_values.id CASCADE |
| `product_variant_id` | bigint unsigned | FK → product_variants.id CASCADE |

### Cascade Chain
```
DELETE attribute → attribute_values CASCADE → attribute_product CASCADE
```

## Dependencies

| File | Role |
|------|------|
| `packages/marvel/src/Rest/Routes.php` | Route definitions |
| `packages/marvel/src/Http/Controllers/AttributeController.php` | CRUD controller |
| `packages/marvel/src/Http/Requests/AttributeRequest.php` | Validation |
| `packages/marvel/src/Http/Resources/AttributeResource.php` | API resource |
| `packages/marvel/src/Database/Models/Attribute.php` | Model |
| `packages/marvel/src/Database/Models/AttributeValue.php` | Value model |
| `packages/marvel/src/Database/Repositories/AttributeRepository.php` | Repository |
| `packages/marvel/src/Enums/Permission.php` | Permissions enum |
| `packages/marvel/config/constants.php` | Response constants |
| `tests/Feature/AttributeApiTest.php` | Tests |
| `tests/Feature/AttributesProductionHardenTest.php` | Tests |
