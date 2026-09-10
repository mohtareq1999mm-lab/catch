# Bug Report - Slider Feature

## Current State

29 automated tests exist in `tests/Feature/SliderApiTest.php`. No known open bugs were identified. The following issues were observed during investigation:

## Issue 1: Duplicate Route Registration

- **File:** `packages/marvel/src/Rest/Routes.php`
- **Description:** `GET /sliders` is registered twice — once as a full `apiResource` (line 201) and once as an `apiResource` with `only:['index']` (line 365). Laravel resolves the first registration, so both are functionally equivalent, but the duplication is dead code.
- **Impact:** Low — no runtime impact. Minor maintenance burden.
- **Severity:** Low

## Issue 2: Media Collection Inconsistency

- **File:** `packages/marvel/src/Database/Repositories/SliderRepository.php`
- **Description:** On `createSlider()`, images are uploaded to `slider-image-desktop` / `slider-image-mobile` collections. On `updateSlider()`, replacement images are uploaded to `sliders-desktop` / `sliders-mobile` collections. The Admin `SliderResource` has a fallback that checks both, but the naming inconsistency is technical debt.
- **Impact:** Low — works due to fallback, but confusing for maintenance.
- **Severity:** Low

## Issue 3: No Slider Policy

- **Description:** Authorization is handled entirely via Spatie Permission middleware in the controller constructor. No dedicated Policy class exists.
- **Impact:** Low — functional, but inconsistent with Laravel best practices if other features use Policies.
- **Severity:** Low

## Issue 4: No GraphQL Support

- **Description:** Sliders have no dedicated GraphQL queries or mutations. They are REST-only. The only GraphQL reference is `promotional_sliders` as media attachments on the `Type` model, which is unrelated to the Slider CRUD.
- **Impact:** Low — REST is sufficient, but GraphQL clients cannot manage sliders.
- **Severity:** Low

## Issue 5: Missing German Translations

- **Files:** `resources/lang/de/message.php`
- **Description:** Slider success messages have English and Arabic translations but no German translations.
- **Impact:** Low — German users see fallback English.
- **Severity:** Low

## Issue 6: No Events/Observers for Activity Logging

- **Description:** Unlike the Category feature (which has `CategoryObserver` + `LogActivityJob`), the Slider feature has no activity logging. Slider CRUD operations are not tracked in the activity log.
- **Impact:** Low — no audit trail for slider changes.
- **Severity:** Low
