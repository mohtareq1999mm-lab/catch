# Endpoint Name

Locations – Check Occupancy (`POST /api/v1/admin/locations/check-occupancy`)

# Description

Audits and categorizes a requested set of location IDs into `empty` bins, `occupied` bins (including product and variant placements, SKUs, quantities, and attributes), and `not_found` IDs.

Enforces warehouse-level authorization (non-global managers are scoped to their home warehouse). Bins with zero live stock (`quantity <= 0`) are categorized as `empty`. Bins with active inventory (`quantity > 0` or `allocated_hint > 0`) are categorized as `occupied` with their full product and variant breakdown.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/locations/check-occupancy` |
| Path composition | `api/v1` prefix + `admin/locations/check-occupancy` from `packages/marvel/src/Rest/Routes.php` (`Route::prefix('admin/locations')` group with middleware `['api', 'auth:sanctum', 'throttle:admin']` and route-level `permission:view-location`) |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\LocationController@checkOccupancy(CheckLocationOccupancyRequest $request, LocationOccupancyService $occupancyService)` |
| Service | `App\Services\Fulfillment\LocationOccupancyService::checkOccupancy` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | `view-location` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en|ar` (default `en`) |
| Rate limit | `throttle:admin` (400 requests/minute) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `location_ids` | body | array | Required (unless `locations` provided) | No | `required_without:locations\|array\|min:1` | Non-empty array of integer location IDs | None | `[10, 11, 12]` | Array of location IDs to check |
| `locations` | body | array | Optional alias | No | `required_without:location_ids\|array\|min:1` | Non-empty array of integer location IDs | None | `[10, 11, 12]` | Alternative alias for `location_ids` |
| `warehouse_id` | body | integer | Optional | Yes | `nullable\|integer\|exists:warehouses,id` | Existing warehouse ID | None | `1` | Warehouse scope filter |

## Request example

```json
{
  "location_ids": [10, 11, 999],
  "warehouse_id": 1
}
```

# Response

## Response metadata

| Property | Value |
| --- | --- |
| HTTP status | `200 OK` |
| Response format | `application/json` |
| Envelope | `ApiResponse::apiResponse` (`{success: bool, message: string, data: object}`) |
| Localized message key | `wms.location.occupancy_checked` (`en`: `"Locations occupancy checked successfully."`, `ar`: `"تم فحص إشغال المواقع بنجاح."`) |

## Success response example

```json
{
  "success": true,
  "message": "Locations occupancy checked successfully.",
  "data": {
    "empty": [
      {
        "id": 10,
        "warehouse_id": 1,
        "code": "A-01-01",
        "barcode": "LOC-A0101",
        "name": "Bin A-01-01",
        "type": "picking",
        "status": "active"
      }
    ],
    "occupied": [
      {
        "id": 11,
        "warehouse_id": 1,
        "code": "A-01-02",
        "barcode": "LOC-A0102",
        "name": "Bin A-01-02",
        "type": "storage",
        "status": "active",
        "total_quantity": 15.0,
        "products_count": 1,
        "products": [
          {
            "placement_id": 45,
            "product_id": 12,
            "product_name": "Cotton T-Shirt",
            "product_slug": "cotton-t-shirt",
            "product_sku": "SKU-TSHIRT",
            "quantity": 15.0,
            "allocated_hint": 2.0,
            "available_quantity": 13.0,
            "has_variant": true,
            "variant": {
              "id": 88,
              "sku": "VAR-TSHIRT-RED-L",
              "price": 60.0,
              "sale_price": 55.0,
              "stock_quantity": 30,
              "attributes": [
                {
                  "name": "Color",
                  "value": "Red",
                  "attribute_id": 1,
                  "attribute_value_id": 5
                }
              ]
            }
          }
        ]
      }
    ],
    "not_found": [999]
  }
}
```

# Errors

| HTTP status | Trigger | Response body | Message |
| --- | --- | --- | --- |
| 401 | Missing or invalid authentication token | `{message: string, status: boolean}` | `"Unauthenticated"` |
| 403 | Missing `view-location` permission | `{message: string, status: boolean}` | `"User does not have the required permissions."` |
| 404 | Caller requests a warehouse outside their authorized scope | `{message: string, status: boolean}` | `"Resource Not Found"` |
| 422 | Missing `location_ids` / `locations` array or empty array | `{message: string, status: boolean, errors: object}` | Validation error message |
