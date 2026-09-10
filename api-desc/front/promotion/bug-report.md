# Bug Report - Promotion Feature

## Current State

5 test files with ~2,534 lines of tests. No known open bugs. The following were identified during investigation:

## Issue 1: No Promotion Policy

- **Description:** Authorization is handled entirely via Spatie Permission middleware in the controller constructor. No dedicated Laravel Policy class exists.
- **Impact:** Low — functional, but inconsistent with Laravel conventions.
- **Severity:** Low

## Issue 2: No GraphQL Support

- **Description:** Promotions have no dedicated GraphQL queries or mutations. REST-only.
- **Impact:** Low — REST suffices, but GraphQL clients cannot manage promotions.
- **Severity:** Low

## Issue 3: No Frontend Components

- **Description:** No Vue/React components for promotion management or display found in `resources/js/`.
- **Impact:** Low — frontend is a separate SPA.
- **Severity:** Low

## Issue 4: Promotion Engine Complexity

- **Description:** The promotion engine uses Strategy Pattern with 5+ service classes, DTOs, outcomes, and evaluators. The checkout integration spans `CartInventoryService`, `PromotionService`, `OrderService`, `OrderCreationService`, and `InvoiceSnapshotService`.
- **Impact:** Medium — deep chain of responsibility makes debugging and tracing difficult. Changes in one area can affect multiple downstream services.
- **Severity:** Medium

## Issue 5: Missing `type_amount` Validation Edge Cases

- **File:** `packages/marvel/src/Http/Requests/PromotionRequest.php`
- **Description:** The `type_amount` field accepts `fixed_rate`, `percentage`, or `gift`, but certain combination validations (e.g., `type=quantity` + `type_amount=gift`) may not be fully covered by the rule set.
- **Impact:** Low — business logic in strategies handles invalidity, but API may accept semantically invalid combinations.
- **Severity:** Low

## Issue 6: Unique Code Constraint

- **File:** `packages/marvel/src/Database/Models/Promotion.php`
- **Description:** The `code` column has a UNIQUE constraint. If auto-generation produces a collision (unlikely but possible with short codes), the save will fail with a DB exception rather than a user-friendly validation error.
- **Impact:** Low — edge case.
- **Severity:** Low
