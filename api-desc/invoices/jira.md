# Dashboard Invoices — Jira Tasks

---

## Task 1: Add `whereUuid` to `verify/{uuid}` and `uuid/{uuid}` in Dashboard Group

**Priority:** Medium
**Component:** Routes
**Effort:** Trivial
**Files:**
- `../../packages/marvel/src/Rest/Routes.php` (or snippet file under review)

**Description:** Reviewed snippet omits `whereUuid` on `verify/{uuid}` and `uuid/{uuid}`. Malformed UUIDs then reach the controller (`where('uuid', $uuid)->firstOrFail()`), producing a 500 or ambiguous 404 instead of a clean routing 404.

**Status:** ⬜ Todo

**Acceptance Criteria:**
- [ ] `Route::get('verify/{uuid}', ...)->whereUuid('uuid')`
- [ ] `Route::get('uuid/{uuid}', ...)->whereUuid('uuid')`
- [ ] Malformed UUID returns framework 404 (not 500)
- [ ] Existing `InvoiceVerifyEndpointTest` still passes

---

## Task 2: Expose `GET {uuid}/view` in Admin Group (Parity with Download)

**Priority:** Medium
**Component:** Controller + Routes
**Effort:** Small
**Files:**
- `../../packages/marvel/src/Rest/Routes.php`
- `../../app/Http/Controllers/Api/InvoiceController.php` (already has `view()`)

**Description:** `InvoiceController::view()` exists (`:273`, streams inline) but production admin group only routes `download`. Dashboard needs symmetric preview. Add `view` with same `throttle:30,1` and inline owner/permission check.

**Status:** ✅ Controller ready — route missing in production

**Acceptance Criteria:**
- [ ] `Route::get('{uuid}/view', [InvoiceController::class, 'view'])->whereUuid('uuid')->middleware('throttle:30,1')` added inside admin `invoices` prefix
- [ ] Non-owner without `view-invoice-download` → 404
- [ ] Owner → 200 inline PDF
- [ ] Verify `verify_count` unchanged on view (only download records)

---

## Task 3: Extract `cancel` Inline Validation to FormRequest

**Priority:** Low
**Component:** Validation
**Effort:** Small
**Files:**
- `../../app/Http/Controllers/Api/InvoiceController.php` (`cancel:182`)
- `app/Http/Requests/Invoice/CancelInvoiceRequest.php` (new)

**Description:** `cancel()` uses `$request->validate(['reason'=>...])` inline while `correct` and `debit-note` use dedicated FormRequests. Violates separation of concerns; inconsistent error shape.

**Status:** ⬜ Todo

**Acceptance Criteria:**
- [ ] `CancelInvoiceRequest` with `reason => required|string|max:500`
- [ ] `InvoiceController::cancel(CancelInvoiceRequest $request, int $id)`
- [ ] 422 on missing/long reason via Request, not inline
- [ ] Existing cancel tests pass

---

## Task 4: Document Route Order if `uuid/{uuid}` Coexists with `/{id}`

**Priority:** Low
**Component:** Routes / Docs
**Effort:** Trivial
**Files:**
- `../../packages/marvel/src/Rest/Routes.php`
- `backend.md`

**Description:** Numeric `/{id}` vs UUID `/{uuid}` are disambiguated by `whereNumber` / `whereUuid`, but adding plain `/{uuid}` without prefix could collide if constraints ever omitted. Prefix `uuid/` already avoids collision; document guard.

**Status:** ✅ Mitigated by `uuid/` prefix + constraints

**Acceptance Criteria:**
- [ ] Comment in routes explains `uuid/` prefix disambiguation
- [ ] Test verifies `GET /uuid/{valid-uuid}` hits `showByUuid`, not `show`

---

## Task 5: Add Throttle Differentiation + Retry-After UX

**Priority:** Low
**Component:** Frontend + Backend
**Effort:** Small
**Files:**
- `../../app/Http/Controllers/Api/InvoiceController.php`
- Dashboard frontend invoice list/detail components

**Description:** `download`/`view` share 30,1; `verify` has 5,1. Frontend currently shows generic error on 429. Differentiate and surface `Retry-After`.

**Status:** ⬜ Todo

**Acceptance Criteria:**
- [ ] Backend returns `Retry-After` header (Laravel throttle does by default — verify)
- [ ] Frontend maps `429` to toast with retry countdown per endpoint
- [ ] Throttle tests `T1-T3` pass

---

## Task 6: Expand Test Coverage for Dashboard Group

**Priority:** High
**Component:** Tests
**Effort:** Medium
**Files:**
- `tests/Feature/Invoice/InvoiceDashboardRoutesTest.php` (new)

**Description:** Existing invoice tests cover service/mutation but not the consolidated 10-route group matrix (auth×permission×throttle×constraint). Add missing dashboard-specific cases.

**Status:** Partial — existing tests cover many flows

**Acceptance Criteria:**
- [ ] Guest cannot access any of 10 routes (401)
- [ ] Permission matrix: each `permission:*` enforced per route (403 when missing)
- [ ] PDF routes: owner vs permission vs guest vs unknown UUID vs not-yet-generated (404 variants)
- [ ] `view` inline vs `download` attachment disposition verified
- [ ] Throttle 429 cases for `download`/`view`/`verify`
- [ ] `whereNumber`/`whereUuid` routing 404 cases
