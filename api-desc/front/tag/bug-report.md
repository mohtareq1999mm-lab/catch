# Bug Report — Tag Module (Public API)

---

## BUG-TAG-001: No Limit/Pagination on Tags Listing

**Severity:** Low

**Component:** `app/Http/Controllers/Api/General/TagController.php` (line 17)

**Description:** The `index()` method returns ALL tags without any pagination or limit. If there are hundreds or thousands of tags, this could cause performance issues and large response payloads.

**Code Location:** `app/Http/Controllers/Api/General/TagController.php` — lines 15-19

```php
public function index(Request $request)
{
    $tags = Tag::query()->get();
    return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, TagResource::collection($tags));
}
```

**Recommendation:** Add pagination with a configurable `limit` parameter (default 15).

---

## BUG-TAG-002: No Filtering or Ordering on Tags Listing

**Severity:** Low

**Component:** `app/Http/Controllers/Api/General/TagController.php`

**Description:** Unlike brands and banners which support `start_date`, `end_date`, `order`, and `tagsId` filtering, the public tags endpoint has no filtering or ordering capabilities. Tags are returned in whatever order the database returns them.

**Recommendation:** Add `order`, `search`, and `limit` query parameters for consistency with other public endpoints.

---

## BUG-TAG-003: No Eager Loading on Tag Listing

**Severity:** Low

**Component:** `app/Http/Controllers/Api/General/TagController.php` (line 17)

**Description:** The `index()` method does not eager load the `type` relationship. The `TagResource::toArray()` calls `getResourceData($this->type)`, which loads the type lazily. This causes N+1 queries when listing multiple tags.

**Current query:** `Tag::query()->get()` — no `->with(['type'])`

**Recommendation:** Change to `Tag::query()->with(['type'])->get()`

---

## BUG-TAG-004: `show()` Method Doesn't Eager Load Type

**Severity:** Low

**Component:** `app/Http/Controllers/Api/General/TagController.php` (line 23)

**Description:** The `show()` method also doesn't eager load `type`, causing an additional lazy load query.

**Recommendation:** Change to `Tag::query()->with(['type'])->where('slug', $slug)->first()`

---

## BUG-TAG-005: No Tests for Public Tag Endpoint Channel Behavior

**Severity:** Low

**Component:** Tests

**Description:** The existing tests in `ProductTagTest.php` cover basic listing and slug lookup, but do not test channel header behavior, empty states, or response structure validation.
