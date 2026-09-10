# Pickup Location Module — Backend Jira Tasks

---

## Task 1: Add Cache to Pickup Location Endpoints

**Priority:** Low
**Component:** PickupLocationService
**Effort:** Small
**Files:**
- `app/Services/General/PickupLocationService.php`

**Description:** Add `Cache::remember()` with 600s TTL for both listing and show endpoints.

**Acceptance Criteria:**
- [ ] `getPickupLocations()` cached with key scoped to search/limit/page params
- [ ] `getPickupLocationById()` cached per ID
- [ ] Cache cleared on location create/update/delete (admin)

---

## Task 2: Standardize `working_hours` JSON Structure

**Priority:** Low
**Component:** PickupLocation model
**Effort:** Small
**Files:**
- `packages/marvel/src/Database/Models/PickupLocation.php`
- Admin validation request

**Description:** Define a consistent schema for working_hours (e.g., `{"day": "HH:MM-HH:MM"}`) and validate on input.

**Acceptance Criteria:**
- [ ] working_hours follows documented schema
- [ ] Admin validation ensures consistent format on save

---

## Task 3: Improve `show()` Error Handling

**Priority:** Low
**Component:** PickupLocationController
**Effort:** Trivial
**Files:**
- `app/Http/Controllers/Api/General/PickupLocationController.php`

**Description:** Catch specific `ModelNotFoundException` for 404 and let other exceptions bubble up as 500.

**Acceptance Criteria:**
- [ ] Not found returns 404
- [ ] Unexpected errors return 500 with generic message

---

## Task 4: Add Filtering Parameters to Listing

**Priority:** Low
**Component:** PickupLocationService
**Effort:** Small
**Files:**
- `app/Services/General/PickupLocationService.php`

**Description:** Add support for address-based filters if the DB schema supports them.

**Acceptance Criteria:**
- [ ] New query parameters filter the listing
- [ ] Backward compatible (existing params unchanged)
