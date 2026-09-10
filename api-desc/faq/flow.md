# Request Flows — FAQ Module

## Flow 1: List FAQs (Admin)

```
Client → GET /api/v1/faqs?order=faq_title&sortedBy=asc&page=1&limit=10
         ↓
    [auth:sanctum] middleware → authenticate token
         ↓
    [permission:view-faqs] middleware → check Spatie permission
         ↓
    FaqsController@index(Request)
         ↓
    FaqsController@fetchFAQs(Request)
         ↓
    Simplified query (role-based scoping removed):
      repository->query()->paginate(limit)
      (shop_id/user_id columns don't exist in migration)
         ↓
    Apply ordering:
      - if order is valid column → orderBy(order, sortedBy)
      - default → no explicit ordering (repository default)
         ↓
    FaqsRepository → paginate($limit)
         ↓
    FaqResource::collection($faqs) → transform (translated strings on index)
         ↓
    Return: { status:200, message, success:true, data: { data[], pagination_meta } }
```

## Flow 2: Create FAQ (Admin)

```
Client → POST /api/v1/faqs (JSON: { faq_title: {en, ar}, faq_description: {en, ar} })
         ↓
    [auth:sanctum] → [permission:create-faq]
         ↓
    CreateFaqsRequest → validation (title array, title.* unique, description array, status optional)
         ↓
    Fail? → 422 with field errors
         ↓
    FaqsController@store(CreateFaqsRequest $request)  [type-hinted]
         ↓
    FaqsRepository::storeFaqs($request)
         ↓
    1. Extract faq_title + faq_description from request
    2. status (only if present — DB defaults to 1)
    3. Faqs::create($data)
         ↓
    FaqResource::make($faq)  → includes status and order
         ↓
    Return: { status:201, message, success:true, data }
```

## Flow 3: Show FAQ (Admin)

```
Client → GET /api/v1/faqs/1
         ↓
    [auth:sanctum] → [permission:view-faqs]
         ↓
    FaqsController@show($id)
         ↓
    FaqsRepository::findOrFail($id)
         ↓
    Found? → FaqResource::make($faq) → 200 (raw JSON with all locales)
    Not found? → Throwable(NOT_FOUND) → 404
```

## Flow 4: Update FAQ (Admin)

```
Client → PUT /api/v1/faqs/1 (JSON: { faq_title: {en, ar} })
         ↓
    [auth:sanctum] → [permission:update-faq]
         ↓
    UpdateFaqsRequest → validation (all fields sometimes, title.* unique ignoring self)
         ↓
    FaqsController@update($request, $id)
         ↓
    FaqsController@updateFaqs($request)
         ↓
    1. FaqsRepository::findOrFail($id)
    2. FaqsRepository::updateFaqs($request, $faqs)
         ↓
    $faqs->update($request->only(dataArray))
         ↓
    FaqResource::make($faq)
         ↓
    Return: { status:200, message, success:true, data }
```

## Flow 5: Soft Delete FAQ (Admin)

```
Client → DELETE /api/v1/faqs/1
         ↓
    [auth:sanctum] → [permission:delete-faq]
         ↓
    FaqsController@destroy($id, Request)
         ↓
    FaqsController@deleteFaq(Request)
         ↓
    Check user has Permission::DELETE_FAQ
      └─ No → AuthorizationException → 403
         ↓
    FaqsRepository::findOrFail($id)
         ↓
    $faq->delete()  → soft delete (sets deleted_at timestamp)
         ↓
    Return: { status:200, message, success:true }
```

## Flow 6: Reorder FAQs (Admin)

```
Client → PUT /api/v1/faqs/reorder (JSON: { faqs: [3, 1, 2] })
         ↓
    [auth:sanctum] → [permission:update-faq]
         ↓
    FaqsController@reorder(Request)
         ↓
    Request validation:
      - faqs: required, array
      - faqs.*: required, exists:faqs,id
         ↓
    FaqsRepository::reorder($faqs)
         ↓
    $this->setNewOrder($faqs)  → Spatie Sortable updates order column
         ↓
    Return: { status:200, message, success:true }
```

## Flow 7: List Active FAQs (Public)

```
Client → GET /api/v1/general/faqs
         ↓
    FAQController@index()  [no auth]
         ↓
    faqService::getfaqs()
         ↓
    Faqs::active()->get()
      └─ where('status', 1)
         ↓
    FaqResource::collection($faqs) → transformed with translated strings
         ↓
    Return: { status:200, message, success:true, data[] }
```
