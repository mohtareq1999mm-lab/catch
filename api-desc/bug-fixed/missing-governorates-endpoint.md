# Missing Governorates Endpoint Blocking Delivery Checkout

**Date:** 2026-07-23

---

## What Happens To Frontend:

When a user selects "delivery" as fulfillment type on checkout, the form requires a `governorate_id` field. But there is no endpoint to fetch governorates to populate a dropdown.

You may have tried paths like `/general/governorates`, `/general/cities`, `/general/regions`, or `/general/locations` — all return 404.

Without a governorate dropdown, the user cannot complete a delivery order because `governorate_id` is validated as required and must exist in the governorates table.

---

## What Changed In The API

A new public endpoint has been added:

```
GET /api/v1/general/governorates
```

This returns all active governorates. No authentication required.

Response example:
```json
{
  "status": 200,
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Cairo",
      "country_id": 1,
      "status": true,
      "is_fast_shipping_enabled": true
    }
  ]
}
```

---

## What You Should Do

1. Call `GET /api/v1/general/governorates` when checkout page loads
2. Map `{id → governorate_id, name → display text}` into a dropdown
3. Send the selected `governorate_id` in the checkout POST body

Full docs: `api-desc/front/governorate/frontend.md`
