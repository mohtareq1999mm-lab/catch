# Search Feature - API Investigation

## Feature Name

Global Search

## Description

The Search feature is **scaffolded but not implemented**. A `SearchController` and `SearchService` exist, but the route is not registered and the service returns an empty array. The expected endpoint `GET /api/v1/general/search` does not function.

## Current State

| Component | Status |
|-----------|--------|
| `SearchController::index()` | Exists — delegates to service |
| `SearchService::search()` | Exists — returns `[]` (stub) |
| Route registration | **Missing** — no route defined |
| Rate limiter (30 req/min) | Defined in `RouteServiceProvider` but **not applied** |
| Test coverage | 1 test that **would fail** (404 instead of 200) |

## Key Files

| Layer | Path |
|-------|------|
| Controller | `app/Http/Controllers/Api/General/SearchController.php` |
| Service | `app/Services/General/SearchService.php` |
| Routes | `routes/api.php` (imported but no route) |
| Rate Limiter | `app/Providers/RouteServiceProvider.php` (defined but unused) |
| Test | `tests/Feature/FastShippingControllerTest.php` (1 test, likely failing) |
