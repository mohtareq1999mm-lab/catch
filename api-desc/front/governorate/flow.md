# Request Flows — Governorate Module (Public API)

## Flow 1: List Governorates — Success

```
Client → GET /api/v1/general/governorates
         ↓
    [api] middleware group
         ↓
    GovernorateController@index
         ↓
    GovernorateRepository::allActive()
         ↓
    Governorate::query()
        ->active()
        ->orderByDesc('id')
        ->get()
         ↓
    Collection of Governorate models
         ↓
    GovernorateResource::collection
         ↓
    Transform each:
        id, country_id, name (translated),
        status (cast boolean), is_fast_shipping_enabled,
        created_at
         ↓
    Response: 200
    {
      "status": 200,
      "message": "Data fetched successfully",
      "success": true,
      "data": [ ... ]
    }
```
