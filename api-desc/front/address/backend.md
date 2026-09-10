# Backend — Address Feature

## Overview

The Address feature manages user addresses with full CRUD. It follows the Marvel package pattern: Controller → Repository → Model, with a single FormRequest and Resource.

## Architecture

```
Controller (AddressController)
    |
    v
Request (AddressRequest → validation)
    |
    v
Repository (AddressRepository → BaseRepository → Eloquent)
    |
    v
Model (Address)
    |
    v
Database (address table)
    |
    v
Resource (AddressResource → JSON response)
```

## Key Files

### 1. Controller — `packages/marvel/src/Http/Controllers/AddressController.php`

| Method | Endpoint | Auth | Scoping |
|--------|----------|------|---------|
| `index(Request)` | GET /api/v1/address | Sanctum | `customer_id = auth()->id()` |
| `store(AddressRequest)` | POST /api/v1/address | Sanctum | `customer_id` auto-set to auth user |
| `show($id)` | GET /api/v1/address/{id} | Sanctum | `customer_id = auth()->id()` |
| `update(AddressRequest, $id)` | PUT /api/v1/address/{id} | Sanctum | `customer_id = auth()->id()`, immutable |
| `destroy($id, Request)` | DELETE /api/v1/address/{id} | Sanctum | `customer_id = auth()->id()` |

**Key implementation details:**
- All queries add `where('customer_id', $request->user()->id)` — user isolation
- Store overwrites `customer_id` with auth user's ID
- Update excludes `customer_id` from request data
- Destroy checks ownership before deleting
- All responses use `ApiResponse` trait

### 2. Model — `packages/marvel/src/Database/Models/Address.php`

**Table:** `address`

**Traits:** None (plain Eloquent Model)

**Fillable:**
| Column | Type | Notes |
|--------|------|-------|
| `title` | string | Address label |
| `type` | string | Address type (billing, shipping, etc.) |
| `default` | boolean | Default flag |
| `address` | json | Address details (json cast → array) |
| `customer_id` | integer | FK to users |
| `location` | json | Geo coordinates (json cast → array, nullable) |

**Relationships:**
- `customer()` → `BelongsTo(User::class, 'customer_id')`

**No SoftDeletes** — records are permanently deleted.

### 3. Repository — `packages/marvel/src/Database/Repositories/AddressRepository.php`

Extends `BaseRepository`. Only defines `model()` returning `Address::class`. All CRUD operations inherit from `BaseRepository` (Prettus repository pattern).

### 4. Form Request — `packages/marvel/src/Http/Requests/AddressRequest.php`

**Authorize:** Always `true` (gate check done via controller middleware).

**Validation Rules:**

| Field | Rules |
|-------|-------|
| `title` | required, string, max:255 |
| `type` | required, string, max:255 |
| `default` | required, in:0,1 |
| `address` | required, array |
| `address.zip` | required, string |
| `address.city` | required, string |
| `address.state` | required, string |
| `address.country` | required, string |
| `address.street_address` | required, string |
| `location` | sometimes, array |
| `location.latitude` | sometimes, numeric |
| `location.longitude` | sometimes, numeric |

**Validation failure:** Returns 422 with field errors.

### 5. Resource — `packages/marvel/src/Http/Resources/AddressResource.php`

```php
[
    "id" => $this->id,
    "title" => $this->title,
    "type" => $this->type,
    "default" => (bool) $this->default,
    "address" => $this->address,
    "location" => $this->location ?? [],
    "customer_id" => $this->customer_id,
    "created_at" => $this->created_at->toIsoString(),
]
```

### 6. Routes

**Registration:** `packages/marvel/src/Rest/Routes.php:555`
```php
Route::apiResource('address', AddressController::class);
```

**Group middleware:** `auth:sanctum`, `email.verified`

**Prefix:** `/api/v1` (via `RestAPIServiceProvider`)

### 7. Constants — `packages/marvel/config/constants.php`

| Constant | Value |
|----------|-------|
| `ADDRESS_FOUND` | `APP_NOTICE_DOMAIN . 'MESSAGE.ADDRESS_FOUND'` |
| `ADDRESS_NOT_FOUND` | `APP_NOTICE_DOMAIN . 'ERROR.ADDRESS_NOT_FOUND'` |
| `ADDRESS_UPDATED` | `APP_NOTICE_DOMAIN . 'MESSAGE.ADDRESS_UPDATED'` |
| `ADDRESS_DELETED` | `APP_NOTICE_DOMAIN . 'MESSAGE.ADDRESS_DELETED'` |

## Data Flow

### Address Creation Flow

```
Client
  |
  POST /api/v1/address
  Body: { title, type, default, address: { ... }, location: { ... } }
  Authorization: Bearer <token>
  |
  v
Middleware: auth:sanctum, email.verified
  |
  v
AddressController@store(AddressRequest)
  |
  +-- AddressRequest validation (10 rules)
  |     |-- title (required, string, max:255)
  |     |-- type (required, string, max:255)
  |     |-- default (required, in:0,1)
  |     |-- address.* (required, string)
  |     |-- location.* (sometimes, numeric)
  |
  +-- $request->merge(['customer_id' => auth()->id()])
  |     |-- Overwrites any customer_id sent by client
  |
  +-- AddressRepository::create($validatedData)
  |     |-- INSERT into address table
  |     |-- Returns Address model
  |
  +-- AddressResource::make($address)
  |     |-- Maps: id, title, type, default, address, location, customer_id, created_at
  |
  v
Response (201): { message, status, success, data }
```

### Address Retrieval Flow

```
Client
  |
  GET /api/v1/address/1
  Authorization: Bearer <token>
  |
  v
AddressController@show(1)
  |
  +-- AddressRepository::where('customer_id', auth()->id())->find(1)
  |     |-- Scoped query: WHERE id = 1 AND customer_id = {auth->id}
  |     |-- If not found → 404
  |
  +-- AddressResource::make($address)
  |
  v
Response (200): { message, status, success, data }
```

### Address Deletion Flow

```
Client
  |
  DELETE /api/v1/address/1
  Authorization: Bearer <token>
  |
  v
AddressController@destroy(1, Request)
  |
  +-- AddressRepository::where('customer_id', $user->id)->find(1)
  |     |-- Ownership check
  |     |-- If not found → 404
  |
  +-- $address->delete()
  |     |-- DELETE from address (hard delete, no SoftDeletes)
  |
  v
Response (200): { message: "ADDRESS_DELETED", status: 200, success: true }
```

## Security

- **User isolation**: All queries scoped to `customer_id = auth()->id()`
- **customer_id override on create**: Client cannot set another user's ID
- **customer_id immutability on update**: Excluded from update data
- **No policy class**: Authorization handled implicitly via query scoping
- **Authentication required**: All endpoints behind `auth:sanctum` + `email.verified`
