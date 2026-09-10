# Address Module — Bug Report

## BUG-ADDR-001: Store success message says "could not create"

**Severity:** Low
**Status:** Open
**File:** `packages/marvel/src/Http/Controllers/AddressController.php:95`

**Issue:** `AddressController@store` returns `COULD_NOT_CREATE_THE_RESOURCE` as the message on a successful 201 response. The resource was actually created successfully, but the message suggests failure.

**Current:**
```php
return $this->apiResponse(COULD_NOT_CREATE_THE_RESOURCE, 201, true, AddressResource::make($address));
```

**Expected:**
```php
return $this->apiResponse(ADDRESS_CREATED, 201, true, AddressResource::make($address));
```

---

## BUG-ADDR-002: PUT requires all fields (no partial update)

**Severity:** Medium
**Status:** Open
**File:** `packages/marvel/src/Http/Requests/AddressRequest.php:29-42`

**Issue:** `AddressRequest` uses `required` for all fields without distinguishing between store and update contexts. This means PUT requests must send all fields, even when only updating a subset.

**Current:**
```php
'title' => ['required', 'string', 'max:255'],
'type' => ['required', 'string', 'max:255'],
```

**Expected:**
```php
// Add context-aware validation:
// POST: required
// PUT/PATCH: sometimes
```

---

## BUG-ADDR-003: No pagination on list endpoint

**Severity:** Low
**Status:** Open
**File:** `packages/marvel/src/Http/Controllers/AddressController.php:62`

**Issue:** `index()` returns all addresses via `->get()` with no pagination. For users with many addresses, this could cause slow responses.

**Current:**
```php
$addresses = $this->repository->where('customer_id', $request->user()->id)->get();
```

---

## BUG-ADDR-004: Missing exception case for customer_id in store

**Severity:** Low
**Status:** Open
**File:** `packages/marvel/src/Http/Controllers/AddressController.php:92-98`

**Issue:** The try/catch in `store()` catches `MarvelException` but the operations inside (`merge`, `create`) are not likely to throw `MarvelException`. The catch block is dead code.

---

## BUG-ADDR-005: No test coverage

**Severity:** High
**Status:** Open

**Issue:** No test files exist for the Address feature. All 5 CRUD endpoints, validation rules, authorization, and edge cases are untested.

**Expected:** Create `tests/Feature/AddressCrudTest.php` with comprehensive test coverage (48+ recommended tests).
