# Architecture Verification Report

**Date:** 2026-07-25
**Scope:** Full-source-code audit across all 14 features + 7 critical bug fixes
**Methodology:** Every claim verified against actual source code (controllers, services, models, routes, tests, migrations). No assumptions.

---

## Actual Feature Status

| Feature | Reported Status | Actual Status | Completion | Evidence |
|---------|----------------|---------------|-----------|----------|
| **Role & Permission** | Production Ready (Rev 2) | **Needs Rework** | 90% | 2 high-severity security issues found |
| **Categories** | Production Ready (Rev 2) | **Partially Complete** | 98% | 1 test failure (locale threading bug) |
| **Brands** | Production Ready (Rev 1) | **Complete** | 100% | All 56 tests pass, all fixes confirmed |
| **Products** | Production Ready (Rev 1) | **Complete** | 100% | 261 tests, all fixes confirmed |
| **Cart** | Production Ready (Rev 1) | **Complete** | 100% | 63 tests/211 assertions, all fixes confirmed |
| **Contacts** | Production Ready (Rev 1) | **Complete** | 100% | 61 tests across 8 files, `sendReplay` eradicated |
| **Flash Sales** | Production Ready (Rev 4) | **Needs Rework** | 90% | NEW HIGH-SEVERITY BUG: duplicate/buggy `Variation` block causes fatal error for variable products in percentage-type flash sales |
| **Attributes** | Production Ready (Rev 1) | **Complete** | 100% | 48 tests, all 3 fixes confirmed |
| **Product Import/Export** | Production Ready (Rev 1) | **Complete** | 100% | 38 tests, `finalizeVariants()` fix confirmed |
| **Authentication** | Production Ready (Rev 1) | **Complete** | 100% | 46 tests, Resend mailer working, all fixes confirmed |
| **Coupon Assignments** | Production Ready (Rev 1) | **Complete** | 100% | 43 tests, all 4 permission constants, 7 translation keys |
| **Orders** | Not Started | **Mostly Complete** | 90% | Full checkout flow, 3 controllers, service layer, event system, tests |
| **Promotions** | Not Started | **Mostly Complete** | 95% | Strategy pattern engine, 6 test files, full CRUD, proper locking |
| **Payment System** | Not Started | **Mostly Complete** | 85% | MyFatoorah integration, callbacks, COD/cashier, transaction system |

---

## Dependency Graph

```
Checkout ──────────────────────────────────────────┐
    │                                               │
    ├── Orders (~90%) ◄── Reports "Not Started"     │
    │       │                                       │
    │       ├── Order Items                         │
    │       ├── Payment Integration (85%)           │
    │       │       ├── MyFatoorah Gateway          │
    │       │       ├── Transaction System          │
    │       │       ├── Callback Handler            │
    │       │       ├── COD / Cashier Support       │
    │       │       └── Webhook Handling ── MISSING │
    │       │                                       │
    │       ├── Inventory (CartInventoryService)    │
    │       │       ├── Reserve (lockForUpdate)     │
    │       │       ├── Release (lockForUpdate)     │
    │       │       ├── Finalize (lockForUpdate)    │
    │       │       └── Expiry (TTL 3 days)         │
    │       │                                       │
    │       ├── Coupon Integration (~95%)           │
    │       └── Promotion Integration (~95%)        │
    │                                               │
    ├── Cart (100%)                                 │
    ├── Products (100%)                             │
    ├── Flash Sales (90% ── NEW CRITICAL BUG)       │
    ├── Coupons (Admin + Assignment = 100%)         │
    └── Promotions (~95%) ◄── Reports "Not Started" │
```

### Blocking Relationships (Unfinished → Blocks)

| Unfinished Feature | Blocks | Critical Path? |
|-------------------|--------|----------------|
| Orders (10% gap) | Refunds, Invoices, Dashboard Analytics | No — core flow works |
| Payment System (15% gap) | Webhook handling, Multi-gateway, Atomic transactions | **YES — 5/7 critical unfixed bugs are here** |
| Flash Sales (NEW bug) | Variable product percentage pricing → fatal error | **YES — HIGH severity, crashes on use** |
| Role & Permission (security gap) | All endpoints vulnerable to unauthenticated access | **YES — HIGH severity** |

---

## Inconsistencies

### INCONSISTENCY #1 — Orders Claimed "Not Started"
- **Reported:** Orders "Not Started"
- **Reality:** 90% complete. `OrderController` with full `checkout()` flow (line 65), `checkoutCallback()` (line 169), `index()`, `markCodAsPaid()`, `markCashierPaid()`, `getTransactionQr()`. `OrderService` with `addItemsInOrder()`, `calcInvoicePrice()`, `changeOrderStatus()`, `recordCouponUsage()`. `OrderCreationService`. 3 test files. 3 order models (active + 2 legacy). Full event system (`OrderCreated`, `OrderCancelled`, `PaymentSucceeded`, `PaymentFailed`). **Not "Not Started" — this is a major reporting error.**

### INCONSISTENCY #2 — Promotions Claimed "Not Started"
- **Reported:** Promotions "Not Started"
- **Reality:** 95% complete. Full strategy-pattern promotion engine in `app/Services/General/PromotionEngine/` with 11 files (strategies, applicator, resolver, DTOs, contracts). `PromotionService` with `applySelectedPromotion()` (uses `lockForUpdate`), `incrementUsage()` (locked), `decrementUsage()` (locked). `PromotionController` (admin CRUD). `PromotionDataService` (public). 6 test files. **Not "Not Started".**

### INCONSISTENCY #3 — Payment System Claimed "Not Started"
- **Reported:** Payment System "Not Started"
- **Reality:** 85% complete. `PaymentCheckoutHandler` (online/COD/cashier). `PaymentGatewayFactory` with `MyFatoorahGateway` (createInvoice, verifyPayment, refund). `Transaction` model with full lifecycle. `checkoutCallback()` handles payment verification, amount/currency mismatch detection, inventory finalization, order status change, event dispatching. `checkoutErrorCallback()` handles failures. COD/cashier mark-paid flows. Cashier QR SVG generation. **Not "Not Started".**

### INCONSISTENCY #4 — Critical Bugs C-2 through C-6 Are NOT Fixed
- **Reported (implied by production-validation-report):** These issues were identified for fixing
- **Reality:**
  - **C-1 (Inventory before payment):** **FIXED** — inventory finalization correctly moved to post-payment
  - **C-2 (No idempotency on callback):** **NOT FIXED** — `checkoutCallback()` has no idempotency guard
  - **C-3 (No lock on Transaction lookup):** **NOT FIXED** — `Transaction::where(...)->first()` at lines 176-178 uses `->first()` not `->lockForUpdate()`
  - **C-4 (calcInvoicePrice no lock):** **NOT FIXED** — `getCartUser()` at line 108 uses `->first()` not `->lockForUpdate()`
  - **C-5 (Coupon increment no lock):** **NOT FIXED** — `$coupon->increment('used')` at line 701/735 operates on unlocked model; `Coupon::where(...)->first()` at line 673 has no `lockForUpdate()`
  - **C-6 (No global transaction on callback):** **NOT FIXED** — `checkoutCallback()` has 5 state-changing operations with no wrapping `DB::transaction()`
- **Only 2 of 6 critical bugs are fixed (C-1, H-1).**

### INCONSISTENCY #5 — Flash Sales Has a New Critical Bug, Not Documented
- **Reported:** Flash Sales Production Ready (Rev 4), all fixes confirmed
- **Reality:** NEW CRITICAL BUG discovered. `FlashSaleProductProcess.php:59-63` has a duplicate block using `Variation::where('id', ...)->update(...)` but `Variation` class is NOT imported. This will cause a **fatal PHP error** (`class "Marvel\Listeners\Variation" not found`) when adding a variable product to a percentage-type flash sale. The correct duplicate block at lines 66-71 uses `$variation->save()` and works correctly. The buggy block should be deleted. **No test covers this scenario.**

### INCONSISTENCY #6 — Role & Permission Routes Missing Auth Middleware
- **Reported:** Production Ready (Rev 2), 32/32 tests pass
- **Reality:** All 13 role/permission routes in `Routes.php:256-268` are **NOT wrapped in `auth:sanctum` middleware**. They rely only on controller-constructor permission middleware. If someone removes constructor middleware during refactoring, these become publicly accessible. Additionally, duplicate `/me` route at line 137 (without auth) shadows the authenticated version at line 104. **These are security issues.**

### INCONSISTENCY #7 — Translation Gaps for Permission Keys
- **Reported:** All translations complete
- **Reality:** Missing translations in both `en/permissions.php` and `ar/permissions.php` for: `restore-user`, `view-fast-shipping`, `update-fast-shipping`, `view-coupon-assignments`, `create-coupon-assignment`, `update-coupon-assignment`, `delete-coupon-assignment`, `view-content-pages`, `create-content-pages`, `update-content-pages`, `delete-content-pages`, `view-sections`, `create-sections`, `update-sections`, `delete-sections`, `view-section-types`, `create-section-types`, `update-section-types`, `delete-section-types`. These will show as raw keys in permission resource responses.

### INCONSISTENCY #8 — Dead Permission Constants
- `VIEW_NOTIFICATTIONS` and `MANAGE_NOTIFICATTIONS` (misspelled, extra T) exist in `Permission.php:27-28` but are never used anywhere.

### INCONSISTENCY #9 — Inconsistent Naming in Permission Enum
- `VIEW_FlASH_SALE`, `CREATE_FlASH_SALE`, etc. use mixed-case `FlASH` instead of `FLASH`. This is referenced in 8 source locations.

### INCONSISTENCY #10 — `discription` Typo Still Exists
- `app/Http/Resources/FlashSale/FlashSaleResource.php:21` uses `'discription'` (misspelled) as API response key. The package resource `Marvel/FlashSaleResource.php:26` correctly uses `'description'`. This means different API consumers get different key names.

### INCONSISTENCY #11 — Test Count Mismatch
- **Reported by production-validation-report:** ~1,913 tests
- **Actual count:** ~2,079 test methods across 108 test files

### INCONSISTENCY #12 — Categories Test Failure Not Documented
- **Reported:** 98/98 tests pass (0 failures)
- **Reality:** `CategoryTranslationTest::test_show_returns_details_in_current_locale` FAILS — returns English details instead of Arabic. The test sets `app()->setLocale('ar')` but the HTTP kernel resets locale during request handling. **This is a real bug for bilingual API consumers.**

---

## Critical Bug Fix Verification

| ID | Issue | File | Method | Line | Status | Current Implementation |
|----|-------|------|--------|------|--------|----------------------|
| **C-1** | Inventory finalized before payment | `OrderService.php` | `addItemsInOrder()` | 226-238 | **FIXED** | `finalizeOrder()` only dispatches `OrderCreated` event. `finalizeItemsByShippingMethod()` moved to `checkoutCallback()` line 272, `markCodAsPaid()` line 592, `markCashierPaid()` line 623 — all AFTER payment verification |
| **C-2** | No idempotency on callback | `OrderController.php` | `checkoutCallback()` | 169-305 | **NOT FIXED** | No idempotency key. No duplicate-check. `completed→completed` transition still allowed at line 485 |
| **C-3** | No lock on Transaction lookup | `OrderController.php` | `checkoutCallback()` | 176-178 | **NOT FIXED** | `Transaction::where(...)->orWhere(...)->first()` — plain `first()`, no `lockForUpdate()` |
| **C-4** | calcInvoicePrice no lock | `OrderService.php` | `calcInvoicePrice()` | 108 | **NOT FIXED** | `getCartUser()` uses `->first()` not `->lockForUpdate()`. Contrast with `addItemsInOrder()` line 156 which correctly uses `lockForUpdate()` |
| **C-5** | Coupon increment no lock | `OrderService.php` | `recordCouponUsage()` | 701, 735 | **NOT FIXED** | `$coupon->increment('used')` on unlocked model. `Coupon::where(...)->first()` at line 673 has no `lockForUpdate()`. Assignment row IS locked, but `coupons` table row is NOT |
| **C-6** | No global transaction wrapping callback | `OrderController.php` | `checkoutCallback()` | 169-305 | **NOT FIXED** | 5 separate state-changing operations: `transaction->update()` (199), `finalizeItemsByShippingMethod()` (272), `finalizePromotionUsageAfterPayment()` (276), `changeOrderStatus()` (278), `event(PaymentSucceeded)` (283) — no wrapping `DB::transaction()` |
| **H-1** | No inventory restoration on cancel | `RestoreProductInventory.php` | `handle()` | 15-65 | **FIXED** | Registered for both `App\Events\OrderCancelled` and `Marvel\Events\OrderCancelled`. Uses `lockForUpdate()`, `inventory_restored_at` idempotency guard, restores products and variants, skips gifts |

---

## Recommended Roadmap (Based on ACTUAL Implementation)

### Tier 0 — Blockers (fix before anything else)

1. **Flash Sale: Remove duplicate/buggy `Variation` block**
   - File: `FlashSaleProductProcess.php:59-63`
   - Severity: HIGH — causes fatal error on variable product percentage flash sale
   - Fix: Delete lines 59-63 (the block using unimported `Variation::class`)

2. **Role & Permission: Wrap routes in `auth:sanctum` middleware**
   - File: `Routes.php:256-268`
   - Severity: HIGH — missing defense-in-depth on all role/permission endpoints
   - Fix: Wrap all 13 routes in `Route::middleware('auth:sanctum')->group(...)`

3. **Fix duplicate `/me` route shadowing**
   - File: `Routes.php:104,137`
   - Severity: HIGH — unauthenticated version shadows authenticated version
   - Fix: Remove the unauthenticated `/me` route at line 137

### Tier 1 — Critical Payment/Checkout Hardening

4. **Add `lockForUpdate()` on Transaction lookup in `checkoutCallback()`**
   - File: `OrderController.php:176-178`

5. **Add `lockForUpdate()` on Cart in `calcInvoicePrice()`**
   - File: `OrderService.php:108`

6. **Add `lockForUpdate()` on Coupon before `increment('used')`**
   - File: `OrderService.php:673`

7. **Wrap `checkoutCallback()` state changes in `DB::transaction()`**
   - File: `OrderController.php:169-305`

8. **Add idempotency guard to `checkoutCallback()`**
   - File: `OrderController.php:169-305`
   - Check `transaction.status` before processing

### Tier 2 — Feature Updates (Update Status Database)

9. **Update `production-status.md`** — Orders: 90%, Promotions: 95%, Payment: 85%
10. **Update `production-history.md`** — Document new Flash Sale bug
11. **Add missing permission translations** in both `en/permissions.php` and `ar/permissions.php`
12. **Clean up dead permission constants**: `VIEW_NOTIFICATTIONS`, `MANAGE_NOTIFICATTIONS`
13. **Fix `discription` typo** in `app/Http/Resources/FlashSale/FlashSaleResource.php:21`
14. **Fix Category locale test failure** — locale not threaded through HTTP kernel

### Tier 3 — Future Features (After Tiers 0-2)

15. **Orders**: Add cancel/refund endpoint to API
16. **Payment**: Add webhook endpoint, support additional gateways, make transaction creation atomic with order creation
17. **Update PermissionSeeder** with 4 new coupon assignment permission constants
