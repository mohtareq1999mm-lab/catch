# Address Feature - API Investigation

## Feature Name

Customer Address Management

## Description

CRUD for customer saved addresses. Owner-scoped by `customer_id` from the Sanctum token (no Spatie permissions; `authorize()` returns true and every query is filtered by the authenticated user). Addresses carry an optional `governorate_id` linking to the master `governorates` table — accepted for any existing governorate regardless of shipping availability. Coupon `area_in` evaluates against this link.

## Architecture

```
[Customer Client (Sanctum)]
     |
     |--- GET /address
     |--- POST /address
     |--- GET /address/{id}
     |--- PUT /address/{id}
     |--- DELETE /address/{id}
     |
     v
[AddressController (Marvel)]
     |--- owner scope: customer_id = auth user id
     |--- store: AddressRequest | update: AddressRequestUpdate
     |
     v
[AddressRepository (Prettus BaseRepository)]
     |
     v
[Address Model]
     |--- table: address
     |--- fillable: title, default, address, customer_id, governorate_id, location
     |--- casts: address (array), location (array), governorate_id (integer)
     |--- governorate_id: nullable FK -> governorates.id, nullOnDelete, index(customer_id, governorate_id)
     |--- relations: customer (BelongsTo User), governorate (BelongsTo Governorate)
     |
     v
[AddressResource]
     |--- id, title, default, address, location, customer_id, governorate_id
```

## Key Endpoints

| Method | URI | Controller | Auth |
|--------|-----|------------|------|
| GET | `/address` | index (own addresses) | sanctum |
| POST | `/address` | store | sanctum |
| GET | `/address/{id}` | show (own only, 404 otherwise) | sanctum |
| PUT | `/address/{id}` | update (own only, 404 otherwise) | sanctum |
| DELETE | `/address/{id}` | destroy (own only, 404 otherwise) | sanctum |

## Request Bodies

### POST /address (store — `AddressRequest`)

```json
{
  "title": "Home",
  "address": {
    "zip": "12345",
    "city": "Cairo",
    "state": "Cairo",
    "country": "Egypt",
    "street_address": "1 Test Street"
  },
  "location": { "latitude": 30.04, "longitude": 31.23 },
  "governorate_id": 1
}
```

| Field | Required | Type | Validation | Notes |
|-------|----------|------|------------|-------|
| `title` | YES | string max:255 | `required` | address label |
| `address` | YES | object | `required\|array` + zip/city/state/country/street_address all `required string` | free-form snapshot |
| `location` | no | object | `sometimes\|array`, lat/lng `sometimes\|numeric` | geo point |
| `governorate_id` | no | integer | `nullable\|integer\|exists:governorates,id` | **master geography link; any existing row accepted — shipping availability is NOT required** |

### PUT /address/{id} (update — `AddressRequestUpdate`)

Same shape, all fields `sometimes` (partial update). `governorate_id` rule identical: `nullable|integer|exists:governorates,id`. `customer_id` cannot be changed (stripped server-side; taken from token on create).

## Success Responses

- Store: `201` + `AddressResource` (includes `governorate_id`).
- Update/show/index: `200` + resource / collection.
- Destroy: `200`.
- Show/update/destroy of another user's id: `404 ADDRESS_NOT_FOUND`.

## governorate_id Contract

- Any id from `GET /general/governorates` (active master list) is accepted — including governorates with **no** `shipping_prices` row or with shipping **disabled**.
- `null` = no link (legacy rows); coupon `area_in` fails closed on them.
- Changing it never touches orders (snapshots immutable) and never affects shipping prices.
- Deleting a governorate nulls the link (`nullOnDelete`); the address itself survives.

## Key Files

| Layer | Path |
|-------|------|
| Controller | `packages/marvel/src/Http/Controllers/AddressController.php` |
| Request (Store) | `packages/marvel/src/Http/Requests/AddressRequest.php` |
| Request (Update) | `packages/marvel/src/Http/Requests/AddressRequestUpdate.php` |
| Model | `packages/marvel/src/Database/Models/Address.php` |
| Repository | `packages/marvel/src/Database/Repositories/AddressRepository.php` |
| Resource | `packages/marvel/src/Http/Resources/AddressResource.php` |
| Routes | `packages/marvel/src/Rest/Routes.php` (`Route::apiResource('address', ...)`, `auth:sanctum` + `lang`) |
| Migration (base) | `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php` (`address` table) |
| Migration (link) | `database/migrations/2026_09_28_000001_add_governorate_id_to_address_table.php` (nullable FK + index, no backfill by design) |
| Master data | `packages/marvel/database/migrations/2026_05_23_100002_create_governorates_table.php` |
| Tests | `tests/Feature/GovernorateShippingDecouplingTest.php` (store/update HTTP persistence incl. shipping-disabled case) |

## Tech Stack

- **Laravel** with Eloquent ORM
- **Sanctum** authentication, owner-scoped (no Spatie permissions on this controller)
- **Prettus BaseRepository** pattern
- **Nullable FK** (`nullOnDelete`) + composite index for the coupon `area_in` lookup pattern
- **Decoupled geography**: address validity never depends on `shipping_prices`
