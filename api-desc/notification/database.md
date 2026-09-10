# Database - Notification Feature (Phase 1 / 2 / 3)

## Table: `notifications` (Laravel built-in)

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | uuid | PK |
| `type` | string | **Business id**, e.g. `price.drop` (NOT class FQN) |
| `notifiable_type` | string | Morphs — `Marvel\Database\Models\User` |
| `notifiable_id` | bigint | Morphs — user ID |
| `data` | text | JSON — title{en,ar}, message{en,ar}, icon, resource_type, resource_id, action_url, + type-specific fields |
| `read_at` | timestamp | nullable |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

> **Change vs. legacy:** historically `type` held the PHP FQCN
> (`App\Notifications\...`). After the refactor it holds the stable business id.
> The broadcast `type` was already the business id pre-refactor.

## `data` payload by notification family

All Phase notifications store the same envelope keys
(`title`, `message`, `icon`, `resource_type`, `resource_id`, `action_url`) plus
type-specific keys:

| `type` | `resource_type` | Extra `data` keys |
|--------|----------------|-------------------|
| `order.created` | order | order_id, order_number, total_amount, payment_status, order_status |
| `payment.succeeded` | order | order_id, order_number, total_amount, payment_status |
| `payment.failed` | order | order_id, order_number, payment_status |
| `order.delivered` | order | order_id, order_number, order_status |
| `order.cancelled` | order | order_id, order_number, order_status |
| `order.refunded` | refund | refund_id, order_id, amount, status |
| `coupon.assigned` | coupon | coupon_assignment_id, coupon_id, coupon_code, max_uses, expires_at |
| `coupon.available` | coupon | coupon_id, coupon_code, coupon_type |
| `coupon.used` | coupon | coupon_id, coupon_code, order_id, remaining_uses, consumed_at |
| `promotion.available` | promotion | promotion_id, promotion_code, discount_type, discount_value, start_at, end_at |
| `flash_sale.available` | flash_sale | flash_sale_id, discount_type, discount_value, start_date, end_date |
| `review.approved` | review | review_id, product_id |
| `review.rejected` | review | review_id, product_id |
| `discount.changed` | product | product_id |
| `price.drop` | product | product_id, old_price, new_price |
| `back.in.stock` | product | product_id |
| `promotion.price.drop` | promotion | promotion_id |
| `flash_sale.price.drop` | flash_sale | flash_sale_id |
| `cart.abandoned` | cart | cart_id |
| `promotion.ending_soon` | promotion | promotion_id, end_at |
| `flash_sale.ending_soon` | flash_sale | flash_sale_id, end_date |

## Key Queries

| Use Case | Pattern |
|----------|---------|
| List (paginated) | `WHERE notifiable_type = ? AND notifiable_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?` |
| Unread | `WHERE notifiable_type = ? AND notifiable_id = ? AND read_at IS NULL ORDER BY created_at DESC` |
| Mark as read | `UPDATE notifications SET read_at = NOW() WHERE id = ? AND notifiable_id = ?` |
| Mark all read | `UPDATE notifications SET read_at = NOW() WHERE notifiable_id = ? AND read_at IS NULL` |
| Delete single | `DELETE FROM notifications WHERE id = ? AND notifiable_id = ?` |
| Delete all | `DELETE FROM notifications WHERE notifiable_id = ?` |

## Indexes

- Primary: `id` (uuid)
- Polymorphic index: `(notifiable_type, notifiable_id)` — created by `morphs()`
- No additional custom indexes (sufficient given user-scoped queries)

## Related tables (read by notifications, not stored here)

Phase 3 wishlist fan-out reads `wishlists` (user_id, product_id). Price/discount
values come from `products`. No new schema is required for notification storage
beyond the Laravel `notifications` table.
