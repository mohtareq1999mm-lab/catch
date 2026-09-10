# Shipment Module — Frontend Integration Guide

## Endpoints

---

### 1. GET /api/v1/shipments — List Shipments (Admin)

**Purpose:** Display shipment list with filtering and pagination.

**Authentication:** Required (Sanctum)

**Permission:** `view-shipments`

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 15 | Items per page (max 100) |
| order_id | int | - | Filter by order ID |
| status | string | - | Filter by status |
| courier | string | - | Filter by courier |
| tracking_number | string | - | Search tracking number |
| from | date | - | Start date |
| to | date | - | End date |

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "uuid": "550e8400-...",
        "order_id": 101,
        "tracking_number": "TRK-001",
        "courier": "DHL",
        "status": "in_transit",
        "shipping_method": "standard",
        "shipping_cost": 50.000,
        "currency": "EGP",
        "origin_address": { "city": "Cairo" },
        "destination_address": { "city": "Alexandria" },
        "total_weight": 1.500,
        "weight_unit": "kg",
        "shipped_at": "2026-07-28T10:00:00Z",
        "estimated_delivery_at": "2026-07-30T10:00:00Z",
        "delivered_at": null,
        "order": { "id": 101 },
        "created_at": "2026-07-28T09:00:00Z",
        "updated_at": "2026-07-28T10:00:00Z"
      }
    ],
    "current_page": 1,
    "total": 25
  }
}
```

---

### 2. POST /api/v1/shipments — Create Shipment (Admin)

**Purpose:** Create a new shipment for an order.

**Authentication:** Required (Sanctum)

**Permission:** `create-shipment`

**Request:**
```json
{
  "order_id": 101,
  "courier": "DHL",
  "shipping_method": "standard",
  "shipping_cost": 50.00,
  "currency": "EGP",
  "origin_address": { "city": "Cairo", "country": "Egypt" },
  "destination_address": { "city": "Alexandria", "country": "Egypt" },
  "items": [ { "product_id": 1, "quantity": 2 } ],
  "total_weight": 1.5,
  "weight_unit": "kg",
  "estimated_delivery_at": "2026-07-30",
  "notes": "Handle with care"
}
```

**Response (201):**
```json
{
  "status": 201,
  "message": "Shipment created successfully",
  "success": true,
  "data": { "id": 1, "uuid": "...", "order_id": 101, "status": "pending", ... }
}
```

---

### 3. GET /api/v1/shipments/{id} — Show Shipment (Admin)

**Purpose:** Get single shipment details by ID.

**Authentication:** Required (Sanctum)

**Permission:** `view-shipment`

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": { ...full shipment object... }
}
```

---

### 4. GET /api/v1/shipments/uuid/{uuid} — Show Shipment by UUID (Admin)

**Purpose:** Get single shipment details by UUID (public-safe identifier).

**Authentication:** Required (Sanctum)

**Permission:** `view-shipment`

---

### 5. PUT /api/v1/shipments/{id} — Update Shipment (Admin)

**Purpose:** Update shipment details (courier, tracking, addresses, etc.).

**Authentication:** Required (Sanctum)

**Permission:** `update-shipment`

**Request:**
```json
{
  "courier": "FedEx",
  "tracking_number": "FX-20260728-001",
  "estimated_delivery_at": "2026-08-01"
}
```

**Response (200):**
```json
{
  "status": 200,
  "message": "Shipment updated successfully",
  "success": true,
  "data": { ...updated shipment... }
}
```

---

### 6. PUT /api/v1/shipments/{id}/status — Update Shipment Status (Admin)

**Purpose:** Transition shipment through its status state machine.

**Authentication:** Required (Sanctum)

**Permission:** `update-shipment`

**Request:**
```json
{
  "status": "in_transit",
  "notes": "Package picked up by courier"
}
```

**Valid Status Values:**
- `pending` → `label_created`, `cancelled`
- `label_created` → `picked_up`, `cancelled`
- `picked_up` → `in_transit`, `cancelled`
- `in_transit` → `out_for_delivery`, `delayed`
- `out_for_delivery` → `delivered`, `failed_delivery`
- `delivered` → *(terminal)*
- `failed_delivery` → `out_for_delivery`, `returned`
- `returned` → *(terminal)*
- `delayed` → `in_transit`, `out_for_delivery`
- `cancelled` → *(terminal)*

**Response (200):**
```json
{
  "status": 200,
  "message": "Shipment status updated",
  "success": true,
  "data": { ...updated shipment with new status... }
}
```

**Response (422) - Invalid Transition:**
```json
{
  "status": 422,
  "message": "Shipment 1 cannot transition from 'pending' to 'delivered'",
  "success": false
}
```

---

## Frontend Usage

```javascript
export const shipmentApi = {
  list(params)                          // GET /api/v1/shipments
  get(id)                              // GET /api/v1/shipments/{id}
  getByUuid(uuid)                      // GET /api/v1/shipments/uuid/{uuid}
  create(data)                         // POST /api/v1/shipments
  update(id, data)                     // PUT /api/v1/shipments/{id}
  updateStatus(id, status, notes)      // PUT /api/v1/shipments/{id}/status
}
```

## Status Display

| Status | Display Label | Color |
|--------|---------------|-------|
| pending | Pending | Gray |
| label_created | Label Created | Blue |
| picked_up | Picked Up | Indigo |
| in_transit | In Transit | Cyan |
| out_for_delivery | Out for Delivery | Purple |
| delivered | Delivered | Green |
| failed_delivery | Failed Delivery | Red |
| returned | Returned | Orange |
| delayed | Delayed | Yellow |
| cancelled | Cancelled | Gray/Dark |

## Key Considerations

1. **No delete endpoint** — Shipments cannot be deleted via API; they can only be cancelled (status transition)
2. **Status transitions** — The frontend should only show valid next-status buttons based on the current status
3. **No resource transformation** — All model fields are returned directly (no computed fields)
4. **UUID lookup** — Use `/uuid/{uuid}` for public-safe references (e.g., customer tracking page)
5. **Order reference** — Each shipment links to an order; the `order` relation is always eager loaded
6. **Hardcoded messages** — Response messages are in English only (no translation support)
