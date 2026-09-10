# Request Flows — Pickup Location Module (Public API)

## Flow 1: List Pickup Locations — Success

```
Client → GET /api/v1/general/pickup-locations?limit=10&search=downtown&page=1
         ↓
    [api] middleware group
         ↓
    PickupLocationController@index(Request)
         ↓
    PickupLocationService::getPickupLocations($request)
         ↓
    PickupLocation::active()
        ->ordered()
        ->when('downtown', fn) → where('store_name', 'like', '%downtown%')
        ->paginate(10)
         ↓
    Paginated collection of PickupLocation models
         ↓
    PickupLocationResource::collection
         ↓
    Transform each:
        id, store_name, address, phone, email,
        latitude, longitude, working_hours,
        status (cast boolean), display_order
         ↓
    Response: 200
    {
      "status": 200,
      "message": "Data fetched successfully",
      "success": true,
      "data": [ ... ]
    }
```

## Flow 2: Show Pickup Location — Found

```
Client → GET /api/v1/general/pickup-locations/1
         ↓
    PickupLocationController@show(1)
         ↓
    PickupLocationService::getPickupLocationById(1)
         ↓
    PickupLocation::active()->findOrFail(1)
         ↓
    WHERE id = 1 AND status = 1 AND deleted_at IS NULL
         ↓
    PickupLocation model
         ↓
    PickupLocationResource::make
         ↓
    Response: 200 { PickupLocationResource }
```

## Flow 3: Show Pickup Location — Not Found

```
Client → GET /api/v1/general/pickup-locations/999
         ↓
    PickupLocationService::getPickupLocationById(999)
         ↓
    PickupLocation::active()->findOrFail(999)
         ↓
    ModelNotFoundException thrown
         ↓
    PickupLocationController@show → catch(\Exception)
         ↓
    Response: 404
    {
      "status": 404,
      "message": "Not found",
      "success": false
    }
```
