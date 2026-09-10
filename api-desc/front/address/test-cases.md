# Test Cases — Address Feature

## Current Coverage

**No existing test files found** for the Address feature. No test files reference `AddressController`, `AddressRepository`, `AddressRequest`, or `AddressResource`.

## Recommended Test Suite

### Feature Test: `AddressCrudTest.php`

#### Authorization Tests

| # | Test | Description |
|---|------|-------------|
| 1 | Guest cannot list | GET /api/v1/address → 401 |
| 2 | Guest cannot create | POST /api/v1/address → 401 |
| 3 | Guest cannot show | GET /api/v1/address/1 → 401 |
| 4 | Guest cannot update | PUT /api/v1/address/1 → 401 |
| 5 | Guest cannot delete | DELETE /api/v1/address/1 → 401 |

#### List Tests

| # | Test | Description |
|---|------|-------------|
| 6 | List returns user's addresses only | Returns addresses scoped to auth user |
| 7 | List with no addresses | Empty data array |
| 8 | List with multiple addresses | All returned |
| 9 | List does not return other users' addresses | Scoping verified |

#### Create Tests

| # | Test | Description |
|---|------|-------------|
| 10 | Create with all required fields | 201 with address data |
| 11 | Create assigns correct customer_id | customer_id = auth()->id() |
| 12 | Create with location | Location stored |
| 13 | Create without optional location | Location = [] |
| 14 | Create with missing title | 422 |
| 15 | Create with missing type | 422 |
| 16 | Create with missing default | 422 |
| 17 | Create with missing address.zip | 422 |
| 18 | Create with missing address.city | 422 |
| 19 | Create with missing address.state | 422 |
| 20 | Create with missing address.country | 422 |
| 21 | Create with missing address.street_address | 422 |
| 22 | Create with invalid default (string "abc") | 422 |
| 23 | Create with empty title | 422 |
| 24 | Create ignores customer_id from request | Overwritten by auth ID |

#### Show Tests

| # | Test | Description |
|---|------|-------------|
| 25 | Show own address | 200 with full data |
| 26 | Show another user's address | 404 |
| 27 | Show nonexistent address | 404 |

#### Update Tests

| # | Test | Description |
|---|------|-------------|
| 28 | Update own address | 200 with updated data |
| 29 | Update another user's address | 404 |
| 30 | Update nonexistent address | 404 |
| 31 | Update changes customer_id (should not change) | customer_id unchanged |
| 32 | Update with missing title | 422 |
| 33 | Update with all valid fields | 200 |

#### Delete Tests

| # | Test | Description |
|---|------|-------------|
| 34 | Delete own address | 200 |
| 35 | Verify address is gone after delete | GET → 404 |
| 36 | Delete another user's address | 404 |
| 37 | Delete nonexistent address | 404 |

#### Response Structure Tests

| # | Test | Description |
|---|------|-------------|
| 38 | List response has correct structure | message, status, success, data |
| 39 | Single response has correct structure | message, status, success, data |
| 40 | Address resource has all fields | id, title, type, default, address, location, customer_id, created_at |
| 41 | Default field is boolean | true/false |
| 42 | Address is object with 5 keys | street_address, city, state, zip, country |
| 43 | Location is object or empty array | latitude, longitude or [] |
| 44 | created_at is ISO 8601 | Valid datetime format |

#### Edge Case Tests

| # | Test | Description |
|---|------|-------------|
| 45 | Title with 255 characters | Accepted |
| 46 | Title exceeding 255 characters | 422 |
| 47 | Multiple addresses for same user | All accessible |
| 48 | Create, show, update, delete in sequence | Full CRUD cycle works |
