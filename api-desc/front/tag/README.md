# Tag Module — Frontend (Public API)

## Overview

The Tag module manages product tags used for filtering, categorization, and SEO. Tags are lightweight labels that can be associated with products. The public API provides read-only access to list all tags and view individual tags by slug.

## Key Files

| Layer | File |
|-------|------|
| Controller | `app/Http/Controllers/Api/General/TagController.php` |
| Model | `packages/marvel/src/Database/Models/Tag.php` |
| Resource | `packages/marvel/src/Http/Resources/TagResource.php` |
| Routes | `routes/api.php` (lines 52-53) |
| Translation (EN) | `resources/lang/en/message.php` |
| Translation (AR) | `resources/lang/ar/message.php` |

## Routes

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/v1/general/tags` | Public | List all tags |
| GET | `/api/v1/general/tags/{slug}` | Public | Get tag by slug |

## Dependencies

- **Cviebrock Sluggable** — auto slug generation from name
- **TranslationTrait** — multi-language tag support
- **Marvel Type** — tag belongs to a type (category/classification)
