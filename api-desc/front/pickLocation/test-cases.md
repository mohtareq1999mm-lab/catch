# Test Coverage — Pickup Location Module (Public API)

---

## Existing Tests

**Files:** `tests/Feature/PickupLocationTest.php`, `tests/Feature/PickupLocationPricingIntegrationTest.php`

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_active_pickup_locations` | Feature | Active only returned |
| 2 | `test_list_inactive_excluded` | Feature | status=false excluded |
| 3 | `test_list_search_by_name` | Feature | ?search filters store_name |
| 4 | `test_list_ordering` | Feature | display_order then id |
| 5 | `test_list_pagination` | Feature | ?limit and ?page |
| 6 | `test_list_empty` | Feature | No locations → [] |
| 7 | `test_show_active_location` | Feature | GET /{id} → 200 |
| 8 | `test_show_inactive_returns_404` | Feature | Inactive → 404 |
| 9 | `test_show_nonexistent_returns_404` | Feature | Invalid ID → 404 |
| 10 | `test_locations_after_create` | Integration | Create via admin, verify listed |
| 11 | `test_locations_after_update` | Integration | Update via admin, verify reflected |
| 12 | `test_locations_after_soft_delete` | Integration | Soft delete, verify excluded |

---

## Recommended Additional Tests

### Response Structure Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_list_response_structure` | Feature | Top-level keys |
| 2 | `test_location_object_structure` | Feature | All resource fields present |
| 3 | `test_working_hours_structure` | Feature | JSON object or null |

### Channel Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_pickup_locations_channel_header` | Feature | X-Channel header (no channel filter on model) |

### Regression Tests

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `test_soft_deleted_excluded` | Feature | deleted_at set → excluded |
| 2 | `test_display_order_negative` | Feature | Negative order value handled |
| 3 | `test_large_limit_pagination` | Feature | ?limit=1000 → capped or paginated |
