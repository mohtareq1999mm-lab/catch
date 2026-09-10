# Test Cases - Fast Shipping Feature

## Current Coverage

**57 test methods across 6 test files:**

| Test File | Tests | Focus |
|-----------|-------|-------|
| `FastShippingControllerTest.php` | 28 | Status, products, orders, checkout, channel filtering |
| `FastShippingHardenTest.php` | 12 | Edge cases, checkout success, authorization |
| `FastShippingScopeTest.php` | 4 | Global scope unit tests |
| `FastShippingRepositoryTest.php` | 5 | Settings CRUD, cache, validation |
| `ChannelContextTest.php` | 4 | Context defaults, switching |
| `ChannelEnumTest.php` | 4 | Enum validation |

## Key Test Areas

### Status Endpoint

| # | Test | Description |
|---|------|-------------|
| 1 | Status when enabled | Returns enabled=true |
| 2 | Status when disabled | Returns enabled=false |
| 3 | Status includes ETA | available_again_at present |

### Products Endpoint

| # | Test | Description |
|---|------|-------------|
| 1 | Only eligible products returned | Filter applied |
| 2 | Search works | Search by name |
| 3 | Pagination | Correct meta |

### Checkout

| # | Test | Description |
|---|------|-------------|
| 1 | Successful COD checkout | Order created |
| 2 | Successful online checkout | Transaction created |
| 3 | Governorate disabled | Error returned |
| 4 | Outside working hours | Error returned |
| 5 | Insufficient stock | Error returned |
| 6 | Mixed cart rejected | Error returned |
| 7 | Empty cart | Error returned |

### Channel Filtering

| # | Test | Description |
|---|------|-------------|
| 1 | Fast channel shows eligible only | Filtered |
| 2 | Home channel excludes fast products | Excluded |
| 3 | Missing header defaults to home | Default behavior |
| 4 | Invalid header falls back to home | Graceful |
| 5 | Cache keys differ by channel | Isolated |

## Recommended Additional Tests

| # | Test | Description |
|---|------|-------------|
| FT-001 | Product toggle endpoint | Toggle works |
| FT-002 | Governorate toggle endpoint | Toggle works |
| FT-003 | Admin settings update | CRUD works |
| FT-004 | Scope disabled via config | All products returned |
| FT-005 | Concurrent checkout race condition | LockForUpdate works |
