# Tag Module — Backend Jira Tasks

---

## Task 1: Add Tag Soft Deletes

**Priority:** Medium
**Component:** Tag Model
**Effort:** Small
**Files:**
- `packages/marvel/src/Database/Models/Tag.php`
- Migration: add `deleted_at` column

**Description:** Tags currently use hard deletes. Add soft delete support to preserve tag records and allow restoration.

**Acceptance Criteria:**
- [ ] Tag model uses `SoftDeletes` trait
- [ ] Migration adds `deleted_at` timestamp
- [ ] `DELETE /tags/{id}` sets `deleted_at` instead of removing the row
- [ ] `GET /tags` excludes soft-deleted tags
- [ ] `GET /tags/{id}` returns 404 for soft-deleted tags

---

## Task 2: Add Tag Observer for Activity Logging

**Priority:** Medium
**Component:** Tag Observer
**Effort:** Small
**Files:**
- `app/Observers/TagObserver.php` (new)
- `packages/marvel/src/Providers/EventServiceProvider.php` or `AppServiceProvider.php`

**Description:** No activity is currently logged for tag CRUD operations. Add an observer to dispatch `LogActivityJob` on created/updated/deleted events.

**Acceptance Criteria:**
- [ ] `TagObserver` logs `tag_created`, `tag_updated`, `tag_deleted` activities
- [ ] Observer is registered in a service provider
- [ ] Activity is logged for create, update, and delete operations
- [ ] Activity logging is queued via `LogActivityJob`

---

## Task 3: Add Tag Public Endpoints

**Priority:** Low
**Component:** Routes / Controller
**Effort:** Medium
**Files:**
- `app/Http/Controllers/Api/General/TagController.php` (new)
- `routes/api.php`
- `app/Http/Resources/Tag/TagPublicResource.php` (new, optional)

**Description:** Tags currently have no public read-only endpoints. Add `/api/v1/general/tags` endpoints for public tag browsing.

**Acceptance Criteria:**
- [ ] `GET /api/v1/general/tags` — public, paginated, filterable by language
- [ ] `GET /api/v1/general/tags/{slug}` — public, single tag by slug
- [ ] No authentication required
- [ ] Only returns tags with associated published products

---

## Task 4: Expose `type` Relationship in TagResource

**Priority:** Low
**Component:** Tag Resource
**Effort:** Trivial
**Files:**
- `packages/marvel/src/Http/Resources/TagResource.php`

**Description:** The `TagRepository` eager-loads `type` relationship but `TagResource` never includes it in the response. Either remove the unnecessary `with(['type'])` or add the `type` field to the resource output.

**Acceptance Criteria:**
- [ ] Either `type` is included in TagResource output, OR
- [ ] The `with(['type'])` is removed from controller/repository to avoid unnecessary queries

---

## Task 5: Clean Up Media on Tag Delete

**Priority:** Low
**Component:** Tag Repository
**Effort:** Trivial
**Files:**
- `packages/marvel/src/Http/Controllers/TagController.php`

**Description:** When a tag is deleted, the associated media files (image, icon) are not cleaned up. Add media cleanup before delete.

**Acceptance Criteria:**
- [ ] Deleting a tag removes its image and icon media files
- [ ] Media is deleted before the tag record is removed
- [ ] Handles case where no media exists (no error)

---

## Task 6: Fix `destroy()` to Return Proper JSON Response

**Priority:** Low
**Component:** Tag Controller
**Effort:** Trivial
**Files:**
- `packages/marvel/src/Http/Controllers/TagController.php`

**Description:** `destroy()` currently returns a raw boolean (`true`/`false`). Should return a proper JSON response with status and message for consistency with other endpoints.

**Acceptance Criteria:**
- [ ] `DELETE /tags/{id}` returns `{ status: 200, message: "Tag deleted successfully", success: true }`
- [ ] `DELETE /tags/{id}` for non-existent returns `{ status: 404, message: "Not found", success: false }`

---

## Task 7: Add Comprehensive Tag Test Suite

**Priority:** High
**Component:** Tests
**Effort:** Medium
**Files:**
- `tests/Feature/Tags/TagCrudTest.php` (new)
- `tests/Feature/Tags/TagValidationTest.php` (new)
- `tests/Feature/Tags/TagAuthorizationTest.php` (new)
- `tests/Feature/Tags/TagTranslationTest.php` (new)
- `tests/Feature/Tags/TagResourceTest.php` (new)
- `tests/Feature/Tags/TagMediaTest.php` (new)
- `tests/Feature/Tags/TagRegressionTest.php` (new)

**Description:** No tests exist for the Tag module. Add comprehensive test coverage.

**Acceptance Criteria:**
- [ ] CRUD tests: create, list, show, update, delete
- [ ] Validation tests: missing name, duplicate name, invalid image
- [ ] Authorization tests: permission checks for each action
- [ ] Translation tests: bilingual name support
- [ ] Resource structure tests: response JSON format
- [ ] Media tests: image/icon upload and replacement
- [ ] Regression tests: edge cases and bug fixes
