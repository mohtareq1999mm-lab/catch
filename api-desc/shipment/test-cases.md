# Test Coverage — Shipment Module

## Current Coverage

**No tests exist.** There are no test files for the Shipment feature. No `tests/Feature/*Shipment*` files found.

## Recommended Tests

### API Functionality Tests (Success Cases)

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_shipments` | Feature | GET /shipments returns paginated list |
| 2 | `test_list_shipments_pagination` | Feature | Verify pagination structure (current_page, per_page, total) |
| 3 | `test_list_shipments_filter_by_status` | Feature | Filter by status returns matching shipments |
| 4 | `test_list_shipments_filter_by_order_id` | Feature | Filter by order_id returns matching shipments |
| 5 | `test_list_shipments_filter_by_courier` | Feature | Filter by courier returns matching shipments |
| 6 | `test_list_shipments_search_tracking_number` | Feature | Search tracking_number (LIKE) returns matches |
| 7 | `test_list_shipments_date_range` | Feature | Filter by from/to dates returns shipments in range |
| 8 | `test_create_shipment` | Feature | POST /shipments with valid data creates shipment |
| 9 | `test_create_shipment_auto_sets_pending_status` | Feature | Created shipment has status 'pending' |
| 10 | `test_create_shipment_auto_generates_uuid` | Feature | Created shipment has non-null UUID |
| 11 | `test_show_shipment_by_id` | Feature | GET /shipments/{id} returns shipment |
| 12 | `test_show_shipment_by_uuid` | Feature | GET /shipments/uuid/{uuid} returns shipment |
| 13 | `test_update_shipment` | Feature | PUT /shipments/{id} updates fields |
| 14 | `test_update_shipment_courier` | Feature | Update courier field |
| 15 | `test_update_shipment_tracking_number` | Feature | Update tracking number |
| 16 | `test_update_shipment_status_valid_transition` | Feature | PUT /shipments/{id}/status follows state machine |
| 17 | `test_update_shipment_status_sets_shipped_at` | Feature | Status → shipped/picked_up sets shipped_at |
| 18 | `test_update_shipment_status_sets_delivered_at` | Feature | Status → delivered sets delivered_at |

### Validation Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 19 | `test_create_shipment_missing_order_id` | Validation | Missing order_id → 422 |
| 20 | `test_create_shipment_invalid_order_id` | Validation | Non-existent order_id → 422 |
| 21 | `test_create_shipment_duplicate_tracking_number` | Validation | Duplicate tracking_number → 422 |
| 22 | `test_create_shipment_invalid_shipping_cost` | Validation | Negative shipping_cost → 422 |
| 23 | `test_create_shipment_invalid_currency` | Validation | Currency > 3 chars → 422 |
| 24 | `test_create_shipment_notes_too_long` | Validation | Notes > 2000 chars → 422 |
| 25 | `test_update_shipment_validates_tracking_unique_ignore_self` | Validation | Update with own tracking number passes |
| 26 | `test_update_status_invalid_enum_value` | Validation | Invalid status string → 422 |
| 27 | `test_update_status_invalid_transition` | Validation | pending → delivered returns 422 |

### Authorization Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 28 | `test_guest_cannot_list_shipments` | Authorization | No auth token → 401 |
| 29 | `test_guest_cannot_create_shipment` | Authorization | No auth token → 401 |
| 30 | `test_guest_cannot_show_shipment` | Authorization | No auth token → 401 |
| 31 | `test_guest_cannot_update_shipment` | Authorization | No auth token → 401 |
| 32 | `test_guest_cannot_update_status` | Authorization | No auth token → 401 |
| 33 | `test_user_without_view_permission_cannot_list` | Authorization | No `view-shipments` → 403 |
| 34 | `test_user_without_create_permission_cannot_store` | Authorization | No `create-shipment` → 403 |
| 35 | `test_user_without_update_permission_cannot_update` | Authorization | No `update-shipment` → 403 |

### Edge Case Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 36 | `test_show_nonexistent_shipment` | Edge Case | GET /shipments/99999 → 404 |
| 37 | `test_show_nonexistent_uuid` | Edge Case | GET /shipments/uuid/nonexistent → 404 |
| 38 | `test_update_nonexistent_shipment` | Edge Case | PUT /shipments/99999 → 404 |
| 39 | `test_update_status_nonexistent_shipment` | Edge Case | PUT /shipments/99999/status → 404 |
| 40 | `test_empty_shipment_list` | Edge Case | No shipments → 200, empty data array |
| 41 | `test_full_state_machine_walkthrough` | Edge Case | pending → label_created → picked_up → in_transit → out_for_delivery → delivered |
| 42 | `test_cancel_shipment` | Edge Case | pending → cancelled (terminal) |
| 43 | `test_failed_delivery_then_redeliver` | Edge Case | out_for_delivery → failed_delivery → out_for_delivery → delivered |
| 44 | `test_shipment_cannot_transition_from_terminal_state` | Edge Case | delivered → in_transit → 422 |
| 45 | `test_concurrent_status_update` | Edge Case | Two simultaneous status updates (lockForUpdate) |
| 46 | `test_list_shipments_exceeds_max_limit` | Edge Case | limit=200 → capped to 100 |

### JSON Structure Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 47 | `test_shipment_list_response_structure` | Feature | Verify JSON structure has all fields |
| 48 | `test_shipment_single_response_structure` | Feature | Verify single shipment JSON structure |

## Coverage Summary

| Category | Count |
|----------|-------|
| Feature Tests (Success) | ~18 |
| Validation Tests | ~9 |
| Authorization Tests | ~8 |
| Edge Case Tests | ~11 |
| JSON Structure Tests | ~2 |
| **Total (estimate)** | ~48 |

## Missing Layers

- [ ] No test files exist at all — entire test suite needs to be created
- [ ] No ShipmentResource — raw model data is returned (cannot test resource transformation)
- [ ] No 404 handling tests — current implementation may throw uncaught ModelNotFoundException
- [ ] No concurrent update tests — validate pessimistic locking works
- [ ] No soft delete tests — feature not implemented
- [ ] No observer tests — no observer exists
- [ ] No translation tests — no translations exist
