# Bug Report — Flash Sale Module

---

## BUG-FS-001: Inline Validation in `reorder()` Method

**Severity:** Low

**Component:** `Marvel\Http\Controllers\FlashSaleController::reorder()`

**Description:** The `reorder()` method uses `$request->validate([...])` inline instead of a dedicated Form Request class. This violates separation of concerns — validation belongs in Form Request classes.

**Code Location:** `packages/marvel/src/Http/Controllers/FlashSaleController.php`

**Current Behavior:**
```php
$request->validate([
    'flash_sales' => 'required|array',
    'flash_sales.*' => 'required|exists:flash_sales,id',
]);
```

**Recommendation:** Extract to a dedicated `FlashSaleReorderRequest` Form Request class (like brand's `BrandsReorderRequest`).

---

## BUG-FS-002: `updateFlashSale()` and `deleteFlashSale()` Are Public

**Severity:** Low

**Component:** `Marvel\Http\Controllers\FlashSaleController`

**Description:** Both `updateFlashSale()` and `deleteFlashSale()` are declared `public` but are only called internally by `update()` and `destroy()` respectively. Public methods expose unnecessary surface area and could be called as route actions if misconfigured.

**Code Location:** `packages/marvel/src/Http/Controllers/FlashSaleController.php`

**Current Behavior:**
```php
public function updateFlashSale(Request $request) { ... }
public function deleteFlashSale(Request $request) { ... }
```

**Recommendation:** Change visibility to `private`.

---

## BUG-FS-003: No Guard Against Reordering Non-Existent Flash Sales

**Severity:** Low

**Component:** `FlashSaleRepository::reorder()`

**Description:** The validation only checks existence at validation time. There is a race condition where a flash sale could be deleted between validation and `setNewOrder()`. Additionally, no `DB::transaction()` wraps the reorder operation.

**Code Location:** `packages/marvel/src/Database/Repositories/FlashSaleRepository.php`

**Current Behavior:**
```php
public function reorder(array $flashSales)
{
    try {
        $this->setNewOrder($flashSales);
    } catch (\Exception $e) {
        throw new HttpException(500, $e->getMessage());
    }
}
```

**Recommendation:** Wrap in `DB::transaction()` for atomicity.

---

## BUG-FS-004: Missing Translation Keys for Flash Sale Messages

**Severity:** Medium

**Component:** `resources/lang/en/message.php`

**Description:** Flash sale success constant keys are missing from the English translation file:

| Key | en | ar |
|-----|----|----|
| `MESSAGE.CREATE_FLASH_SALE_SUCCESSFULLY` | ❌ Missing | ✅ Present |
| `MESSAGE.UPDATE_FLASH_SALE_SUCCESSFULLY` | ❌ Missing | ✅ Present |
| `MESSAGE.DELETE_FLASH_SALE_SUCCESSFULLY` | ❌ Missing | ✅ Present |
| `MESSAGE.FLASH_SALE_REORDERED_SUCCESSFULLY` | ❌ Missing | ✅ Present |

**Impact:** Medium — API responses for flash sale operations will display constant paths instead of human-readable messages in English locale.

**Recommendation:** Add the missing keys to `resources/lang/en/message.php`.

---

## BUG-FS-005: `getFlashSaleInfoByProductID` Can Return Raw Data

**Severity:** Medium

**Component:** `FlashSaleController::getFlashSaleInfoByProductID()`

**Description:** The method returns raw `$product->flash_sales` (BelongsToMany collection) directly without wrapping in a Resource. If the product is not found, it returns an empty array `[]` with a 200 status, which may be misleading.

**Code Location:** `packages/marvel/src/Http/Controllers/FlashSaleController.php`

**Current Behavior:**
```php
public function getFlashSaleInfoByProductID(Request $request)
{
    $flash_sale_info = [];
    $product = Product::find($request->id);
    if ($product) {
        $flash_sale_info = $product->flash_sales;
    }
    return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $flash_sale_info);
}
```

**Recommendation:** Wrap in a Resource, return explicit 404 when product not found.

---

## BUG-FS-006: `fetchFlashSales()` Throws Exception Instead of Returning 500

**Severity:** Low

**Component:** `FlashSaleController::index()`

**Description:** The `index()` method catches `MarvelException` but the `fetchFlashSales()` method is a simple query builder that doesn't throw `MarvelException` — it would throw a generic `Exception` which is not caught by the try-catch. Similarly, the catch block returns 500 instead of throwing.

**Code Location:** `packages/marvel/src/Http/Controllers/FlashSaleController.php`

**Current Behavior:**
```php
try {
    $flashSales = $this->fetchFlashSales($request)->paginate($limit)->withQueryString();
    // ...
} catch (MarvelException $e) {
    return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
}
```

**Recommendation:** Catch `\Exception` instead of `MarvelException`, or remove the try-catch and let the global handler manage it.

---

## BUG-FS-007: Route `flash-sale/reorder` Must Be Defined Before `apiResource`

**Severity:** Low

**Component:** `packages/marvel/src/Rest/Routes.php`

**Description:** The `PUT flash-sale/reorder` route (line 673) is defined before `apiResource('flash-sale', ...)` (line 675), so the literal `reorder` matches before `{flash_sale}` parameter binding. This is correct now but fragile — if route order were ever changed, `PUT flash-sale/reorder` would be caught by `PUT flash-sale/{flash_sale}` → `update()` and attempt to find a flash sale with ID "reorder", resulting in a 404.

**Current Behavior:** Reorder route is correctly positioned before apiResource.

**Recommendation:** Add a defensive comment explaining the ordering requirement.

---

## BUG-FS-008: `FlashSaleUpdateRequest` Ignores Own Title via Route Parameter

**Severity:** Low

**Component:** `Marvel\Http\Requests\UpdateFlashSaleRequest`

**Description:** The uniqueness check uses `$this->route("flash_sale")` to get the current flash sale ID for the ignore rule. If the route parameter name changes (e.g., due to apiResource naming convention changes), the ignore logic would silently fail.

**Code Location:** `packages/marvel/src/Http/Requests/UpdateFlashSaleRequest.php`

**Current Behavior:**
```php
$id = $this->route("flash_sale");
```

**Impact:** Low — route parameter name is stable as it's generated by `apiResource('flash-sale', ...)`.

---

## BUG-FS-009: Inconsistent Naming — `FlASH` Instead of `FLASH` in Permission Constants

**Severity:** Low

**Component:** `Marvel\Enums\Permission`

**Description:** Permission constants use `FlASH` (lowercase 'l', uppercase 'ASH') instead of `FLASH`:
```php
public const VIEW_FlASH_SALE = "view-flash-sale";
public const CREATE_FlASH_SALE = "create-flash-sale";
```

**Impact:** Very low — cosmetic only, the string values are correct. The constant names are internally consistent within the codebase.

---

## BUG-FS-010: `FlashSaleResource` Returns Raw Title on List, Translated on Detail

**Severity:** Low

**Component:** `Marvel\Http\Resources\FlashSaleResource`

**Description:** The resource conditionally returns raw vs translated title based on route name:
```php
"title" => request()->routeIs('flash-sale.index')
    ? $this->getRawOriginal('title')
    : $this->getTranslation("title", app()->getLocale()),
```

On `index` routes, the raw JSON is returned. On other routes, the translated string is returned. This means list responses contain raw JSON strings for title, while detail responses contain human-readable translated text.

**Impact:** Low — intentional design choice, but may cause inconsistency for API consumers.
