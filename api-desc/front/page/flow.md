    # Data Flow - Content Page Feature

    ## Flow 1: Public Page Rendering with Sections

    ```
    Client Browser
    |
    GET /api/v1/general/pages/home
    |
    v
    General\ContentPageController@show('home')
    |
    v
    ContentPage::where('slug', 'home')
    ->where('is_active', true)
    ->with(['sections' => function ($q) {
        $q->where('is_active', true)
            ->orderBy('order');
    }])
    ->firstOrFail()
    |
    v
    ContentPageResource::make($page)
    |
    For each section:
        - Build endpoint: "general/{type}?{back_settings_as_query_params}"
        - Resolve setting: section->setting ?? SectionType defaults
        - Map to SectionResource: id, type, title (if title_visible), is_active, endpoint, order, setting
    |
    v
    JSON Response
    {
    "data": {
        "id": 1,
        "title": "Home",
        "slug": "home",
        "is_active": true,
        "sections": [
        {
            "id": 1,
            "type": "sliders",
            "title": "Hero Sliders",
            "is_active": true,
            "endpoint": "/api/v1/general/sliders?limit=5",
            "order": 0,
            "setting": { "autoplay": true, "slider_speed": 5000 }
        },
        {
            "id": 2,
            "type": "categories",
            "title": null,
            "is_active": true,
            "endpoint": "/api/v1/general/categories?parentOnly=true",
            "order": 1,
            "setting": { "parentOnly": true }
        }
        ]
    }
    }
    |
    v
    Frontend renders each section by:
    1. Identifying the section type (e.g., "sliders")
    2. Fetching from the section's endpoint
    3. Rendering the appropriate block component with the setting config
    ```

    ## Flow 2: Admin Content Page Creation

    ```
    Client (Admin)
    |
    POST /api/v1/content-pages
    Authorization: Bearer <token>
    Body: { "title": { "en": "About Us", "ar": "من نحن" } }
    |
    v
    Middleware: permission:create-content-pages
    |
    v
    ContentPageController@store(StoreContentPageRequest $request)
    |
    +-- Validation: title.* required|string|max:30|unique_translation
    |
    +-- $contentPage = ContentPage::create([
    |     'title' => $request->title,
    |     'slug' => Str::slug($request->title['en']),
    |     'is_active' => true
    |   ])
    |
    v
    Response: ContentPageResource (201)
    ```

    ## Flow 3: Attach Sections to Content Page

    ```
    Client (Admin)
    |
    POST /api/v1/content-pages/1/attach-sections
    Body: { "sections": [1, 3, 5] }
    |
    v
    ContentPageController@attachSections(AttachSectionsRequest $request, ContentPage $page)
    |
    +-- $page->attachSectionsByIds([1, 3, 5])
    |     |
    |     +-- Detach all existing sections:
    |     |     Section::where('content_page_id', $page->id)
    |     |       ->update(['content_page_id' => null])
    |     |
    |     +-- Attach new sections:
    |     |     Section::whereIn('id', [1, 3, 5])
    |     |       ->update(['content_page_id' => $page->id])
    |
    v
    Response: { "message": "Sections attached successfully" }
    ```

    ## Flow 4: Section Reorder

    ```
    Client (Admin)
    |
    POST /api/v1/sections/reorder
    Body: { "sections": [3, 1, 5, 2, 4] }
    |
    v
    SectionController@reorder(Request $request)
    |
    +-- Validate: $request->has('sections') && is_array
    |
    v
    Section::setNewOrder([3, 1, 5, 2, 4])
    |  (Spatie Sortable trait)
    |  Updates 'order' column:
    |    3 → order=1, 1 → order=2, 5 → order=3, 2 → order=4, 4 → order=5
    |
    v
    Response: { "message": "Sections reordered successfully" }
    ```

    ## Flow 5: Puck Page Upsert

    ```
    Client (Puck Editor)
    |
    POST /api/v1/puck/page
    Body: {
        "path": "/about",
        "title": "About Us",
        "slug": "about-us",
        "data": {
        "root": { "props": {} },
        "content": [
            { "type": "HeroBlock", "props": { "heading": "About Us" } }
        ]
        }
    }
    |
    v
    CmsPageController@storePuckPage(CmsPageRequest $request)
    |
    +-- $page = CmsPage::where('path', '/about')->first()
    |
    +-- if ($page): update existing
    |     $page->update([
    |       'title' => 'About Us',
    |       'slug' => 'about-us',
    |       'data' => $request->data,
    |       'content' => null  // Puck format takes precedence
    |     ])
    |     return 200
    |
    +-- else: create new
            $page = CmsPage::create([
            'path' => '/about',
            'title' => 'About Us',
            'slug' => 'about-us',
            'data' => $request->data,
            ])
            return 201
    |
    v
    CmsPageResource response
    ```

    ## Flow 6: Component Data Fetching

    ```
    Client (Puck SSR or Frontend)
    |
    GET /api/v1/component-data/categories?limit=10&topLevelOnly=true
    |
    v
    ComponentDataController@categories(Request $request)
    |
    v
    ComponentDataService::getCategories(10, null, true)
    |
    +-- Category::active()
    |     ->whereNull('parent_id')  // topLevelOnly
    |     ->limit(10)
    |     ->get()
    |
    v
    Array response: [{ id, name, slug, image: { desktop, mobile } }]
    ```

    ## Section Endpoint Auto-Generation

    ```
    SectionResource::toArray($request)
    |
    +-- $baseEndpoint = "general/{$this->type}"
    |
    +-- $backSettings = $this->setting['back'] ?? []
    |     (from section's setting JSON, e.g., { "limit": 5, "parentOnly": true })
    |
    +-- if backSettings has 'with_product':
    |     query param uses slug from backSettings
    |
    +-- $endpoint = $baseEndpoint . '?' . http_build_query($backSettings)
    |
    +-- Example:
    |     type = "sliders"
    |     backSettings = { "limit": 5 }
    |     → endpoint = "/api/v1/general/sliders?limit=5"
    |
    v
    Returned as part of SectionResource
    ```
