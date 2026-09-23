# COUPON — PUSHER PAYLOADS (frontend reference)

Channel: `users.{userId}` (private, owner only).
Transport: Laravel `broadcast` channel; payload = `toDatabase()` object verbatim.

---

## Event: `coupon.assigned`

```json
{
  "title": { "en": "...", "ar": "..." },
  "message": { "en": "... SAVE20 ...", "ar": "... SAVE20 ..." },
  "icon": "tag",
  "resource_type": "coupon",
  "resource_id": 123,
  "action_url": "/coupons/123",
  "coupon_assignment_id": 456,
  "coupon_id": 123,
  "coupon_code": "SAVE20",
  "max_uses": 2,
  "expires_at": "2026-09-30T23:59:59+00:00"
}
```

`expires_at` is null when the grant never expires. `action_url` is frontend-relative — prefix with the frontend host.

---

## Event: `coupon.eligible`

```json
{
  "title": { "en": "...", "ar": "..." },
  "message": { "en": "...", "ar": "..." },
  "icon": "tag",
  "resource_type": "coupon",
  "resource_id": 123,
  "action_url": "/coupons/123",
  "coupon_id": 123,
  "tree_hash": "abc123...",
  "run_id": 42
}
```

No `coupon_code` by design. Frontend must still POST claim/apply, then read the code from `GET general/coupons/mine`.

---

## Event: `coupon.used`

```json
{
  "title": { "en": "...", "ar": "..." },
  "message": { "en": "... SAVE20 ...", "ar": "... SAVE20 ..." },
  "icon": "tag",
  "resource_type": "coupon",
  "resource_id": 123,
  "action_url": "/coupons/123",
  "coupon_id": 123,
  "coupon_code": "SAVE20",
  "order_id": 987,
  "remaining_uses": 1,
  "consumed_at": "2026-09-23T10:00:00+00:00"
}
```

---

## Event: `coupon.available`

```json
{
  "title": { "en": "...", "ar": "..." },
  "message": { "en": "... SAVE20 ...", "ar": "... SAVE20 ..." },
  "icon": "tag",
  "resource_type": "coupon",
  "resource_id": 123,
  "action_url": "/coupons/123",
  "coupon_id": 123,
  "coupon_code": "SAVE20",
  "coupon_type": null
}
```

`coupon_type` is always null (stale field).
