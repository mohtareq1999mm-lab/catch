# Endpoint Name

Warehouses – WMS Permissions & Roles Catalog (`GET /api/v1/admin/warehouses/permissions`)

# Description

Returns the comprehensive catalog of all Warehouse Management System (WMS) permissions categorized by operational module. For each permission, it provides its system key, human-readable localized label, and a boolean flag (`granted`) indicating whether the currently authenticated user holds that permission.

It also returns standard WMS role templates (`picker`, `packer`, `warehouse-supervisor`, `warehouse-manager`), a flat list of the caller's granted WMS permissions (`my_wms_permissions`), and the total number of distinct WMS permissions in the platform. This endpoint is primarily consumed by frontend/mobile admin terminals to dynamically enable UI tabs, buttons, scanners, and access-controlled features.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/warehouses/permissions` |
| Path composition | `api/v1` prefix + `admin/warehouses` prefix group + `permissions` route in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\WarehouseController@permissions(Request $request)` |
| Service | `App\Services\Warehouse\WarehouseDashboardService@getPermissions` |
| Authentication | Required (`auth:sanctum`). Bearer token in the `Authorization` header |
| Permission | Authenticated admin/staff (no specialized Spatie permission required on the route pipe) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en|ar` (default `en`; selects response message and permission label locale) |
| Supported request formats | Query string only; no request body |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.warehouses.permissions` |

## Request inputs

No path parameters, query parameters, or request body fields are required.

## Request example

```http
GET /api/v1/admin/warehouses/permissions HTTP/1.1
Host: api.example.com
Authorization: Bearer 1|abcdef123456
Accept: application/json
lang: ar
```

---

# Responses

## Success: Warehouse permissions fetched — HTTP 200

Returned via `ApiResponse::apiResponse(__('wms.warehouse.permissions_fetched'), 200, true, $data)`.

| Property | Value |
| --- | --- |
| HTTP status | `200` |
| Content-Type | `application/json` |
| Envelope | `{status: number, message: string, success: boolean, data: object}` |
| `status` | Always present. Integer `200` |
| `message` | Always present. String. Translated `wms.warehouse.permissions_fetched`: `en` → `"Warehouse permissions fetched successfully."`, `ar` → `"تم جلب صلاحيات المستودعات بنجاح."` |
| `success` | Always present. Boolean `true` |
| `data` | Object containing categorized modules, roles, and user permissions |

### `data` object structure

| Key | Type | Description |
| :--- | :--- | :--- |
| `modules` | array of objects | WMS operational modules (`warehouses`, `locations`, `fulfillments`, `picking_and_batches`, `packing_and_packages`, `shipments`, `inventory`, `workflow`, `barcode`). Each contains module name and array of permissions with `key`, `label`, and `granted`. |
| `roles` | object | Standard role configurations and their assigned permissions (`picker`, `packer`, `warehouse-supervisor`, `warehouse-manager`). |
| `my_wms_permissions` | array of strings | Flat list of permission keys currently granted to the authenticated user. Super admins receive all distinct WMS keys. |
| `total_wms_permissions` | integer | Total count of unique WMS permissions managed in the catalog. |

### Success response example

```json
{
  "status": 200,
  "message": "Warehouse permissions fetched successfully.",
  "success": true,
  "data": {
    "modules": [
      {
        "module": "warehouses",
        "name": "إدارة المستودعات (Warehouse Management)",
        "permissions": [
          {
            "key": "warehouses.index",
            "label": "عرض قائمة المستودعات",
            "granted": true
          },
          {
            "key": "warehouses.show",
            "label": "عرض تفاصيل المستودع",
            "granted": true
          },
          {
            "key": "warehouses.create",
            "label": "إنشاء مستودع",
            "granted": false
          }
        ]
      },
      {
        "module": "locations",
        "name": "المواقع والأرفف (Locations & Bins)",
        "permissions": [
          {
            "key": "locations.index",
            "label": "عرض المواقع والأرفف",
            "granted": true
          },
          {
            "key": "locations.check_occupancy",
            "label": "فحص إشغال المواقع",
            "granted": true
          }
        ]
      },
      {
        "module": "picking_and_batches",
        "name": "الالتقاط والباتشات (Picking & Batches)",
        "permissions": [
          {
            "key": "picking.claim",
            "label": "حجز مهمة التقاط",
            "granted": true
          },
          {
            "key": "picking-execute",
            "label": "تنفيذ التقاط المنتجات",
            "granted": true
          }
        ]
      },
      {
        "module": "barcode",
        "name": "مسح الباركود (Barcode Scanning)",
        "permissions": [
          {
            "key": "barcode.scan",
            "label": "مسح وقراءة الباركود",
            "granted": true
          }
        ]
      }
    ],
    "roles": {
      "picker": {
        "role": "picker",
        "name": "عامل التقاط (Picker)",
        "permissions": [
          "view-warehouse",
          "view-location",
          "view-fulfillment",
          "picking-execute",
          "picking.claim",
          "barcode.scan"
        ]
      },
      "packer": {
        "role": "packer",
        "name": "عامل تغليف (Packer)",
        "permissions": [
          "view-warehouse",
          "view-location",
          "view-fulfillment",
          "packing-execute",
          "packing.complete",
          "barcode.scan"
        ]
      },
      "warehouse-supervisor": {
        "role": "warehouse-supervisor",
        "name": "مشرف مستودع (Supervisor)",
        "permissions": [
          "view-warehouse",
          "view-location",
          "view-fulfillment",
          "manage-fulfillment",
          "picking-execute",
          "packing-execute",
          "fulfillment-override",
          "fulfillment.create",
          "fulfillment.cancel",
          "picking.claim",
          "picking.complete",
          "packing.complete",
          "batch.manage",
          "batch.operate",
          "order.cancel-during-fulfillment",
          "barcode.scan"
        ]
      },
      "warehouse-manager": {
        "role": "warehouse-manager",
        "name": "مدير مستودع (Manager)",
        "permissions": [
          "view-warehouse",
          "manage-warehouse",
          "view-location",
          "manage-location",
          "view-fulfillment",
          "manage-fulfillment",
          "picking-execute",
          "packing-execute",
          "fulfillment-override",
          "inventory-adjust",
          "manage-warehouse-workflow",
          "fulfillment.create",
          "fulfillment.cancel",
          "picking.claim",
          "picking.complete",
          "packing.complete",
          "batch.manage",
          "batch.operate",
          "order.cancel-during-fulfillment",
          "barcode.scan"
        ]
      }
    },
    "my_wms_permissions": [
      "warehouses.index",
      "warehouses.show",
      "locations.index",
      "locations.check_occupancy",
      "picking.claim",
      "picking-execute",
      "barcode.scan"
    ],
    "total_wms_permissions": 68
  }
}
```

---

# Errors

| HTTP status | Trigger | Response body | Message |
| :--- | :--- | :--- | :--- |
| 401 | Missing or invalid Bearer token | `{"message": "Unauthenticated."}` | `"Unauthenticated."` |
| 429 | Exceeded admin rate limit (> 400 req/min) | `{"success": false, "message": "Too many requests. Please try again later."}` | Rate limit error |
