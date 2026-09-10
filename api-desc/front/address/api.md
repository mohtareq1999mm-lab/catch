# API Documentation — Address Feature

## Endpoints

---

### 1. List Addresses

**GET** `/api/v1/address`

**Purpose:** Retrieve all addresses for the authenticated user. Scoped to `customer_id`.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Middleware | `email.verified` |

#### Success Response (200)

```json
{
    "message": "MESSAGE.ADDRESS_FOUND",
    "status": 200,
    "success": true,
    "data": [
        {
            "id": 1,
            "title": "Home",
            "type": "shipping",
            "default": true,
            "address": {
                "street_address": "123 Main St",
                "city": "Cairo",
                "state": "Cairo Governorate",
                "zip": "11511",
                "country": "Egypt"
            },
            "location": {
                "latitude": 30.0444,
                "longitude": 31.2357
            },
            "customer_id": 10,
            "created_at": "2026-07-26T10:00:00.000000Z"
        }
    ]
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |

---

### 2. Create Address

**POST** `/api/v1/address`

**Purpose:** Add a new address to the user's profile. `customer_id` is auto-set to the authenticated user.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |

#### Request Parameters

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `title` | `string` | Yes | Address label (e.g., "Home", "Work"), max 255 |
| `type` | `string` | Yes | Address type, max 255 |
| `default` | `string` | Yes | `0` or `1` |
| `address` | `object` | Yes | Address details object |
| `address.zip` | `string` | Yes | Postal/ZIP code |
| `address.city` | `string` | Yes | City name |
| `address.state` | `string` | Yes | State/region |
| `address.country` | `string` | Yes | Country name |
| `address.street_address` | `string` | Yes | Street address |
| `location` | `object` | No | Geo-location object |
| `location.latitude` | `numeric` | Sometimes | Required if location provided |
| `location.longitude` | `numeric` | Sometimes | Required if location provided |

#### Example Request Body

```json
{
    "title": "Work",
    "type": "billing",
    "default": "1",
    "address": {
        "street_address": "456 Business Ave",
        "city": "Alexandria",
        "state": "Alexandria Governorate",
        "zip": "21500",
        "country": "Egypt"
    },
    "location": {
        "latitude": 31.2001,
        "longitude": 29.9187
    }
}
```

#### Success Response (201)

```json
{
    "message": "MESSAGE.COULD_NOT_CREATE_THE_RESOURCE",
    "status": 201,
    "success": true,
    "data": {
        "id": 2,
        "title": "Work",
        "type": "billing",
        "default": true,
        "address": {
            "street_address": "456 Business Ave",
            "city": "Alexandria",
            "state": "Alexandria Governorate",
            "zip": "21500",
            "country": "Egypt"
        },
        "location": {
            "latitude": 31.2001,
            "longitude": 29.9187
        },
        "customer_id": 10,
        "created_at": "2026-07-26T10:05:00.000000Z"
    }
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 422 | Validation error |

---

### 3. Get Address

**GET** `/api/v1/address/{id}`

**Purpose:** Retrieve details of a specific address. Scoped to the authenticated user.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |

#### Path Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | `integer` | Address ID |

#### Success Response (200)

```json
{
    "message": "MESSAGE.ADDRESS_FOUND",
    "status": 200,
    "success": true,
    "data": {
        "id": 1,
        "title": "Home",
        "type": "shipping",
        "default": true,
        "address": {
            "street_address": "123 Main St",
            "city": "Cairo",
            "state": "Cairo Governorate",
            "zip": "11511",
            "country": "Egypt"
        },
        "location": {
            "latitude": 30.0444,
            "longitude": 31.2357
        },
        "customer_id": 10,
        "created_at": "2026-07-26T10:00:00.000000Z"
    }
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 404 | Address not found or not owned by user |

---

### 4. Update Address

**PUT** `/api/v1/address/{id}`

**Purpose:** Update an existing address. `customer_id` is protected and cannot be changed.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |

#### Path Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | `integer` | Address ID |

#### Request Parameters

Same validation rules as Create. All fields are required (partial update not supported via validation).

#### Success Response (200)

```json
{
    "message": "MESSAGE.ADDRESS_UPDATED",
    "status": 200,
    "success": true,
    "data": {
        "id": 1,
        "title": "Home",
        "type": "shipping",
        "default": true,
        "address": { ... },
        "location": { ... },
        "customer_id": 10,
        "created_at": "2026-07-26T10:00:00.000000Z"
    }
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 404 | Address not found or not owned by user |
| 422 | Validation error |

---

### 5. Delete Address

**DELETE** `/api/v1/address/{id}`

**Purpose:** Remove an address from the user's profile.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |

#### Path Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | `integer` | Address ID |

#### Success Response (200)

```json
{
    "message": "MESSAGE.ADDRESS_DELETED",
    "status": 200,
    "success": true
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 404 | Address not found or not owned by user |

---

## Resource Structure

### AddressResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | `integer` | Primary key |
| `title` | `string` | Address label (e.g., "Home", "Work") |
| `type` | `string` | Address type (e.g., "billing", "shipping") |
| `default` | `boolean` | Is default address |
| `address` | `object` | `{ street_address, city, state, zip, country }` |
| `location` | `object` | `{ latitude, longitude }` or `[]` if not set |
| `customer_id` | `integer` | Owner user ID |
| `created_at` | `string` | ISO 8601 timestamp |

## Business Rules

1. **User isolation**: All CRUD operations are scoped to `customer_id = auth()->user()->id`. A user cannot access another user's addresses.
2. **customer_id override**: On store, the `customer_id` from the request is overwritten with the authenticated user's ID.
3. **customer_id immutability**: On update, `customer_id` is explicitly excluded from the update data.
4. **Partial update limitation**: The `AddressRequest` validation requires all fields — the controller does not restrict to `sometimes` rules, so PUT behaves closer to a full replacement.
5. **No soft deletes**: Addresses are hard-deleted from the database.
6. **No pagination**: The `index` method returns all addresses as a flat collection — no pagination.

## Dependencies

| Layer | File |
|-------|------|
| Controller | `packages/marvel/src/Http/Controllers/AddressController.php` |
| Request | `packages/marvel/src/Http/Requests/AddressRequest.php` |
| Resource | `packages/marvel/src/Http/Resources/AddressResource.php` |
| Repository | `packages/marvel/src/Database/Repositories/AddressRepository.php` |
| Model | `packages/marvel/src/Database/Models/Address.php` |
| Base Repository | `packages/marvel/src/Database/Repositories/BaseRepository.php` |
| Trait | `packages/marvel/src/Traits/ApiResponse.php` |
| Constants | `packages/marvel/config/constants.php` |
