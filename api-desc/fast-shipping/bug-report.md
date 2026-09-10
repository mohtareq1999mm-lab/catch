# Fast Shipping — Bug Report

## Known Bugs

### BUG-FS-001: FastShippingScope may cause unexpected filtering in admin panel

**Severity:** Medium
**Status:** Open
**Description:** The `FastShippingScope` is a global scope on the Product model. If an admin sends `X-Channel: fast-shipping` header (e.g., from a POSTman test), the admin product listing will be filtered to only show fast-shipping-eligible products.
**Impact:** Admin may not see all products when using the channel header.
**Recommendation:** Disable the scope in admin routes by checking the request path or using a different approach.

### BUG-FS-002: Settings cache not invalidated on concurrent updates

**Severity:** Low
**Status:** Open
**Description:** Two simultaneous `updateSettings` calls could cause cache to be invalidated before the second write completes, potentially returning stale data.
**Impact:** Low - settings are rarely updated concurrently.
**Recommendation:** Use a mutex lock or atomic cache invalidation.

### BUG-FS-003: ETA calculation does not consider order processing time

**Severity:** Low
**Status:** By Design
**Description:** `calculateEta()` simply adds `duration_minutes` to the current time. It does not account for order processing, food preparation, or driver dispatch time.
**Impact:** ETA may be optimistic.
**Recommendation:** Add a processing buffer to the calculation.

### BUG-FS-004: FastShippingScope and search queries may conflict

**Severity:** Medium
**Status:** Open
**Description:** When FastShippingScope applies `WHERE is_fast_shipping_available = 1` and the search query adds additional WHERE clauses, there could be unexpected query plan changes on large datasets.
**Impact:** Potential performance degradation on large product tables.
**Recommendation:** Add a composite index on `(is_fast_shipping_available, name)`.

### BUG-FS-005: `checkout` returns 200 for empty payment method fallback

**Severity:** Low
**Status:** Open
**Description:** If `payment_method` is empty and the code falls through all conditions, it returns "Invalid payment method" with 422. But if a new payment method is added in the future without updating this method, it silently returns an error.
**Impact:** Minor - proper validation is in the FormRequest.
**Recommendation:** Add an exhaustive check or default behavior.
