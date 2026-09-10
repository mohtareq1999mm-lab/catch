# Banner Feature - API Investigation

## Feature Name

Banner Management

## Description

CRUD for promotional banners with translatable titles/descriptions, desktop/mobile image support via Spatie MediaLibrary, product association, status toggle, and drag-and-drop reordering via Spatie EloquentSortable. 7 endpoints total (5 standard apiResource + 2 custom).

## Architecture

```
[Admin Client]
    |
    |--- GET    /banners               (VIEW_BANNERS)
    |--- POST   /banners               (CREATE_BANNERS)
    |--- GET    /banners/{id}          (VIEW_BANNERS)
    |--- PUT    /banners/{id}          (UPDATE_BANNERS)
    |--- DELETE /banners/{id}          (DELETE_BANNERS)
    |--- PUT    /banner/change-status  (UPDATE_BANNERS)
    |--- POST   /banner/reorder        (UPDATE_BANNERS)
    |
    v
[BannerController]
    |--- DI of BannerRepository
    |--- Permission middleware (Spatie)
    |
    v
[BannerRepository]
    |--- extends BaseRepository (Prettus)
    |--- trait MediaManager (image upload)
    |--- DB::transaction on create/update
    |
    v
[Banner Model]
    |--- Spatie Translatable (title, description)
    |--- Spatie MediaLibrary (banners-desktop, banners-mobile)
    |--- Spatie EloquentSortable (order column)
    |--- SoftDeletes
    |--- BelongsToMany products
    |
    v
[BannerResource]
    |--- title (translated), description (translated)
    |--- image.desktop, image.mobile (media URLs)
    |--- products (when loaded)
```

## Key Endpoints

| Method | URI | Controller Method | Permission |
|--------|-----|-------------------|------------|
| GET | `/banners` | `index` | VIEW_BANNERS |
| POST | `/banners` | `store` | CREATE_BANNERS |
| GET | `/banners/{id}` | `show` | VIEW_BANNERS |
| PUT | `/banners/{id}` | `update` | UPDATE_BANNERS |
| DELETE | `/banners/{id}` | `destroy` | DELETE_BANNERS |
| PUT | `/banner/change-status` | `changeStatus` | UPDATE_BANNERS |
| POST | `/banner/reorder` | `reorder` | UPDATE_BANNERS |

## Key Files

| Layer | Path |
|-------|------|
| Controller | `packages/marvel/src/Http/Controllers/BannerController.php` |
| Model | `packages/marvel/src/Database/Models/Banner.php` |
| Repository | `packages/marvel/src/Database/Repositories/BannerRepository.php` |
| Resource | `packages/marvel/src/Http/Resources/BannerResource.php` |
| Request (Create) | `packages/marvel/src/Http/Requests/BannerCreateRequest.php` |
| Request (Update) | `packages/marvel/src/Http/Requests/BannerUpdateRequest.php` |
| Migration (pivot) | `database/migrations/2026_06_23_000001_create_banner_product_table.php` |

## Tech Stack

- **Laravel** with Eloquent ORM
- **Spatie Translatable** — title, description (JSON columns)
- **Spatie MediaLibrary** — desktop + mobile image upload
- **Spatie EloquentSortable** — drag-and-drop reorder
- **Prettus BaseRepository** pattern
- **SoftDeletes**
- **BelongsToMany** products via `banner_product` pivot
