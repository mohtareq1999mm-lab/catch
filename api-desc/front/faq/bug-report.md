# Bug Report - FAQ Feature

## Current State

9 test files in `tests/Feature/Faqs/`. No known open bugs. The following were identified during investigation:

## Issue 1: Missing English Translation Keys

- **Files:** `resources/lang/en/message.php`
- **Affected Keys:** `MESSAGE.FAQ_CREATED_SUCCESSFULLY`, `MESSAGE.FAQ_UPDATED_SUCCESSFULLY`, `MESSAGE.FAQ_DELETED_SUCCESSFULLY`, `MESSAGE.FAQS_REORDERED_SUCCESSFULLY`
- **Description:** The constants are defined in `packages/marvel/config/constants.php` and used in the controller, and Arabic translations exist, but English translations are missing.
- **Impact:** Medium — English users see the translation key string instead of a proper message.
- **Severity:** Medium

## Issue 2: No Laravel Policy Class

- **Description:** Authorization is handled entirely via Spatie Permission middleware in the controller constructor. No dedicated `FaqPolicy` class exists.
- **Impact:** Low — functional, but inconsistent with Laravel conventions.
- **Severity:** Low

## Issue 3: No Events/Observers for Activity Logging

- **Description:** Unlike Category and Promotion features (which use Observers + `LogActivityJob`), FAQ CRUD operations have no activity logging. Changes are not tracked.
- **Impact:** Low — no audit trail for FAQ changes.
- **Severity:** Low

## Issue 4: No Media Support

- **Description:** The `Faqs` model does not implement `HasMedia` or use `InteractsWithMedia`. Other models in the codebase (Category, Slider, Promotion, etc.) support image attachments.
- **Impact:** Low — FAQs don't typically need images, but inconsistent with the rest of the codebase pattern.
- **Severity:** Low

## Issue 5: Public Endpoint Returns All Active FAQs (No Pagination)

- **File:** `app/Services/General/faqService.php`
- **Description:** The public `getfaqs()` method returns all active FAQs via `Faqs::active()->get()` with no pagination, limit, or filtering.
- **Impact:** Low — FAQ lists are typically small, but could become large over time.
- **Severity:** Low
