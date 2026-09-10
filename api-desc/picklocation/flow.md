# Data Flow — Pickup Location Feature

## Flow: Admin List with Search

```
Admin Client
  |
  GET /api/v1/pickup-locations?search=Downtown&active=true&per_page=15
  Authorization: Bearer <token>
  |
  v
auth:sanctum + throttle:admin middleware (group)
  |
  v
permission:VIEW_PICKUP_LOCATIONS middleware (registered in controller constructor)
  |
  v
Marvel\Http\Controllers\PickupLocationController@index($request)
  |  -- $limit = per_page ?? limit ?? 15
  |  -- $search = 'Downtown'; $active = 'true'
  |
  +-- PickupLocationRepository::orderBy('display_order')->orderBy('id')
  +-- where('store_name', 'like', '%Downtown%')
  +-- where('status', true)          [active scope]
  +-- paginate(15)
  |
  v
PickupLocationResource::collection($paginator)
  |
  +-- Manual extraction of pagination meta
  |     response()->getData(true)
  |     -> data['data'], data['meta'], data['links']
  |
  v
HasCache::remember(PICKUP_LOCATIONS, md5(fullUrl), $data)
  ├── Cache HIT → return cached pagination object
  └── Cache MISS → store & return
  |
  v
JSON Response (duplicate page/current_page keys inside data)
```

## Flow: Public List (Checkout)

```
Customer (no auth)
  |
  GET /api/v1/general/pickup-locations?limit=10&search=
  |
  v
throttle:public-api middleware (v1/general group)
  |
  v
App\Http\Controllers\Api\General\PickupLocationController@index($request)
  |
  v
App\Services\General\PickupLocationService::getPickupLocations($request)
  |  -- $limit   = query('limit', 10)
  |  -- $default = query('default', false)
  |  -- PickupLocation::active()->ordered()     [status = true; display_order → id]
  |  -- optional: where('store_name', 'like', "%{$search}%")
  |  -- if default truthy: where('is_default', true)   [only the default branch]
  |  -- paginate($limit)
  |
  v
App\Http\Resources\PickupLocation\PickupLocationResource::collection(...)
  |  [same fields as admin resource MINUS created_at]
  |
  v
JSON Response — Laravel paginator structure inside envelope data
(data.data[], data.links{}, data.meta{})   [NOT cached]
```

> `?default=1` returns the default branch wrapped in the same paginator shape (a list with one item, or an empty list if no active default exists).

## Flow: Public Show

```
Customer (no auth)
  |
  GET /api/v1/general/pickup-locations/{id}
  |
  v
PickupLocationService::getPickupLocationById($id)
  |  -- PickupLocation::active()->findOrFail($id)
  |      inactive / soft-deleted / missing → ModelNotFoundException
  |
  ├── found    → 200 envelope + resource
  └── catch \Exception → apiResponse(NOT_FOUND, 404, false)
        { "status": 404, "message": "...", "success": false }
```

## Flow: Order Checkout with Pickup

```
Customer
  |
  POST /api/v1/general/checkout
  Body: { fulfillment_type: 'pickup', pickup_location_id: 1, ... }
  |
  v
[Checkout Service]
  |  -- Reads PickupLocation (even if soft-deleted)
  |  -- Snapshots: store_name, address, phone, coordinates
  |  -- Stores: pickup_location_id + snapshot columns on orders table
  |
  v
Order created with pickup snapshot
```

## Flow: Create Pickup Location

```
Admin Client
  |
  POST /api/v1/pickup-locations
  Body: { store_name, address, phone, working_hours[{ day:[...], open, close }], is_default? }
  |
  v
auth:sanctum + throttle:admin
  |
  v
permission:CREATE_PICKUP_LOCATION
  |
  v
StorePickupLocationRequest validation  [422 flat errors on failure]
  |
  v
Marvel\Http\Controllers\PickupLocationController@store
  |  -- repository->create(validated)
  |  -- flushTag(PICKUP_LOCATIONS)      [admin list cache cleared]
  |
  v
[Model saving hook]
  |  -- is_default dirty & true?
  |  -- yes → atomic UPDATE clears flag on all other rows (incl. soft-deleted)
  |
  v
[PickupLocationObserver::created]
  |  -- LogActivityJob::dispatch(... 'created' ...)   [queued]
  |
  v
200 envelope + resource ("Pickup location created successfully")
```

## Flow: Switching the Default Location

```
Admin Client
  |
  PUT /api/v1/pickup-locations/9
  Body: { "is_default": true }
  |
  v
permission:UPDATE_PICKUP_LOCATION
  |
  v
UpdatePickupLocationRequest validation (sometimes rules)
  |
  v
Marvel\Http\Controllers\PickupLocationController@update(9)
  |  -- repository->findOrFail(9)
  |  -- $location->update({ is_default: true })
  |
  v
[Model saving hook]
  |  -- is_default dirty & true?
  |  -- yes → single atomic UPDATE clears flag on all other rows
  |         UPDATE pickup_locations SET is_default = 0 WHERE is_default = 1 AND id <> 9
  |
  v
Location 9 persisted as the only default
  |
  v
Controller flushes PICKUP_LOCATIONS cache tag
  |
  v
[PickupLocationObserver::updated]
  |  -- status changed? → separate LogActivityJob ('statusChanged', old/new status)
  |  -- other fields changed? → LogActivityJob ('updated', old/new values)
  |
  v
200 envelope + updated resource (is_default: true)
```

Updating other fields of the current default does NOT clear its flag (hook only runs when `is_default` itself changes).

## Flow: Delete Pickup Location

```
Admin Client
  |
  DELETE /api/v1/pickup-locations/5
  |
  v
permission:DELETE_PICKUP_LOCATION
  |
  v
Marvel\Http\Controllers\PickupLocationController@destroy(5)
  |  -- repository->findOrFail(5)
  |  -- $location->delete()  (soft delete)
  |
  v
[Model deleted hook]
  |  -- was the deleted location the default (is_default = true)?
  |  -- yes → promote remaining location with lowest id
  |         UPDATE pickup_locations SET is_default = 1 WHERE id = (lowest remaining id)
  |
  v
flushTag(PICKUP_LOCATIONS)
  |
  v
[PickupLocationObserver::deleted]
  |  -- LogActivityJob::dispatch(... 'deleted' ...)   [queued]
  |
  v
200 + message ("Pickup location deleted successfully", no data key payload)
  -- Existing orders retain snapshot data
```