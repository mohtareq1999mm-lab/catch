# Attribute Module — Bug Report

## BUG-ATT-001: `updateAttribute()` is public (should be private)

| Field | Value |
|-------|-------|
| Severity | Low |
| Component | Encapsulation |
| File | `AttributeController.php:208` |
| Description | `updateAttribute()` is declared `public` but only called internally from `update()`. |
| Fix | Changed to `private`. |

## BUG-ATT-002: `updateAttribute()` missing DB transaction

| Field | Value |
|-------|-------|
| Severity | Medium |
| Component | Data Integrity |
| File | `AttributeRepository.php:70-105` |
| Description | `updateAttribute()` performs name update + value sync without `DB::beginTransaction()`. Partial failures can leave inconsistent data. |
| Impact | Partial updates — values may be created/deleted without attribute update |
| Fix | Wrap in DB transaction (same pattern as `storeAttribute()`). |

## BUG-ATT-003: `AttributeRequest` unique validation may not ignore current ID on update

| Field | Value |
|-------|-------|
| Severity | Medium |
| Component | Validation |
| File | `AttributeRequest.php:32` |
| Description | `$this->route('attribute')` may return `null` during update because the controller sets `$request->id = $id` manually instead of using route model binding. This causes false "already taken" errors. |
| Fix | Use `$this->route('attribute') ?? $this->id` or inject the model. |

## BUG-ATT-004: `AttributeRequest` requires all locale fields on update

| Field | Value |
|-------|-------|
| Severity | Low |
| Component | Validation |
| File | `AttributeRequest.php:31-33` |
| Description | Both `name.en` and `name.ar` are always `required` even on PUT update. Prevents partial updates. |
| Impact | Forces clients to always send both languages |
| Fix | Split into separate create/update requests (brand/category pattern). |
