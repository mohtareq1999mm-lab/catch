# Governorate Module — Frontend Integration Guide

---

### GET /api/v1/general/governorates — List Governorates (Public)

**Purpose:** Fetch active governorates for rendering a dropdown selector during checkout delivery form.

**Authentication:** None (public)

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "country_id": 1,
      "name": "Cairo",
      "status": true,
      "is_fast_shipping_enabled": true,
      "created_at": "2025-01-01T00:00:00+00:00"
    }
  ]
}
```

**Fields for Dropdown:**
| Field | Type | Description |
|-------|------|-------------|
| id | int | Value to send as `governorate_id` in checkout |
| name | string | Display text for dropdown option |

---

## Frontend Usage

### Checkout Delivery Address Form

1. Call `GET /api/v1/general/governorates` when checkout page loads
2. Populate a `<select>` dropdown with `{value: id, label: name}`
3. On form submit, send selected `governorate_id` in the checkout POST body

### State Handling

| State | Behavior |
|-------|----------|
| **Loading** | Disabled dropdown with "Loading governorates..." placeholder |
| **Empty** | Hide delivery option or show "No governorates available" |
| **Error** | Hide dropdown, show "Could not load governorates" message, allow retry |
| **Success** | Enable dropdown with governorate options |

### Example Checkout Request (Delivery)

```json
{
  "name": "John Doe",
  "user_phone": "+201234567890",
  "user_email": "john@example.com",
  "address": { "street": "12 Main St", "building": "5" },
  "fulfillment_type": "delivery",
  "payment_method": "cod",
  "governorate_id": 1
}
```
