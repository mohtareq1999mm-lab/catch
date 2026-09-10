# Navigation Bar — QA Test Cases

## Test Files

No existing tests for this endpoint (see BUG-NAV-002).

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | Fetch nav-data | GET /general/nav-data | 200, array of categories |
| F2 | Fetch with level=1 | GET /general/nav-data?level=1 | Only top-level categories (no children) |
| F3 | Fetch with level=2 | GET /general/nav-data?level=2 | Categories with direct children only |
| F4 | Fetch with level=3 | GET /general/nav-data?level=3 | Full 3-level hierarchy |
| F5 | Fetch with no active categories | No active categories in DB | 200, empty array `[]` |
| F6 | Verify category images | Category with and without images | null or URL for each |

---

## Cache Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| C1 | Response is cached | First request hits DB, second request within 120s hits cache | Same response, same data |
| C2 | Cache expires after TTL | Wait 120s | Fresh data fetched from DB |
| C3 | Channel-scoped cache | Different X-Channel headers get independent caches | Different cache keys |
| C4 | Cache invalidation on category change | Update category → clear cache → fresh data returned | Updated data |

---

## Channel Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| H1 | Default channel (no header) | No X-Channel header | Uses 'home' channel cache |
| H2 | Home channel header | X-Channel: home | 'home' cache key used |
| H3 | Fast shipping channel header | X-Channel: fast-shipping | 'fast-shipping' cache key used |
| H4 | Invalid channel (non-strict) | X-Channel: invalid | Falls back to default, 200 |
| H5 | Invalid channel (strict mode) | X-Channel: invalid, strict=true | 400 Bad Request |

---

## Response Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | JSON structure | Validate response schema | status, message, success, data |
| S2 | Field types per category | id, name, slug, level, image, children | Correct types |
| S3 | Image object structure | image has desktop and mobile keys | String or null |
| S4 | Children array | children is always an array | Never null |
| S5 | Translation support | Category with Arabic name | Returns correct locale name |

---

## Regression Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| R1 | Category soft-deleted | Soft-deleted categories | Excluded from response |
| R2 | Category status inactive | Inactive category | Excluded from response |
| R3 | Large hierarchy | 100+ categories | Response time < 500ms |
| R4 | Concurrent requests | 50 concurrent requests | All return 200, no deadlocks |
