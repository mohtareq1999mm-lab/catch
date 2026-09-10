# Slider Feature - API Investigation

## Feature Name

Slider Management

## Description

CRUD for promotional sliders with translatable titles, desktop/mobile image support via Spatie MediaLibrary, product association, status toggle, and drag-and-drop reordering via Spatie EloquentSortable. 7 admin endpoints + 2 public endpoints.

## Architecture

```
[Admin Client]                    [Public (no auth)]
    |                                  |
    |--- GET    /sliders               |--- GET /general/sliders
    |--- POST   /sliders               |--- GET /general/sliders/{slug}
    |--- GET    /sliders/{id}
    |--- PUT    /sliders/{id}
    |--- DELETE /sliders/{id}
    |--- PATCH  /sliders/change-status
    |--- PUT    /sliders/reorder
    |
    v
[SliderController (admin)]         [GeneralSliderController (public)]
    |--- DI of SliderRepository
    |--- Permission middleware (Spatie)
    |
    v
[SliderRepository]
    |--- extends BaseRepository (Prettus)
    |--- trait MediaManager (image upload)
    |--- DB::transaction on create/update
    |
    v
[Slider Model]
    |--- Spatie Translatable (title)
    |--- Spatie MediaLibrary (sliders-desktop, sliders-mobile)
    |--- Spatie EloquentSortable (order column)
    |--- SoftDeletes
    |--- BelongsToMany products
    |
    v
[SliderResource]
    |--- title (translated), slug, status, order
    |--- image.desktop, image.mobile (media URLs)
    |--- products (when loaded)
```

## Key Endpoints

| Method | URI | Controller Method | Permission |
|--------|-----|-------------------|------------|
| GET | `/sliders` | `index` | VIEW_SLIDER |
| POST | `/sliders` | `store` | CREATE_SLIDER |
| GET | `/sliders/{id}` | `show` | VIEW_SLIDER |
| PUT | `/sliders/{id}` | `update` | UPDATE_SLIDER |
| DELETE | `/sliders/{id}` | `destroy` | DELETE_SLIDER |
| PATCH | `/sliders/change-status` | `changeStatus` | UPDATE_SLIDER |
| PUT | `/sliders/reorder` | `reorder` | UPDATE_SLIDER |

## Key Files

| Layer | Path |
|-------|------|
| Controller (Admin) | `packages/marvel/src/Http/Controllers/SliderController.php` |
| Controller (Public) | `app/Http/Controllers/Api/General/SliderController.php` |
| Model | `packages/marvel/src/Database/Models/Slider.php` |
| Repository | `packages/marvel/src/Database/Repositories/SliderRepository.php` |
| Resource (Admin) | `packages/marvel/src/Http/Resources/SliderResource.php` |
| Resource (Public) | `app/Http/Resources/Slider/SliderResource.php` |
| Request (Create) | `packages/marvel/src/Http/Requests/SliderCreateRequest.php` |
| Request (Update) | `packages/marvel/src/Http/Requests/SliderUpdateRequest.php` |
| Service | `app/Services/General/SliderService.php` |
| Observer | `app/Observers/MediaCleanupObserver.php` |
| Seeder | `database/seeders/SliderSeeder.php` |
| Test | `tests/Feature/SliderApiTest.php` |

## Tech Stack

- **Laravel** with Eloquent ORM
- **Spatie Translatable** — title (JSON column)
- **Spatie MediaLibrary** — desktop + mobile image upload
- **Spatie EloquentSortable** — drag-and-drop reorder
- **Prettus BaseRepository** pattern
- **SoftDeletes**
- **BelongsToMany** products via `slider_product` pivot
