# Bug Report - Content Page Feature

## Current State

2 test files exist (`ContentPageSectionTypeApiTest.php` at 1,069 lines, `CmsPageTest.php` at 127 lines). No known open bugs. The following were identified during investigation:

## Issue 1: No Laravel Policy Classes

- **Description:** Authorization is handled entirely via Spatie Permission middleware in controller constructors and role-based middleware on routes. No dedicated Policy classes exist for ContentPage, Section, SectionType, or CmsPage.
- **Impact:** Low — functional, but inconsistent with Laravel conventions.
- **Severity:** Low

## Issue 2: No GraphQL Support

- **Description:** No GraphQL queries or mutations for any page entity. REST-only.
- **Impact:** Low — REST suffices, but GraphQL clients cannot manage pages.
- **Severity:** Low

## Issue 3: Missing Permission Translation Labels

- **Files:** `resources/lang/en/permissions.php`, `resources/lang/ar/permissions.php`
- **Description:** Permission constants exist in code (`view-content-pages`, `create-sections`, etc.) but have no corresponding translation labels in the permissions language files. Slider and promotion permissions have labels, but page/section permissions do not.
- **Impact:** Medium — permission labels may display as keys in admin UI.
- **Severity:** Medium

## Issue 4: Two Parallel Page Systems

- **Description:** There are two separate page systems: `ContentPage` (sections-based) and `CmsPage` (Puck-ready). Both serve similar purposes but have different data models and APIs.
- **Impact:** Medium — duplication of functionality. Unclear which system is the "current" one.
- **Severity:** Medium

## Issue 5: No Frontend Components

- **Description:** No Vue/React components for page management or display found in `resources/js/`.
- **Impact:** Low — frontend is a separate SPA. However, the Puck API contract is well-defined in `packages/marvel/docs/puck-api.yaml`.
- **Severity:** Low

## Issue 6: Section `setting` Fallback Logic Duplicated

- **File:** `app/Http/Resources/Pages/SectionResource.php`
- **Description:** The SectionResource resolves settings by checking `section->setting` first, then falling back to `SectionType` defaults. This logic lives in the resource rather than a service, making it hard to reuse and test independently.
- **Impact:** Low — works correctly but not ideal for maintainability.
- **Severity:** Low
