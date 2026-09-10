# Address Module — API Investigation

## Feature Name

User Address Management

## Investigation Scope

Route: `Route::apiResource('address', AddressController::class)`

### Actual Route

```
Prefix: /api/v1 (via RestAPIServiceProvider)
Full path: /api/v1/address
Middleware: auth:sanctum, email.verified
```

### Generated Endpoints

| Method | URI | Controller@method | Auth | Purpose |
|--------|-----|-------------------|------|---------|
| GET | `/api/v1/address` | `AddressController@index` | Sanctum | List all addresses for authenticated user |
| POST | `/api/v1/address` | `AddressController@store` | Sanctum | Create a new address |
| GET | `/api/v1/address/{id}` | `AddressController@show` | Sanctum | Get address details |
| PUT | `/api/v1/address/{id}` | `AddressController@update` | Sanctum | Update existing address |
| DELETE | `/api/v1/address/{id}` | `AddressController@destroy` | Sanctum | Delete address |

### Key Business Rules

1. **User-scoped**: All queries filter by `customer_id = auth()->user()->id`. Users can only see/manage their own addresses.
2. **customer_id is overwritten**: On create, the `customer_id` in the request is replaced with the authenticated user's ID — users cannot create addresses for other users.
3. **customer_id is protected**: On update, `customer_id` is explicitly excluded from the request data via `$request->except('customer_id')` — users cannot reassign addresses.
4. **Address is JSON**: The `address` field is a JSON object containing `street_address`, `city`, `state`, `zip`, `country`.
5. **Location is optional**: The `location` field is a JSON object with `latitude` and `longitude`.
6. **Type enum**: `type` accepts any string (billing, shipping, etc.) — validated as string, not enum.
7. **Default is boolean**: `default` accepts `0` or `1` (string/integer).
8. **Authorized only**: All endpoints require `auth:sanctum` + `email.verified`.
9. **Not address-specific**: There is no policy class — authorization is implicit via `customer_id` scoping.
10. **Soft/hard delete**: Uses Eloquent `delete()` — no SoftDeletes trait on model.

### Key Files

| Layer | Path |
|-------|------|
| Controller | `packages/marvel/src/Http/Controllers/AddressController.php` |
| Model | `packages/marvel/src/Database/Models/Address.php` |
| Repository | `packages/marvel/src/Database/Repositories/AddressRepository.php` |
| Request | `packages/marvel/src/Http/Requests/AddressRequest.php` |
| Resource | `packages/marvel/src/Http/Resources/AddressResource.php` |
| Routes | `packages/marvel/src/Rest/Routes.php` (line 555) |
| Migration | `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php` |
| Constants | `packages/marvel/config/constants.php` |
| Service Provider | `packages/marvel/src/Providers/RestAPIServiceProvider.php` (prefix) |

### Actual Response Format

All responses use `ApiResponse` trait:

**Success:**
```json
{
    "message": "MESSAGE.ADDRESS_FOUND",
    "status": 200,
    "success": true,
    "data": { ... }
}
```

**Error:**
```json
{
    "message": "ERROR.ADDRESS_NOT_FOUND",
    "status": 404,
    "success": false
}
```
