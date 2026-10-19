# Endpoint Name

Warehouses – Default / Home Warehouse Dashboard (`GET /api/v1/admin/warehouses/dashboard`)

# Description

Returns a real-time, comprehensive operational metrics snapshot for the default active warehouse or the caller's assigned home warehouse. This endpoint does not take an `{id}` in the path; instead, it automatically resolves:
1. The caller's assigned warehouse (`WarehouseAccess::warehouseIdFor(auth()->user())`).
2. If the user is unassigned, falls back to the system's active default warehouse (`Warehouse::where('is_default', true)->value('id')`).
3. If no default warehouse exists in the system, returns HTTP 404 (`wms.warehouse.no_default`).

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/warehouses/dashboard` |
| Path composition | `api/v1` prefix + `admin/warehouses` prefix group + `dashboard` route in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\WarehouseController@dashboard(Request $request)` (`$id` is null) |
| Service | `App\Services\Warehouse\WarehouseDashboardService@getDashboard` |
| Authentication | Required (`auth:sanctum`). Bearer token in the `Authorization` header |
| Permission | Required: `warehouses.show` on the route (`permission:warehouses.show`) + object-level scope via `authorizeWarehouseScope(warehouse_id, 'view-warehouse', asNotFound: TRUE)` |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en|ar` (default `en`; selects response message locale) |
| Supported request formats | Query string only; no request body |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user |
| Route name | `api.admin.warehouses.dashboard.default` |

## Request inputs

No path parameters, query parameters, or request body fields are required.

## Request example

```http
GET /api/v1/admin/warehouses/dashboard HTTP/1.1
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
| `worker_context` | object | Caller's operational context: `is_assigned`, assigned roles (`picker`, `packer`, etc.), and active claimed tasks (`active_picking_task`, `active_packing_task`). |
| `quick_actions` | object | Pre-built API endpoints scoped to this warehouse for barcode scan, batch next task, pending fulfillments, etc. |

### Success response example

```json
{
  "status": 200,
  "message": "Warehouse dashboard fetched successfully.",
  "success": true,
  "data": {
    "warehouse": {
      "id": 1,
      "code": "WH-CAI-01",
      "name": "Main Cairo Fulfillment Center",
      "status": "active",
      "is_default": true,
      "address": "Industrial Zone, Block 4",
      "city": "Cairo",
      "country": "Egypt",
      "metadata": null,
      "created_at": "2026-08-20T10:00:00.000000Z"
    },
    "locations": {
      "total": 120,
      "occupied": 45,
      "available": 75,
      "occupancy_rate_percent": 37.5,
      "by_status": {
        "active": 115,
        "inactive": 5
      },
      "by_type": {
        "storage": 90,
        "picking": 30
      }
    },
    "fulfillments": {
      "total": 18,
      "pending": 4,
      "picking": 6,
      "packing": 5,
      "ready_to_ship": 2,
      "shipped": 1,
      "cancelled": 0,
      "by_status": {
        "pending": 4,
        "picking": 6,
        "packing": 5,
        "ready_to_ship": 2,
        "shipped": 1
      }
    },
    "picking_and_batches": {
      "total_batches": 3,
      "batches_by_status": {
        "in_progress": 2,
        "completed": 1
      },
      "total_tasks": 24,
      "tasks_by_status": {
        "pending": 8,
        "claimed": 4,
        "picking": 6,
        "completed": 6
      },
      "total_units_to_pick": 150.0,
      "total_units_picked": 45.0,
      "picking_progress_percent": 30.0
    },
    "packing_and_packages": {
      "stations_total": 4,
      "stations_active": 3,
      "total_tasks": 10,
      "tasks_by_status": {
        "assigned": 3,
        "in_progress": 2,
        "completed": 5
      },
      "total_packages": 7,
      "packages_by_status": {
        "sealed": 5,
        "open": 2
      }
    },
    "shipments": {
      "total": 5,
      "by_status": {
        "created": 2,
        "dispatched": 2,
        "delivered": 1
      }
    },
    "inventory": {
      "placements_with_stock": 45,
      "unique_products_count": 38,
      "total_physical_units": 1420.0
    },
    "worker_context": {
      "user_id": 14,
      "user_name": "Ahmed Warehouse Worker",
      "is_assigned": true,
      "roles": [
        "picker"
      ],
      "active_picking_task": {
        "id": 102,
        "batch_id": 15,
        "status": "picking",
        "quantity_to_pick": 5.0,
        "quantity_picked": 2.0,
        "product_location_id": 88
      },
      "active_packing_task": null
    },
    "quick_actions": {
      "barcode_scan": "/api/v1/admin/barcode/scan?warehouse_id=1",
      "batches_next_task": "/api/v1/admin/batches/next-task?warehouse_id=1",
      "pending_fulfillments": "/api/v1/admin/batches/pending-fulfillments?warehouse_id=1",
      "inventory_overview": "/api/v1/admin/inventory/overview?warehouse_id=1",
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
| 404 | User has no assigned warehouse and no default warehouse is configured in the system | `{"message": "No default warehouse configured"}` | Translated `wms.warehouse.no_default` |
| 404 | Auto-resolved warehouse access is denied by warehouse tenancy scope | `{"message": "Resource Not Found"}` | Anti-enumeration masking |
