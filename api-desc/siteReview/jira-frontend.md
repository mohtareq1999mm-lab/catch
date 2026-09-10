# Site Reviews — Frontend Jira Tasks

## Task 1: Public "Customer Reviews" Wall

**Description:** Display approved website reviews (homepage testimonials or dedicated section).

**Data source:** `GET /api/v1/general/site-reviews` (no auth, cached 4h)

**Requirements:**
- Render each review: rating stars (1–5), customer name, optional title, comment, relative date
- Handle `title: null` gracefully
- Loading skeleton + empty state ("No reviews yet")
- Sort is already newest-first from the API
- **Do NOT expect** `status`, `moderator`, `moderated_at` fields — they are never in the public payload

---

## Task 2: Review Submission Form

**Description:** Let authenticated customers submit a website review.

**Endpoint:** `POST /api/v1/general/site-reviews` (auth required)

**Fields:**
- `rating` — star picker 1–5 (required)
- `title` — text input, max 191 (optional)
- `comment` — textarea, max 2000 (required)

**Behavior:**
- On 201 → success message "Your review has been submitted and is awaiting moderation"
- On 422 → render field errors under each input
- On 401 → redirect to login
- **Do NOT send** `status`, `moderated_by`, `moderated_at` — they are ignored anyway (service forces them)

---

## Task 3: Admin Moderation Table

**Description:** Dashboard table for managing site reviews.

**Endpoint:** `GET /api/v1/site-reviews?status=&limit=&page=`

**Requirements:**
- Columns: ID, Customer (name/email), Rating, Title, Comment, Status, Moderator, Moderated At, Created
- Status filter dropdown: all / pending / approved / rejected
- Server-side pagination (limit, page)
- Status badge colors: pending=amber, approved=green, rejected=red
- Pending rows show **empty** moderator column (null)

---

## Task 4: Approve / Reject Actions

**Description:** Moderation actions on pending reviews.

**Endpoints:**
- `PATCH /api/v1/site-reviews/{id}/approve` (permission: `approve-site-reviews`)
- `PATCH /api/v1/site-reviews/{id}/reject` (permission: `reject-site-reviews`)

**Behavior:**
- Show Approve/Reject buttons only on `pending` rows
- On success → toast + refresh list (moderator name now appears on the row)
- On 403 → hide actions (insufficient permission)
- On 404 → row no longer exists / already moderated → refresh list
- Applies to existing `site-reviews/{id}` detail screen too

---

## Task 5: Review Detail View

**Description:** Admin detail modal/screen for a single review.

**Endpoint:** `GET /api/v1/site-reviews/{id}`

**Fields:** id, user_id, customer{id,name,email}, rating, title, comment, status, moderator{id,name}|null, moderated_at, created_at

**Requirements:**
- Show full comment text
- Show customer email (admin-only field)
- Show moderator name if moderated, else "—"

---

## Task 6: Loading / Empty / Error States

**Description:** Consistent states across all review components.

- **Loading:** Skeletons for table rows and review cards
- **Empty:** "No reviews" illustration (list), "No pending reviews" (pending filter)
- **Error:** Inline error with retry for failed fetches
- **Unauthorized (403):** Hide action buttons, show read-only mode

---

## Task 7: Permission-Guarded UI

**Description:** Hide/show actions based on the user's permissions.

| Permission | UI Impact |
|------------|-----------|
| `view-site-reviews` | Can see the module + detail |
| `approve-site-reviews` | Approve button visible |
| `reject-site-reviews` | Reject button visible |

If a user lacks all three, hide the Site Reviews menu entry.
