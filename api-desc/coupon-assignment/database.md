# Coupon Assignment — Database Schema

---

## Table: `coupon_assignments`

| Column | Type | Constraints | Default | Description |
|--------|------|-------------|---------|-------------|
| id | bigint unsigned | PK, auto-increment | — | Primary key |
| coupon_id | bigint unsigned | FK → coupons.id, NOT NULL | — | Parent coupon |
| user_id | bigint unsigned | FK → users.id, NOT NULL | — | Assigned user |
| max_uses | int unsigned | NOT NULL | — | Max allowed uses |
| used | int unsigned | NOT NULL | 0 | Current usage count |
| assigned_at | timestamp | NOT NULL | current_timestamp | When assignment was created |
| expires_at | timestamp | nullable | null | When assignment expires |
| created_at | timestamp | NOT NULL | current_timestamp | Laravel timestamp |
| updated_at | timestamp | NOT NULL | current_timestamp | Laravel timestamp |

**Unique Index:** `(coupon_id, user_id)` — prevents duplicate assignments

**Foreign Keys:**
- `coupon_id` → `coupons(id)` ON DELETE CASCADE
- `user_id` → `users(id)` ON DELETE CASCADE

---

## Table: `coupon_assignment_usages`

(Pre-existing — tracks individual consumption events)

| Column | Type | Description |
|--------|------|-------------|
| id | bigint unsigned | Primary key |
| coupon_assignment_id | bigint unsigned | FK → coupon_assignments.id |
| order_id | bigint unsigned | FK → orders.id |
| used_at | timestamp | When the usage occurred |
| created_at | timestamp | Laravel timestamp |

---

## Visibility Model

| Assignments Count | Coupon Behavior |
|-------------------|-----------------|
| 0 | Public — any user can apply the coupon |
| 1 or more | Restricted — only assigned users can apply |

This is an **implicit** visibility model — there is no `is_public` column. The consumption services (`CouponAssignmentValidator`) check `->assignments()->count()` at runtime.

---

## Migration

**File:** `database/migrations/2026_07_15_000003_create_coupon_assignments_table.php`
