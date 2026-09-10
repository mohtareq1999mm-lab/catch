# Coupon Configuration Guide

## Understanding Coupon Types

### Public Coupons (No Assignments)

**Definition:** Coupons available to all customers without pre-assignment.

**Usage Model:**
- Each customer can redeem **exactly once**
- Enforced by `UNIQUE(coupon_usages.coupon_id, user_id)` database constraint
- Global capacity controlled by `coupons.limiter`

**Example:**
```
Coupon: SUMMER2024
Limiter: 100
Result: First 100 different customers can each redeem once
```

**Use Cases:**
- First-time customer discounts
- Limited-time promotions
- One-time referral codes

**Common Mistake:**
Setting `limiter=100` and expecting each customer to use 5 times.
→ Reality: 100 different customers × 1 use = 100 total redemptions

---

### Assigned Coupons (With Assignments)

**Definition:** Coupons pre-assigned to specific customers.

**Usage Model:**
- Each assignment has `max_uses` quota (e.g., 5)
- Customer can redeem multiple times up to quota
- History tracked in `coupon_assignment_usages`

**Example:**
```
Coupon: VIP2024
Assignment: user_id=5, max_uses=5
Result: User 5 can redeem 5 times
```

**Use Cases:**
- VIP customer benefits
- Loyalty rewards
- Subscription-based discounts

**Multi-Use Setup:**
```php
// Create assignment for multi-use
CouponAssignment::create([
    'coupon_id' => $coupon->id,
    'user_id' => $customerId,
    'max_uses' => 5,  // Customer can use 5 times
    'expires_at' => now()->addMonths(3),
]);
```

---

## Decision Tree

```
Do you want customers to use the coupon multiple times?
  │
  ├─ NO (Single-use) ──► Use PUBLIC coupon
  │                       - Set limiter = total capacity
  │                       - No assignments needed
  │
  └─ YES (Multi-use) ──► Use ASSIGNED coupon
                          - Create assignments with max_uses > 1
                          - Optional: Set limiter for global cap
```

---

## Validation API

Before saving coupon configuration, validate:

```http
POST /api/v1/admin/coupons/validate-configuration
Content-Type: application/json
Authorization: Bearer <admin_token>

{
  "coupon_type": "public",
  "limiter": 100,
  "max_uses_per_user": 1
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "valid": true,
    "errors": [],
    "warnings": [],
    "recommendations": [
      {
        "title": "Public Coupon Behavior",
        "description": "Each customer can redeem this coupon exactly once. Global capacity: 100 total redemptions."
      }
    ]
  }
}
```

Error example (public multi-use):
```json
{
  "success": false,
  "data": {
    "valid": false,
    "errors": [
      {
        "field": "max_uses_per_user",
        "message": "Public coupons only support single use per customer.",
        "explanation": "The coupon_usages table enforces UNIQUE(coupon_id, user_id), preventing repeat use. For multi-use, switch to \"assigned\" coupon type."
      }
    ]
  }
}
```

---

## Common Scenarios

### Scenario 1: "10% off for first 500 customers, one-time use"
```
Type: Public
Limiter: 500
Assignments: None
Result: 500 different customers, 1 use each
```

### Scenario 2: "VIP customers get 20% off, usable 3 times this month"
```
Type: Assigned
Create assignments: max_uses=3, expires_at=end of month
Result: Each VIP can redeem 3 times
```

### Scenario 3: "Referral code, each referred user can use twice"
```
Type: Assigned
Create assignment when user is referred: max_uses=2
Result: Each referred user gets 2 uses
```

---

## Migration Path

### Converting Public → Assigned:

```php
// 1. Get coupon
$coupon = Coupon::where('code', 'SUMMER2024')->first();

// 2. Identify eligible users (e.g., all active customers)
$eligibleUsers = User::where('is_active', true)->get();

// 3. Create assignments
foreach ($eligibleUsers as $user) {
    CouponAssignment::create([
        'coupon_id' => $coupon->id,
        'user_id' => $user->id,
        'max_uses' => 5,  // Multi-use
        'expires_at' => now()->addDays(30),
    ]);
}

// 4. Existing coupon_usages (public path) remain valid
//    New redemptions will use assignment path
```

---

## Database Constraints

**Why can't public coupons be multi-use?**

```sql
-- coupon_usages table
CREATE TABLE coupon_usages (
    coupon_id BIGINT,
    user_id BIGINT,
    UNIQUE KEY (coupon_id, user_id)  -- This prevents second redemption
);

-- Attempt to redeem twice:
INSERT INTO coupon_usages (coupon_id, user_id) VALUES (1, 5); -- Success
INSERT INTO coupon_usages (coupon_id, user_id) VALUES (1, 5); -- Duplicate key error
```

**Why can assigned coupons be multi-use?**

```sql
-- coupon_assignment_usages table (child records, multiple allowed)
CREATE TABLE coupon_assignment_usages (
    coupon_assignment_id BIGINT,
    order_id BIGINT,
    UNIQUE KEY (coupon_assignment_id, order_id)  -- Unique per ORDER, not per user
);

-- Same user, different orders:
INSERT ... (assignment_id=1, order_id=100); -- Use 1
INSERT ... (assignment_id=1, order_id=101); -- Use 2
INSERT ... (assignment_id=1, order_id=102); -- Use 3
```

---

## Admin Helpers

- `GET /api/v1/admin/coupons/{id}/usage-info` — current usage, limits, assignment info
- `POST /api/v1/admin/coupons/{id}/suggest-fix` — body `desired_behavior: multi_use_per_user | single_use_per_user` → steps

---

## FAQ

**Q: Can I change a public coupon to multi-use without assignments?**
A: No. Database constraint prevents this. Must convert to assigned type.

**Q: What if I have 1000 VIP customers who should each get 10 uses?**
A: Create 1000 assignments with `max_uses=10`. This is the correct approach.

**Q: Can I mix public and assigned for the same coupon?**
A: Technically yes (existing public usages remain), but not recommended. Choose one model.

**Q: Does `coupons.limiter` apply to assigned coupons?**
A: Yes. Global limiter is checked for both public and assigned paths. Set high or null if using assignments.

---

## Related

- `ORDER_SYSTEM_FINAL_AUDIT.md` §7 Coupon Flow
- `database/migrations/2026_09_10_..._rename_max_claims...` — total capacity semantics
