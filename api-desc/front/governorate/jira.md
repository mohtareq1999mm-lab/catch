# Governorate Module — Backend Jira Tasks

---

## Task 1: Add Caching to Governorate Endpoint

**Priority:** Low
**Component:** GovernorateRepository
**Effort:** Small
**Files:**
- `packages/marvel/src/Database/Repositories/GovernorateRepository.php`

**Description:** Governorate list rarely changes. Add `Cache::remember()` with 1-hour TTL.

**Acceptance Criteria:**
- [ ] `allActive()` cached
- [ ] Cache cleared on governorate create/update/delete (admin)

---

## Task 2: Write Feature Tests

**Priority:** Medium
**Component:** Governorate
**Effort:** Small
**Files:**
- `tests/Feature/GovernorateTest.php`

**Description:** Add feature tests for the public governorates endpoint.

**Acceptance Criteria:**
- [ ] Active governorates listed
- [ ] Inactive governorates excluded
- [ ] Response structure validated
