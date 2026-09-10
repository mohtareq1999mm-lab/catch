# Pages Module — Backend Architecture

## Endpoints

### Public (no auth)

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/product-type` | List product types with localized labels |
| GET | `/api/v1/general/content-pages` | List pages with active sections |
| GET | `/api/v1/general/content-pages/{slug}` | Show page by slug |

### Admin (role: super_admin|editor, auth:sanctum, email.verified)

| Method | URL | Permissions | Purpose |
|--------|-----|-------------|---------|
| GET | `/api/v1/content-pages` | view-content-pages | List all pages |
| POST | `/api/v1/content-pages` | create-content-pages | Create page |
| GET | `/api/v1/content-pages/{content_page}` | view-content-pages | Show page |
| PUT | `/api/v1/content-pages/{content_page}` | update-content-pages | Update page |
| DELETE | `/api/v1/content-pages/{content_page}` | delete-content-pages | Delete page |
| POST | `/api/v1/content-pages/{content_page}/attach-sections` | update-content-pages | Attach sections |
| PATCH | `/api/v1/content-pages/{content_page}/toggle-active` | update-content-pages | Toggle active |
| GET | `/api/v1/sections` | view-sections | List sections |
| POST | `/api/v1/sections` | create-sections | Create section |
| GET | `/api/v1/sections/{section}` | view-sections | Show section |
| PUT | `/api/v1/sections/{section}` | update-sections | Update section |
| DELETE | `/api/v1/sections/{section}` | delete-sections | Delete section |
| POST | `/api/v1/sections/reorder` | update-sections | Reorder sections |
| GET | `/api/v1/sections/types` | view-sections | List types in use |
| PATCH | `/api/v1/sections/{section}/toggle-active` | update-sections | Toggle active |
| GET | `/api/v1/section-types` | view-section-types | List types |
| POST | `/api/v1/section-types` | create-section-types | Create type |
| GET | `/api/v1/section-types/{section_type}` | view-section-types | Show type settings |
| PUT | `/api/v1/section-types/{section_type}` | update-section-types | Update type |
| DELETE | `/api/v1/section-types/{section_type}` | delete-section-types | Delete type |
| GET | `/api/v1/section-types/{type}/settings` | view-section-types | Get type settings |
| POST | `/api/v1/section-types/{type}/settings` | update-section-types | Update type settings |

## Route Definitions

**File:** `packages/marvel/src/Rest/Routes.php` (lines 825-842) — Product type route:

```php
Route::get('product-type', function () {
    $keys = [
        'best_product_sales', 'brands_product', 'new_arrivals',
        'all_product_discounts', 'product_discount_today_or_low_qty',
        'flash_sales_product', 'flash_sales_end_today',
        'product_for_parent_category', 'flash_sales_end_week',
    ];

    $result = [];
    foreach ($keys as $key) {
        $result[$key] = __("message.PRODUCT_TYPE." . strtoupper($key));
    }
    return $result;
});
```

**File:** `packages/marvel/src/Rest/Routes.php` (lines 425-443) — Admin pages routes:

```php
Route::group(
    ['middleware' => ['role:' . Role::SUPER_ADMIN . "|" . Role::EDITOR, 'auth:sanctum', 'email.verified']],
    function () {
        Route::post('content-pages/{content_page}/attach-sections', [ContentPageController::class, 'attachSections']);
        Route::patch('content-pages/{content_page}/toggle-active', [ContentPageController::class, 'toggleActive']);
        Route::apiResource('content-pages', ContentPageController::class);
        Route::post('sections/reorder', [SectionController::class, 'reorder']);
        Route::get('sections/types', [SectionController::class, 'getTypeSection']);
        Route::patch('sections/{section}/toggle-active', [SectionController::class, 'toggleStatus']);
        Route::apiResource('sections', SectionController::class);
        Route::apiResource('section-types', SectionTypeController::class);
        Route::post('section-types/{type}/settings', [SectionTypeController::class, 'updateSettings']);
        Route::get('section-types/{type}/settings', [SectionTypeController::class, 'settings']);
    }
);
```

**File:** `routes/api.php` (lines 67-71) — Public routes:

```php
Route::controller(ContentPageController::class)->group(function () {
    Route::get('content-pages', 'index')->name('general-content-page-index');
    Route::get('content-pages/{slug}', 'show')->name('general-content-page-show');
});
```

Loaded via `RouteServiceProvider` with `prefix('api')` + `prefix('v1/general')`.

## Key Classes

| Class | Key Methods | Responsibility |
|-------|-------------|----------------|
| `ContentPageController` (admin) | index, store, show, update, destroy, attachSections, toggleActive | Admin CRUD + attach/toggle |
| `ContentPageController` (public) | index, show | Public page listing (active sections only) |
| `SectionController` | index, store, show, update, destroy, reorder, toggleStatus, getTypeSection | Section CRUD + reorder/toggle |
| `SectionTypeController` | index, store, show, update, destroy, settings, updateSettings, byType | Section type management + settings |
| `SectionTypeService` | getAll, getByType, getSettingsGrouped, createType, updateType, deleteType, upsertSettings | Type + settings business logic |
| `ContentPageResource` | toArray | Response: id, title, slug, is_active, sections |
| `SectionResource` | toArray | Response: id, type, title, is_active, endpoint, order, setting |

## Request Flow

### Bug Fix: excludeUnvalidatedArrayKeys

Laravel's Validator Factory enables `excludeUnvalidatedArrayKeys` by default. When a FormRequest has rules like:
```
'title' => 'required|array',
'title.*' => 'required|string|max:50',
```
The `validated()` method skips the parent `title` key because it has `array` rule AND wildcard sub-rules. Only `title.*` is returned, and `Arr::set($results, 'title.*', [...])` on a non-existent parent key creates an empty array `[]`.

**Fix:** In `SectionController::store()` and `update()`, if `title` is missing from validated data but present in the request, re-add it from `$request->input('title')`.

```php
if (! isset($data['title']) && $request->has('title')) {
    $data['title'] = $request->input('title');
}
```

This matches the pattern used by `ContentPageController::store()` which uses `$request->only(['title'])` instead of `validated()`.

---

### Flow: Create Page with Sections

```
POST /api/v1/content-pages { title: { en: "Home" } }
  → StoreContentPageRequest validation
  → ContentPageController@store
    → DB::transaction
      → ContentPage::create({ title, slug, is_active: true })
    → ContentPageResource::make($page)
    → Response: 201

POST /api/v1/content-pages/{id}/attach-sections { sections: [1,2,3] }
  → AttachSectionsRequest validation
  → ContentPageController@attachSections
    → DB::transaction
      → Section::whereIn('id', $sectionIds)->each → set content_page_id
      → $page->load('sections')
    → ContentPageResource::make($page)
    → Response: 200
```

### Flow: Section Settings Resolution

```
SectionResource::toArray
  → getSettings()
    → $this->setting !== null? YES → use section's own setting
    → NO → SectionType::where('type', $this->type)->first()
      → settings()->where('setting_key', 'front')->value
      → settings()->where('setting_key', 'back')->value
    → Merge into ['front' => [...], 'back' => [...]]
  → endpoint = 'general/' . $this->type . '?' . http_build_query(back params)
```

## Authorization

All admin endpoints use Spatie permission-based middleware:

| Permission | Applied To |
|------------|------------|
| `view-content-pages` | index, show |
| `create-content-pages` | store |
| `update-content-pages` | update, attachSections, toggleActive |
| `delete-content-pages` | destroy |
| `view-sections` | index, show, getTypeSection |
| `create-sections` | store |
| `update-sections` | update, reorder, toggleStatus |
| `delete-sections` | destroy |
| `view-section-types` | index, show, settings, byType |
| `create-section-types` | store |
| `update-section-types` | update, updateSettings |
| `delete-section-types` | destroy |
