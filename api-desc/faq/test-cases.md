# Test Coverage — FAQ Module

## Test Files Overview

| File | Type | Tests | Focus |
|------|------|-------|-------|
| `FaqCrudTest.php` | Feature | 7 | Create, list, show, update, delete, 404 on show, 404 on delete |
| `FaqValidationTest.php` | Feature | 3 | Partial update, status field, same title self-update |
| `FaqAuthenticationTest.php` | Feature | 7 | Unauthenticated access to all routes, authenticated access |
| `FaqAuthorizationTest.php` | Feature | 10 | View-only, create-only, update-only, delete-only, reorder, no permissions |
| `FaqResourceTest.php` | Feature | 7 | Paginated response, expected fields, translated strings, envelope, JSON type |
| `FaqSoftDeleteTest.php` | Feature | 5 | Soft deletes trait, hidden from index, 404 on show, force delete, multiple |
| `FaqTranslationTest.php` | Feature | 5 | Create with translations, locale-aware index, Arabic, show returns raw, translatable fields |
| `FaqReorderTest.php` | Feature | 3 | Reorder, order column updated, validation |
| `FaqRegressionTest.php` | Feature | 9 | Soft delete, resource translation, model traits, search, sorting |

**Approximate Total: 56+ tests**

---

## FaqCrudTest.php

| Test | Description |
|------|-------------|
| `can_create_faq` | Create FAQ with valid data, assert 201 |
| `can_list_faqs` | List FAQs, assert paginated response |
| `can_show_faq` | Show FAQ by ID, assert 200 with data |
| `can_update_faq` | Update FAQ, assert 200 with updated data |
| `can_delete_faq` | Delete FAQ, assert 200 |
| `show_returns_404_for_nonexistent_faq` | Non-existent ID → 404 |
| `delete_returns_404_for_nonexistent_faq` | Non-existent ID → 404 |

---

## FaqValidationTest.php

| Test | Description |
|------|-------------|
| `update_accepts_partial_data` | Update with only status field → success |
| `update_accepts_status_field` | Toggle status 0/1 |
| `update_allows_same_title_for_self` | Update with same title → success (ignores self) |

---

## FaqAuthenticationTest.php

| Test | Description |
|------|-------------|
| `unauthenticated_user_cannot_index_faqs` | No token → 401 |
| `cannot_show` | No token → 401 |
| `cannot_create` | No token → 401 |
| `cannot_update` | No token → 401 |
| `cannot_delete` | No token → 401 |
| `cannot_reorder` | No token → 401 |
| `authenticated_user_can_access_all_routes` | With token → 200 |

---

## FaqAuthorizationTest.php

| Test | Description |
|------|-------------|
| `user_with_view_only_can_index_and_show` | view-faqs permission → GET 200, others 403 |
| `cannot_create` | No create-faq → 403 |
| `cannot_update` | No update-faq → 403 |
| `cannot_delete` | No delete-faq → 403 |
| `cannot_reorder` | No update-faq → 403 |
| `user_with_create_only_can_create` | create-faq only → POST 200, others 403 |
| `user_with_update_only_can_update` | update-faq only → PUT 200, reorder 200, others 403 |
| `user_with_update_only_can_reorder` | update-faq → reorder 200 |
| `user_with_delete_only_can_delete` | delete-faq only → DELETE 200, others 403 |
| `user_with_no_faq_permissions_gets_forbidden` | No FAQ permissions → 403 all routes |

---

## FaqResourceTest.php

| Test | Description |
|------|-------------|
| `index_returns_paginated_response` | Response has pagination meta |
| `index_response_contains_expected_fields` | id, faq_title, faq_description present |
| `index_response_returns_translated_string_not_raw_json` | Translated string, not JSON object |
| `show_response_includes_id_title_and_description` | All fields in show response |
| `show_response_does_not_include_internal_fields` | No internal/DB-only fields leaked |
| `response_has_correct_envelope` | success, message, status wrapper |
| `response_type_is_json` | Content-Type header |

---

## FaqSoftDeleteTest.php

| Test | Description |
|------|-------------|
| `faq_uses_soft_deletes_trait` | Model uses SoftDeletes trait |
| `deleted_faq_not_in_index` | Deleted FAQ excluded from listing |
| `show_returns_404_for_soft_deleted_faq` | Soft-deleted → 404 |
| `force_delete_removes_permanently` | forceDelete → permanently gone |
| `multiple_soft_deletes_work` | Delete same FAQ multiple times |

---

## FaqTranslationTest.php

| Test | Description |
|------|-------------|
| `create_faq_with_multiple_translations` | Create with en + ar → both stored |
| `index_returns_translated_title_in_current_locale` | English locale → English title |
| `index_returns_arabic_translation_when_locale_is_ar` | Arabic locale → Arabic title |
| `show_returns_faq_with_translatable_fields` | Show returns raw JSON with all locales |
| `model_has_translatable_fields_defined` | Translatable array contains both fields |

---

## FaqReorderTest.php

| Test | Description |
|------|-------------|
| `can_reorder_faqs` | Reorder IDs → 200 |
| `reorder_updates_order_column` | Order column matches array position |
| `reorder_validates_faqs_required` | Missing faqs field → 422 |

---

## FaqRegressionTest.php

| Test | Description |
|------|-------------|
| `b1_soft_delete_does_not_hard_delete` | Soft delete preserves record |
| `b2_resource_returns_translated_name_on_index` | Index returns translated title |
| `b3_model_uses_soft_deletes` | SoftDeletes in use |
| `b4_model_has_translatable_fields` | HasTranslations in use |
| `b5_model_uses_sortable_trait` | SortableTrait in use |
| `b6_translation_keys_exist` | Translation constants defined |
| `b7_faq_search_by_title` | Search by title LIKE |
| `b8_faq_sorting_by_title_asc` | Sort asc by title |
| `b9_faq_sorting_by_title_desc` | Sort desc by title |

---

## Coverage Summary

| Category | Coverage | Notes |
|----------|----------|-------|
| Admin CRUD | ✅ Full | List, create, show, update, delete |
| Public API | ✅ Full | List active FAQs |
| Validation | ✅ Full | Create + update fields |
| Authentication | ✅ Full | All routes tested with/without token |
| Authorization | ✅ Full | All permission levels tested |
| Soft Delete | ✅ Full | Soft delete, restore, force delete |
| Translation | ✅ Full | Bilingual create, locale-aware index |
| Reorder | ✅ Full | Reorder flow, validation |
| Schema Sync | ❌ Open | GraphQL schema out of sync with migration |
| English Translations | ❌ Open | Missing keys in en/message.php |
