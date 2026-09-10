# Site Reviews — Frontend Integration Guide

## Public Endpoints

### GET /api/v1/general/site-reviews

Fetch approved website reviews for display (e.g., homepage testimonials, "What our customers say" section).

**Request:**
```js
fetch('/api/v1/general/site-reviews')
  .then(res => res.json())
  .then(data => console.log(data.data));
// Returns: [{ id, rating, title, comment, customer: {id, name}, created_at }]
```

**Response Schema:**
```json
{
  "data": [
    {
      "id": 1,
      "rating": 5,
      "title": "Excellent Website",
      "comment": "The website is easy to use.",
      "customer": { "id": 3, "name": "Ahmed" },
      "created_at": "2026-08-10T09:00:00.000000Z"
    }
  ]
}
```

**Usage:**
- Display rating stars (1–5), customer name, optional title, and comment
- Sort client-side by `created_at` desc if needed (API already returns newest first)
- Response is cached server-side for 4h — a newly approved review appears immediately (cache flushed on moderation), but other changes are eventual

### POST /api/v1/general/site-reviews

Submit a website review. Requires an authenticated customer token.

**Request:**
```js
fetch('/api/v1/general/site-reviews', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    rating: 5,
    title: 'Excellent Website',   // optional
    comment: 'The website is easy to use.'
  })
});
```

**Response 201:**
```json
{
  "status": 201,
  "message": "Site review submitted successfully",
  "success": true,
  "data": {
    "id": 2,
    "rating": 5,
    "title": "Excellent Website",
    "comment": "The website is easy to use.",
    "customer": { "id": 3, "name": "Ahmed" },
    "created_at": "2026-08-10T09:00:00.000000Z"
  }
}
```

## Admin Endpoints

| Method | Endpoint | Permission | Purpose |
|--------|----------|------------|---------|
| GET | `/api/v1/site-reviews?status=&limit=&page=` | `view-site-reviews` | Paginated list |
| GET | `/api/v1/site-reviews/{id}` | `view-site-reviews` | Detail |
| PATCH | `/api/v1/site-reviews/{id}/approve` | `approve-site-reviews` | Approve |
| PATCH | `/api/v1/site-reviews/{id}/reject` | `reject-site-reviews` | Reject |

## Frontend Patterns

### Reviews Wall (Public)
```jsx
function SiteReviews() {
  const [reviews, setReviews] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetch('/api/v1/general/site-reviews')
      .then(res => res.json())
      .then(data => {
        setReviews(data.data || []);
        setLoading(false);
      })
      .catch(() => setLoading(false));
  }, []);

  if (loading) return <Skeleton variant="rectangular" height={300} />;
  // ...
}
```

### Review Submission Form (Authenticated Customer)
```jsx
// Fields:
//   rating   → star picker 1–5 (required)
//   title    → text input, max 191 (optional)
//   comment  → textarea, max 2000 (required)
//
// Submit → POST /api/v1/general/site-reviews
// On 201 → show "Thank you! Your review is awaiting moderation."
// On 422 → render field errors
// On 401 → prompt login
```

### Admin Moderation Table
```jsx
// Columns: ID, Customer (name/email), Rating, Title, Comment, Status, Moderator, Created
// Filters: status dropdown (all / pending / approved / rejected)
// Pagination: server-side (page, limit)
// Actions per pending row:
//   Approve → PATCH /site-reviews/{id}/approve
//   Reject  → PATCH /site-reviews/{id}/reject
//   (approved/rejected rows show moderator name + moderated_at)
```

### Rating Stars
```jsx
// 1–5 scale. Use the numeric `rating` field.
// Half stars are not supported (integer only).
```

## Key Considerations

1. **Moderation gate** — a submitted review does NOT appear in `GET /api/v1/general/site-reviews` until an admin approves it. Always show a "pending approval" confirmation after submission.
2. **Moderation-safe payload** — the public resource contains only `id, rating, title, comment, customer {id, name}, created_at`. Never expect `status`, `moderator`, `moderated_at` from the public endpoint.
3. **Customer name only** — email is exposed only in admin endpoints, never publicly.
4. **Optional title** — handle `title: null` in rendering.
5. **Multiple reviews allowed** — a customer may submit more than one site review (no dedup).
6. **Cache freshness** — after admin moderation, the public list cache is flushed so the change is visible immediately. Between modulations the public list is served from a 4-hour cache.
