# Bug Report - Search Feature

## Current State: NOT FUNCTIONAL

The Search feature is scaffolded but completely non-functional due to two critical bugs.

## Issue 1 (CRITICAL): Route Not Registered

- **File:** `routes/api.php`
- **Description:** `SearchController::class` is imported but no route is defined. The endpoint `GET /api/v1/general/search` returns 404.
- **Impact:** Blocker — endpoint unusable.
- **Fix:** Register `Route::get('search', [SearchController::class, 'index']);` in the v1/general group.

## Issue 2 (CRITICAL): Service is a Stub

- **File:** `app/Services/General/SearchService.php`
- **Description:** `search()` returns `[]` regardless of input.
- **Impact:** Blocker — even with a route, the endpoint returns empty data.
- **Fix:** Implement actual search logic using Scout (Meilisearch) or LIKE queries across models.

## Issue 3 (HIGH): Test Will Fail

- **File:** `tests/Feature/FastShippingControllerTest.php` line 943
- **Description:** `search_endpoint_works_with_channel_header()` expects 200 but will receive 404.
- **Impact:** Test failure.

## Issue 4 (MEDIUM): Rate Limiter Unused

- **File:** `app/Providers/RouteServiceProvider.php`
- **Description:** A 30 req/min rate limiter is defined for 'search' but never applied.
- **Impact:** No protection against scraping if/when the endpoint is implemented.
