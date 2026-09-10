# Flash Sale Module — Changelog (Public API)

## [1.0.0] — 2026-07-20

### Added
- Comprehensive API investigation documentation (`api-desc/front/flash/`)
- Public flash sale endpoints: list, detail, products-by-qty, ending-this-week, ending-today
- FlashSaleResource with translatable fields and products
- ProductMiniResource for flash sale products
- `valid()` scope for automatic date/status filtering
- Channel-aware product filtering via HasChannelFilter
- Product pricing enrichment with flash sale calculations

### Known Issues
1. **No `valid()` scope on slug lookup** — Expired flash sales accessible by direct slug (BUG-FLASH-002)
2. **Typo in response key** — `discription` instead of `description` (BUG-FLASH-003)
3. **No dedicated tests** — Only one channel-header test exists (BUG-FLASH-004)
4. **Implicit slug behavior** — `index()` has undocumented `?slug=x` param (BUG-FLASH-001)
5. **No caching** — Each request hits the database (Jira Task 5)
