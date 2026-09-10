# Attribute Module — Backend Jira Tasks (CRUD)

## ATT-BE-001: Wrap `AttributeRepository::updateAttribute()` in DB transaction

| Field | Value |
|-------|-------|
| Priority | High |
| Component | Data Integrity |
| Story Points | 1 |
| Description | `updateAttribute()` does name update + value sync without a transaction. Wrap in `DB::beginTransaction()/commit()/rollBack()`. |
| Acceptance Criteria | - All changes committed atomically - On failure, all changes rolled back |

## ATT-BE-002: Fix `AttributeRequest` unique validation ignore on update

| Field | Value |
|-------|-------|
| Priority | High |
| Component | Validation |
| Story Points | 2 |
| Description | `$this->route('attribute')` returns null during update since controller uses `$request->id = $id` instead of route model binding. Unique check doesn't ignore current ID. |
| Acceptance Criteria | - Create with taken name → 422 - Update without changing name → 200 - Update to taken name → 422 |

## ATT-BE-003: Make `updateAttribute()` private (done)

| Field | Value |
|-------|-------|
| Priority | Low |
| Component | Code Quality |
| Story Points | 1 |
| Description | `updateAttribute()` was public but only called from `update()`. |
| Status | ✅ Done |

## ATT-BE-004: Split `AttributeRequest` into create and update form requests

| Field | Value |
|-------|-------|
| Priority | Medium |
| Component | Validation |
| Story Points | 2 |
| Description | `name.en`/`name.ar` always required even on update. Split into `AttributeCreateRequest` (required) and `AttributeUpdateRequest` (sometimes). |
| Acceptance Criteria | - Create requires both name locales - Update allows partial name - Update ignores current ID for unique check |

## ATT-BE-005: Add missing test coverage

| Field | Value |
|-------|-------|
| Priority | Medium |
| Component | Testing |
| Story Points | 3 |
| Description | Add tests for name validation (min/max/unique), partial updates, empty values, resource name format (list vs show), and slug uniqueness per attribute. |
| Acceptance Criteria | See `test-cases.md` |
