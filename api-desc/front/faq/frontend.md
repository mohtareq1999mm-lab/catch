# Frontend - FAQ Feature

## Status

**No dedicated frontend Vue/React components** found in `resources/js/`. The frontend is a separate SPA.

## Consumption Patterns

### 1. Public FAQ Page

FAQs appear on a public help/FAQ page:

```
GET /api/v1/general/faqs

Response:
{
  "data": [
    {
      "id": 1,
      "faq_title": "What is your return policy?",
      "faq_description": "You can return any item within 30 days of purchase..."
    },
    {
      "id": 2,
      "faq_title": "How long does shipping take?",
      "faq_description": "Standard shipping takes 5-7 business days..."
    }
  ]
}
```

### 2. Admin FAQ Management

Admin users manage FAQs via CRUD operations:

```
GET /api/v1/faqs?search=return&sort=faq_title&order=asc
POST /api/v1/faqs (multipart or JSON for translatable fields)
PUT /api/v1/faqs/{id}
DELETE /api/v1/faqs/{id}
POST /api/v1/faqs/reorder
```

## What a Frontend Implementation Would Need

### Public Components

```
FaqAccordion.vue
  Props: faqs (array)
  Renders: accordion-style list with expandable question/answer pairs
    - Click question to toggle answer visibility
    - Animated expand/collapse
    - Search/filter functionality

FaqPage.vue
  Fetches: GET /api/v1/general/faqs
  Renders: page title, optional search bar, FaqAccordion
  Loading/empty/error states
```

### Admin Components

```
AdminFaqListPage.vue
  Fetches: GET /api/v1/faqs (paginated)
  Features:
    - Table: question, answer (truncated), status, order
    - Drag-and-drop reorder (calls POST /api/v1/faqs/reorder)
    - Search by title
    - Edit/Delete actions
    - Create button

AdminFaqForm.vue
  Fields:
    - faq_title (multi-language tabs EN / AR)
    - faq_description (multi-language textarea)
    - status toggle
  Validation errors inline
  Submit: POST /api/v1/faqs (create) or PUT /api/v1/faqs/{id} (update)
```

### API Service Layer

```javascript
// services/faqApi.js
export const faqApi = {
  publicList()               // GET /api/v1/general/faqs
  list(params)               // GET /api/v1/faqs
  show(id)                   // GET /api/v1/faqs/{id}
  create(data)               // POST /api/v1/faqs
  update(id, data)           // PUT /api/v1/faqs/{id}
  delete(id)                 // DELETE /api/v1/faqs/{id}
  reorder(ids)               // POST /api/v1/faqs/reorder
}
```

## Key Request/Response Examples

**Public Listing:**
```
GET /api/v1/general/faqs
Response: { data: [{ id, faq_title, faq_description }] }
```

**Admin Create:**
```
POST /api/v1/faqs
Content-Type: application/json
Body:
{
  "faq_title": { "en": "What is your return policy?", "ar": "ما هي سياسة الإرجاع؟" },
  "faq_description": { "en": "You can return items within 30 days.", "ar": "يمكنك إرجاع المنتجات خلال 30 يومًا." },
  "status": 1
}
```

**Reorder:**
```
POST /api/v1/faqs/reorder
Body: { "faqs": [3, 1, 2, 5, 4] }
```
