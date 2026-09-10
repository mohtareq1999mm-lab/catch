# Bug Report — Flash Sale Module (Public API)

---

## BUG-FLASH-001: `index()` Method Has Implicit Slug Route Logic

**Severity:** Low

**Component:** `app/Http/Controllers/Api/General/FlashSaleController.php` (line 27)

**Description:** Same pattern as brands/banners — passing `?slug=x` to the listing endpoint returns a single flash sale instead of a list. Undocumented behavior.

---

## BUG-FLASH-002: `getFlashSaleBySlug()` Doesn't Check `valid()` Scope

**Severity:** Medium

**Component:** `app/Services/General/FlashSaleService.php` (line 47)

**Description:** The `getFlashSaleBySlug()` method uses `FlashSale::search(...)->first()` without the `->valid()` scope. This means expired or inactive flash sales can still be accessed directly by slug. The listing endpoint correctly uses `->valid()`.

**Code Location:** `app/Services/General/FlashSaleService.php` — lines 45-53

```php
public function getFlashSaleBySlug($slug)
{
    $FlashSale = FlashSale::search('slug', $slug, app()->getLocale())->first();
    // Missing ->valid() scope!
    ...
}
```

**Recommendation:** Add `->valid()` to the query: `FlashSale::valid()->search(...)->first()`

---

## BUG-FLASH-003: Typo in FlashSaleResource — `discription` Instead of `description`

**Severity:** Low

**Component:** `app/Http/Resources/FlashSale/FlashSaleResource.php` (line 21)

**Description:** The response key is `discription` (missing 's') instead of `description`. This is a breaking misspelling that frontend code must account for.

**Code:**
```php
'discription' => $this?->getTranslation('description', app()->getLocale()),
```

**Recommendation:** Fix to `description`.

---

## BUG-FLASH-004: No Tests for Public Flash Sale Endpoints

**Severity:** Medium

**Component:** Tests

**Description:** Only one test exists (`FastShippingControllerTest::flash_sales_endpoint_works_with_channel_header`) which tests channel header behavior. No tests cover listing, slug lookup, products-by-qty, ending-this-week, or ending-today endpoints.

---

## BUG-FLASH-005: `getFlashSalesAndHereProductsByQtySet` Doesn't Use `valid()` Scope

**Severity:** Medium

**Component:** `app/Services/General/FlashSaleService.php` (line 60)

**Description:** The method does use `->valid()` on the FlashSale query. This is correct. No bug here.

(Correct — listed for completeness.)
