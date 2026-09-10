# Tag Module — Changelog (Public API)

## [1.0.0] — 2026-07-20

### Added
- Comprehensive API investigation documentation (`api-desc/front/tag/`)
- Public tag endpoints: list all tags and get by slug
- TagResource with type relationship
- 3 existing tests in `ProductTagTest.php`

### Known Issues
1. **No pagination** — All tags returned in one response (BUG-TAG-001)
2. **No filtering/ordering** — No limit, search, or sort parameters (BUG-TAG-002)
3. **N+1 query on type** — Eager loading of `type` relationship missing on both endpoints (BUG-TAG-003, BUG-TAG-004)
4. **No caching** — Each request hits the database (Jira Task 3)
