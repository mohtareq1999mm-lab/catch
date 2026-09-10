# Bug Report — Shipment Module

---

## BUG-SHP-001: Uncaught ModelNotFoundException Returns HTML Instead of JSON 404

**Severity:** HIGH

**Component:** `App\Http\Controllers\Api\ShipmentController`

**Description:** The `show()`, `showByUuid()`, `update()`, and `updateStatus()` methods call `findOrFail()` / `firstOrFail()` in `ShipmentService`, which throws `Illuminate\Database\Eloquent\ModelNotFoundException` when the shipment does not exist. This exception is NOT caught by the controller, so Laravel's exception handler returns an HTML debug page (or a generic error page in production) instead of a proper JSON 404 response.

**Code Locations:**
- `app/Http/Controllers/Api/ShipmentController.php:40` — `show()` calls `$this->shipmentService->find($id)` → `findOrFail()`
- `app/Http/Controllers/Api/ShipmentController.php:47` — `showByUuid()` calls `$this->shipmentService->findByUuid($uuid)` → `firstOrFail()`
- `app/Http/Controllers/Api/ShipmentController.php:60` — `updateStatus()` calls `$this->shipmentService->updateStatus($id, ...)` → `lockForUpdate()->findOrFail()`
- `app/Http/Controllers/Api/ShipmentController.php:76` — `update()` calls `$this->shipmentService->update($id, ...)` → `findOrFail()`

**Execution Trace:**
```
Client → GET /api/v1/shipments/99999
  → ShipmentController@show(99999)
  → ShipmentService::find(99999)
  → Shipment::with('order')->findOrFail(99999)
  → ModelNotFoundException thrown
  → Laravel Handler → HTML response (not JSON)
```

**Production Impact:**
- Frontend receives HTML instead of JSON → parse error in JS fetch/axios
- No consistent error handling for 404s

**Fix:** Add try/catch for `ModelNotFoundException` in each controller method, or add a global exception handler in `App\Exceptions\Handler`.

---

## BUG-SHP-002: Hardcoded English Strings Instead of Translation Keys

**Severity:** MEDIUM

**Component:** `App\Http\Controllers\Api\ShipmentController`

**Description:** Success messages use hardcoded English strings instead of constants with translation keys:
```php
'Shipment created successfully'      // Line 56
'Shipment status updated'            // Line 68
'Shipment updated successfully'      // Line 78
```

Other modules (Brand, Category, etc.) use `__(BRAND_CREATED_SUCCESSFULLY)` pattern with constants in `packages/marvel/config/constants.php` and translation keys in `resources/lang/{en,ar}/message.php`.

**Production Impact:**
- Arabic locale will show English messages (no translation fallback)
- Inconsistent with other modules

**Fix:**
1. Define constants: `SHIPMENT_CREATED_SUCCESSFULLY`, `SHIPMENT_UPDATED_SUCCESSFULLY`, `SHIPMENT_STATUS_UPDATED`
2. Add translation keys in both `en/message.php` and `ar/message.php`
3. Replace hardcoded strings with `__(CONSTANT_NAME)`

---

## BUG-SHP-003: No Rate Limiting on Shipment Endpoints

**Severity:** MEDIUM

**Component:** `routes/api.php`

**Description:** The shipment route group has no `throttle` middleware. Frequent polling (e.g., checking shipment status every second) can overwhelm the server. The Cart module has 20 req/min rate limiting (via `RateLimiter::for('cart')`).

**Code Location:** `routes/api.php:113`

**Production Impact:**
- Potential DoS vector
- Server resource exhaustion from polling

**Fix:** Register `RateLimiter::for('shipment')` in `RouteServiceProvider` and apply `->middleware('throttle:shipment')` to the shipment route group.

---

## BUG-SHP-004: `update()` Method Missing Database Transaction

**Severity:** LOW

**Component:** `App\Services\Shipment\ShipmentService`

**Description:** The `update()` method performs `findOrFail()` and `update()` outside of a `DB::transaction()`, while `create()` and `updateStatus()` both use transactions. If a concurrent request modifies the same record between `findOrFail()` and `update()`, the update could overwrite stale data.

**Code Location:** `app/Services/Shipment/ShipmentService.php:70-75`

```php
public function update(int $id, array $data): Shipment
{
    $shipment = Shipment::findOrFail($id);   // No lock
    $shipment->update($data);                 // Could overwrite concurrent changes
    return $shipment->fresh();
}
```

**Fix:** Wrap in `DB::transaction()` with optional `lockForUpdate()`.

---

## BUG-SHP-005: Duplicated State Machine Logic

**Severity:** LOW

**Component:** `App\Enums\ShipmentStatus` / `App\Models\Shipment`

**Description:** The shipment state machine transition rules are defined in TWO places:
1. `ShipmentStatus::allowedTransitions()` — pure enum method
2. `Shipment::allowedTransitions()` — static model method

The service layer calls `Shipment::canTransitionTo()` (model), never `ShipmentStatus::canTransitionTo()` (enum). The enum method is unused in the execution flow.

**Code Locations:**
- `app/Enums/ShipmentStatus.php:19-32`
- `app/Models/Shipment.php:69-84`

**Production Impact:**
- If the two definitions diverge during maintenance, behavior becomes inconsistent
- Future developers may call the wrong method and get unexpected results

**Fix:** Consolidate state machine logic into the `ShipmentStatus` enum and have `Shipment::canTransitionTo()` delegate to the enum. Or remove the enum's method if the model is the single source of truth.

---

## BUG-SHP-006: No DELETE_SHIPMENT Permission (Architectural Gap)

**Severity:** LOW

**Component:** `Marvel\Enums\Permission`

**Description:** The controller has no `destroy` endpoint and the Permission enum has no `DELETE_SHIPMENT` constant. While this is consistent (no delete = no permission needed), it means that if a delete requirement arises in the future, a new permission must be added and seeded.

**Code Location:** `packages/marvel/src/Enums/Permission.php:262-266`

**Production Impact:** Low — no existing business requirement for deletion. Shipments are cancelled via status transition instead.

**Fix:** Add `DELETE_SHIPMENT` permission and `destroy()` method when business requirement arises.
