# Request Flows — Pages Module

## Flow 0: Section Title Fix (excludeUnvalidatedArrayKeys)

```
$data = $request->validated()
         ↓
    [Laravel excludeUnvalidatedArrayKeys = true]
    `title` (parent key with array + wildcard sub-rules) → SKIPPED
    `title.*` (wildcard) → Arr::set($results, 'title.*', [...])
         ↓
    RESULT: $data['title'] = [] (empty array)
         ↓
    FIX: if (! isset($data['title']) && $request->has('title'))
         $data['title'] = $request->input('title')
         ↓
    $section = Section::create($data) — title now correct
```

---

## Flow 1: Create Content Page

```
Client → POST /api/v1/content-pages { title: { en: "Home", ar: "الرئيسية" } }
         ↓
    [auth:sanctum] → authenticate user
    [role:super_admin|editor] → authorize role
    [email.verified] → verify email
    [permission:create-content-pages] → authorize permission
         ↓
    StoreContentPageRequest::rules() validation
         ↓
    ContentPageController@store(Request)
         ↓
    DB::beginTransaction
         ↓
    $data = $request->only(['title'])
    $data['slug'] = Str::slug($data['title']['en'])
         ↓
    ContentPage::create($data + ['is_active' => true])
         ↓
    DB::commit
         ↓
    ContentPageResource::make($page)
         ↓
    Response: 201 { id, title, slug, is_active, sections: [] }
```

## Flow 2: Attach Sections to Page

```
Client → POST /api/v1/content-pages/1/attach-sections { sections: [1, 2, 3] }
         ↓
    [auth:sanctum, role, email.verified]
    [permission:update-content-pages]
         ↓
    AttachSectionsRequest::rules() validation
         ↓
    ContentPageController@attachSections(Request, ContentPage:1)
         ↓
    DB::beginTransaction
         ↓
    sections = [1, 2, 3] → NOT empty
         ↓
    Section::whereIn('id', [1,2,3])->get()
    sections->each → $this->sections()->save($section)
    [sets content_page_id = 1 on each section]
         ↓
    $page->load('sections')
         ↓
    DB::commit
         ↓
    ContentPageResource::make($page) with sections
         ↓
    Response: 200 { ... sections: [ { id:1, ... }, { id:2, ... } ] }
```

## Flow 3: Attach Empty Sections (Detach All)

```
Client → POST /api/v1/content-pages/1/attach-sections { sections: [] }
         ↓
    [same auth/permission]
         ↓
    AttachSectionsRequest validation
    prepareForValidation: sections present → use as-is
         ↓
    sections = [] → EMPTY
         ↓
    $content_page->sections()->update(['content_page_id' => null])
         ↓
    Response: 200 { message: "...deleted...", success: true }
    (Note: message says "deleted" but actually detaches)
```

## Flow 4: Reorder Sections

```
Client → POST /api/v1/sections/reorder { sections: [3, 1, 2] }
         ↓
    [auth:sanctum, role, email.verified]
    [permission:update-sections]
         ↓
    Inline validation: required|array|distinct|exists:sections,id
         ↓
    SectionController@reorder(Request)
         ↓
    Section::setNewOrder([3, 1, 2])
    [Spatie SortableTrait: updates order column based on array index]
         ↓
    Response: 200 { message: "Sections reordered successfully" }
```

## Flow 5: Section Settings Resolution

```
SectionResource::toArray
         ↓
    getSettings()
         ↓
    $this->setting (section-level) !== null?
         ├─ YES → use section's own setting JSON
         └─ NO  → SectionType::where('type', $this->type)->first()
                    ├─ EXISTS → settings()->where('setting_key', 'front')→value
                    │           settings()->where('setting_key', 'back')→value
                    │           return ['front' => $front, 'back' => $back]
                    └─ NULL  → return ['front' => [], 'back' => []]
         ↓
    buildEndpoint($settings)
         ↓
    params = $settings['back'] ?? []
    endpoint = 'general/' . $type . '?' . http_build_query(array_filter(params))
         ↓
    Return: { id, type, title, is_active, endpoint, order, setting }
```

## Flow 6: Update Section Type Settings

```
Client → POST /api/v1/section-types/banners/settings
         { front: { display: "grid", columns: 3 }, back: { slug: "home-banner" } }
         ↓
    [auth:sanctum, role, email.verified]
    [permission:update-section-types]
         ↓
    Validation: front nullable|array, back nullable|array
         ↓
    SectionTypeController@updateSettings(Request, "banners")
         ↓
    SectionTypeService::getByType("banners") → SectionType
         ↓
    upsertSettings($sectionType->id, { front: {...}, back: {...} })
         ↓
    SectionTypeSetting::where('section_type_id', $id)->delete()
    SectionTypeSetting::create({ section_type_id, setting_key: 'front', value: {...} })
    SectionTypeSetting::create({ section_type_id, setting_key: 'back', value: {...} })
         ↓
    getSettingsGrouped("banners") → { front: {...}, back: {...} }
         ↓
    Response: 200 { front: {...}, back: {...} }
```
