# ADR-002: Coupon System Architecture

## Metadata
- Decision ID: ADR-002
- Architecture Area: Coupon Discount System
- Status: Accepted
- Decision State: Active
- Production Status: Approved

---

## Context

The platform requires a coupon discount system that supports two distinct modes:

1. **Public Coupons** — Any authenticated user can apply the coupon once (enforced by unique constraint on `coupon_usages`).
2. **Assigned Coupons** — Specific users are granted a personal quota (up to `max_uses` uses per user). Unassigned users cannot use the coupon.

Both modes share the same discount math (fixed rate, percentage, free shipping) and basic validity checks (dates, global limiter, product restrictions, enabled status).

The existing codebase already had a `CouponValidator`, `CouponCalculator`, and `CouponUsage` model for public coupons. The assigned coupon system was added without modifying the existing public coupon pipeline.

---

## Decision

The coupon pipeline is:

```
CouponOrchestrator::validateByCode(code, user, items)
     │
     ├── 1. Find Coupon by code
     │
     ├── 2. CouponAssignmentValidator::validate(coupon, user)
     │       ├── No assignments → public → CouponValidator (all checks)
     │       └── Has assignments → check user's assignment:
     │             ├── Not assigned → "not_assigned"
     │             ├── Expired       → "assignment_expired"
     │             ├── Quota exceeded → "usage_quota_exceeded"
     │             └── Valid         → CouponValidator (SKIPS already_used)
     │
     ├── 3. If valid → CouponCalculator::calculate() for price
     │
     └── 4. On payment success → OrderService::recordCouponUsage()
             ├── Assigned → increment assignment.used + create CouponAssignmentUsage
             └── Public   → CouponUsage::firstOrCreate()
```

### Public vs Assigned Detection

Detection is automatic via `CouponAssignment::exists()` on the coupon:

- **Zero assignments** on a coupon → Public (existing behavior, anyone can use once)
- **One or more assignments** → Assigned (only assigned users can use)

This means a coupon transitions from public to assigned the moment the first assignment row is created.

### CouponCalculator is Frozen

`CouponCalculator` is intentionally assignment-unaware. It performs pure math (fixed rate, percentage, free shipping) and does not know or care whether the coupon is public or assigned. This ensures that changing assignment logic never affects pricing calculations.

---

## Database Schema

### coupons (existing, unmodified)

| Column | Type | Description |
|--------|------|-------------|
| id | PK | |
| code | string UNIQUE | Coupon code |
| discount_type | enum | fixed_rate, percentage, free_shipping |
| discount | decimal | Discount value |
| max_discount_amount | decimal | Cap for percentage discounts |
| start_date / end_date | date | Validity window |
| status | boolean | Enabled/disabled |
| limiter | int nullable | Global max uses |
| used | int | Global usage counter |

### coupon_assignments (new)

| Column | Type | Description |
|--------|------|-------------|
| id | PK | |
| coupon_id | FK→coupons CASCADE | Parent coupon |
| user_id | FK→users CASCADE | Assigned user |
| max_uses | unsigned int | Per-user quota (default 1) |
| used | unsigned int | Current consumption |
| assigned_at | timestamp | When assigned |
| expires_at | timestamp nullable | Per-assignment expiry |
| UNIQUE(coupon_id, user_id) | | No duplicate assignments |

### coupon_assignment_usages (new)

| Column | Type | Description |
|--------|------|-------------|
| id | PK | |
| coupon_assignment_id | FK→coupon_assignments CASCADE | Parent assignment |
| order_id | FK→orders null ON DELETE | The order that consumed this use |
| used_at | timestamp | When consumed |
| INDEX(coupon_assignment_id, created_at) | | Composite for usage queries |

### coupon_usages (existing, unmodified)

| Column | Type | Description |
|--------|------|-------------|
| id | PK | |
| coupon_id | FK→coupons CASCADE | |
| user_id | FK→users nullable | |
| order_id | FK→orders nullable | |
| used_at | timestamp | |
| UNIQUE(coupon_id, user_id) | | One use per user for public coupons |

---

## Transaction Boundaries

Coupon usage recording happens inside `DB::transaction()` in `OrderService::markCodAsPaid()` and `markCashierPaid()`.

```
DB::transaction(function () {
    1. Lock transaction row (lockForUpdate)
    2. Update transaction status
    3. Update order status
    4. recordCouponUsage(order)
       ├── Lock assignment row (lockForUpdate)
       ├── Double-check quota inside lock
       ├── Increment coupon.used + assignment.used
       ├── Create CouponAssignmentUsage audit row
       └── DB::afterCommit() → dispatch AssignedCouponConsumed event
    5. Dispatch PaymentSucceeded event
});
```

### Concurrency Protection

- The assignment row is locked with `lockForUpdate()` before incrementing, so concurrent checkouts cannot over-consume the quota.
- Quota is re-checked inside the lock (defense-in-depth against race conditions).
- `recordCouponUsage` silently skips if quota is already exhausted (belt-and-suspenders).

### Policy: Quota is Never Returned

Coupon usage quota is NEVER automatically returned on cancellation or refund. This prevents abuse where a user could re-use the same quota by repeatedly cancelling and re-ordering. If manual adjustment is needed, an administrator can modify the `used` column directly.

---

## Key Components

| Component | Path | Responsibility |
|-----------|------|----------------|
| CouponOrchestrator | `app/Services/Coupon/CouponOrchestrator.php` | Entry point: composes assignment validation + coupon validation in correct order |
| CouponAssignmentValidator | `app/Services/Coupon/CouponAssignmentValidator.php` | Validates assignment existence, expiry, and quota |
| CouponValidator | `app/Services/Coupon/CouponValidator.php` | Validates coupon-level rules (dates, status, limiter, products, already_used) |
| CouponCalculator | `app/Services/Coupon/CouponCalculator.php` | Pure math: calculates discounted price (assignment-unaware) |
| OrderService | `app/Services/General/OrderService.php` | Records usage after payment success inside a transaction |
| AssignedCouponConsumed | `app/Events/AssignedCouponConsumed.php` | Domain event dispatched after assignment usage is recorded |

---

## Events

| Event | Dispatched When | Payload |
|-------|----------------|---------|
| `AssignedCouponConsumed` | After assignment usage recorded (inside `DB::afterCommit`) | Coupon, CouponAssignment, User, Order, remainingUses, consumedAt |
| `PaymentSucceeded` | After payment transaction updated | Order |

`AssignedCouponConsumed` is dispatched inside `DB::afterCommit()` so it only fires after the outer transaction commits, preventing listeners from seeing uncommitted data.

---

## Testing Strategy

- 47 tests in `AssignedCouponSystemTest.php` cover success, failure, multi-use, public backward compatibility, product restrictions, concurrency, transaction rollback, cancellation policy, user deletion, and quota modification scenarios.
- Tests use `RefreshDatabase` trait (runs all migrations) or `DatabaseTransactions` + `CreatesTestTables` (manual schema for unit tests).
- SQLite in-memory is used for testing (`phpunit.xml`: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`).
