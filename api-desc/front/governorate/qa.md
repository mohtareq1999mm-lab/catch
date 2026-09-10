# Governorate Module — QA Test Cases (Public API)

## Test Files

None yet. Tests need to be written.

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List active governorates | GET /general/governorates | 200, active only |
| F2 | List empty | No active governorates | 200, empty array |
| F3 | List ordering | id DESC | Correct sort |

---

## Response Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | List top-level | status, message, success, data | Correct keys |
| S2 | Governorate object | id, country_id, name, status, is_fast_shipping_enabled, created_at | Correct types |
| S3 | Name translation | name field | String in current locale |

---

## Integration Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| I1 | Checkout with governorate_id | POST /checkout { governorate_id: 1 } | 200, order created |
| I2 | Checkout with invalid governorate_id | POST /checkout { governorate_id: 999 } | 422 validation error |
