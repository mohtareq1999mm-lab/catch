# API Reference — Governorate Module (Public)

---

### GET /api/v1/general/governorates

List active governorates. Only returns governorates where `status = true`, ordered by `id` DESC.

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
    },
    {
      "id": 2,
      "country_id": 1,
      "name": "Alexandria",
      "status": true,
      "is_fast_shipping_enabled": false,
      "created_at": "2025-01-01T00:00:00+00:00"
    }
  ]
}
```

**Quick Test:**
```bash
curl -X GET "http://example.com/api/v1/general/governorates" \
  -H "Accept: application/json"
```

**Business Rules:**
- Only active (`status = true`) governorates are returned
- Ordered by `id` DESC
- `name` is translatable — returns the value in the current application locale
- No pagination — all active governorates returned at once (small dataset)
