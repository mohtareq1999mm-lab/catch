# Brand Module — Changelog (Public API)

## [1.0.0] — 2026-07-20

### Added
- Comprehensive API investigation documentation (`api-desc/front/brand/`)
- Public brand endpoints: list, detail by slug, brand-products by quantity
- BrandResource for public brand responses
- BrandProductResource for product responses within brand context
- Product pricing enrichment on brand detail and brand-products endpoints
- Channel-aware product filtering via HasChannelFilter trait

### Known Issues
1. **No tests exist** — Zero test coverage for public brand endpoints (BUG-BRAND-003)
2. **No caching** — Brand endpoints hit the database on every request (Jira Task 2)
3. **Inconsistent route naming** — `brands-products` vs REST conventions (BUG-BRAND-001)
4. **Implicit slug behavior** — `index()` method has undocumented slug query param logic (BUG-BRAND-002)
