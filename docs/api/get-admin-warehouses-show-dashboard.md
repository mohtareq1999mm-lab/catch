# Endpoint Name

Warehouses – Warehouse Dashboard by ID (`GET /api/v1/admin/warehouses/{id}/dashboard`)

# Description

Returns a real-time, comprehensive operational metrics snapshot for a specific warehouse identified by its numeric `{id}`. 

### Security & Tenancy Isolation:
The endpoint evaluates object-level warehouse access using `authorizeWarehouseScope($warehouse->id, 'view-warehouse', asNotFound: TRUE)`:
- Users with global management capability (`manage-warehouse`) or assigned to the target warehouse can view the dashboard.
- Staff assigned to other warehouses attempting to inspect this warehouse's dashboard receive a masked HTTP `404 Resource Not Found` (anti-enumeration protection).

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/warehouses/{id}/dashboard` |
| Path composition | `api/v1` prefix + `admin/warehouses` prefix group + `{id}/dashboard` route in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\WarehouseController@dashboard(Request $request, int $id)` |
| Service | `App\Services\Warehouse\WarehouseDashboardService@getDashboard` |
| Authentication | Required (`auth:sanctum`). Bearer token in the `Authorization` header |
| Permission | Required: `warehouses.show` on the route (`permission:warehouses.show`) + object-level scope via `authorizeWarehouseScope(warehouse_id, 'view-warehouse', asNotFound: TRUE)` |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en|ar` (default `en`; selects response message locale) |
| Supported request formats | Path parameter only; no request body |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user |
| Route name | `api.admin.warehouses.dashboard` |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Meaning |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | path | integer | Required | No | `whereNumber('id')` | Valid warehouse ID | None | Target warehouse ID |

## Request example

```http
GET /api/v1/admin/warehouses/3/dashboard HTTP/1.1
Host: api.example.com
Authorization: Bearer 1|abcdef123456
Accept: application/json
lang: ar
```

---

# Responses

## Success: Warehouse dashboard fetched — HTTP 200

Returned via `ApiResponse::apiResponse(__('wms.warehouse.dashboard_fetched'), 200, true, $data)`.

| Property | Value |
| --- | --- |
| HTTP status | `200` |
| Content-Type | `application/json` |
| Envelope | `{status: number, message: string, success: boolean, data: object}` |
| `status` | Always present. Integer `200` |
| `message` | Always present. String. Translated `wms.warehouse.dashboard_fetched`: `en` → `"Warehouse dashboard fetched successfully."`, `ar` → `"تم جلب لوحة بيانات المستودع بنجاح."` |
| `success` | Always present. Boolean `true` |
| `data` | Object containing comprehensive operational metrics |

### `data` object structure

| Key | Type | Description |
| :--- | :--- | :--- |
| `warehouse` | object | Warehouse basic details (`id`, `code`, `name`, `status`, `is_default`, `address`, `city`, `country`, `metadata`, `created_at`). |
| `locations` | object | Total storage bins, occupied bins (`quantity > 0`), available bins, occupancy rate percent, breakdown by status (`active`, `inactive`) and type (`storage`, `picking`, etc.). |
| `fulfillments` | object | Total orders in fulfillment and breakdown by status (`pending`, `picking`, `packing`, `ready_to_ship`, `shipped`, `cancelled`). |
| `picking_and_batches` | object | Total batch count, task counts, total units to pick, units picked, and picking progress percentage. |
| `packing_and_packages` | object | Packing stations total & active, packing tasks breakdown, and sealed/voided packages count. |
| `shipments` | object | Total shipments count and breakdown by status (`created`, `dispatched`, `delivered`, `cancelled`). |
| `inventory` | object | Placements with stock count, distinct SKUs placed, and total physical units in bins. |
| `worker_context` | object | Caller's operational context within this warehouse: `is_assigned`, assigned roles, and active claimed tasks. |
| `quick_actions` | object | Pre-built API endpoints scoped to this warehouse for barcode scan, batch next task, pending fulfillments, etc. |

### Success response example

```json
{
  "status": 200,
  "message": "Warehouse dashboard fetched successfully.",
  "success": true,
  "data": {
    "warehouse": {
      "id": 3,
      "code": "WH-ALX-01",
      "name": "Alexandria Regional Depot",
      "status": "active",
      "is_default": false,
      "address": "Borg El Arab Industrial Zone",
      "city": "Alexandria",
      "country": "Egypt",
      "metadata": null,
      "created_at": "2026-09-01T08:30:00.000000Z"
    },
    "locations": {
      "total": 60,
      "occupied": 22,
      "available": 38,
      "occupancy_rate_percent": 36.7,
      "by_status": {
        "active": 60,
        "inactive": 0
      },
      "by_type": {
        "storage": 45,
        "picking": 15
      }
    },
    "fulfillments": {
      "total": 9,
      "pending": 2,
      "picking": 3,
      "packing": 2,
      "ready_to_ship": 1,
      "shipped": 1,
      "cancelled": 0,
      "by_status": {
        "pending": 2,
        "picking": 3,
        "packing": 2,
        "ready_to_ship": 1,
        "shipped": 1
      }
    },
    "picking_and_batches": {
      "total_batches": 2,
      "batches_by_status": {
        "in_progress": 2
      },
      "total_tasks": 14,
      "tasks_by_status": {
        "pending": 4,
        "claimed": 2,
        "picking": 3,
        "completed": 5
      },
      "total_units_to_pick": 80.0,
      "total_units_picked": 25.0,
      "picking_progress_percent": 31.3
    },
    "packing_and_packages": {
      "stations_total": 2,
      "stations_active": 2,
      "total_tasks": 4,
      "tasks_by_status": {
        "assigned": 1,
        "in_progress": 1,
        "completed": 2
      },
      "total_packages": 3,
      "packages_by_status": {
        "sealed": 2,
        "open": 1
      }
    },
    "shipments": {
      "total": 2,
      "by_status": {
        "created": 1,
        "dispatched": 1
      }
    },
    "inventory": {
      "placements_with_stock": 22,
      "unique_products_count": 19,
      "total_physical_units": 610.0
    },
    "worker_context": {
      "user_id": 14,
      "user_name": "Ahmed Warehouse Worker",
      "is_assigned": true,
      "roles": [
        "picker"
      ],
      "active_picking_task": null,
      "active_packing_task": null
    },
    "quick_actions": {
      "barcode_scan": "/api/v1/admin/barcode/scan?warehouse_id=3",
      "batches_next_task": "/api/v1/admin/batches/next-task?warehouse_id=3",
      "pending_fulfillments": "/api/v1/admin/batches/pending-fulfillments?warehouse_id=3",
      "inventory_overview": "/api/v1/admin/inventory/overview?warehouse_id=3",
      "stock_receipt": "/api/v1/admin/locations/{location}/stock-receipts",
      "stock_transfer": "/api/v1/admin/stock-transfers"
    }
  }
}
```

---

# Errors

| HTTP status | Trigger | Response body | Message |
| :--- | :--- | :--- | :--- |
| 401 | Missing or expired token | `{"message": "Unauthenticated."}` | `"Unauthenticated."` |
| 403 | Missing `warehouses.show` permission | `{"message": "This action is unauthorized."}` | `"This action is unauthorized."` |
| 404 | Warehouse ID does not exist in the database | `{"message": "Resource Not Found"}` | `"Resource Not Found"` |
| 404 | Warehouse exists but the caller is scoped to another warehouse (anti-enumeration masking) | `{"message": "Resource Not Found"}` | Byte-identical to a non-existent warehouse |
