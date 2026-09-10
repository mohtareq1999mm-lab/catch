# Address Module — Backend JIRA Tasks

## B-001: Add pagination to address list

**Priority:** Low
**Story Points:** 1
**Labels:** refactoring, scalability

**Description:** `AddressController@index` returns all addresses in a flat collection with no pagination. For users with many addresses, this could become a performance issue. Add pagination support with `?limit=` and `?page=` query parameters.

**Acceptance Criteria:**
- `GET /api/v1/address?limit=10&page=1` returns paginated results
- Default limit: 20
- Max limit: 100
- Backward compatible (current behavior returns all if no pagination params)

---

## B-002: Add `sometimes` to AddressRequest rules for updates

**Priority:** Medium
**Story Points:** 1
**Labels:** bug, validation

**Description:** `AddressRequest` applies the same `required` rules for both store and update operations. For PUT requests, all fields are required even if the client only wants to update a subset. Change validation to use `sometimes` for update context.

**Acceptance Criteria:**
- `POST /api/v1/address` still requires all fields
- `PUT /api/v1/address/{id}` allows partial updates (only provided fields are validated)
- Backward compatible

---

## B-003: Change response status message for store

**Priority:** Low
**Story Points:** 1
**Labels:** bug, messaging

**Description:** `AddressController@store` returns message `COULD_NOT_CREATE_THE_RESOURCE` on success (201). This is misleading — the resource was created successfully. Should use a dedicated success constant like `ADDRESS_CREATED`.

**Current:**
```php
return $this->apiResponse(COULD_NOT_CREATE_THE_RESOURCE, 201, true, ...);
```

**Expected:**
```php
return $this->apiResponse(ADDRESS_CREATED, 201, true, ...);
```

---

## B-004: Add created_at to address list response

**Priority:** Low
**Story Points:** 1
**Labels:** consistency, resource

**Description:** Confirm that `created_at` is included in the `AddressResource` for both list and single responses. The resource already includes it, but test to verify.

---

## B-005: Add address test suite

**Priority:** High
**Story Points:** 5
**Labels:** testing, coverage

**Description:** No existing tests for the Address feature. Create a feature test file covering all CRUD operations, validation, authorization, and edge cases.

**Acceptance Criteria:**
- Tests for all 5 CRUD endpoints
- Validation tests (all required fields)
- Authorization tests (guest, own vs other's addresses)
- Response structure tests
- Edge case tests (no addresses, missing location, max length)

---

## B-006: Consider adding SoftDeletes to Address model

**Priority:** Low
**Story Points:** 2
**Labels:** enhancement, safety

**Description:** Addresses are currently hard-deleted. If users accidentally delete an address, there is no recovery option. Consider adding `SoftDeletes` trait for safety.

---

## Epic Summary

| Task | Points | Priority |
|------|--------|----------|
| B-001: Add pagination to address list | 1 | Low |
| B-002: Add `sometimes` to AddressRequest rules | 1 | Medium |
| B-003: Fix store response message | 1 | Low |
| B-004: Verify created_at in list response | 1 | Low |
| B-005: Add address test suite | 5 | High |
| B-006: Consider SoftDeletes | 2 | Low |
| **Total** | **11** | |
