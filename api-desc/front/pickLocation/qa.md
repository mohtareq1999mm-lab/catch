# Pickup Location Module — QA Test Cases (Public API)

## Test Files

`tests/Feature/PickupLocationTest.php` — Good coverage of public endpoints
`tests/Feature/PickupLocationPricingIntegrationTest.php` — Pricing integration tests

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List active locations | GET /general/pickup-locations | 200, active only |
| F2 | List with search | ?search=Downtown | Filtered by store_name |
| F3 | List empty | No active locations | 200, empty array |
| F4 | List pagination | ?limit=2&page=1 | Paginated response |
| F5 | List ordering | display_order asc, then id asc | Correct sort |
| F6 | Show location | GET /general/pickup-locations/1 | 200 |
| F7 | Show inactive | status=false location | 404 |
| F8 | Show non-existent | Invalid ID | 404 |

---

## Response Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | List top-level | status, message, success, data | Correct keys |
| S2 | Location object | id, store_name, address, phone, email, lat, lng, working_hours, status, display_order | Correct types |
| S3 | working_hours | JSON object | Array or object |

---

## Validation / Edge Case Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Soft-deleted location | deleted_at set | Not in listing, 404 on show |
| V2 | Negative limit | ?limit=-1 | Falls back to default |
| V3 | Search no match | ?search=zzzzz | 200, empty array |

---

## Regression Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| R1 | Inactive excluded from list | status=false | Not returned |
| R2 | Display order respected | Multiple locations | Correct sort |
| R3 | working_hours structure | Various formats | Consistent in response |
