# Attribute Module — Frontend Integration Guide

## Overview

The Attribute module manages product attributes (Size, Color, Material) and their values. Attributes define product variation dimensions. CRUD only — no public endpoints.

## Endpoints

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/attributes` | List attributes (paginated, searchable) |
| POST | `/api/v1/attributes` | Create attribute with optional values |
| GET | `/api/v1/attributes/{id}` | Show attribute by ID or slug |
| PUT | `/api/v1/attributes/{id}` | Update attribute name and/or sync values |
| DELETE | `/api/v1/attributes/{id}` | Delete attribute (cascades to values) |

## Response Structure

```json
{
  "id": 1,
  "name": "Size",
  "slug": "size",
  "values": [
    { "id": 1, "value": "Small", "slug": "small" },
    { "id": 2, "value": "Large", "slug": "large" }
  ]
}
```

## States

### Loading
- List: Skeleton rows for attributes table
- Detail: Skeleton card with value placeholders
- Create/Edit: Disable submit button with spinner

### Empty
- No attributes: "No attributes yet" with CTA
- No search results: "No matches" with clear search
- No values: "No values" message in attribute detail

### Error
- 401: Redirect to login
- 403: "You don't have permission"
- 404: "Attribute not found" with back
- 422: Inline validation per field
- 500: "Something went wrong" with retry

## Key Considerations

### Values Sync on Update
- Server **replaces** values on update (slug-based matching)
- Send complete list of desired values — omitted values are deleted
- Values with matching slugs preserved (ID + pivot relations kept)

### Translatable Fields
- Both `name` (attribute) and `value` (value) require `en` + `ar`
- Create form needs bilingual inputs for name and each value

### Delete Warning
- **Hard delete** — no recovery
- All values and product variant associations removed
- Show confirmation dialog with cascade warning

### Name Format Difference
- Index: `name` returned as **translated string** ("Size")
- Show: `name` returned as **raw JSON** (`{"en": "Size", "ar": "حجم"}`)
