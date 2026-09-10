# Request Flows — Navigation Bar

## Flow 1: Fetch Nav-Data (Cache Miss)

```
Client → GET /api/v1/general/nav-data?level=3
         ↓
    [api] middleware group
         ├─ throttle:api → pass
         ├─ SubstituteBindings → no-op (no route params)
         └─ ChannelMiddleware
              ├─ Read X-Channel header (absent → default 'home')
              └─ Set ChannelContext::channel = Channel::HOME
         ↓
    HomeController@navData(Request $request)
         ↓
    Extract $level = $request->integer('level') = 3
         ↓
    HomeService::getNavData(3)
         ↓
    Cache key = 'home:home-nav-bar:level:3'
         ↓
    Cache::get('home:home-nav-bar:level:3') → MISS
         ↓
    getCategoryWithChildren()
         ↓
    Category::query()
        → active() [where status = 1]
        → whereNull('parent_id')
        → withCount('products')
        → with(['children' => function ($q) {
            $q->active()
              ->withCount('products')
              ->with(['children' => function ($q) {
                  $q->active()
                    ->withCount('products');
              }]);
          }])
        → orderByDesc('products_count')
        → get()
         ↓
    Collection of Category models (3 levels deep)
         ↓
    CategoryNavbarResource::collection($categories)
         ↓
    For each category:
      → id: 1
      → name: $this->getTranslation('name', app()->getLocale())  → "Electronics"
      → slug: "electronics"
      → level: 1
      → image: [
          'desktop' => $this->getFirstMediaUrl('categories-desktop'),
          'mobile' => $this->getFirstMediaUrl('categories-mobile'),
        ]
      → children: (if level < 3, recurse)
          → [ChildCategory] → same transformation
              → [GrandchildCategory] → same transformation (level >= 3 → children: [])
         ↓
    Cache::put('home:home-nav-bar:level:3', $resourceCollection, 120)
         ↓
    Return to Controller
         ↓
    $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $data)
         ↓
    Response JSON:
    {
      "status": 200,
      "message": "Data fetched successfully",
      "success": true,
      "data": [ ... category tree ... ]
    }
```

## Flow 2: Fetch Nav-Data (Cache Hit)

```
Client → GET /api/v1/general/nav-data
         ↓
    ... middleware (same as above) ...
         ↓
    HomeService::getNavData(null)
         ↓
    Cache key = 'home:home-nav-bar'
         ↓
    Cache::get('home:home-nav-bar') → HIT
         ↓
    Return cached CategoryNavbarResource collection immediately
         ↓
    No database queries executed
         ↓
    Response: 200 with cached JSON data
```

## Flow 3: Fetch Nav-Data With Invalid Channel (Strict Mode)

```
Client → GET /api/v1/general/nav-data
         Header: X-Channel: invalid
         ↓
    ChannelMiddleware
         ↓
    Channel::isValid('invalid') → false
         ↓
    config('channel.strict') → true
         ↓
    Throw BadRequestHttpException('Invalid channel "invalid". Accepted values: home, fast-shipping.')
         ↓
    Response: 400 Bad Request
    {
      "status": 400,
      "message": "Invalid channel \"invalid\". Accepted values: home, fast-shipping.",
      "success": false
    }
```

## Flow 4: Nav-Data With No Active Categories

```
Client → GET /api/v1/general/nav-data
         ↓
    getCategoryWithChildren()
         ↓
    Category::query()->active()->whereNull('parent_id')->get()
         ↓
    Empty Collection (no active parent categories)
         ↓
    CategoryNavbarResource::collection(collect()) → Empty Collection
         ↓
    Response: 200
    {
      "status": 200,
      "message": "Data fetched successfully",
      "success": true,
      "data": []
    }
```
