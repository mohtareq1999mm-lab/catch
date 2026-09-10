# API Reference — Site Reviews

---

## Admin Endpoints

All admin endpoints require `auth:sanctum` (applied at the route group) plus the listed `permission:` middleware (applied in the controller constructor). The route group also applies `throttle:admin`.

---

### GET /api/v1/site-reviews

Paginated list of all site reviews with optional moderation-status filtering.

**Authentication**: `auth:sanctum`, permission: `view-site-reviews`

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | int | 1 | Page number |
| limit | int | 15 | Items per page (normalized to 1–100) |
| status | string | - | Filter: `pending`, `approved`, `rejected`, or `all`. Unknown values are ignored (returns all) |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "user_id": 3,
        "customer": { "id": 3, "name": "Ahmed", "email": "ahmed@example.com" },
        "rating": 5,
        "title": "Excellent Website",
        "comment": "The website is easy to use.",
        "status": "approved",
        "moderator": { "id": 1, "name": "Shop Admin" },
        "moderated_at": "2026-08-10T10:00:00.000000Z",
        "created_at": "2026-08-10T09:00:00.000000Z"
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://example.com/api/v1/site-reviews",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://example.com/api/v1/site-reviews?page=1",
    "first_page_url": "http://example.com/api/v1/site-reviews?page=1"
  }
}
```

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/site-reviews?status=pending&limit=20" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Eager-loads `user` and `moderator` — no N+1.
- `limit` is clamped to `min(max((int)limit, 1), 100)`; `limit=0`, `limit=abc`, `limit=-5` are normalized instead of erroring.
- Pending reviews show `moderator: null` and `moderated_at: null`.
- Sorted newest-first (`latest()`).

---

### GET /api/v1/site-reviews/{id}

Get a single review with customer and moderator details.

**Authentication**: `auth:sanctum`, permission: `view-site-reviews`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Review ID (must be numeric; route uses `->whereNumber('id')`) |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "user_id": 3,
    "customer": { "id": 3, "name": "Ahmed", "email": "ahmed@example.com" },
    "rating": 5,
    "title": "Excellent Website",
    "comment": "The website is easy to use.",
    "status": "pending",
    "moderator": null,
    "moderated_at": null,
    "created_at": "2026-08-10T09:00:00.000000Z"
  }
}
```

**Response 404**:
```json
{ "status": 404, "message": "Site review not found", "success": false }
```

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/site-reviews/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Non-numeric `{id}` (e.g., `/site-reviews/abc`) returns 404 (route constraint), not 500.
- Missing review returns 404 with the `SITE_REVIEW_NOT_FOUND` message.

---

### PATCH /api/v1/site-reviews/{id}/approve

Approve a pending review. Sets `status = approved`, `moderated_by = {admin id}`, `moderated_at = now`.

**Authentication**: `auth:sanctum`, permission: `approve-site-reviews`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Review ID (must be numeric) |

**Response 200**:
```json
{
  "status": 200,
  "message": "Site review approved successfully",
  "success": true,
  "data": {
    "id": 1,
    "user_id": 3,
    "customer": { "id": 3, "name": "Ahmed", "email": "ahmed@example.com" },
    "rating": 5,
    "title": "Excellent Website",
    "comment": "The website is easy to use.",
    "status": "approved",
    "moderator": { "id": 1, "name": "Shop Admin" },
    "moderated_at": "2026-08-10T10:00:00.000000Z",
    "created_at": "2026-08-10T09:00:00.000000Z"
  }
}
```

**Response 404**: `{ "status": 404, "message": "Site review not found", "success": false }`

**Business Rules**:
- Only `pending → approved` is allowed. Approving an already-approved/rejected review returns 404.
- Runs in a `DB::transaction`; the status guard check happens inside the transaction.
- Flushes the `site_reviews` frontend cache tag so the public list updates immediately.

---

### PATCH /api/v1/site-reviews/{id}/reject

Reject a pending review. Sets `status = rejected`, `moderated_by = {admin id}`, `moderated_at = now`.

**Authentication**: `auth:sanctum`, permission: `reject-site-reviews`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Review ID (must be numeric) |

**Response 200**:
```json
{
  "status": 200,
  "message": "Site review rejected successfully",
  "success": true,
  "data": {
    "id": 1,
    "user_id": 3,
    "customer": { "id": 3, "name": "Ahmed", "email": "ahmed@example.com" },
    "rating": 1,
    "title": "Poor Experience",
    "comment": "Frequent errors during checkout.",
    "status": "rejected",
    "moderator": { "id": 1, "name": "Shop Admin" },
    "moderated_at": "2026-08-10T10:05:00.000000Z",
    "created_at": "2026-08-10T09:00:00.000000Z"
  }
}
```

**Response 404**: `{ "status": 404, "message": "Site review not found", "success": false }`

**Business Rules**:
- Only `pending → rejected` is allowed. Rejecting an already-moderated review returns 404.
- Runs in a `DB::transaction`.
- Flushes the `site_reviews` frontend cache tag.

---

## Public Endpoints

---

### GET /api/v1/general/site-reviews

List all approved reviews (public, no authentication).

**Authentication**: None (public)

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
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

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/general/site-reviews" \
  -H "Accept: application/json"
```

**Business Rules**:
- Only `status = approved` reviews are returned; pending/rejected are never exposed.
- **Moderation-safe** — response contains only `id`, `rating`, `title`, `comment`, `customer {id, name}`, `created_at`. No `status`, `moderated_by`, `moderated_at`, or `moderator`.
- Customer email is intentionally not exposed publicly.
- Cached under the `site_reviews` tag for 4 hours (key = md5 of full URL); flushed on admin approve/reject.
- Sorted newest-first. No pagination on the public endpoint.

---

### POST /api/v1/general/site-reviews

Submit a new website review. The review is always created as `pending`.

**Authentication**: `auth:sanctum`

**Request Body** (JSON):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| rating | int | required | 1–5 |
| title | string | optional | Max 191 chars |
| comment | string | required | Max 2000 chars |

**Validation Rules**:
| Field | Rules |
|-------|-------|
| rating | required, integer, min:1, max:5 |
| title | nullable, string, max:191 |
| comment | required, string, max:2000 |

**Response 201**:
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

**Response 401** (unauthenticated):
```json
{ "message": "Unauthenticated", "status": false }
```

**Response 422** (validation):
```json
{
  "message": "The rating field is required. (and 1 more error)",
  "status": false,
  "errors": {
    "rating": ["The rating field is required."],
    "comment": ["The comment field is required."]
  }
}
```

**Quick Test**:
```bash
curl -X POST "http://example.com/api/v1/general/site-reviews" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"rating": 5, "title": "Excellent Website", "comment": "The website is easy to use."}'
```

**Business Rules**:
- The service **forces** `status = pending`, `moderated_by = null`, `moderated_at = null`. Any customer-supplied `status`, `moderated_by`, or `moderated_at` is silently ignored (keys are never read from the request).
- No duplicate-review prevention — a customer may submit multiple site reviews (by design; this is website feedback, not a per-product review).
- New reviews are invisible publicly until approved.

---

## Error Responses

| Status | Meaning |
|--------|---------|
| 401 | Unauthenticated (missing/invalid token) |
| 403 | Authenticated but missing the required permission |
| 404 | Review not found / already moderated / non-numeric `{id}` |
| 422 | Validation failure on store |
| 429 | Rate-limited (`throttle:admin`) |

**Response Shape** (error):
```json
{ "message": "Site review not found", "status": false }
```
