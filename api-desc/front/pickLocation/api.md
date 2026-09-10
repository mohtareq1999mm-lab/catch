# API Reference — Pickup Location Module (Public API)

---

### GET /api/v1/general/pickup-locations

List active pickup locations. Only returns locations where `status = true`, ordered by `display_order` then `id`.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Items per page |
| search | string | - | Search by store_name (LIKE %%) |
| page | int | 1 | Page number |

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "store_name": "Downtown Branch",
      "address": "123 Main St, City Center",
      "phone": "+1-555-0101",
      "email": "downtown@marvel.com",
      "latitude": "30.0444",
      "longitude": "31.2357",
      "working_hours": {
        "saturday": "09:00-21:00",
        "sunday": "09:00-21:00"
      },
      "status": true,
      "display_order": 1
    }
  ]
}
```

**Quick Test:**
```bash
curl -X GET "http://example.com/api/v1/general/pickup-locations" \
  -H "Accept: application/json"
```

---

### GET /api/v1/general/pickup-locations/{id}

Show a specific pickup location by ID. Returns 404 if not found or inactive.

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| id | integer | Pickup location ID |

**Response 200:** Single `PickupLocationResource` object.
**Response 404:** Not found or inactive.

**Quick Test:**
```bash
curl -X GET "http://example.com/api/v1/general/pickup-locations/1" \
  -H "Accept: application/json"
```

**Business Rules:**
- Only active (status=true) locations are returned
- Ordered by `display_order` ASC, then `id` ASC
- Soft-deleted locations are excluded
- `working_hours` is a free-form JSON object (no enforced schema)
- Coordinates (`latitude`, `longitude`) are stored as strings
