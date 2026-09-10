# Invoice System — Execution Constitution

**Document Type**: Governance Rules  
**Status**: Enforced  
**Effective Date**: Immediately  
**Supersedes**: All prior implementation conventions where conflict exists  
**Enforcement**: Code review gate — every PR must pass all 10 articles  

This Constitution governs all implementation work on the Invoice System. Every developer, AI agent, and reviewer must comply.

---

## Article 1 — Scope Control

1.1 Implement **only** what is defined in the frozen architecture (`docs/cms-endpoints/invoice-system-architecture.md`).

1.2 Implement **only** what is listed in the Implementation Manifest (`docs/implementation/invoice-system-implementation-manifest.md`).

1.3 Do **not** introduce new features, endpoints, fields, or behavior beyond what these documents specify.

1.4 Do **not** redesign, refactor, or restructure existing payment, order, coupon, promotion, pricing, or inventory flows.

1.5 Do **not** expand the scope. If a requirement is not in both documents, it does not exist.

---

## Article 2 — Architecture Compliance

2.1 Every line of code must conform to ADR-003 as frozen.

2.2 Any deviation from the frozen architecture requires a new ADR approved before implementation begins.

2.3 No silent architectural changes are permitted. "Improving the design" during implementation is a violation.

2.4 If implementation reveals that a frozen architectural decision cannot work as specified, halt work and file an ADR. Do not work around it.

---

## Article 3 — Business Logic Protection

3.1 The Invoice module is a **read-only consumer**. It must **never**:

| Prohibited Action | Example |
|------------------|---------|
| Calculate prices | `$price = $quantity * $unitPrice` |
| Calculate taxes | `$tax = $subtotal * 0.14` |
| Calculate shipping | `$shipping = $this->shippingService->getPrice()` |
| Calculate promotions | `$promo = $this->promotionService->apply(...)` |
| Calculate coupons | `$discount = $coupon->calculate($total)` |
| Calculate discounts | `$discount = $price * 0.10` |
| Validate coupons | `$coupon->isValid()` |
| Apply any pricing logic | Any formula that computes a financial value |

3.2 The Invoice module must **only** read already-computed values from `Order` and `OrderProduct` snapshot fields.

3.3 The Invoice module must **never** call these services:
- `ProductPricingService`
- `CouponCalculator`
- `CouponOrchestrator`
- `PromotionService`
- `FlashSaleService`
- `ProductFlashSaleService`

3.4 The Invoice module must **never** modify these models:
- `Order` (no `update()` calls)
- `Transaction` (no `update()` calls)
- `Product` (no reads or writes)
- `Coupon` / `CouponAssignment` / `CouponUsage`
- `Promotion`
- `FlashSale`

---

## Article 4 — Single Source of Truth

4.1 The existing order, payment, promotion, coupon, inventory, and pricing workflows remain the authoritative single source of truth.

4.2 The Invoice module must **never** duplicate business logic that exists elsewhere in the codebase.

4.3 When in doubt whether a piece of logic already exists, search the codebase. If it exists, reference it. Do not reimplement it.

4.4 The order snapshot fields (`orders.price`, `orders.total_price`, `orders.coupon_discount`, `order_products.product_total_price`, etc.) are the **only** acceptable data sources for financial values in the invoice snapshot.

---

## Article 5 — Incremental Delivery

5.1 Every phase must leave the application in a fully deployable state.

5.2 Before any phase is considered complete:

- [ ] Application compiles without errors
- [ ] All pre-existing tests pass
- [ ] All new tests for this phase pass
- [ ] `php artisan migrate` succeeds (if migrations exist)
- [ ] `php artisan migrate:rollback` succeeds (if migrations exist)
- [ ] No partial or dead code is introduced
- [ ] No new classes are left unused
- [ ] No `var_dump`, `dd()`, `ray()`, `logger()->debug()` remain

5.3 Any phase that introduces a migration must verify forward and backward migration succeed.

5.4 Any phase that modifies an existing service must verify all callers still work.

---

## Article 6 — Testing Requirements

6.1 No phase is complete until:

| Test Type | Required for | Gate |
|-----------|-------------|------|
| Unit tests | All services, validators, builders | Must pass |
| Feature tests | All listeners, jobs, API endpoints | Must pass |
| Regression tests | Modified existing code (OrderService, OrderController) | Must pass |
| Idempotency tests | Listener (duplicate event handling) | Must pass when applicable |
| Concurrency tests | Listener, invoice number generation | Must pass when applicable |
| Authorization tests | API endpoints | Must pass |

6.2 Tests must cover:

- [ ] Success paths
- [ ] Failure paths (validation errors, missing data, exceptions)
- [ ] Edge cases (zero values, null values, boundary conditions)
- [ ] Idempotency (same input delivered twice)
- [ ] Authorization (owner vs admin vs guest)

6.3 Integration tests must use `DatabaseTransactions` trait to isolate database state.

---

## Article 7 — Code Review Rules

7.1 Every PR must verify these items before merging:

| Check | Criterion |
|-------|-----------|
| Architecture compliance | Does this PR comply with ADR-003? |
| Scope control | Does this PR introduce anything not in the Manifest? |
| Business logic protection | Does any code call a prohibited service or recalculate a price? |
| Source of truth | Does this PR duplicate logic that exists elsewhere? |
| Single responsibility | Does each class have one reason to change? |
| No hidden side effects | Does the PR modify Order, Transaction, or other non-invoice models? |
| Backward compatibility | Are existing routes, responses, and behaviors unchanged? |
| Translation usage | Are all user-facing strings using `__()` with translation keys? |
| Test coverage | Are all new code paths tested? |

7.2 Any PR that violates Articles 1-4 must be **rejected** regardless of code quality.

---

## Article 8 — Change Control

8.1 The following changes require a **new ADR** approved before implementation:

| Change Category | Examples |
|----------------|----------|
| Database design | New columns on `invoices` table, new tables, changed FKs |
| Event flow | New events, changed listeners, different dispatch timing |
| API contract | Changed response structure, new required parameters, removed endpoints |
| Snapshot schema | New fields, renamed fields, changed types in `data` JSON |
| Payment flow | Changes to `PaymentSucceeded` dispatch, transaction logic |
| Aggregate boundaries | Invoice module writing to `orders` or `transactions` |
| Frozen architecture | Any change to what Section 16 declares frozen |

8.2 The following changes do **not** require an ADR:

| Change Category | Examples |
|----------------|----------|
| Bug fixes | Code doesn't compile, migration fails, test assertion incorrect |
| Performance improvements | Adding an index, eager loading, query optimization |
| PDF template layout | Visual changes to the PDF output |
| Translation additions | New translation keys, additional languages |
| Test additions | More test coverage, edge cases |

---

## Article 9 — Definition of Ready

9.1 Before starting any phase, verify:

- [ ] All dependent phases are complete and merged
- [ ] The previous phase's exit criteria are met
- [ ] All required migrations for this phase are written and reviewed
- [ ] Rollback plan for this phase is documented
- [ ] Test plan for this phase is documented
- [ ] Deployment precautions are understood

9.2 Do not begin a phase if its dependencies are not yet merged to `main`.

---

## Article 10 — Definition of Done

10.1 A task is complete only when **all** of these hold:

| # | Condition | Verification |
|---|-----------|-------------|
| 1 | Implementation matches the frozen architecture | Code review against ADR-003 |
| 2 | Implementation matches the Implementation Manifest | Code review against manifest |
| 3 | All acceptance criteria for this phase are satisfied | Reviewed sign-off |
| 4 | All tests pass (new + existing) | CI pipeline green |
| 5 | No business logic is duplicated | grep for prohibited service calls |
| 6 | No pricing, coupon, promotion, or shipping logic exists in the Invoice module | Code review |
| 7 | All user-facing strings use `__()` with translation keys | grep for hardcoded strings in responses |
| 8 | The application remains fully deployable | `php artisan migrate` + `test` succeed |
| 9 | No regressions are introduced | Full test suite passes |
| 10 | Documentation is updated (if affected) | README, API docs, or changelog |

---

## Governance Statement

The Invoice System is now under **Architecture Governance**.

ADR-003 (`docs/cms-endpoints/invoice-system-architecture.md`) is the supreme governing document for all invoice-related implementation. The Implementation Manifest (`docs/implementation/invoice-system-implementation-manifest.md`) is the execution plan. This Constitution (`docs/implementation/invoice-execution-constitution.md`) is the rulebook.

These three documents form a binding contract:

```
ADR-003 (What to build — frozen)
    ↓
Manifest (How to build it — phased plan)
    ↓
Constitution (Rules for building it — this document)
```

Any future work on the Invoice System must comply with all three. No developer or AI agent may deviate from the frozen architecture unless an approved ADR explicitly amends it. No silent changes. No scope creep. No redesign.

**This Constitution takes effect immediately and applies to all future implementation work on the Invoice System.**

---

## Audit Fixes

### Compliance Verification (2026-07-15)

An architecture compliance audit was performed against all 10 Articles of this Constitution.

#### Article 1 — Scope Control
| Requirement | Status | Notes |
|-------------|--------|-------|
| 1.1: Implement only what is in frozen architecture | ⚠️ Partial | Phase 1-2 services exist. Phase 3-7 not yet implemented. |
| 1.2: Implement only what is in Manifest | ⚠️ Partial | Phase 1-2 files partially implemented. See Manifest Audit Fixes for details. |
| 1.3-1.5: No scope creep | ✅ Compliant | No unauthorized features introduced. |

#### Article 2 — Architecture Compliance
| Requirement | Status | Notes |
|-------------|--------|-------|
| 2.1: Conform to ADR-003 | ✅ Compliant | Existing Phase 1-2 implementation follows architecture. |
| 2.2: No deviations without ADR | ✅ Compliant | No architectural deviations found. |

#### Article 3 — Business Logic Protection
| Requirement | Status | Notes |
|-------------|--------|-------|
| 3.1: No price calculation in invoice module | ✅ Compliant | InvoiceSnapshotService reads from order snapshot fields only. |
| 3.2: Read only from Order/OrderProduct snapshots | ✅ Compliant | Verified in InvoiceSnapshotService code. |
| 3.3: No calls to prohibited services | ✅ Compliant | No such calls found in invoice module. |
| 3.4: No modification of Order/Transaction/Product | ✅ Compliant | Phase 1-2 services are read-only. |

#### Article 4 — Single Source of Truth
| Requirement | Status | Notes |
|-------------|--------|-------|
| 4.1-4.4: No duplicated business logic | ✅ Compliant | InvoiceSnapshotService reads from order fields without recalculating. |

#### Article 5 — Incremental Delivery
| Requirement | Status | Notes |
|-------------|--------|-------|
| 5.1: Deployable state | ✅ Compliant | Existing code compiles without errors. |
| 5.2: Exit criteria for current phase | ⚠️ Partial | Phase 1 exit criteria not fully met (see Manifest). |

#### Article 6-10: Pending Implementation
Articles 6-10 apply to Phases 3-7 which have not yet begun. Compliance will be verified when those phases are implemented.

### Key Action Items Before Phase 3

1. ✅ Permission enum constants resolved (2026-07-15) — `VIEW_INVOICES`, `ISSUE_CORRECTION_INVOICE`, `REGENERATE_INVOICE_PDF`, `EXPORT_INVOICES` added.
2. ❌ Order `governorate()` relationship — Requires `ALTER TABLE orders ADD governorate_id` migration + `BelongsTo` relationship on model.
3. ❌ Permission seed migration — `2026_07_16_000003_seed_invoice_permissions.php` needs to be created.
4. These must be resolved before starting Phase 3 implementation to maintain architecture compliance.
