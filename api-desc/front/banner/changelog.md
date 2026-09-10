# Banner Module — Changelog (Public API)

## [1.0.0] — 2026-07-20

### Added
- Comprehensive API investigation documentation (`api-desc/front/banner/`)
- Public banner endpoints: list and detail by slug
- BannerResource with translatable title/description and media images
- ProductMiniResource for banner-associated products
- Optional product loading via `with_products` query parameter
- Channel-aware product filtering

### Known Issues
1. **No tests exist** — Zero dedicated test coverage (BUG-BANNER-003)
2. **No caching** — Banner endpoints hit DB on every request (BUG-BANNER-004)
3. **`with_products` boolean coercion bug** — Only literal string `'false'` disables products (BUG-BANNER-002)
4. **Implicit slug behavior** — `index()` has undocumented slug query param logic (BUG-BANNER-001)
