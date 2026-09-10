# FAQ Module — Backend Jira Tasks

## Task 1: Add English FAQ Translation Keys

**Component:** Translations
**File:** `resources/lang/en/message.php`
**Status:** ❌ Open

**Description:** The English `message.php` file is missing FAQ translation keys. The Arabic translations exist in `resources/lang/ar/message.php` but the English ones fall back to raw constant strings. Add:
```php
'MESSAGE.FAQ_CREATED_SUCCESSFULLY' => 'FAQ created successfully',
'MESSAGE.FAQ_UPDATED_SUCCESSFULLY' => 'FAQ updated successfully',
'MESSAGE.FAQ_DELETED_SUCCESSFULLY' => 'FAQ deleted successfully',
'MESSAGE.FAQS_REORDERED_SUCCESSFULLY' => 'FAQs reordered successfully',
```

---

## Task 2: Sync GraphQL Schema with Current Migration

**Component:** GraphQL
**Files:** `packages/marvel/src/GraphQL/Schema/models/faqs.graphql`
**Status:** ❌ Open

**Description:** The GraphQL schema references columns (`shop_id`, `slug`, `faq_type`, `issued_by`, `language`, `translated_languages`) that no longer exist in the current migration. The schema needs to be updated to match the current `faqs` table structure.

---

## Task 3: Document User/Shop Relationship Inconsistency

**Component:** Model
**File:** `packages/marvel/src/Database/Models/Faqs.php`
**Status:** ❌ Open

**Description:** The Faqs model defines `user()` and `shop()` BelongsTo relations but the current migration does not have `user_id` or `shop_id` columns. These are remnants from an older schema. Either add the columns back to the migration or remove the relations from the model.

---

## Task 4: Add Comprehensive Tests

**Component:** Tests
**Files:** `tests/Feature/Faqs/*.php`
**Status:** ✅ Done (9 test files, ~60+ tests)

**Description:** 9 test files covering CRUD, validation, authentication, authorization, resource structure, soft deletes, translations, reorder, and regression tests.

---

## Task 5: Verify All FAQ Endpoints Work End-to-End

**Component:** System Integration
**Status:** ❌ Open

**Description:** Run all FAQ tests to verify no regressions.

---

## Task 6: Add Search Functionality to Public FAQ Endpoint

**Component:** Public Controller / Service
**Status:** ❌ Open

**Description:** The public `/api/v1/general/faqs` endpoint has no search or filter parameters. Consider adding `search` query parameter to filter FAQs by title/description.

---

## Task 7: Add Pagination to Public FAQ Endpoint

**Component:** Public Controller / Service
**File:** `app/Services/General/faqService.php`
**Status:** ❌ Open

**Description:** The public endpoint returns ALL active FAQs in one response. For platforms with many FAQs, consider adding pagination (limit/page parameters).
