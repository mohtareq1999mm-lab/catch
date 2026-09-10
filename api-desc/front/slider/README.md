# Slider Feature - API Investigation

## Feature Name

Slider Management (Hero Banners / Carousels)

## Description

The Slider feature provides hero banner/carousel management with image uploads (desktop + mobile), product associations, translatable titles, drag-and-drop reordering, and active status toggling. Sliders are displayed on the shop homepage as rotating carousels to promote products, sales, and campaigns.

## Architecture Overview

```
[Client]
    |
    |--- GET /api/v1/general/sliders             (Public API)
    |--- GET /api/v1/general/sliders/{slug}      (Public API)
    |--- GET /api/v1/sliders                     (Admin API - auth)
    |--- POST /api/v1/sliders                    (Admin API - auth)
    |--- GET /api/v1/sliders/{id}                (Admin API - auth)
    |--- PUT /api/v1/sliders/{id}                (Admin API - auth)
    |--- DELETE /api/v1/sliders/{id}             (Admin API - auth)
    |--- PATCH /api/v1/sliders/change-status     (Admin API - auth)
    |--- PUT /api/v1/sliders/reorder             (Admin API - auth)
    |
    v
[SliderController (Marvel)]  or  [SliderController (General)]
    |
    v
[SliderRepository / SliderService]
    |
    v
[Slider Model]
    |--- products (BelongsToMany Product via slider_product pivot)
    |
    v
[sliders table]  ←→  [slider_product pivot]
```

## Key Endpoints

### Public API (routes/api.php - prefix: `v1/general`)

| Method | URI | Controller | Auth |
|--------|-----|-----------|------|
| GET | `/v1/general/sliders` | `General\SliderController@index` | No |
| GET | `/v1/general/sliders/{slug}` | `General\SliderController@getSliderBySlug` | No |

### Admin API (packages/marvel/src/Rest/Routes.php - prefix: `v1`)

| Method | URI | Controller | Permission |
|--------|-----|-----------|-----------|
| GET | `/v1/sliders` | `SliderController@index` | `view-slider` |
| POST | `/v1/sliders` | `SliderController@store` | `create-slider` |
| GET | `/v1/sliders/{slider}` | `SliderController@show` | `view-slider` |
| PUT | `/v1/sliders/{slider}` | `SliderController@update` | `update-slider` |
| DELETE | `/v1/sliders/{slider}` | `SliderController@destroy` | `delete-slider` |
| PATCH | `/v1/sliders/change-status` | `SliderController@changeStatus` | `update-slider` |
| PUT | `/v1/sliders/reorder` | `SliderController@reorder` | `update-slider` |

## Key Files

| Layer | Path |
|-------|------|
| Model | `packages/marvel/src/Database/Models/Slider.php` |
| Repository | `packages/marvel/src/Database/Repositories/SliderRepository.php` |
| Controller (Admin) | `packages/marvel/src/Http/Controllers/SliderController.php` |
| Controller (Public) | `app/Http/Controllers/Api/General/SliderController.php` |
| Service (Public) | `app/Services/General/SliderService.php` |
| Create Request | `packages/marvel/src/Http/Requests/SliderCreateRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/SliderUpdateRequest.php` |
| Resource (Admin) | `packages/marvel/src/Http/Resources/SliderResource.php` |
| Resource (Public) | `app/Http/Resources/Slider/SliderResource.php` |
| Routes (Marvel) | `packages/marvel/src/Rest/Routes.php` |
| Routes (General) | `routes/api.php` |
| Migration (sliders) | `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php` |
| Migration (pivot) | `database/migrations/2026_06_17_000003_create_slider_product_table.php` |
| Seeder | `database/seeders/SliderSeeder.php` |
| Seeder (pivot) | `database/seeders/SliderProductSeeder.php` |
| Test | `tests/Feature/SliderApiTest.php` |
| Import Sheet | `packages/marvel/src/Imports/Sheets/SlidersSheetImport.php` |
| Export Sheet | `packages/marvel/src/Exports/Sheets/SlidersSheetExport.php` |

## Tech Stack

- **Laravel** with Eloquent ORM
- **Spatie Translatable** for localized titles
- **Spatie Media Library** for image attachments (desktop + mobile)
- **Spatie Sortable** for drag-and-drop reordering
- **Soft Deletes** for safe removal
- **Spatie Permission** for authorization (no Policy class)
