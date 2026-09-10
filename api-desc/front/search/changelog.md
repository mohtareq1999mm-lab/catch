# Changelog - Search Feature

## [Unreleased]

### Added
- `SearchController` with `index()` method (calls `SearchService`)
- `SearchService` with `search()` method (stub — returns `[]`)
- Search rate limiter defined (30 req/min)

### Missing (Not Yet Implemented)
- [ ] Route registration for `GET /api/v1/general/search`
- [ ] Actual search logic in `SearchService`
- [ ] Request validation (FormRequest)
- [ ] API resource for search results
- [ ] Tests
