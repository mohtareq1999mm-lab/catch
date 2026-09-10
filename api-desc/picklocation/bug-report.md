# Bug Report - Pickup Location Feature

## Issue 1 (LOW): Duplicate Pagination Keys in Admin List Response

- **File:** `packages/marvel/src/Http/Controllers/PickupLocationController.php:53-67`
- **Description:** `index()` manually extracts pagination meta from the ResourceCollection response object, resulting in both `page` and `current_page` with the same value, and `last_page_url`, `first_page_url` alongside `last_page`, `path`. The API response has 14 pagination keys instead of the standard 4-5.
- **Impact:** Inconsistent pagination structure compared to other admin endpoints.

## Issue 2 (RESOLVED): Stale Admin List Cache After Writes

- **File:** `packages/marvel/src/Http/Controllers/PickupLocationController.php`
- **Description:** `index()` cached its response under the `PICKUP_LOCATIONS` tag (via `HasCache::remember`), but `store()`/`update()`/`destroy()` never flushed the tag — so after creating/editing/deleting a location (including switching the default), the admin list kept returning stale data until the 4h TTL expired.
- **Fix:** `store()`, `update()`, and `destroy()` now call `$this->flushTag(FrontendResource::PICKUP_LOCATIONS->value)` after a successful write.
- **Impact:** Admin list reflects create/update/delete (and default switches) immediately. Public list was never cached, so it was unaffected.
