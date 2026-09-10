# Bug Report - Category Feature

## Current State

No known open bugs were identified during investigation. The category feature has 12 dedicated test files covering CRUD, validation, authentication, authorization, translations, soft deletes, relationships, resources, media lifecycle, pivots, featured toggling, and regression scenarios.

## Identified Issues

### Issue 1: Legacy Slug JSON Handling in Model

- **File:** `packages/marvel/src/Database/Models/Category.php` (`retrieved` event)
- **Description:** The model contains a `retrieved` event that checks if `slug` is stored as JSON (legacy data) and extracts the English translation. This indicates a past data migration/inconsistency that required runtime handling.
- **Impact:** Minor performance overhead on every model retrieval. Technical debt from legacy migration.
- **Severity:** Low

### Issue 2: Circular Reference Validation Only in Update Request

- **File:** `packages/marvel/src/Http/Requests/CategoryUpdateRequest.php`
- **Description:** Circular reference detection (closure in `parent_id` rule) is only implemented in the update request, not the create request. Creating a category with an invalid parent_id that forms a cycle is impossible during creation (no existing category self-reference), but the inconsistency in validation placement is notable.
- **Impact:** Low — creation cannot cause cycles since the new category has no children yet.
- **Severity:** Low

### Issue 3: Details Excluded in Index but Present in Resource

- **File:** `packages/marvel/src/Http/Resources/CategoryResource.php`
- **Description:** The resource conditionally excludes `details` on the `categories.index` route. The logic checks `request()->route()->getName() === 'categories.index'`. If route names change or are not properly configured, `details` could leak in list responses.
- **Impact:** Low — works correctly with current route configuration.
- **Severity:** Low

### Issue 4: No Rate Limiting on Public Endpoints

- **Location:** `routes/api.php` (public category endpoints)
- **Description:** The public `GET /v1/general/categories` and `GET /v1/general/categories/{slug}` endpoints have no throttling middleware.
- **Impact:** Could be scraped or abused, though read-only endpoints pose limited risk.
- **Severity:** Low
