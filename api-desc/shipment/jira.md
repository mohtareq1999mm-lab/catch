# Shipment Module — Jira Tasks

---

## Task 1: Create ShipmentResource for Consistent Response Structure

**Priority:** High
**Component:** Shipment API
**Effort:** Medium
**Files:**
- `app/Http/Resources/Shipment/ShipmentResource.php` (new)
- `app/Http/Controllers/Api/ShipmentController.php`

**Description:** The Shipment controller currently returns raw Eloquent model data directly from the service layer. A dedicated `ShipmentResource` is needed to:
- Select which fields are exposed
- Format dates consistently
- Handle conditional relationship loading (`whenLoaded`)
- Provide consistent JSON structure matching other modules

**Acceptance Criteria:**
- [ ] `ShipmentResource` created with explicit field mapping
- [ ] All controller methods use `ShipmentResource::make()` or `ShipmentResource::collection()`
- [ ] Response JSON structure matches other modules (e.g., BrandResource)
- [ ] Order relationship uses `whenLoaded()` for conditional inclusion
- [ ] Existing response contracts are preserved (no breaking changes)

---

## Task 2: Add Constants and Translations for Response Messages

**Priority:** High
**Component:** Shipment API
**Effort:** Small
**Files:**
- `packages/marvel/config/constants.php`
- `resources/lang/en/message.php`
- `resources/lang/ar/message.php`
- `app/Http/Controllers/Api/ShipmentController.php`

**Description:** The controller currently uses hardcoded English strings:
```php
'Shipment created successfully'
'Shipment status updated'
'Shipment updated successfully'
```
These should be replaced with constants and translation keys following the pattern used by other modules (e.g., `BRAND_CREATED_SUCCESSFULLY`).

**Acceptance Criteria:**
- [ ] Constants defined in `packages/marvel/config/constants.php`:
  - `SHIPMENT_CREATED_SUCCESSFULLY`
  - `SHIPMENT_UPDATED_SUCCESSFULLY`
  - `SHIPMENT_STATUS_UPDATED`
- [ ] Translation keys added to both `resources/lang/en/message.php` and `resources/lang/ar/message.php`
- [ ] Controller updated to use `__(SHIPMENT_CREATED_SUCCESSFULLY)` pattern

---

## Task 3: Add Exception Handling for ModelNotFoundException

**Priority:** High
**Component:** Shipment Controller
**Effort:** Small
**Files:**
- `app/Http/Controllers/Api/ShipmentController.php`
- `app/Exceptions/Handler.php` (or controller-level handling)

**Description:** The `show()`, `showByUuid()`, `update()`, and `updateStatus()` methods call `findOrFail()` / `firstOrFail()` which throw `ModelNotFoundException` when the shipment is not found. These exceptions are NOT caught in the controller, resulting in a Laravel HTML exception page instead of a proper JSON 404 response.

**Acceptance Criteria:**
- [ ] `show()` catches `ModelNotFoundException` and returns `404` JSON response
- [ ] `showByUuid()` catches `ModelNotFoundException` and returns `404` JSON response
- [ ] `update()` catches `ModelNotFoundException` and returns `404` JSON response
- [ ] `updateStatus()` catches `ModelNotFoundException` and returns `404` JSON response
- [ ] Response format: `{ status: 404, message: "Not found", success: false }`

---

## Task 4: Add Rate Limiting to Shipment Endpoints

**Priority:** Medium
**Component:** Routes
**Effort:** Small
**Files:**
- `app/Providers/RouteServiceProvider.php`
- `routes/api.php`

**Description:** The shipment endpoints have no rate limiting. High-frequency polling (e.g., checking shipment status) could overwhelm the server. Rate limiting should be configured, similar to the Cart module's 20 req/min limit.

**Acceptance Criteria:**
- [ ] `RateLimiter::for('shipment')` registered in `RouteServiceProvider`
- [ ] Rate limit applied to shipment route group (e.g., 30 req/min per user)
- [ ] 429 response returned when limit exceeded

---

## Task 5: Add Comprehensive Test Suite

**Priority:** High
**Component:** Tests
**Effort:** Large
**Files:**
- `tests/Feature/Shipment/ShipmentApiTest.php` (new)
- `tests/Feature/Shipment/ShipmentStateMachineTest.php` (new)
- `tests/Feature/Shipment/ShipmentValidationTest.php` (new)

**Description:** No tests exist for the Shipment module. A comprehensive test suite is needed covering:
- Full CRUD operations
- All state machine transitions (positive and negative)
- Authorization and permission checks
- Validation rules
- Edge cases (concurrent updates, empty lists, non-existent records)

**Acceptance Criteria:**
- [ ] `ShipmentApiTest`: CRUD tests, filtering, pagination, search
- [ ] `ShipmentStateMachineTest`: All valid/invalid transitions verified
- [ ] `ShipmentValidationTest`: All validation rules tested
- [ ] Auth tests: 401 without token, 403 without permission
- [ ] Concurrent update test with pessimistic locking verification
- [ ] All tests pass with 0 failures

---

## Task 6: Add Observer for Activity Logging

**Priority:** Low
**Component:** Shipment Observer
**Effort:** Small
**Files:**
- `app/Observers/ShipmentObserver.php` (new)
- `app/Providers/EventServiceProvider.php` (register observer)

**Description:** Other modules (Brand, Category, etc.) have observers that log activities via `LogActivityJob`. The Shipment module lacks this — status changes and CRUD operations are not logged.

**Acceptance Criteria:**
- [ ] `ShipmentObserver` created with `created`, `updated`, status-change events
- [ ] Observer registered in `EventServiceProvider`
- [ ] Activity logged on: create, update, status transition
- [ ] Log uses existing `LogActivityJob` pattern

---

## Task 7: Review Route Ordering for `uuid/{uuid}` Pattern

**Priority:** Low
**Component:** Routes
**Effort:** Trivial
**Files:**
- `routes/api.php`

**Description:** The `GET uuid/{uuid}` route is defined BEFORE `GET {id}` in the route group. This is correct — if `{id}` came first, `GET uuid/xxx` would be captured by `GET {id}` with `id = "uuid"`. Add a defensive comment explaining the ordering requirement.

**Acceptance Criteria:**
- [ ] Comment added explaining route ordering
- [ ] Test verifies both endpoints resolve correctly

---

## Task 8: Consider Adding DELETE_SHIPMENT Permission and Endpoint

**Priority:** Low
**Component:** Shipment Controller
**Effort:** Medium
**Files:**
- `packages/marvel/src/Enums/Permission.php`
- `app/Http/Controllers/Api/ShipmentController.php`
- `routes/api.php`

**Description:** The Shipment module has no delete endpoint and no `DELETE_SHIPMENT` permission. Consider whether hard-delete (current table has no soft deletes) or soft-delete is needed for business requirements.

**Acceptance Criteria:**
- [ ] Business decision: hard-delete vs soft-delete vs cancel-only
- [ ] If delete needed: `DELETE_SHIPMENT` permission added to Permission enum
- [ ] DELETE endpoint added to controller and routes
- [ ] Test coverage for delete operation

---

## Task 9: Create Shipment Seeder for Development

**Priority:** Low
**Component:** Database
**Effort:** Small
**Files:**
- `database/seeders/ShipmentSeeder.php` (new)
- `database/seeders/DatabaseSeeder.php` (call)

**Description:** No seed data exists for shipments. Development and testing would benefit from pre-seeded shipment records with various statuses.

**Acceptance Criteria:**
- [ ] ShipmentSeeder creates shipments in various statuses
- [ ] Links to existing orders from OrderSeeder
- [ ] Diverse couriers and tracking numbers
- [ ] Date ranges for shipped_at/delivered_at
- [ ] Idempotent: can be re-run safely
