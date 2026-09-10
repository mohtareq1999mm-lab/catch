# Request Flows — Tag Module (Public API)

## Flow 1: List Tags

```
Client → GET /api/v1/general/tags
         ↓
    [api] middleware group
         ├─ throttle:api → pass
         ├─ SubstituteBindings → no-op
         └─ ChannelMiddleware → set channel context
         ↓
    TagController@index(Request)
         ↓
    Tag::query()->get()
         ↓
    Collection of Tag models
         ↓
    TagResource::collection($tags)
        → For each tag:
          id, name, slug, details, image, icon,
          language, translated_languages,
          type → lazy loaded via getResourceData($this->type)
         ↓
    Response: 200
    {
      "status": 200,
      "message": "Data fetched successfully",
      "success": true,
      "data": [
        { "id": 1, "name": "Organic", "slug": "organic", ... },
        { "id": 2, "name": "Wireless", "slug": "wireless", ... }
      ]
    }
```

## Flow 2: Get Tag by Slug

```
Client → GET /api/v1/general/tags/organic
         ↓
    [api] middleware group
         ↓
    TagController@show(Request, 'organic')
         ↓
    Tag::query()->where('slug', 'organic')->first()
         ↓
    Found?
    ├─ YES:
    │    ↓
    │    TagResource::make($tag)
    │    ↓
    │    Response: 200
    │    {
    │      "status": 200,
    │      "message": "Data fetched successfully",
    │      "success": true,
    │      "data": { "id": 1, "name": "Organic", ... }
    │    }
    │
    └─ NO:
         ↓
         Response: 404
         { "status": 404, "message": "Data not found", "success": false }
```
