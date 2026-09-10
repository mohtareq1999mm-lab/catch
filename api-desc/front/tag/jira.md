# Tag Module — Backend Jira Tasks

---

## Task 1: Add Pagination and Filtering to Tags Listing

**Priority:** Medium
**Component:** TagController
**Effort:** Small
**Files:**
- `app/Http/Controllers/Api/General/TagController.php`

**Description:** The public tags endpoint returns all tags with no pagination, limit, or filtering. Add `limit`, `order`, and `search` query parameters for consistency with other public endpoints.

**Acceptance Criteria:**
- [ ] `?limit=15` limits results
- [ ] `?order=asc|desc` sorts by id
- [ ] `?search=term` filters by name
- [ ] Default limit of 15 if not specified
- [ ] Backward compatible — no param returns up to 15 tags

---

## Task 2: Add Eager Loading for `type` Relationship

**Priority:** Medium
**Component:** TagController
**Effort:** Trivial
**Files:**
- `app/Http/Controllers/Api/General/TagController.php`

**Description:** Both `index()` and `show()` methods query tags without eager loading the `type` relationship, causing N+1 queries. Add `->with(['type'])` to both queries.

**Acceptance Criteria:**
- [ ] `index()` uses `Tag::with(['type'])->get()`
- [ ] `show()` uses `Tag::with(['type'])->where('slug', $slug)->first()`
- [ ] Response structure unchanged
- [ ] Existing tests pass

---

## Task 3: Add Cache to Public Tag Endpoints

**Priority:** Low
**Component:** TagController
**Effort:** Small
**Files:**
- `app/Http/Controllers/Api/General/TagController.php`

**Description:** Tags change infrequently. Add caching to reduce database load.

**Acceptance Criteria:**
- [ ] `index()` response cached for 300s
- [ ] Cache key includes language
- [ ] Cache invalidated on tag create/update/delete
