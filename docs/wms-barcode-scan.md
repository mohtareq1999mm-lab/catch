# WMS Barcode Scan

## Endpoint

| | |
|---|---|
| Method | `GET` |
| URL | `/api/v1/admin/barcode/scan` |
| Route name | `api.admin.barcode.scan.get` |
| Purpose | Resolves any scanned warehouse barcode (location, product/variant, or package) with heuristic detection and warehouse scope isolation |

Use it from handheld terminals (PDA), scanners, and admin interfaces to perform lightweight, fast lookup without modifying database state.

---

## Authentication & Authorization

| | |
|---|---|
| Auth | Required: `auth:sanctum` (Bearer token) |
| Permission | `barcode.scan` (`\Marvel\Enums\Permission::BARCODE_SCAN`) |
| Throttle | `throttle:admin` |
| Warehouse scope | Users without `manage-warehouse` can only query within their assigned home warehouse (`WarehouseAccess`). Cross-warehouse queries yield `404 Not Found` (anti-enumeration). |

---

## Query Parameters

| Parameter | Type | Required | Description |
|---|---|---|---|
| `code` | `string` | **Yes** | Scanned barcode string (`min:1`, `max:255`). E.g. `LOC-A-01`, `TSHIRT-BLK-L`, `PKG-88219`. |
| `type` | `string` | No | Scope type: `auto`, `location`, `product`, `variant`, `package`. Defaults to `auto`. |
| `warehouse_id` | `int` | No | Target warehouse ID. Must exist in `warehouses`. |

### Example Request

```http
GET /api/v1/admin/barcode/scan?code=LOC-A-01&type=auto&warehouse_id=1 HTTP/1.1
Host: api.example.com
Authorization: Bearer <token>
Accept: application/json
```

---

## Business Rules & Detection Pipeline

1. **Explicit Target Scan (`type != auto`)**:
   - If `type=location`: Only queries `locations`. Zero queries against products or packages.
   - If `type=product` or `variant`: Only queries products and variants.
   - If `type=package`: Only queries packages.

2. **Smart Heuristic Detection (`type=auto` or omitted)**:
   - Evaluates string patterns in-memory first (zero database round-trips):
     - **Location Prefix / Pattern**: Starts with `LOC-`, `BIN-`, `ZONE-`, `AISLE-`, `SHELF-`, `RACK-`, `PICK-`, `BULK-` or matches rack regex `^[A-Z]{1,3}-\d{1,4}(-\d{1,4})*$` (e.g. `A-01`, `B-02-01`).
     - **Package Prefix**: Starts with `PKG-`, `PACKAGE-`, `SHP-`, `TRACK-`.
     - **Retail Barcode**: Purely digits with length `8` (EAN-8), `12` (UPC-A), `13` (EAN-13), or `14` (GTIN-14).
   - Queries the probable entity first. If a collision occurs or not found, it falls back gracefully across remaining entities.

3. **Ambiguous Lookup**:
   - If the code pattern is ambiguous, sequential priority is: `location` $\rightarrow$ `product` $\rightarrow$ `package`.

4. **Warehouse Scope Isolation**:
   - Users with `manage-warehouse` can target any `warehouse_id`.
   - Regular warehouse operators are locked to their assigned warehouse (`home warehouse`). Attempting to scan or query a different warehouse throws `404 Not Found`.

5. **Read-Only**:
   - Purely queries state. No mutations, reservations, or locks are applied.

---

## Success Response — `200`

The response format follows standard WMS API envelopes:

```json
{
  "status": 200,
  "message": "Barcode resolved successfully.",
  "success": true,
  "data": {
    "kind": "location",
    "detected_by": "pattern",
    "data": {}
  }
}
```

### 1. Location Resolved (`kind: "location"`)

```json
{
  "status": 200,
  "message": "Barcode resolved successfully.",
  "success": true,
  "data": {
    "kind": "location",
    "detected_by": "pattern",
    "data": {
      "id": 14,
      "code": "LOC-A-01",
      "name": "Aisle A Shelf 01",
      "barcode": "LOC-A-01",
      "type": "picking",
      "status": "active",
      "priority": 10,
      "warehouse_id": 1,
      "warehouse": {
        "id": 1,
        "code": "WH-01",
        "name": "Main Warehouse"
      },
      "stored_items_count": 1,
      "stored_items": [
        {
          "placement_id": 5,
          "product_id": 20,
          "product_variant_id": 12,
          "sku": "TSHIRT-BLK-L",
          "name": "Cotton T-Shirt (Black, L)",
          "quantity": 25.0,
          "allocated_hint": 5.0,
          "available_quantity": 20.0
        }
      ]
    }
  }
}
```

### 2. Product / Variant Resolved (`kind: "product"`)

```json
{
  "status": 200,
  "message": "Barcode resolved successfully.",
  "success": true,
  "data": {
    "kind": "product",
    "detected_by": "pattern",
    "data": {
      "product_id": 20,
      "product_variant_id": 12,
      "is_variant": true,
      "sku": "6281000123456",
      "name": "Cotton T-Shirt",
      "price": 45.0,
      "product_type": "variable",
      "stock_quantity": 100,
      "reserved_quantity": 10,
      "available_quantity": 90,
      "warehouse_id": 1,
      "placements_count": 1,
      "placements": [
        {
          "placement_id": 5,
          "location_id": 14,
          "location_code": "LOC-A-01",
          "location_name": "Aisle A Shelf 01",
          "location_type": "picking",
          "quantity": 25.0,
          "allocated_hint": 5.0,
          "available_quantity": 20.0
        }
      ]
    }
  }
}
```

### 3. Package Resolved (`kind: "package"`)

```json
{
  "status": 200,
  "message": "Barcode resolved successfully.",
  "success": true,
  "data": {
    "kind": "package",
    "detected_by": "pattern",
    "data": {
      "id": 88,
      "package_number": "PKG-88219",
      "barcode": "PKG-88219",
      "status": "packed",
      "fulfillment_id": 42,
      "fulfillment": {
        "id": 42,
        "fulfillment_number": "FUL-2026-0042",
        "status": "in_transit",
        "warehouse_id": 1
      }
    }
  }
}
```

---

## Detection Mechanism Values (`detected_by`)

| Value | Meaning |
|---|---|
| `explicit` | Found via user-specified target parameter (`type=location`, `type=product`, etc.). |
| `pattern` | Inferred directly through prefix/regex/digit heuristics without DB trial-and-error. |
| `fallback` | Pattern match had a code collision; item was resolved on secondary fallback. |
| `lookup` | Ambiguous string resolved via ordered fallback scan. |

---

## Error Responses

| Code | When | Example Message |
|---|---|---|
| `401` | Missing or invalid Bearer token | `{"message": "Unauthenticated."}` |
| `403` | User lacks `barcode.scan` permission | `{"message": "User does not have the right permissions."}` |
| `404` | Barcode not found, or belongs to a warehouse outside user's scope | `{"message": "Unknown barcode: XYZ-999."}` |
| `422` | Missing `code` parameter or invalid `type` value | `{"message": "The code field is required.", "errors": {"code": ["The code field is required."]}}` |
| `429` | Exceeded `throttle:admin` rate limit | `{"message": "Too Many Attempts."}` |

### Validation Error Example — `422`

```json
{
  "message": "The selected type is invalid.",
  "errors": {
    "type": [
      "The selected type is invalid."
    ]
  }
}
```

---

## Database Impact

| Table | Usage | Notes |
|---|---|---|
| `locations` | Read | Indexed lookup by `barcode` or `code` + `warehouse_id`. |
| `product_locations` | Read | Eager-loaded placements with `quantity > 0`. |
| `products` | Read | Indexed lookup by `sku` or primary `id`. |
| `product_variants` | Read | Indexed lookup by `sku` or primary `id`. |
| `packages` | Read | Indexed lookup by `barcode` or `package_number`. |
| `warehouses` | Read | Eager loaded relation. |
| `fulfillments` | Read | Eager loaded relation for packages. |

> All queries utilize indexed columns (`sku`, `barcode`, `code`, `warehouse_id`) with eager loading to prevent N+1 queries. Zero database writes.

---

## Dependencies

| Layer | Class / File |
|---|---|
| Route | `packages/marvel/src/Rest/Routes.php` |
| Controller | `App\Http\Controllers\Api\Admin\Wms\BarcodeScanController::scan` |
| Form Request | `App\Http\Requests\Admin\Wms\ScanBarcodeRequest` |
| Resource | `App\Http\Resources\Wms\BarcodeScanResource` |
| Service | `App\Services\Warehouse\BarcodeScanService` |
| Access Control | `App\Services\Warehouse\WarehouseAccess` |
| Enum | `Marvel\Enums\Permission::BARCODE_SCAN` |
| Models | `Location`, `ProductLocation`, `Package`, `Product`, `ProductVariant` |
| Translations | `resources/lang/{en,ar}/wms.php` (`barcode.scanned`, `barcode.unknown`) |
