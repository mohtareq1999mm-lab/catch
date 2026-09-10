# Settings Module — Backend Jira Tasks

---

## Task 1: Add Caching to Public Settings Endpoint

**Priority:** Medium
**Component:** SettingService
**Effort:** Small
**Files:**
- `app/Services/General/SettingService.php`

**Description:** `SettingService::getSetting()` calls `Settings::first()` on every request with no caching. Add `Cache::remember()` with 600s TTL and a channel-scoped key.

**Acceptance Criteria:**
- [ ] `getSetting()` cached with key pattern `public_settings_{locale}`
- [ ] Cache cleared when settings are updated via admin endpoint
- [ ] Fallback returns fresh data on cache miss

---

## Task 2: Write Tests for Public GET /general/settings

**Priority:** High
**Component:** Tests
**Effort:** Small
**Files:**
- `tests/Feature/Settings/` (new file or add to existing)

**Description:** Add feature tests for the public settings endpoint. Existing Settings tests only cover admin CRUD.

**Acceptance Criteria:**
- [ ] Test returns 200 with correct structure
- [ ] Test translations return correct locale
- [ ] Test media URLs present when logo/favicon exist
- [ ] Test `options` is object (not null) when empty

---

## Task 3: Handle Null `options` Gracefully in Resource

**Priority:** Low
**Component:** SettingResource
**Effort:** Trivial
**Files:**
- `packages/marvel/src/Http/Resources/SettingResource.php`

**Description:** Use `$this->options ?? []` or `(object) []` to ensure `options` is always an object in the response. `minimumOrderAmount` already uses `?? 0` fallback.

**Acceptance Criteria:**
- [ ] `options` returns `{}` when DB value is null
- [ ] `options` returns the JSON object when populated
