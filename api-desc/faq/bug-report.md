# Bug Report — FAQ Module

---

## BUG-FAQ-001: English FAQ Translation Keys Missing

**Severity:** Low

**Component:** Translations

**Description:** The English `resources/lang/en/message.php` file does not contain FAQ translation keys. The Arabic translations exist in `resources/lang/ar/message.php`, but the English keys fall back to raw constant strings (e.g., `"MESSAGE.FAQ_CREATED_SUCCESSFULLY"` displayed as-is instead of "FAQ created successfully").

**Code Location:** `resources/lang/en/message.php`

**Status:** ❌ Open

---

## BUG-FAQ-002: GraphQL Schema References Non-Existent Columns

**Severity:** Medium

**Component:** GraphQL Schema

**Description:** The GraphQL schema at `packages/marvel/src/GraphQL/Schema/models/faqs.graphql` defines fields (`shop_id`, `slug`, `faq_type`, `issued_by`, `language`, `translated_languages`) that do not exist in the current `faqs` migration. Queries or mutations referencing these fields will fail.

**Code Location:** `packages/marvel/src/GraphQL/Schema/models/faqs.graphql`

**Status:** ❌ Open

---

## BUG-FAQ-003: User and Shop Relations Defined But Columns Missing

**Severity:** Low

**Component:** Model

**Description:** The `Faqs` model defines `user()` and `shop()` BelongsTo relations, but the current `faqs` migration does not have `user_id` or `shop_id` columns. These relations will return null or throw errors when accessed.

**Code Location:** `packages/marvel/src/Database/Models/Faqs.php` — lines for `user()` and `shop()` relations

**Note:** The `fetchFAQs()` method previously relied on these relations for role-based scoping. The method was simplified on 2026-07-21 to remove the dependency. The model relations remain but are unused.

**Status:** ✅ Mitigated — fetchFAQs() simplified to remove dependency on missing columns

---

## BUG-FAQ-004: Public FAQ Endpoint Missing Search/Pagination

**Severity:** Low

**Component:** Public API

**Description:** The public `/api/v1/general/faqs` endpoint returns ALL active FAQs with no search, filter, or pagination support. For platforms with many FAQs (50+), this can result in large response payloads.

**Code Location:** `app/Services/General/faqService.php` — `getfaqs()`

**Status:** ❌ Open — Enhancement request

---

## BUG-FAQ-005: Index Response Title Format Differs From Other Routes

**Severity:** Low

**Component:** Resource

**Description:** On `faqs.index`, the `FaqResource` returns a translated string for the current locale. On all other routes (show, store, update), it returns the raw JSON object with all locales. This inconsistency may confuse frontend developers expecting a consistent response format.

**Code Location:** `packages/marvel/src/Http/Resources/FaqResource.php`

**Status:** ✅ By design — Index returns single locale for listing display; show returns raw JSON for editing.

---

## BUG-FAQ-006: status and order Fields Missing From API Response

**Severity:** Medium

**Component:** Resources

**Description:** Both `Marvel\Http\Resources\FaqResource` (admin) and `App\Http\Resources\Faqs\FaqResource` (public) omitted `status` and `order` from their `toArray()` return. The data existed in the database but was never included in API responses.

**Code Location:**
- `packages/marvel/src/Http/Resources/FaqResource.php:21-22`
- `app/Http/Resources/Faqs/FaqResource.php:20-21`

**Status:** ✅ Fixed 2026-07-21

**Fix:** Added `'status' => (int) $this->status` and `'order' => (int) $this->order` to both resources.

---

## BUG-FAQ-007: status Required in CreateFaqsRequest Overrides DB Default

**Severity:** Medium

**Component:** Validation

**Description:** `CreateFaqsRequest` had `'status' => ['required', "in:1,0"]` which forced clients to always send status. Additionally, `FaqsRepository::storeFaqs()` unconditionally set `$faqs['status'] = $request['status']`, which passed `null` to the database when omitted, overriding the DB default of `true`.

**Code Location:**
- `packages/marvel/src/Http/Requests/CreateFaqsRequest.php:36`
- `packages/marvel/src/Database/Repositories/FaqsRepository.php:68`

**Status:** ✅ Fixed 2026-07-21

**Fix:** Changed validation to `'sometimes'` and made repository only set status when present in request.

---

## BUG-FAQ-008: fetchFAQs() Had Dead Code Referencing Missing Columns

**Severity:** Low

**Component:** Controller

**Description:** `FaqsController::fetchFAQs()` had 60+ lines of role-based scoping logic (Super Admin, Store Owner, Staff branches) that referenced `shop_id` and `user_id` columns not present in the `faqs` migration. The code was unreachable/dead for production use.

**Code Location:** `packages/marvel/src/Http/Controllers/FaqsController.php`

**Status:** ✅ Fixed 2026-07-21

**Fix:** Simplified to single paginated query: `$this->repository->query()->paginate($request->limit ?? 10)`.

---

## BUG-FAQ-009: Controller store() Method Used Generic Request Type

**Severity:** Low

**Component:** Controller

**Description:** `FaqsController::store()` accepted `Request $request` instead of `CreateFaqsRequest $request`, bypassing the dedicated form request's type safety.

**Code Location:** `packages/marvel/src/Http/Controllers/FaqsController.php:216`

**Status:** ✅ Fixed 2026-07-21

**Fix:** Changed parameter type to `CreateFaqsRequest $request`.
