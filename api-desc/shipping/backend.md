# Backend - Shipping Feature

## Controllers

### CountryController (`packages/marvel/src/Http/Controllers/CountryController.php`)

| Method | Permission | Description |
|--------|------------|-------------|
| `index` | VIEW_COUNTRY | Paginate with search/status |
| `store` | CREATE_COUNTRY | Create country |
| `show` | VIEW_COUNTRY | Find by ID with governorates |
| `update` | UPDATE_COUNTRY | Find, update, return |
| `destroy` | DELETE_COUNTRY | Find, delete |
| `governorates` | VIEW_COUNTRY | Find by ID, return with governorates |
| `bulkStatus` | UPDATE_COUNTRY | Bulk update status by IDs |

### GovernorateController (`packages/marvel/src/Http/Controllers/GovernorateController.php`)

| Method | Permission | Description |
|--------|------------|-------------|
| `index` | VIEW_GOVERNORATE | Paginate with search/status/country_id |
| `store` | CREATE_GOVERNORATE | Create + shipping price in transaction |
| `show` | VIEW_GOVERNORATE | Find with country/cities/shippingPrice |
| `update` | UPDATE_GOVERNORATE | Update + shipping price upsert |
| `destroy` | DELETE_GOVERNORATE | Delete (fails if has cities) |
| `cities` | VIEW_GOVERNORATE | Return with cities relation |
| `bulkStatus` | UPDATE_GOVERNORATE | Bulk update status |
| `toggleFastShipping` | UPDATE_GOVERNORATE | Toggle is_fast_shipping_enabled |

### Route Ordering (Critical)

**Custom routes** (`change-status`, `fast-shipping`, `cities`) **must be defined BEFORE** `Route::apiResource('governorates', ...)` in `Routes.php`. Otherwise `PUT /governorates/change-status` is captured by `PUT /governorates/{governorate}` with `{governorate} = "change-status"`, hitting `GovernorateController@update(int $id)` with a string argument (HTTP 500).

Fixed in `packages/marvel/src/Rest/Routes.php`:

```php
Route::put('governorates/change-status', [GovernorateController::class, 'bulkStatus']);      // MUST be first
Route::put('governorates/{id}/fast-shipping', [GovernorateController::class, 'toggleFastShipping']);
Route::get('governorates/{id}/cities', [GovernorateController::class, 'cities']);
Route::apiResource('governorates', GovernorateController::class);                               // generic last
```

### CityController (`packages/marvel/src/Http/Controllers/CityController.php`)

| Method | Permission | Description |
|--------|------------|-------------|
| `index` | VIEW_CITY | Paginate with search/governorate_id |
| `store` | CREATE_CITY | Create city |
| `show` | VIEW_CITY | Find with governorate |
| `update` | UPDATE_CITY | Update city |
| `destroy` | DELETE_CITY | Delete city |

## Repositories

### CountryRepository

- `paginate(int $perPage, ?string $search, ?bool $status)` — JSON search on name.en/name.ar, status filter, orderByDesc('id')
- `allActive()` — `where('status', true)->get()`
- `findById(int $id, array $with)` — simple `find()`
- `bulkStatus(array $ids, bool $status)` — `whereIn('id', $ids)->update(['status' => $status])`

### GovernorateRepository

- `paginate(...)` — same pattern with `$countryId` filter
- `create(array $data)` — transactional: creates governorate + optional shipping price
- `update(...)` — transactional: updates governorate + upserts shipping price
- `delete()` — throws if `cities()->exists()`
- `ensureCountryExists(int $countryId)` — validates FK integrity

### CityRepository

- `paginate(...)` — always eager loads `governorate`
- `create()` — validates governorate exists
- `ensureGovernorateExists()` — validates FK integrity

## Form Requests

All extend `CoreRequest` which extends `FormRequest` with custom `failedValidation()` returning JSON 422.

### Validation Rules

| Request | Notable Rules |
|---------|---------------|
| CountryStoreRequest | `name.en`/`name.ar`: required, string, min:2, max:50, UniqueTranslationRule |
| CountryUpdateRequest | Same, but `sometimes` + ignore current ID |
| GovernorateStoreRequest | `country_id`: exists:countries,id; `name` array; `shipping_price.*`: numeric |
| GovernorateUpdateRequest | Same but `sometimes` |
| CityStoreRequest | `governorate_id`: exists:governorates,id; `name.*`: UniqueTranslationRule |
| CityUpdateRequest | Same but `sometimes` + ignore |
| BulkStatusRequest | `ids`: required, array, min:1; `ids.*`: distinct, exists:countries,id; `status`: required, in:0,1 |

## Translations

**Missing from both EN and AR (`resources/lang/`):**

| Constant | Translation Key |
|----------|----------------|
| COUNTRY_CREATED_SUCCESSFULLY | MESSAGE.COUNTRY_CREATED_SUCCESSFULLY |
| COUNTRY_UPDATED_SUCCESSFULLY | MESSAGE.COUNTRY_UPDATED_SUCCESSFULLY |
| COUNTRY_DELETED_SUCCESSFULLY | MESSAGE.COUNTRY_DELETED_SUCCESSFULLY |
| COUNTRY_NOT_FOUND | ERROR.COUNTRY_NOT_FOUND |
| GOVERNORATES_FETCHED_SUCCESSFULLY | MESSAGE.GOVERNORATES_FETCHED_SUCCESSFULLY |
| CITY_CREATED_SUCCESSFULLY | MESSAGE.CITY_CREATED_SUCCESSFULLY |
| CITY_UPDATED_SUCCESSFULLY | MESSAGE.CITY_UPDATED_SUCCESSFULLY |
| CITY_DELETED_SUCCESSFULLY | MESSAGE.CITY_DELETED_SUCCESSFULLY |

Present in AR only: `GOVERNORATE_CREATED_SUCCESSFULLY`, `GOVERNORATE_UPDATED_SUCCESSFULLY`, `GOVERNORATE_DELETED_SUCCESSFULLY`, `BULK_STATUS_UPDATED_SUCCESSFULLY`, `FAST_SHIPPING_GOVERNORATE_DISABLED`.

## Permissions (12 Spatie permissions)

| Permission Slug | Controllers |
|----------------|-------------|
| `view-country` | CountryController (index, show) |
| `create-country` | CountryController (store) |
| `update-country` | CountryController (update), CountryController (bulkStatus) |
| `delete-country` | CountryController (destroy) |
| `view-governorate` | GovernorateController (index, show) |
| `create-governorate` | GovernorateController (store) |
| `update-governorate` | GovernorateController (update, bulkStatus, toggleFastShipping) |
| `delete-governorate` | GovernorateController (destroy) |
| `view-city` | CityController (index, show) |
| `create-city` | CityController (store) |
| `update-city` | CityController (update) |
| `delete-city` | CityController (destroy) |
