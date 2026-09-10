# Coupon Assignment — Backend Implementation

---

## Controller

**File:** `packages/marvel/src/Http/Controllers/CouponAssignmentController.php`

### Architecture

The controller follows the same pattern as other Marvel admin controllers:

1. Constructor injects `CouponAssignmentRepository`
2. `__construct` registers permission middleware for each action
3. Each method receives `$couponId` and optionally `$assignmentId` as path parameters
4. All methods are wrapped in try/catch blocks for consistent error responses

### Permission Middleware

| Method | Permission |
|--------|-----------|
| index, show | `view-coupon-assignments` |
| store | `create-coupon-assignment` |
| update | `update-coupon-assignment` |
| destroy | `delete-coupon-assignment` |

### Error Handling

| Exception | HTTP Status |
|-----------|-------------|
| `ModelNotFoundException` | 404 |
| `MarvelBadRequestException` | 409 (store/destroy) or 422 (update) |
| General `Exception` | 400 |

---

## Repository

**File:** `packages/marvel/src/Database/Repositories/CouponAssignmentRepository.php`

### Methods

| Method | Transaction | Lock | Description |
|--------|-------------|------|-------------|
| `listByCoupon($couponId, $limit)` | No | No | Paginated list with eager-loaded `user` relation |
| `findAssignment($couponId, $assignmentId)` | No | No | Scoped find: `where('coupon_id', $couponId)->where('id', $assignmentId)->firstOrFail()` |
| `assignCoupon($couponId, $data)` | **Yes** | No | Creates assignment with duplicate detection |
| `updateAssignment($couponId, $assignmentId, $data)` | No | No | Updates max_uses/expires_at with `max_uses >= used` guard |
| `removeAssignment($couponId, $assignmentId)` | **Yes** | **Yes** | Deletes with `lockForUpdate()` + `used > 0` block |

### assignCoupon Logic

1. Validates coupon exists via `Coupon::findOrFail()`
2. Checks for existing assignment `(coupon_id, user_id)` — throws 409 if duplicate
3. Creates `CouponAssignment` record
4. Returns `$assignment->fresh()` to pick up database defaults (used = 0)
5. All in `DB::transaction()`

### removeAssignment Logic

1. Scoped find with `lockForUpdate()` (pessimistic lock to prevent race with concurrent order consumption)
2. If `used > 0`, throws 409 (prevent audit loss)
3. Deletes the record
4. All in `DB::transaction()`

---

## Services (External / Unchanged)

These services are NOT modified by this feature. They handle the customer-facing consumption flow:

| Service | Purpose |
|---------|---------|
| `CouponOrchestrator` | Validates coupon for user consumption |
| `CouponAssignmentValidator` | Validates per-user assignment limits |
| `CouponValidator` | Validates general coupon rules |
| `CouponCalculator` | Pure price calculation |
| `OrderService` | Consumption flow (records usage in `coupon_usage`) |
