# QA - Notification Feature (Phase 1 / 2 / 3)

## Test Matrix

| TC ID | Area | Expected |
|-------|------|----------|
| TC-NOT-001 | Auth | Unauthenticated → 401 on all 6 endpoints |
| TC-NOT-002 | List | `GET /api/v1/notifications` → paginated, own only |
| TC-NOT-003 | List empty | Empty `data`, `meta.total=0` |
| TC-NOT-004 | Unread | `GET .../unread` → only unread + `meta.total` |
| TC-NOT-005 | Unread all read | Empty array, `total=0` |
| TC-NOT-006 | Show | `GET .../{id}` → full object |
| TC-NOT-007 | Mark read | `PATCH .../{id}/read` → `read_at` set |
| TC-NOT-008 | Mark read idempotent | Already read → 200, no error |
| TC-NOT-009 | Mark read not found | 404 |
| TC-NOT-010 | Mark all read | All read, `marked_count` returned |
| TC-NOT-011 | Delete | `DELETE .../{id}` → 200 |
| TC-NOT-012 | Delete not found | 404 |
| TC-NOT-013 | Delete other user's | Not affected (user-scoped) |
| TC-NOT-014 | Type contract | `type` is business id (`price.drop`), never FQCN |
| TC-NOT-015 | Broadcast channel | Realtime channel = `private-users.{id}` |
| TC-NOT-016 | Broadcast type | Realtime payload `type` = business id |
| TC-NOT-017 | Localization REST | `lang: ar` → Arabic `title`/`message` |
| TC-NOT-018 | Localization realtime | Realtime `title`/`message` are `{en,ar}` |
| TC-NOT-019 | Phase 1 types | `order.created`, `payment.*`, `order.*`, `coupon.*` present |
| TC-NOT-020 | Phase 2 types | `promotion.available`, `flash_sale.available` present |
| TC-NOT-021 | Phase 3 types | `price.drop`, `back.in.stock`, `review.*`, `*.ending_soon`, `cart.abandoned` present |
| TC-NOT-022 | Wishlist fan-out | Only wishlist users notified; admins excluded |
| TC-NOT-023 | Queue | Listeners run on `meem-medium` |

## Manual Test Checklist

- [ ] Place an order → `order.created` notification appears (REST + realtime)
- [ ] Pay → `payment.succeeded` / fail → `payment.failed`
- [ ] Mark delivered/cancelled/refunded → respective types
- [ ] Assign/activate/use a coupon → `coupon.*`
- [ ] Activate promotion/flash sale → `*.available`
- [ ] Drop price / discount change / back in stock on a wishlisted product → respective types
- [ ] Approve/reject review → `review.*`
- [ ] Promotion/flash sale near end → `*.ending_soon`
- [ ] Abandon a cart → scheduler sends `cart.abandoned`
- [ ] Verify `type` in DB = business id (not FQCN)
- [ ] Verify realtime channel = `private-users.{id}`
- [ ] Verify `lang: ar` returns Arabic via REST
