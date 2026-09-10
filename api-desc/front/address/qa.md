# QA — Address Feature

## Test Environment Setup

- **PHP Version:** 8.x
- **Laravel Version:** As defined in `composer.json`
- **Package:** `packages/marvel/`
- **Database:** MySQL with `RefreshDatabase`
- **Authentication:** Sanctum for all endpoints

## Test Matrix

### CRUD Tests

| TC ID | Description | Input | Expected |
|-------|-------------|-------|----------|
| TC-001 | List addresses (authenticated) | `GET /api/v1/address` | 200, array of addresses |
| TC-002 | List addresses (no auth) | `GET /api/v1/address` | 401 |
| TC-003 | Create address with valid data | `POST /api/v1/address` with all required fields | 201, address returned |
| TC-004 | Create address (no auth) | `POST /api/v1/address` | 401 |
| TC-005 | Create address with missing title | `POST /api/v1/address` without title | 422 |
| TC-006 | Create address with missing type | `POST /api/v1/address` without type | 422 |
| TC-007 | Create address with missing default | `POST /api/v1/address` without default | 422 |
| TC-008 | Create address with missing address fields | `POST /api/v1/address` without address.zip | 422 |
| TC-009 | Create address with invalid default value | `POST /api/v1/address` with default=2 | 422 |
| TC-010 | Get address by ID (own) | `GET /api/v1/address/{id}` | 200, address returned |
| TC-011 | Get address by ID (not found) | `GET /api/v1/address/999` | 404 |
| TC-012 | Get address by ID (not owned) | `GET /api/v1/address/{other_user_id}` | 404 |
| TC-013 | Update address with valid data | `PUT /api/v1/address/{id}` | 200, updated address |
| TC-014 | Update address (not owned) | `PUT /api/v1/address/{other_user_id}` | 404 |
| TC-015 | Update address (not found) | `PUT /api/v1/address/999` | 404 |
| TC-016 | Delete address (own) | `DELETE /api/v1/address/{id}` | 200 |
| TC-017 | Delete address (not owned) | `DELETE /api/v1/address/{other_user_id}` | 404 |
| TC-018 | Delete address (not found) | `DELETE /api/v1/address/999` | 404 |

### Security Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-SEC-001 | Another user cannot list your addresses | Scoped by customer_id |
| TC-SEC-002 | Another user cannot see your address | 404 (implicit via scoping) |
| TC-SEC-003 | Another user cannot update your address | 404 (implicit via scoping) |
| TC-SEC-004 | Another user cannot delete your address | 404 (implicit via scoping) |
| TC-SEC-005 | Cannot set customer_id on create | Overwritten by auth ID |
| TC-SEC-006 | Cannot change customer_id on update | Excluded from update |
| TC-SEC-007 | Guest cannot access any endpoint | 401 |

### Edge Case Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-EC-001 | User with no addresses | Empty data array |
| TC-EC-002 | Address with no location set | location = [] |
| TC-EC-003 | Create address with location | location stored correctly |
| TC-EC-004 | Title with special characters | Stored as-is |
| TC-EC-005 | Address with all fields max length | Stored correctly |

### Response Structure Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-RS-001 | Response has message field | String |
| TC-RS-002 | Response has status field | Integer |
| TC-RS-003 | Response has success field | Boolean |
| TC-RS-004 | Response has data field (list) | Array |
| TC-RS-005 | Response has data field (single) | Object |
| TC-RS-006 | Address has all resource fields | id, title, type, default, address, location, customer_id, created_at |
| TC-RS-007 | default is boolean | true/false |

## Manual Test Checklist

- [ ] Verify user can list their own addresses
- [ ] Verify user cannot list another user's addresses
- [ ] Verify user can create an address with all required fields
- [ ] Verify validation errors return 422 with field details
- [ ] Verify created address has correct customer_id (auth user's)
- [ ] Verify user can view their own address by ID
- [ ] Verify user gets 404 for another user's address (not 403)
- [ ] Verify user can update their own address
- [ ] Verify customer_id cannot be changed on update
- [ ] Verify user can delete their own address
- [ ] Verify deleted address is gone (not soft-deleted)
- [ ] Verify unauthenticated requests return 401
