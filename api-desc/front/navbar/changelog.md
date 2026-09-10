# Navigation Bar — Changelog

## [1.0.0] — 2026-07-20

### Added
- Comprehensive API investigation documentation (`api-desc/fornt/navbar/`)
- Navigation data endpoint: `GET /api/v1/general/nav-data`
- CategoryNavbarResource for hierarchical category tree response
- Cache integration with 120-second TTL and channel-scoped cache keys
- Channel middleware support for multi-channel nav-bar data

### Known Issues
1. **No tests exist** — Zero test coverage for the nav-data endpoint (BUG-NAV-002)
2. **Level parameter has no DB impact** — `level` query param only affects rendering depth, not query depth (BUG-NAV-001)
3. **Cache invalidation gap** — Level-prefixed cache keys (`home-nav-bar:level:{n}`) are not invalidated by `clearCache()` (BUG-NAV-003)
4. **Locale translation may be cached** — Category names are translated at cache-write time, potentially freezing locale (Jira Task 4)
