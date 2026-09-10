# API Documentation - Shipping Feature

All endpoints under prefix `/api/v1`. Authentication via `auth:sanctum`. Permission middleware applied in each controller constructor.

## 1. Countries - CRUD

### GET /api/v1/countries

**Query:** `per_page` (default 15), `search`, `status` (boolean)

**Response:**
```json
{
    "success": true,
    "message": "Data fetched successfully",
    "data": [
        {
            "id": 1,
            "name": "Egypt",
            "phone_code": "+20",
            "status": true,
            "governorates": [],
            "created_at": "2026-07-20T10:00:00+00:00"
        }
    ]
}
```

### POST /api/v1/countries

**Body:** `name.en` (required), `name.ar` (required), `phone_code`, `status`

### GET /api/v1/countries/{id}

Eager loads `governorates` relation.

### PUT /api/v1/countries/{id}

**Body:** `name.en`, `name.ar`, `phone_code`, `status` (all optional)

### DELETE /api/v1/countries/{id}

Cascades to governorates (FK constraint).

## 2. Countries - Custom

### GET /api/v1/countries/{id}/governorates

Returns country with loaded `governorates` relation.

### POST /api/v1/countries/change-status

**Body:** `ids` (array of ints), `status` (0 or 1)

## 3. Governorates - CRUD

### GET /api/v1/governorates

**Query:** `per_page` (default 15), `search`, `status` (boolean), `country_id` (int)

### POST /api/v1/governorates

**Body:** `country_id` (required), `name.en` (required), `name.ar` (required), `status`, `is_fast_shipping_enabled`, `shipping_price` (array: price, estimated_days, free_shipping_over, status)

Creates governorate + optional shipping price in a transaction.

### GET /api/v1/governorates/{id}

Eager loads `country`, `cities`, `shippingPrice`.

**Response:**
```json
{
    "data": {
        "id": 1,
        "country_id": 1,
        "name": "Cairo",
        "status": true,
        "is_fast_shipping_enabled": true,
        "country": { "id": 1, "name": "Egypt", ... },
        "cities": [ { "id": 1, "name": "New Cairo", ... } ],
        "shipping_price": { "id": 1, "price": 50.00, "estimated_days": 3, "free_shipping_over": null },
        "created_at": "..."
    }
}
```

### PUT /api/v1/governorates/{id}

**Body:** Same as store (all optional). Nested `shipping_price` updates or creates.

### DELETE /api/v1/governorates/{id}

Fails with `InvalidArgumentException` if governorate has cities.

## 4. Governorates - Custom

### GET /api/v1/governorates/{id}/cities

Returns governorate with loaded `cities`.

### PUT /api/v1/governorates/change-status

**Body:** `ids` (array), `status` (0/1)

### PUT /api/v1/governorates/{id}/fast-shipping

**Body:** `is_fast_shipping_enabled` (required, boolean)

## 5. Cities - CRUD

### GET /api/v1/cities

**Query:** `per_page` (default 15), `search`, `governorate_id` (int)

Always eager loads `governorate` relation.

### POST /api/v1/cities

**Body:** `governorate_id` (required), `name.en` (required), `name.ar` (required), `status`

### GET /api/v1/cities/{id}

Eager loads `governorate`.

### PUT /api/v1/cities/{id}

**Body:** Same as store (all optional).

### DELETE /api/v1/cities/{id}

## Business Rules

1. **Hierarchical integrity:** Governorate requires existing `country_id`; City requires existing `governorate_id`
2. **Cascade delete:** Deleting a country cascades to its governorates (FK cascade)
3. **Protective delete:** Deleting a governorate with cities throws error
4. **Search:** Applied on JSON translatable columns (`name->"$.en"` and `name->"$.ar"`) via `LOWER()` + `LIKE`
5. **Pagination:** Default 15, no explicit max limit
6. **Ordering:** All lists sorted by `id DESC`
7. **Fast shipping:** Toggled via boolean field; no additional logic in controller
