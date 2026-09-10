# API Reference — Shipment

---

### GET /api/v1/shipments

Paginated list of shipments with filtering.

**Authentication**: `auth:sanctum`, permission: `view-shipments`

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 15 | Items per page (max 100) |
| order_id | int | - | Filter by order ID |
| status | string | - | Filter by status (pending, label_created, picked_up, in_transit, out_for_delivery, delivered, failed_delivery, returned, delayed, cancelled) |
| courier | string | - | Filter by courier name |
| tracking_number | string | - | Search by tracking number (LIKE) |
| from | date | - | Filter by created_at >= from |
| to | date | - | Filter by created_at <= to |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "uuid": "550e8400-e29b-41d4-a716-446655440000",
        "order_id": 101,
        "tracking_number": "TRK-20260728-0001",
        "courier": "DHL",
        "status": "in_transit",
        "shipping_method": "standard",
        "shipping_cost": 50.000,
        "currency": "EGP",
        "origin_address": { "city": "Cairo", "country": "Egypt" },
        "destination_address": { "city": "Alexandria", "country": "Egypt" },
        "items": [ { "product_id": 1, "quantity": 2 } ],
        "total_weight": 1.500,
        "weight_unit": "kg",
        "shipped_at": "2026-07-28T10:00:00Z",
        "estimated_delivery_at": "2026-07-30T10:00:00Z",
        "delivered_at": null,
        "notes": "Handle with care",
        "metadata": null,
        "order": { "id": 101 },
        "created_at": "2026-07-28T09:00:00Z",
        "updated_at": "2026-07-28T10:00:00Z"
      }
    ],
    "current_page": 1,
    "from": 1,
    "to": 15,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

**Quick Test**:
```bash
# List all shipments (page 1, 15 per page)
curl -X GET "http://example.com/api/v1/shipments?page=1&limit=15" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Filter by status and courier
curl -X GET "http://example.com/api/v1/shipments?status=in_transit&courier=DHL" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Filter by date range
curl -X GET "http://example.com/api/v1/shipments?from=2026-07-01&to=2026-07-28" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Results are ordered by `created_at desc`
- Pagination is capped at `min(perPage, 100)` to prevent large page sizes
- Tracking number search uses LIKE containment (substring match)
- Order relationship is always eager loaded

---

### POST /api/v1/shipments

Create a new shipment for an order.

**Authentication**: `auth:sanctum`, permission: `create-shipment`

**Request Body** (JSON):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| order_id | int | required | Valid order ID (exists:orders,id) |
| tracking_number | string | sometimes | Unique tracking number (max 100) |
| courier | string | sometimes | Courier name (max 50) |
| shipping_method | string | sometimes | Method name (max 30) |
| shipping_cost | numeric | sometimes | Cost >= 0 |
| currency | string | sometimes | 3-letter code (max 3) |
| origin_address | object | sometimes | JSON address data |
| destination_address | object | sometimes | JSON address data |
| items | array | sometimes | Array of shipment items |
| total_weight | numeric | sometimes | Weight >= 0 |
| weight_unit | string | sometimes | Unit (max 10) |
| estimated_delivery_at | date | sometimes | Estimated delivery date |
| notes | string | sometimes | Notes (max 2000) |
| metadata | object | sometimes | Arbitrary JSON metadata |

**Validation Rules**:
| Field | Rules |
|-------|-------|
| order_id | required, integer, exists:orders,id |
| tracking_number | nullable, string, max:100, unique:shipments,tracking_number |
| courier | nullable, string, max:50 |
| shipping_method | nullable, string, max:30 |
| shipping_cost | nullable, numeric, min:0 |
| currency | nullable, string, max:3 |
| origin_address | nullable, array |
| destination_address | nullable, array |
| items | nullable, array |
| total_weight | nullable, numeric, min:0 |
| weight_unit | nullable, string, max:10 |
| estimated_delivery_at | nullable, date |
| notes | nullable, string, max:2000 |
| metadata | nullable, array |

**Response 201**:
```json
{
  "status": 201,
  "message": "Shipment created successfully",
  "success": true,
  "data": {
    "id": 1,
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "order_id": 101,
    "status": "pending",
    "tracking_number": null,
    "courier": null,
    "created_at": "2026-07-28T09:00:00Z"
  }
}
```

**Response 422** (validation):
```json
{
  "status": 422,
  "message": "The given data was invalid.",
  "success": false,
  "errors": {
    "order_id": ["The selected order id is invalid."],
    "tracking_number": ["The tracking number has already been taken."]
  }
}
```

**Quick Test**:
```bash
# Create a basic shipment
curl -X POST "http://example.com/api/v1/shipments" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"order_id": 101, "courier": "DHL", "shipping_cost": 50, "currency": "EGP"}'
```

**Business Rules**:
- Status is auto-set to `pending` on creation
- UUID is auto-generated via `Str::orderedUuid()` in model boot
- Uses database transaction for atomicity
- Order ID must reference an existing order (`restrictOnDelete` — cannot delete orders with shipments)

---

### GET /api/v1/shipments/{id}

Get a single shipment by primary ID.

**Authentication**: `auth:sanctum`, permission: `view-shipment`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Shipment ID |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "order_id": 101,
    "tracking_number": "TRK-001",
    "courier": "DHL",
    "status": "in_transit",
    "shipping_method": "standard",
    "shipping_cost": 50.000,
    "currency": "EGP",
    "origin_address": { "city": "Cairo" },
    "destination_address": { "city": "Alex" },
    "items": [ { "product_id": 1, "quantity": 1 } ],
    "total_weight": 0.500,
    "weight_unit": "kg",
    "shipped_at": "2026-07-28T10:00:00Z",
    "estimated_delivery_at": "2026-07-30T10:00:00Z",
    "delivered_at": null,
    "notes": null,
    "metadata": null,
    "order": { "id": 101 },
    "created_at": "2026-07-28T09:00:00Z",
    "updated_at": "2026-07-28T10:00:00Z"
  }
}
```

**Response 404**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": null
}
```

> **Note:** The current implementation does not catch `ModelNotFoundException` — the response format for 404 may be a Laravel exception page instead of the standard API response.

**Quick Test**:
```bash
# Get shipment by ID
curl -X GET "http://example.com/api/v1/shipments/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Eager loads the `order` relationship
- Uses `findOrFail()` — throws 404 if not found
- UUID-based lookup is available via `/uuid/{uuid}` endpoint

---

### GET /api/v1/shipments/uuid/{uuid}

Get a single shipment by UUID (public-safe identifier).

**Authentication**: `auth:sanctum`, permission: `view-shipment`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| uuid | string | Shipment UUID |

**Response 200**: Same structure as GET by ID.

**Response 404**: Same as GET by ID.

**Quick Test**:
```bash
# Get shipment by UUID
curl -X GET "http://example.com/api/v1/shipments/uuid/550e8400-e29b-41d4-a716-446655440000" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

---

### PUT /api/v1/shipments/{id}

Update an existing shipment's details.

**Authentication**: `auth:sanctum`, permission: `update-shipment`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Shipment ID |

**Request Body** (JSON):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| tracking_number | string | sometimes | Unique tracking number (ignores own ID) |
| courier | string | sometimes | Courier name (max 50) |
| shipping_method | string | sometimes | Method name (max 30) |
| shipping_cost | numeric | sometimes | Cost >= 0 |
| currency | string | sometimes | 3-letter code (max 3) |
| origin_address | object | sometimes | JSON address data |
| destination_address | object | sometimes | JSON address data |
| items | array | sometimes | Array of shipment items |
| total_weight | numeric | sometimes | Weight >= 0 |
| weight_unit | string | sometimes | Unit (max 10) |
| estimated_delivery_at | date | sometimes | Estimated delivery date |
| notes | string | sometimes | Notes (max 2000) |
| metadata | object | sometimes | Arbitrary JSON metadata |

**Response 200**:
```json
{
  "status": 200,
  "message": "Shipment updated successfully",
  "success": true,
  "data": { "id": 1, ...updated fields... }
}
```

**Quick Test**:
```bash
# Update courier and tracking number
curl -X PUT "http://example.com/api/v1/shipments/1" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"courier": "FedEx", "tracking_number": "FX-20260728-001"}'

# Update estimated delivery
curl -X PUT "http://example.com/api/v1/shipments/1" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"estimated_delivery_at": "2026-08-01"}'
```

**Business Rules**:
- All fields are optional on update
- Tracking number uniqueness ignores the current shipment's own tracking number
- Unlike `create()`, the `update()` method does NOT wrap in a transaction
- Status cannot be changed via this endpoint — use `PUT {id}/status` instead

---

### PUT /api/v1/shipments/{id}/status

Update shipment status with state machine validation.

**Authentication**: `auth:sanctum`, permission: `update-shipment`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Shipment ID |

**Request Body**:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| status | string | required | Must be a valid ShipmentStatus enum value |
| notes | string | sometimes | Status change notes (max 2000) |

**Valid Status Values**: `pending`, `label_created`, `picked_up`, `in_transit`, `out_for_delivery`, `delivered`, `failed_delivery`, `returned`, `delayed`, `cancelled`

**Status State Machine**:
```
pending → label_created, cancelled
label_created → picked_up, cancelled
picked_up → in_transit, cancelled
in_transit → out_for_delivery, delayed
out_for_delivery → delivered, failed_delivery
delivered → (terminal)
failed_delivery → out_for_delivery, returned
returned → (terminal)
delayed → in_transit, out_for_delivery
cancelled → (terminal)
```

**Response 200**:
```json
{
  "status": 200,
  "message": "Shipment status updated",
  "success": true,
  "data": {
    "id": 1,
    "status": "delivered",
    "delivered_at": "2026-07-28T12:00:00Z",
    "shipped_at": "2026-07-27T10:00:00Z"
  }
}
```

**Response 422** (invalid transition):
```json
{
  "status": 422,
  "message": "Shipment 1 cannot transition from 'pending' to 'delivered'",
  "success": false
}
```

**Quick Test**:
```bash
# Mark shipment as in_transit
curl -X PUT "http://example.com/api/v1/shipments/1/status" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"status": "in_transit"}'

# Mark as delivered with notes
curl -X PUT "http://example.com/api/v1/shipments/1/status" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"status": "delivered", "notes": "Signed by customer"}'
```

**Business Rules**:
- Ships must follow the valid state machine transitions above
- Invalid transitions return 422 with a descriptive error message
- Setting status to `shipped` or `picked_up` auto-sets `shipped_at` (preserves existing if already set)
- Setting status to `delivered` auto-sets `delivered_at` to current timestamp
- Uses `lockForUpdate` (pessimistic lock) inside a transaction to prevent race conditions
- Returns fresh model instance after update
