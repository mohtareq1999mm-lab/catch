# FAQ Module — Frontend Integration Guide

## Public Endpoints

---

### GET /api/v1/general/faqs

Fetch list of active FAQs for display (e.g., Help/FAQ page).

**Request:**
```js
fetch('/api/v1/general/faqs')
  .then(res => res.json())
  .then(data => console.log(data.data));
// Returns: [{ id, faq_title, faq_description }]
```

**Response Schema:**
```json
{
  "data": [
    {
      "id": 1,
      "faq_title": "How to return a product?",
      "faq_description": "You can return any product within 30 days of purchase."
    }
  ]
}
```

**Usage:**
- Display in an accordion/expandable FAQ section
- Group by category (if applicable, frontend-side grouping)
- The response is locale-aware — set `Accept-Language` header or configure app locale

---

## Admin Endpoints

### GET /api/v1/faqs

Admin FAQ listing (paginated, sortable).

**Query Parameters:** `page`, `limit`, `order`, `sortedBy`, `shop_id`

### POST /api/v1/faqs

Create FAQ (JSON body with translatable fields).

### GET /api/v1/faqs/{id}

Show FAQ (returns raw JSON with all locales).

### PUT /api/v1/faqs/{id}

Update FAQ (JSON body, all fields optional).

### DELETE /api/v1/faqs/{id}

Soft delete FAQ.

### PUT /api/v1/faqs/reorder

Reorder FAQs (JSON: `{ faqs: [3, 1, 2] }`).

---

## Frontend Patterns

### Loading State
```jsx
function FaqPage() {
  const [loading, setLoading] = useState(true);
  const [faqs, setFaqs] = useState([]);

  useEffect(() => {
    fetch('/api/v1/general/faqs')
      .then(res => res.json())
      .then(data => {
        setFaqs(data.data || []);
        setLoading(false);
      })
      .catch(() => setLoading(false));
  }, []);

  if (loading) return <Skeleton variant="rectangular" height={400} />;
  // ...
}
```

### Empty State
```jsx
{faqs.length === 0 && (
  <EmptyState
    icon={<HelpIcon />}
    title="No FAQs available"
    description="Check back later for answers to common questions"
  />
)}
```

### FAQ Accordion Component
```jsx
function FaqAccordion({ faqs }) {
  const [openId, setOpenId] = useState(null);

  return (
    <div>
      {faqs.map(faq => (
        <div key={faq.id}>
          <button onClick={() => setOpenId(openId === faq.id ? null : faq.id)}>
            {faq.faq_title}
            <Icon rotate={openId === faq.id} />
          </button>
          {openId === faq.id && (
            <div>{faq.faq_description}</div>
          )}
        </div>
      ))}
    </div>
  );
}
```

### Admin CRUD Table
```jsx
// Columns: ID, Title, Status, Order, Created, Actions (Edit, Delete)
// Sorting: Click column headers (order + sortedBy query params)
// Pagination: Server-side (page, limit)
// Drag-and-drop: Reorder via PUT /faqs/reorder
```

### Admin Create/Edit Form
```jsx
// Fields:
//   faq_title (multilingual: en, ar text inputs)
//   faq_description (multilingual: en, ar textareas)
//   status (toggle)
```

### Reorder UI
```jsx
// Drag-and-drop list:
//   1. User drags FAQ items to reorder
//   2. On drop, collect IDs in new order
//   3. PUT /api/v1/faqs/reorder { faqs: [3, 1, 2] }
//   4. Refresh list
//   5. Show success/error toast
```

### Key Considerations
1. **Translatable fields** — Send `faq_title` and `faq_description` as `{"en": "...", "ar": "..."}`
2. **Locale-aware responses** — On `faqs.index`, only the current locale's translation is returned (not raw JSON)
3. **Soft deletes** — Deleted FAQs disappear from listings but are recoverable from DB
4. **No pagination on public** — Public endpoint returns ALL active FAQs (no limit parameter)
5. **Reorder validation** — All IDs must exist; invalid IDs return 422
