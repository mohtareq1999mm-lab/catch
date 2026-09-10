# Data Flow - FAQ Feature

## Flow 1: Public FAQ Listing

```
Client
  |
  GET /api/v1/general/faqs
  |
  v
General\FAQController@index()
  |
  v
faqService::getfaqs()
  |
  +-- Faqs::active()->orderBy('order')->get()
  |     |-- where('status', true)
  |     |-- whereNull('deleted_at')
  |     |-- orderBy('order', 'asc')
  |
  v
FaqResource collection
  |
  Maps each FAQ to:
    - id
    - faq_title (translated: app()->getLocale())
    - faq_description (translated)
  |
  v
JSON Response
{
  "data": [
    {
      "id": 1,
      "faq_title": "What is your return policy?",
      "faq_description": "You can return items within 30 days."
    }
  ]
}
```

## Flow 2: Admin FAQ Creation

```
Client (Admin)
  |
  POST /api/v1/faqs
  Authorization: Bearer <token>
  Body: { "faq_title": { "en": "...", "ar": "..." }, ... }
  |
  v
Middleware: permission:create-faq
  |
  v
FaqsController@store(Request $request)
  |
  +-- CreateFaqsRequest validation:
  |     |-- faq_title (required|array)
  |     |-- faq_title.* (required|string|min:3|max:1000|unique_translation)
  |     |-- faq_description (required|array)
  |     |-- faq_description.* (required|string|min:3|max:1000|unique_translation)
  |     |-- shop_id (nullable|exists:shops,id)
  |
  v
FaqsRepository::storeFaqs($request)
  |
  +-- Faqs::create([
  |     'faq_title' => $request->faq_title,
  |     'faq_description' => $request->faq_description,
  |     'status' => true,
  |   ])
  |   (order auto-assigned by Spatie Sortable)
  |
  v
FaqResource response (201)
```

## Flow 3: Admin FAQ Reorder

```
Client (Admin)
  |
  POST /api/v1/faqs/reorder
  Body: { "faqs": [3, 1, 5, 2, 4] }
  |
  v
FaqsController@reorder(Request $request)
  |
  +-- Validate: $request->has('faqs') && is_array
  |
  v
FaqsRepository::reorder([3, 1, 5, 2, 4])
  |
  +-- $this->setNewOrder([3, 1, 5, 2, 4])
  |     (Spatie Sortable trait)
  |     Updates 'order' column:
  |       3 → order=1, 1 → order=2, 5 → order=3, 2 → order=4, 4 → order=5
  |
  v
Response: { "message": "FAQs reordered successfully" }
```

## Flow 4: Admin FAQ Soft Delete

```
Client (Admin)
  |
  DELETE /api/v1/faqs/5
  |
  v
FaqsController@destroy(5, Request)
  |
  +-- deleteFaq($request):
  |     |-- Check DELETE_FAQ permission
  |     |-- $faq = Faqs::findOrFail(5)
  |     |-- $faq->delete()  // Soft delete
  |
  v
Response: { "message": "FAQ deleted successfully" }
```
