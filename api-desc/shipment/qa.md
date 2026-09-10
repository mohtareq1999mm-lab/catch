# Shipment Module — QA Test Cases

## Test Files

No test files exist yet for the Shipment module. All tests below are recommended.

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List shipments | GET /shipments returns paginated list | 200, pagination structure |
| F2 | Filter by status | GET /shipments?status=in_transit | Filtered results |
| F3 | Filter by order_id | GET /shipments?order_id=101 | Matching results |
| F4 | Search tracking number | GET /shipments?tracking_number=TRK | LIKE search results |
| F5 | Date range filter | GET /shipments?from=2026-07-01&to=2026-07-28 | Results in range |
| F6 | Create shipment | POST /shipments with valid data | 201, shipment returned |
| F7 | Show by ID | GET /shipments/{id} | 200, shipment data |
| F8 | Show by UUID | GET /shipments/uuid/{uuid} | 200, shipment data |
| F9 | Update shipment | PUT /shipments/{id} | 200, updated fields |
| F10 | Update status valid transition | PUT /shipments/{id}/status | 200, status changed |
| F11 | Full state machine walkthrough | Sequential status updates | Each transition succeeds |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Create without order_id | Missing required field | 422 |
| V2 | Create with invalid order_id | Non-existent order | 422 |
| V3 | Create with negative cost | shipping_cost < 0 | 422 |
| V4 | Create with duplicate tracking | Existing tracking number | 422 |
| V5 | Create with notes too long | > 2000 characters | 422 |
| V6 | Update with invalid status | Not a valid enum value | 422 |
| V7 | Update status with invalid transition | e.g., pending → delivered | 422 |
| V8 | Update status with non-enum value | Random string | 422 |

---

## Authorization Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| A1 | Guest lists shipments | No token | 401 |
| A2 | Guest creates shipment | No token | 401 |
| A3 | Guest shows shipment | No token | 401 |
| A4 | Guest updates shipment | No token | 401 |
| A5 | Guest updates status | No token | 401 |
| A6 | No view-shipments permission | List, show | 403 |
| A7 | No create-shipment permission | Create | 403 |
| A8 | No update-shipment permission | Update, status update | 403 |

---

## Edge Case Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| E1 | Show non-existent ID | GET /shipments/99999 | 404 |
| E2 | Show non-existent UUID | GET /shipments/uuid/nonexistent | 404 |
| E3 | Update non-existent | PUT /shipments/99999 | 404 |
| E4 | Update status non-existent | PUT /shipments/99999/status | 404 |
| E5 | Empty list | No shipments in DB | 200, empty data[] |
| E6 | Full state machine walkthrough | pending → ... → delivered | All transitions succeed |
| E7 | Cancel from valid states | pending, label_created, picked_up → cancelled | 200 |
| E8 | Cannot transition from delivered | delivered → anything | 422 |
| E9 | Cannot transition from returned | returned → anything | 422 |
| E10 | Cannot transition from cancelled | cancelled → anything | 422 |
| E11 | Limit exceeds max | limit=200 | Capped to 100 |
| E12 | Concurrent status updates | Two simultaneous requests | One succeeds, one may fail |

---

## State Machine Transition Tests

| # | Transition | Expected |
|---|-----------|----------|
| T1 | pending → label_created | ✓ OK |
| T2 | pending → cancelled | ✓ OK |
| T3 | pending → delivered | ✗ 422 |
| T4 | label_created → picked_up | ✓ OK |
| T5 | label_created → cancelled | ✓ OK |
| T6 | picked_up → in_transit | ✓ OK |
| T7 | picked_up → cancelled | ✓ OK |
| T8 | in_transit → out_for_delivery | ✓ OK |
| T9 | in_transit → delayed | ✓ OK |
| T10 | out_for_delivery → delivered | ✓ OK |
| T11 | out_for_delivery → failed_delivery | ✓ OK |
| T12 | delivered → anything | ✗ 422 |
| T13 | failed_delivery → out_for_delivery | ✓ OK |
| T14 | failed_delivery → returned | ✓ OK |
| T15 | returned → anything | ✗ 422 |
| T16 | delayed → in_transit | ✓ OK |
| T17 | delayed → out_for_delivery | ✓ OK |

## Missing Coverage

- [ ] No automated tests exist at all
- [ ] Race condition: concurrent status updates (lockForUpdate integration test)
- [ ] Race condition: update while status transition in progress
- [ ] Large filter sets (many shipments, pagination edge cases)
- [ ] UUID collision detection (theoretical only)
- [ ] Invalid UUID format in URL
