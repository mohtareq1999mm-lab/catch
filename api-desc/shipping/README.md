# Shipping Feature - API Investigation

## Feature Name

Shipping Zone Management (Countries, Governorates, Cities)

## Description

Hierarchical CRUD for the shipping zone hierarchy: Country → Governorate → City. Each governorate links to a `ShippingPrice` (price, estimated days, free shipping threshold). Includes bulk status toggle, fast-shipping toggle on governorates, and nested resource lookups. 20 routes total.

## Architecture

```
[Admin Client]
    |
    |--- /api/v1/countries          (apiResource + 2 custom)
    |--- /api/v1/governorates       (apiResource + 3 custom)
    |--- /api/v1/cities             (apiResource)
    |
    v
[CountryController / GovernorateController / CityController]
    |--- Constructor DI of Repository
    |--- Permission middleware (Spatie)
    |
    v
[CountryRepository / GovernorateRepository / CityRepository]
    |--- paginate(), findById(), create(), update(), delete(), bulkStatus()
    |--- applySearch() for JSON translatable columns
    |
    v
[Country / Governorate / City Model]
    |--- Spatie Translatable (name column is JSON)
    |--- HasMany/BelongsTo relationships
    |
    v
[CountryResource / GovernorateResource / CityResource]
    |--- getTranslation('name', locale)
    |--- whenLoaded() for nested relations
```

## Hierarchy

```
Country (id, name, phone_code, status)
  └── Governorate (id, country_id, name, status, is_fast_shipping_enabled)
        ├── City (id, governorate_id, name)
        └── ShippingPrice (id, governorate_id, price, estimated_days, free_shipping_over, status)
```

## Key Endpoints (20 total)

| Method | URI | Controller Method | Permission |
|--------|-----|-------------------|------------|
| GET | `/countries` | `index` | `VIEW_COUNTRY` |
| POST | `/countries` | `store` | `CREATE_COUNTRY` |
| GET | `/countries/{id}` | `show` | `VIEW_COUNTRY` |
| PUT | `/countries/{id}` | `update` | `UPDATE_COUNTRY` |
| DELETE | `/countries/{id}` | `destroy` | `DELETE_COUNTRY` |
| GET | `/countries/{id}/governorates` | `governorates` | `VIEW_COUNTRY` |
| POST | `/countries/change-status` | `bulkStatus` | `UPDATE_COUNTRY` |
| GET | `/governorates` | `index` | `VIEW_GOVERNORATE` |
| POST | `/governorates` | `store` | `CREATE_GOVERNORATE` |
| GET | `/governorates/{id}` | `show` | `VIEW_GOVERNORATE` |
| PUT | `/governorates/{id}` | `update` | `UPDATE_GOVERNORATE` |
| DELETE | `/governorates/{id}` | `destroy` | `DELETE_GOVERNORATE` |
| GET | `/governorates/{id}/cities` | `cities` | `VIEW_GOVERNORATE` |
| PUT | `/governorates/change-status` | `bulkStatus` | `UPDATE_GOVERNORATE` |
| PUT | `/governorates/{id}/fast-shipping` | `toggleFastShipping` | `UPDATE_GOVERNORATE` |
| GET | `/cities` | `index` | `VIEW_CITY` |
| POST | `/cities` | `store` | `CREATE_CITY` |
| GET | `/cities/{id}` | `show` | `VIEW_CITY` |
| PUT | `/cities/{id}` | `update` | `UPDATE_CITY` |
| DELETE | `/cities/{id}` | `destroy` | `DELETE_CITY` |

## Key Files

| Layer | Path |
|-------|------|
| Controller (Country) | `packages/marvel/src/Http/Controllers/CountryController.php` |
| Controller (Governorate) | `packages/marvel/src/Http/Controllers/GovernorateController.php` |
| Controller (City) | `packages/marvel/src/Http/Controllers/CityController.php` |
| Model (Country) | `packages/marvel/src/Database/Models/Country.php` |
| Model (Governorate) | `packages/marvel/src/Database/Models/Governorate.php` |
| Model (City) | `packages/marvel/src/Database/Models/City.php` |
| Repository (Country) | `packages/marvel/src/Database/Repositories/CountryRepository.php` |
| Repository (Governorate) | `packages/marvel/src/Database/Repositories/GovernorateRepository.php` |
| Repository (City) | `packages/marvel/src/Database/Repositories/CityRepository.php` |
| Resource (Country) | `packages/marvel/src/Http/Resources/CountryResource.php` |
| Resource (Governorate) | `packages/marvel/src/Http/Resources/GovernorateResource.php` |
| Resource (City) | `packages/marvel/src/Http/Resources/CityResource.php` |
| Resource (ShippingPrice) | `packages/marvel/src/Http/Resources/ShippingPriceResource.php` |
| Enum (Permission) | `packages/marvel/src/Enums/Permission.php` |
| Routes | `packages/marvel/src/Rest/Routes.php` (lines 200–209) |

## Tech Stack

- **Laravel** with Eloquent ORM
- **Sanctum** authentication
- **Spatie permissions** (12 permissions for CRUD)
- **Spatie Translatable** (`name` stored as JSON)
- **Repository pattern** (standalone classes, not BaseRepository)
- **API Resources** with `whenLoaded()` conditional loading
- **Form Requests** with `CodeZero\UniqueTranslationRule`
- **Translations:** EN keys missing for country/city messages; AR has governorate keys only
- **No dedicated tests** for Country/Governorate/City CRUD

## Bug Fixes

| # | Issue | Fix |
|---|-------|-----|
| BUG-001 | `PUT /governorates/change-status` returned 500 — route caught by `apiResource` as `{id}` parameter | Moved custom routes before `apiResource` in `Routes.php` |
