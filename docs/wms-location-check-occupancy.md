# Check Location Occupancy

## Endpoint

| | |
|---|---|
| Method | `POST` |
| URL | `/api/v1/admin/locations/check-occupancy` |
| Route name | `api.admin.locations.check-occupancy` |
| Purpose | Takes one or more locations and returns each as empty, occupied (with its products), or not found |

Use it to get the basic product data for everything stored in a location. To check one location, send one id.

---

## Authentication & Authorization

| | |
|---|---|
| Auth | Required: `auth:sanctum` (Bearer token) |
| Permission | `locations.check-occupancy` (`Permission::LOCATIONS_CHECK_OCCUPANCY`) |
| Throttle | `throttle:admin` |
| Warehouse scope | Users without `manage-warehouse` only see locations in their own warehouse |

---

## Request Body

| Field | Type | Required | Description |
|---|---|---|---|
| `location_ids` | `int[]` | Yes, unless `locations` is sent | Location ids to check (min 1) |
| `locations` | `int[]` | Yes, unless `location_ids` is sent | Alias of `location_ids` |
| `warehouse_id` | `int` | No | Only check locations in this warehouse. Must exist in `warehouses` |

> Duplicate ids and zero/invalid values are removed automatically.

### Example

```json
{
  "location_ids": [5, 6, 999],
  "warehouse_id": 1
}
```

---

## Business Rules

1. Locations are loaded with their placements (`product_locations`). Placements with `quantity = 0` and `allocated_hint = 0` are ignored.
2. A location is **empty** if:
   - it has no remaining placements, **or**
   - the sum of `quantity` across its placements is `<= 0`. This includes a location that only has reservations (`allocated_hint`).
3. A location is **occupied** when the total quantity is `> 0`. Its products are returned.
4. **not_found** lists ids that:
   - do not exist, **or**
   - are outside the user's warehouse, **or**
   - do not match the `warehouse_id` sent.
5. Read only: nothing in the database changes.
6. Queries are eager loaded (`product`, `variant.attributeProducts.attributeValue.attribute`), so there are no N+1 queries.

---

## Success Response — `200`

```json
{
  "status": 200,
  "message": "Locations occupancy checked successfully.",
  "success": true,
  "data": {
    "empty": [
      {
        "id": 6,
        "warehouse_id": 1,
        "code": "A-01-04",
        "barcode": "LOC-0006",
        "name": "Shelf A1-4",
        "type": "bin",
        "status": "active"
      }
    ],
    "occupied": [
      {
        "id": 5,
        "warehouse_id": 1,
        "code": "A-01-03",
        "barcode": "LOC-0005",
        "name": "Shelf A1-3",
        "type": "bin",
        "status": "active",
        "total_quantity": 12,
        "products_count": 1,
        "products": [
          {
            "placement_id": 30,
            "product_id": 55,
            "product_name": "T-Shirt",
            "product_slug": "t-shirt",
            "product_sku": "SKU-001",
            "quantity": 12,
            "allocated_hint": 2,
            "available_quantity": 10,
            "has_variant": true,
            "variant": {
              "id": 9,
              "sku": "SKU-001-RED-M",
              "price": 100,
              "sale_price": 90,
              "stock_quantity": 40,
              "attributes": [
                { "name": "Color", "value": "Red", "attribute_id": 1, "attribute_value_id": 4 },
                { "name": "Size", "value": "M", "attribute_id": 2, "attribute_value_id": 8 }
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

> `message` is translated: `wms.location.occupancy_checked` (en / ar). The values in `type` and `status` above are examples.

---

## Resource Structure

### Location (`empty[]`, `occupied[]`)

| Field | Type | Notes |
|---|---|---|
| `id` | int | |
| `warehouse_id` | int | |
| `code` | string | |
| `barcode` | string\|null | |
| `name` | string | |
| `type` | string | |
| `status` | string | |
| `total_quantity` | float | Occupied only |
| `products_count` | int | Occupied only |
| `products` | array | Occupied only |

### Product (`occupied[].products[]`)

| Field | Type | Notes |
|---|---|---|
| `placement_id` | int | `product_locations.id` |
| `product_id` | int | |
| `product_name` | string\|null | |
| `product_slug` | string\|null | |
| `product_sku` | string\|null | |
| `quantity` | float | Physical quantity in the location |
| `allocated_hint` | float | Reserved quantity |
| `available_quantity` | float | Available quantity |
| `has_variant` | bool | |
| `variant` | object\|null | `null` for simple products |

### Variant (`products[].variant`)

| Field | Type | Notes |
|---|---|---|
| `id` | int | |
| `sku` | string\|null | |
| `price` | float\|null | |
| `sale_price` | float\|null | |
| `stock_quantity` | int | Total variant stock (not just this location) |
| `attributes[]` | array | `{ name, value, attribute_id, attribute_value_id }`, translated to the request locale |

> No product image is returned.

---

## Error Responses

| Code | When |
|---|---|
| `401` | No token, or token is invalid |
| `403` | User lacks `locations.check-occupancy` |
| `404` | `warehouse_id` is outside the user's allowed scope |
| `422` | Validation: neither `location_ids` nor `locations` sent, empty array, non-integer values, or `warehouse_id` does not exist |
| `429` | `throttle:admin` limit exceeded |

### Validation Example — `422`

```json
{
  "message": "The location ids field is required when locations is not present.",
  "errors": {
    "location_ids": ["The location ids field is required when locations is not present."],
    "locations": ["The locations field is required when location ids is not present."]
  }
}
```

---

## Database Impact

| Table | Usage |
|---|---|
| `locations` | Read |
| `product_locations` | Read (only rows with `quantity > 0` or `allocated_hint > 0`) |
| `products` | Read (`id, name, slug, sku, product_type, status`) |
| `product_variants` + attribute tables | Read |

No writes and no transactions.

---

## Dependencies

| Layer | Class |
|---|---|
| Route | `packages/marvel/src/Rest/Routes.php` (line 578) |
| Controller | `App\Http\Controllers\Api\Admin\Wms\LocationController::checkOccupancy` |
| Request | `App\Http\Requests\Admin\Wms\CheckLocationOccupancyRequest` |
| Service | `App\Services\Fulfillment\LocationOccupancyService` |
| Access | `App\Services\Warehouse\WarehouseAccess` |
| Models | `Location`, `ProductLocation`, `Product`, variant models |
| Enum | `Marvel\Enums\Permission::LOCATIONS_CHECK_OCCUPANCY` |
| Translation | `resources/lang/{en,ar}/wms.php` → `location.occupancy_checked` |
