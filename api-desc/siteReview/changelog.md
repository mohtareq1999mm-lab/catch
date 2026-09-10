# Site Reviews Module — Changelog

## [2.0.0] — 2026-08-10

### Added
- `api-desc/siteReview/` — full API investigation documentation set (README, api, backend, database, flow, frontend, bug-report, changelog, test-cases, qa, jira, jira-frontend)
- `SiteReviewBugRegressionTest.php` — 4 regression tests (non-numeric id, negative/zero/abc/oversized limit)

### Fixed
- **BUG-SR-001 (High):** Non-numeric `{id}` on `show`/`approve`/`reject` returned HTTP 500 (`TypeError`) — added `->whereNumber('id')` to the three `{id}` admin routes
- **BUG-SR-002 (Medium):** Unvalidated `limit` — `?limit=-5` → 409 (SQL `LIMIT -5`), `?limit=0`/`?limit=abc` silent fallback, no upper bound — now normalized to `min(max((int)limit, 1), 100)`

### Changed
- `packages/marvel/src/Http/Controllers/SiteReviewController.php::index()` — limit normalization

### Known Issues (Open)
- BUG-SR-003: Public list cached 4h — stale only if moderation reverted outside the API (acceptable)
- BUG-SR-004: `rating` 1–5 enforced at app layer only (no DB CHECK)
- BUG-SR-005: Multiple reviews per customer allowed (by design)
- BUG-SR-006: `moderate()` conflates "not found" and "already moderated" → both 404
- BUG-SR-007: Redundant indexes on `user_id` / `moderated_by`

---

## [1.0.0] — 2026-08-10

### Added
- `SiteReviewStatus` enum (`pending`/`approved`/`rejected`)
- `site_reviews` migration (FKs, status index)
- `SiteReview` model (`user()`, `moderator()` relations, enum/datetime casts)
- `SiteReviewService` (create + list + find + transactional moderate)
- `CreateSiteReviewRequest` (rating 1–5, title ≤191, comment ≤2000)
- `SiteReviewResource` (public, moderation-safe) + `AdminSiteReviewResource` (moderator name)
- Customer controller (`app/Http/Controllers/Api/General/SiteReviewController.php`) — public GET + authenticated POST
- Admin controller (`packages/marvel/src/Http/Controllers/SiteReviewController.php`) — list/detail/approve/reject with 3 permissions
- 6 routes (2 customer, 4 admin)
- `FrontendResource::SITE_REVIEWS` cache tag
- 3 permission constants + seeder + en/ar translations
- 4 message constants + en/ar translations
- `SiteReviewFactory` (pending/approved/rejected states), `SiteReviewSeeder` (registered in DatabaseSeeder)
- 5 test files (54 tests, 141 assertions)

### Fixed
- N/A (initial implementation)
