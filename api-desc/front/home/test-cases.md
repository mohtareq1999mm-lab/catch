# Test Cases - Home Feature

## Current Coverage

No dedicated Home test file. Coverage is spread across:

| Test File | Focus |
|-----------|-------|
| `ChannelContextTest.php` | Channel defaults, header parsing, cache keys |
| `FastShippingControllerTest.php` | Home endpoint assertion (minimal), channel-based filtering |
| `FastShippingHardenTest.php` | Cache key isolation |
| `PricingCacheInvalidationTest.php` | Home cache clearing on pricing changes |
| `Unit/ChannelContextTest.php` | Defaults to home, switching |
| `Unit/ChannelEnumTest.php` | Enum validation |
| `Unit/FastShippingScopeTest.php` | Scope application |

## Recommended Tests

| # | Test | Description |
|---|------|-------------|
| FT-001 | Home endpoint returns all sections by default | Full data structure |
| FT-002 | Home endpoint filters sections | Only requested sections returned |
| FT-003 | Home endpoint respects parent_category_id | Scoped categories |
| FT-004 | nav-data returns category tree | Correct nesting |
| FT-005 | nav-data respects level parameter | Depth limited |
| FT-006 | Cache isolation by channel | Different channels = different cache |
| FT-007 | Home channel excludes fast-shipping products | Filter applied |
| FT-008 | Empty catalog returns empty sections | Graceful handling |
