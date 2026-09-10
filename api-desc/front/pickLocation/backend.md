# Pickup Location Module — Backend Architecture (Public API)

## Endpoints

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/general/pickup-locations` | Public | List active pickup locations (paginated) |
| GET | `/api/v1/general/pickup-locations/{id}` | Public | Get single pickup location by ID |

## Route Definitions

**File:** `routes/api.php` (lines 69-70)

```php
Route::prefix('v1/general')->middleware('api')->group(function () {
    Route::get('pickup-locations', [PickupLocationController::class, 'index']);
    Route::get('pickup-locations/{id}', [PickupLocationController::class, 'show']);
});
```

## Middleware

- `api` group (throttle, SubstituteBindings, ChannelMiddleware) — no auth

## Request Flow

### Flow 1: List Locations
```
Client → GET /api/v1/general/pickup-locations?limit=10&search=downtown&page=1
         ↓
    PickupLocationController@index(Request)
         ↓
    PickupLocationService::getPickupLocations($request)
         ↓
    PickupLocation::active()              → where('status', true)
        ->ordered()                       → orderBy('display_order')->orderBy('id')
        ->when(search)                    → where('store_name', 'like', '%downtown%')
        ->paginate($limit)
         ↓
    Collection of active PickupLocation models
         ↓
    PickupLocationResource::collection
         ↓
    Response: 200 { paginated }
```

### Flow 2: Show Location
```
Client → GET /api/v1/general/pickup-locations/1
         ↓
    PickupLocationController@show(1)
         ↓
    PickupLocationService::getPickupLocationById(1)
         ↓
    PickupLocation::active()->findOrFail(1)
         ↓
    PickupLocationResource::make($location)
         ↓
    Response: 200
    On fail: throw \Exception → catch → Response: 404
```

## Key Classes

| Class | Method | Responsibility |
|-------|--------|----------------|
| `PickupLocationController` | `index()` | List locations |
| `PickupLocationController` | `show()` | Show single location |
| `PickupLocationService` | `getPickupLocations()` | Active+ordered query with search |
| `PickupLocationService` | `getPickupLocationById()` | Find by ID with active scope |

## Model: PickupLocation

| Column | Type | Description |
|--------|------|-------------|
| id | bigint UNSIGNED | Primary key |
| store_name | varchar(255) | Location display name |
| address | text | Full address |
| phone | varchar(255), nullable | Contact phone |
| email | varchar(255), nullable | Contact email |
| latitude | varchar(255), nullable | Map latitude |
| longitude | varchar(255), nullable | Map longitude |
| working_hours | json, nullable | Hours per day (array cast) |
| status | boolean | Active flag |
| display_order | integer | Sort priority |
| deleted_at | timestamp, nullable | Soft delete |

## Resource Fields

| Field | Type | Description |
|-------|------|-------------|
| id | integer | |
| store_name | string | |
| address | string | |
| phone | string | null |
| email | string | null |
| latitude | string | null |
| longitude | string | null |
| working_hours | object | null |
| status | boolean | |
| display_order | integer | |

## Caching

- **No caching** — every request hits DB
- Locations are low-churn data ideal for long TTL cache (e.g., 1 hour)
