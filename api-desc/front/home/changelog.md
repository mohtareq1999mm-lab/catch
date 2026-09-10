# Changelog - Home Feature

## [Unreleased]

### Added
- Home page data aggregation with 13 configurable sections (sliders, banners, brands, categories, products, coupons, flash sales)
- Public navigation category tree endpoint (nav-data)
- Channel-aware caching (120 min TTL, isolated by channel)
- Section filtering via query parameters
- Parent category scoping
- Channel context system (Home vs Fast Shipping)
- CategoryHomeResource and CategoryNavbarResource
- ContentPageSeeder for home page structure
- HasChannelFilter trait for home-channel product exclusion

### Infrastructure
- ChannelMiddleware for X-Channel header parsing
- ChannelContext singleton for channel state
- CategoryHierarchyService for category tree operations

### Tests
- Channel context and scope tests
- Cache isolation tests
- Minimal home endpoint assertion

## [Unreleased - Technical Debt]

- [ ] Create dedicated HomeFeatureTest with full structural assertions
- [ ] Add nav-data endpoint tests
- [ ] Standardize section filter keys to match response keys
- [ ] Add cache invalidation for all section types (not just pricing)
