# Fixed: Section Multilingual Title Not Saving in FormData

## What Happened

When creating or editing a Section using FormData, the multilingual title (Arabic/English) was silently lost and saved as an empty array `[]` instead of the actual values you entered.

For example, sending:
- `title[en]`: "Featured Products"
- `title[ar]`: "منتجات مميزة"

Would result in the section having no title at all.

**Note:** This only happened when the request was sent as `multipart/form-data` (FormData). Regular JSON requests were never affected.

## What Was Fixed

The backend now correctly processes multilingual titles sent via FormData. No changes are needed on the frontend side.

## Frontend Requirements

When sending Section create/update requests via FormData, always send translations using the bracket notation:

```
title[en]: English Title
title[ar]: عنوان عربي
```

**Do NOT** send as a JSON string:
```
title: {"en":"English Title","ar":"عنوان عربي"}
```

## Affected Endpoints

- `POST /api/v1/sections` (create section)
- `PUT /api/v1/sections/{id}` (update section)

Both are fixed. Table (index), show, delete, reorder, and toggle-status endpoints were never affected.
