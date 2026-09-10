# Shipment Module — Backend Architecture

## Overview

The Shipment module manages order shipment tracking. It provides authenticated API endpoints for creating, tracking, and updating shipments. The module implements a state machine pattern for status transitions with pessimistic locking for concurrency safety.

## Endpoints

**API Prefix:** `/api/v1/shipments` (all endpoints under `auth:sanctum` group)

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| GET | `/api/v1/shipments` | `auth:sanctum` | `view-shipments` | List shipments (paginated, filterable) |
| GET | `/api/v1/shipments/{id}` | `auth:sanctum` | `view-shipment` | Show shipment by ID |
| GET | `/api/v1/shipments/uuid/{uuid}` | `auth:sanctum` | `view-shipment` | Show shipment by UUID |
| POST | `/api/v1/shipments` | `auth:sanctum` | `create-shipment` | Create shipment |
| PUT | `/api/v1/shipments/{id}` | `auth:sanctum` | `update-shipment` | Update shipment details |
| PUT | `/api/v1/shipments/{id}/status` | `auth:sanctum` | `update-shipment` | Update status (state machine) |

## Route Definitions

**File:** `routes/api.php` (lines 112-120)

```
Line 112: //======================== shipments ========================/
Line 113: Route::prefix('shipments')->middleware('auth:sanctum')->group(function () {
Line 114:     Route::get('/',                        [ShipmentController::class, 'index']);
Line 115:     Route::get('uuid/{uuid}',               [ShipmentController::class, 'showByUuid']);
Line 116:     Route::get('{id}',                      [ShipmentController::class, 'show']);
Line 117:     Route::post('/',                        [ShipmentController::class, 'store']);
Line 118:     Route::put('{id}/status',               [ShipmentController::class, 'updateStatus']);
Line 119:     Route::put('{id}',                      [ShipmentController::class, 'update']);
Line 120: });
```

## Middleware

### ShipmentController

| Method | Middleware |
|--------|-----------|
| `index` | `auth:sanctum` (route group), `permission:view-shipments` (constructor) |
| `show` | `auth:sanctum` (route group), `permission:view-shipment` (constructor) |
| `showByUuid` | `auth:sanctum` (route group), `permission:view-shipment` (constructor) |
| `store` | `auth:sanctum` (route group), `permission:create-shipment` (constructor) |
| `update` | `auth:sanctum` (route group), `permission:update-shipment` (constructor) |
| `updateStatus` | `auth:sanctum` (route group), `permission:update-shipment` (constructor) |

**Note:** `auth:sanctum` is applied at the route group level in `api.php`. Permission middleware is applied per-method in the controller constructor.

## Controller Flow

**File:** `app/Http/Controllers/Api/ShipmentController.php`

```
GET /shipments
  → ShipmentController@index(Request)
    → $this->shipmentService->list($filters, $perPage)
      → Shipment::query()
        → with('order')
        → when(order_id) → where('order_id', ...)
        → when(status) → where('status', ...)
        → when(courier) → where('courier', ...)
        → when(tracking_number) → where('tracking_number', 'like', ...)
        → when(from) → whereDate('created_at', '>=', ...)
        → when(to) → whereDate('created_at', '<=', ...)
        → orderBy('created_at', 'desc')
        → paginate(min(perPage, 100))
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $shipments)

POST /shipments
  → ShipmentController@store(CreateShipmentRequest)
    → $this->shipmentService->create($request->validated())
      → DB::transaction
        → $data['status'] = 'pending'
        → Shipment::create($data)
    → $this->apiResponse('Shipment created successfully', 201, true, $shipment)

GET /shipments/{id}
  → ShipmentController@show($id)
    → $this->shipmentService->find($id)
      → Shipment::with('order')->findOrFail($id)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $shipment)

GET /shipments/uuid/{uuid}
  → ShipmentController@showByUuid($uuid)
    → $this->shipmentService->findByUuid($uuid)
      → Shipment::with('order')->where('uuid', $uuid)->firstOrFail()
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $shipment)

PUT /shipments/{id}
  → ShipmentController@update(UpdateShipmentRequest, $id)
    → $this->shipmentService->update($id, $request->validated())
      → Shipment::findOrFail($id)
      → $shipment->update($data)
      → $shipment->fresh()
    → $this->apiResponse('Shipment updated successfully', 200, true, $shipment)

PUT /shipments/{id}/status
  → ShipmentController@updateStatus(UpdateShipmentStatusRequest, $id)
    → try:
      → $this->shipmentService->updateStatus($id, $status, $notes)
        → DB::transaction
          → Shipment::lockForUpdate()->findOrFail($id)
          → if (!$shipment->canTransitionTo($newStatus)) → throw RuntimeException
          → if shipped/picked_up → update shipped_at
          → if delivered → update delivered_at
          → $shipment->update(['status' => $newStatus, 'notes' => ...])
          → return $shipment->fresh()
    → catch RuntimeException:
      → $this->apiResponse($e->getMessage(), 422, false)
```

## Service

**File:** `app/Services/Shipment/ShipmentService.php`

| Method | Description |
|--------|-------------|
| `list(array $filters, int $perPage)` | Paginated list with conditional filters, eager loads order, max 100 per page |
| `find(int $id)` | Find by primary ID with order relation, throws ModelNotFoundException |
| `findByUuid(string $uuid)` | Find by UUID with order relation, throws ModelNotFoundException |
| `create(array $data)` | Transactional create, auto-sets status to 'pending' |
| `updateStatus(int $id, string $status, ?string $notes)` | Transactional status update with pessimistic lock, state machine validation, auto-timestamps |
| `update(int $id, array $data)` | Basic update, returns fresh instance (no transaction) |

### `create()` Flow
```
1. DB::beginTransaction()
2. $data['status'] = 'pending'
3. Shipment::create($data)
4. DB::commit()
5. Return $shipment
```

### `updateStatus()` Flow
```
1. DB::beginTransaction()
2. Shipment::lockForUpdate()->findOrFail($id)
3. Validate transition via Shipment::canTransitionTo($newStatus)
4. If invalid → throw RuntimeException("Shipment {id} cannot transition from '{$from}' to '{$to}'")
5. If transition to 'shipped'|'picked_up': set shipped_at (preserve existing)
6. If transition to 'delivered': set delivered_at = now()
7. $shipment->update(['status' => $newStatus, 'notes' => $notes])
8. DB::commit()
9. Return $shipment->fresh()
```

### `update()` Flow
```
1. Shipment::findOrFail($id)
2. $shipment->update($data)
3. Return $shipment->fresh()
```

**Note:** Unlike `create()` and `updateStatus()`, the `update()` method does NOT use a database transaction.

## Model

**File:** `app/Models/Shipment.php`
**Table:** `shipments`
**Traits:** None (no SoftDeletes, no HasTranslations)

| Property | Details |
|----------|---------|
| Fillable | `uuid`, `order_id`, `tracking_number`, `courier`, `status`, `shipping_method`, `shipping_cost`, `currency`, `origin_address`, `destination_address`, `items`, `total_weight`, `weight_unit`, `shipped_at`, `estimated_delivery_at`, `delivered_at`, `notes`, `metadata` |
| Casts | `origin_address` (array), `destination_address` (array), `items` (array), `metadata` (array), `shipped_at` (datetime), `estimated_delivery_at` (datetime), `delivered_at` (datetime), `shipping_cost` (float), `total_weight` (float) |

### Model Events (boot)

| Event | Behavior |
|-------|----------|
| `creating` | Auto-generates `uuid` via `Str::orderedUuid()` if empty |

### Relationships

| Relation | Type | Foreign Key |
|----------|------|-------------|
| `order()` | BelongsTo | `order_id` → `Marvel\Database\Models\Order::id` |

### Status State Machine

| From | Allowed To |
|------|------------|
| `pending` | `label_created`, `cancelled` |
| `label_created` | `picked_up`, `cancelled` |
| `picked_up` | `in_transit`, `cancelled` |
| `in_transit` | `out_for_delivery`, `delayed` |
| `out_for_delivery` | `delivered`, `failed_delivery` |
| `delivered` | *(terminal)* |
| `failed_delivery` | `out_for_delivery`, `returned` |
| `returned` | *(terminal)* |
| `delayed` | `in_transit`, `out_for_delivery` |
| `cancelled` | *(terminal)* |

The state machine is defined in two places:
1. `ShipmentStatus` enum — `allowedTransitions()` and `canTransitionTo()` (object-oriented)
2. `Shipment` model — `allowedTransitions()` and `canTransitionTo()` (static string-based)

**Note:** The business logic is duplicated — both `ShipmentStatus::canTransitionTo()` and `Shipment::canTransitionTo()` define the same transitions. The service layer calls `Shipment::canTransitionTo()`.

## Form Requests

### CreateShipmentRequest (`App\Http\Requests\Shipment\CreateShipmentRequest`)

**File:** `app/Http/Requests/Shipment/CreateShipmentRequest.php`

| Field | Rules |
|-------|-------|
| `order_id` | `required`, `integer`, `exists:orders,id` |
| `tracking_number` | `nullable`, `string`, `max:100`, `unique:shipments,tracking_number` |
| `courier` | `nullable`, `string`, `max:50` |
| `shipping_method` | `nullable`, `string`, `max:30` |
| `shipping_cost` | `nullable`, `numeric`, `min:0` |
| `currency` | `nullable`, `string`, `max:3` |
| `origin_address` | `nullable`, `array` |
| `destination_address` | `nullable`, `array` |
| `items` | `nullable`, `array` |
| `total_weight` | `nullable`, `numeric`, `min:0` |
| `weight_unit` | `nullable`, `string`, `max:10` |
| `estimated_delivery_at` | `nullable`, `date` |
| `notes` | `nullable`, `string`, `max:2000` |
| `metadata` | `nullable`, `array` |

### UpdateShipmentRequest (`App\Http\Requests\Shipment\UpdateShipmentRequest`)

**File:** `app/Http/Requests/Shipment/UpdateShipmentRequest.php`

Same rules as create, but all fields are `sometimes` (optional). Tracking number uniqueness ignores the current shipment ID via `->ignore($id)`.

### UpdateShipmentStatusRequest (`App\Http\Requests\Shipment\UpdateShipmentStatusRequest`)

**File:** `app/Http/Requests/Shipment/UpdateShipmentStatusRequest.php`

| Field | Rules |
|-------|-------|
| `status` | `required`, `Enum(ShipmentStatus::class)` |
| `notes` | `nullable`, `string`, `max:2000` |

## Resources

**No Resources exist.** The controller returns raw model data via `ApiResponse` trait directly, without using a dedicated `ShipmentResource`. There is no resource transformation layer, meaning:
- All fillable fields are exposed directly (no field selection)
- No computed/appended fields
- No conditional loading (`whenLoaded`) — relationship is always included if loaded

## Enum

**File:** `app/Enums/ShipmentStatus.php`

```php
enum ShipmentStatus: string
{
    case PENDING = 'pending';
    case LABEL_CREATED = 'label_created';
    case PICKED_UP = 'picked_up';
    case IN_TRANSIT = 'in_transit';
    case OUT_FOR_DELIVERY = 'out_for_delivery';
    case DELIVERED = 'delivered';
    case FAILED_DELIVERY = 'failed_delivery';
    case RETURNED = 'returned';
    case DELAYED = 'delayed';
    case CANCELLED = 'cancelled';
}
```

**Methods:**
- `allowedTransitions(): array` — returns allowed target statuses for this enum case
- `canTransitionTo(self $target): bool` — checks if transition to target is allowed

## Permissions

**Enum:** `Marvel\Enums\Permission`

| Constant | Value |
|----------|-------|
| `VIEW_SHIPMENTS` | `view-shipments` |
| `VIEW_SHIPMENT` | `view-shipment` |
| `CREATE_SHIPMENT` | `create-shipment` |
| `UPDATE_SHIPMENT` | `update-shipment` |

**Missing:** No `DELETE_SHIPMENT` permission exists — the controller has no destroy method, so this is consistent.

## Constants & Translations

**Missing:** The Shipment module has NO constants in `packages/marvel/config/constants.php` and NO translation keys in `resources/lang/{en,ar}/message.php`.

The controller uses hardcoded English strings:
- `'Shipment created successfully'` (instead of a constant like `SHIPMENT_CREATED_SUCCESSFULLY`)
- `'Shipment status updated'`
- `'Shipment updated successfully'`
- `FETCH_DATA_SUCCESSFULLY` — this constant IS defined globally

## Dependencies

| File | Role |
|------|------|
| `routes/api.php` | Route definitions (lines 112-120) |
| `app/Http/Controllers/Api/ShipmentController.php` | Controller |
| `app/Http/Requests/Shipment/CreateShipmentRequest.php` | Create validation |
| `app/Http/Requests/Shipment/UpdateShipmentRequest.php` | Update validation |
| `app/Http/Requests/Shipment/UpdateShipmentStatusRequest.php` | Status update validation |
| `app/Services/Shipment/ShipmentService.php` | Business logic |
| `app/Models/Shipment.php` | Model |
| `app/Enums/ShipmentStatus.php` | Status enum with state machine |
| `database/migrations/2026_07_28_000004_create_shipments_table.php` | Migration |
| `packages/marvel/src/Enums/Permission.php` | Permissions enum |
| `packages/marvel/Traits/ApiResponse.php` | API response trait |

## Missing Layers (compared to Brand module)

| Layer | Status |
|-------|--------|
| ShipmentResource | ❌ Missing — raw model data returned directly |
| Observer | ❌ Missing — no activity logging |
| Constants | ❌ Missing — hardcoded strings used |
| Translations | ❌ Missing — all messages in English only |
| Tests | ❌ Missing — no test files exist |
| Seeder | ❌ Missing — no shipment seed data |
