# Attribute Module — Frontend Jira Tasks (CRUD)

## ATT-FE-001: Attributes CRUD Table Page

| Field | Value |
|-------|-------|
| Priority | High |
| Component | UI |
| Story Points | 5 |
| Description | Paginated table listing all attributes. Columns: Name, Values Count, Actions (View, Edit, Delete). |
| Acceptance Criteria | - Pagination (15/page) - Search by name - Sort by columns - Empty state - Row hover |

## ATT-FE-002: Create/Edit Attribute Form

| Field | Value |
|-------|-------|
| Priority | High |
| Component | UI |
| Story Points | 8 |
| Description | Form with bilingual name inputs (en/ar) and dynamic values list with add/remove. |
| Acceptance Criteria | - Bilingual name inputs with validation - Dynamic values rows with add/remove - Each value has en/ar inputs - Validation errors inline - Loading on submit |

## ATT-FE-003: Attribute Detail Page

| Field | Value |
|-------|-------|
| Priority | Medium |
| Component | UI |
| Story Points | 3 |
| Description | Detail page showing attribute name in both languages and its values list. |
| Acceptance Criteria | - Name in current locale - Values list - Links to edit/delete |

## ATT-FE-004: Delete Attribute Confirmation Dialog

| Field | Value |
|-------|-------|
| Priority | High |
| Component | UI |
| Story Points | 2 |
| Description | Confirmation dialog warning about permanent deletion and cascade to values/variants. |
| Acceptance Criteria | - Shows attribute name - Cascade warning - Confirm/Cancel - Loading state - Notifications |

## ATT-FE-005: Loading, Empty, Error States

| Field | Value |
|-------|-------|
| Priority | Medium |
| Component | UI |
| Story Points | 3 |
| Description | Skeleton loading, empty illustrations, error messages with retry. |
| Acceptance Criteria | - Loading skeletons - Empty state CTAs - Error retry - Permission denied message |

## ATT-FE-006: i18n Support

| Field | Value |
|-------|-------|
| Priority | Medium |
| Component | i18n |
| Story Points | 2 |
| Description | All UI text translatable. Handle name format difference (list = translated, show = raw JSON). |
| Acceptance Criteria | - UI text in translation files - Correct display per locale - Detail form shows raw JSON for editing |

## ATT-FE-007: Search Attributes

| Field | Value |
|-------|-------|
| Priority | Medium |
| Component | UI |
| Story Points | 2 |
| Description | Search input with debounce on attribute name. |
| Acceptance Criteria | - Debounced input (300ms) - Search by name - Clear button - Inline results |
