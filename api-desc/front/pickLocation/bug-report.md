# Bug Report — Pickup Location Module (Public API)

---

## BUG-PICK-001: No Caching on Public Endpoints

**Severity:** Low

**Component:** `app/Services/General/PickupLocationService.php`

**Description:** Both `getPickupLocations()` and `getPickupLocationById()` hit the database on every request. Pickup locations rarely change, making them ideal candidates for caching.

**Code Location:** `PickupLocationService.php` — lines 9, 23

---

## BUG-PICK-002: `working_hours` Structure Not Standardized

**Severity:** Low

**Component:** `packages/marvel/src/Database/Models/PickupLocation.php` (line 28)

**Description:** `working_hours` is cast to `array` with no defined schema. Different stores could enter data in different formats, causing frontend rendering issues.

---

## BUG-PICK-003: `show()` Swallows All Exceptions as 404

**Severity:** Low

**Component:** `app/Http/Controllers/Api/General/PickupLocationController.php` (lines 30-35)

**Description:** The `show()` method catches any `\Exception` and returns 404. If the database connection fails or another system error occurs, the user sees a misleading "not found" error instead of a 500.

---

## BUG-PICK-004: `index()` Missing Filtering & Sorting Parameters

**Severity:** Low

**Component:** `app/Services/General/PickupLocationService.php`

**Description:** The listing endpoint only supports `search` and `limit`. No support for `city`, `governorate`, `page`, or other common location filters. The `ordered()` scope is hardcoded with no sort direction override.
