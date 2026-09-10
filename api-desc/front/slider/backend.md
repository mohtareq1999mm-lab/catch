# Backend - Slider Feature

## Overview

The Slider feature is implemented across two layers:

1. **App Layer (`app/`)**: Public-facing API consumed by the shop frontend (homepage carousel)
2. **Package Layer (`packages/marvel/`)**: Admin API with full CRUD, reordering, status management

## Key Files

### 1. Model - `packages/marvel/src/Database/Models/Slider.php`

**Table:** `sliders`

**Traits:** `InteractsWithMedia` (Spatie), `SortableTrait` (Spatie), `SoftDeletes`, `HasTranslations` (Spatie)

**Translatable:** `['title']`

**Fillable:**
- `title` (translatable JSON)
- `slug` (auto-generated from English title)
- `order` (auto-managed by Spatie Sortable)
- `status` (boolean)

**Relationships:**

| Method | Type | Related | Pivot |
|--------|------|---------|-------|
| `products()` | `BelongsToMany` | `Product` | `slider_product` |

**Scopes:** `active()`, `inactive()`, `search($field, $term, $locale)`

**Boot Events:**
- `saving`: Auto-generates `slug` from English title via `Str::slug()`

### 2. Repository - `packages/marvel/src/Database/Repositories/SliderRepository.php`

**Extends:** `BaseRepository` (with `CacheableRepository` trait)

**Methods:**

| Method | Description |
|--------|-------------|
| `model()` | Returns `Slider::class` |
| `getSliders(Request)` | Paginated list with active filter, sorting |
| `createSlider(Request)` | Creates in transaction (model + images + product sync) |
| `updateSlider(Request, $id)` | Updates in transaction (model + images + product sync) |
| `changeStatus($id)` | Toggles `status` boolean |
| `reorder(array $sliders)` | Spatie Sortable `setNewOrder()` |

**DB Transactions:** Used in `createSlider()` and `updateSlider()`.

### 3. Controller (Admin) - `packages/marvel/src/Http/Controllers/SliderController.php`

**Extends:** `CoreController`

**Permissions (via middleware in constructor):**

| Method | Permission |
|--------|-----------|
| `index` | `view-slider` |
| `store` | `create-slider` |
| `show` | `view-slider` |
| `update` | `update-slider` |
| `destroy` | `delete-slider` |
| `changeStatus` | `update-slider` |
| `reorder` | `update-slider` |

**Methods:**

| Method | Signature | Description |
|--------|-----------|-------------|
| `index` | `(Request $request)` | Paginated list via repository |
| `store` | `(SliderCreateRequest $request)` | Create via repository |
| `show` | `(string $id)` | Single slider by ID |
| `update` | `(SliderUpdateRequest $request, string $id)` | Update via repository |
| `destroy` | `(string $id)` | Soft delete |
| `changeStatus` | `(Request $request)` | Toggle active/inactive |
| `reorder` | `(Request $request)` | Reorder sliders |

### 4. Controller (Public) - `app/Http/Controllers/Api/General/SliderController.php`

**Methods:**

| Method | Signature | Description |
|--------|-----------|-------------|
| `index` | `(Request $request)` | Lists active sliders, supports `?slug=` param |
| `getSliderBySlug` | `($slug)` | Single slider by slug with products |

### 5. Service (Public) - `app/Services/General/SliderService.php`

**Uses trait:** `HasChannelFilter`

| Method | Description |
|--------|-------------|
| `getSliders($request)` | Filters: start_date, end_date, limit, slidersId, order. Returns active sliders. |
| `getSliderBySlug($slug)` | Fetches active slider by slug, eager-loads products with channel filter, enriches pricing |

### 6. Form Requests

**SliderCreateRequest** (`packages/marvel/src/Http/Requests/SliderCreateRequest.php`):
- `title.*` (required, array of locale strings)
- `title.en` (required, string, unique translation)
- `title.ar` (required, string, unique translation)
- `image_desktop` (required, image, mimes:jpeg/png/jpg/gif, max:2MB)
- `image_mobile` (required, image, mimes:jpeg/png/jpg/gif, max:2MB)
- `status` (sometimes, in:1,0)
- `products` (sometimes, array of existing product IDs)

**SliderUpdateRequest** (`packages/marvel/src/Http/Requests/SliderUpdateRequest.php`):
- Same as create but all fields optional
- Title unique check ignores current slider ID

### 7. API Resources

| Resource | Route | Fields |
|----------|-------|--------|
| `SliderResource` (Admin) | Admin routes | id, title (string on index, object on show/update), slug, status, order, image {desktop, mobile}, products |
| `SliderResource` (Public) | General routes | id, title (string), slug, status, image {desktop, mobile}, products (when loaded) |

### 8. Permissions - `packages/marvel/src/Enums/Permission.php`

| Constant | Value |
|----------|-------|
| `VIEW_SLIDER` | `view-slider` |
| `CREATE_SLIDER` | `create-slider` |
| `UPDATE_SLIDER` | `update-slider` |
| `DELETE_SLIDER` | `delete-slider` |

### 9. Config Constants - `packages/marvel/config/constants.php`

| Constant | Message Key |
|----------|-------------|
| `SLIDER_CREATED_SUCCESSFULLY` | `message.SLIDER_CREATED_SUCCESSFULLY` |
| `SLIDER_UPDATED_SUCCESSFULLY` | `message.SLIDER_UPDATED_SUCCESSFULLY` |
| `SLIDER_DELETED_SUCCESSFULLY` | `message.SLIDER_DELETED_SUCCESSFULLY` |
| `SLIDER_STATUS_CHANGED` | `message.SLIDER_STATUS_CHANGED` |
| `SLIDERS_REORDERED_SUCCESSFULLY` | `message.SLIDERS_REORDERED_SUCCESSFULLY` |

### 10. Media Collections

| Collection | Used In |
|-----------|---------|
| `slider-image-desktop` | Create (initial upload) |
| `slider-image-mobile` | Create (initial upload) |
| `sliders-desktop` | Update (replacement) / Resource fallback |
| `sliders-mobile` | Update (replacement) / Resource fallback |

### 11. Import/Export

- **Import:** `SlidersSheetImport` — reads Excel, groups by `product_sku`, syncs `slider_slug` via `ProductImportService::syncSliders()`
- **Export:** `SlidersSheetExport` — exports `product_sku` + `slider_slug` columns

## Data Flow (Public Slider Listing)

```
Client
  |
  GET /api/v1/general/sliders?limit=5
  |
  v
General\SliderController@index(Request $request)
  |
  v
SliderService::getSliders($request)
  |--- Applies channel filter if applicable
  |--- Filters by: start_date, end_date, limit, slidersId
  |--- Orders by specified order (default: asc)
  |--- Limits results
  |
  v
SliderResource collection
  |--- Maps each slider: id, title (translated), slug, status,
  |--- image { desktop URL, mobile URL }, products (when loaded)
  |
  v
JSON Response
```

## Data Flow (Admin Slider Creation)

```
Client
  |
  POST /api/v1/sliders
  Authorization: Bearer <token>
  Content-Type: multipart/form-data
  Body: title[en]=Summer Sale, image_desktop=<file>, ...
  |
  v
Permission middleware: create-slider
  |
  v
SliderController@store(SliderCreateRequest $request)
  |
  +-- Request validation (title, images, status, products)
  |
  v
SliderRepository::createSlider($request)
  |
  +-- DB::beginTransaction()
  +-- Create Slider record (title, slug auto-generated)
  +-- Upload image_desktop → media collection 'slider-image-desktop'
  +-- Upload image_mobile → media collection 'slider-image-mobile'
  +-- Sync products pivot
  +-- DB::commit()
  |
  v
Admin SliderResource response
```
