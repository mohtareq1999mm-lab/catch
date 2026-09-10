# Changelog - Slider Feature

All notable changes to the Slider feature should be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added
- Hero banner slider management with image uploads and product associations
- `Slider` model with translatable titles (Spatie Translatable)
- Media support via Spatie Media Library (desktop + mobile image collections)
- Drag-and-drop reordering via Spatie Sortable
- Soft deletes for safe slider removal
- Auto-generated slugs from English title

### Admin API (Marvel Package)
- `GET /api/v1/sliders` — Paginated list with active filter
- `POST /api/v1/sliders` — Create slider (multi-language title, desktop/mobile images, product sync)
- `GET /api/v1/sliders/{slider}` — Single slider by ID
- `PUT /api/v1/sliders/{slider}` — Update slider with partial data, image replacement
- `DELETE /api/v1/sliders/{slider}` — Soft delete slider
- `PATCH /api/v1/sliders/change-status` — Toggle active/inactive status
- `PUT /api/v1/sliders/reorder` — Bulk reorder sliders

### Public API (App Layer)
- `GET /api/v1/general/sliders` — Public slider listing with date range and limit filters
- `GET /api/v1/general/sliders/{slug}` — Public slider detail by slug with products

### Infrastructure
- `SliderRepository` with transactional create/update and image handling
- `SliderService` for public API with channel filtering and pricing enrichment
- Permission enums: `view-slider`, `create-slider`, `update-slider`, `delete-slider`
- Slider seeder with 10 promotional banners (EN + AR)
- `SliderProductSeeder` for random pivot associations
- Import/Export Excel sheets for bulk product-slider association
- Section type registration for homepage content blocks
- Translation constants for success messages (EN + AR)
- Permission translations (EN + AR)

### Tests
- `SliderApiTest` with 29 test methods covering CRUD, auth, permissions, status toggle, reorder, translations, response structure, and product relations

## Identified Technical Debt

- [ ] Consolidate duplicate route registration for `GET /sliders` in `Routes.php`
- [ ] Standardize media collection naming (`slider-image-*` → `sliders-*`)
- [ ] Add missing German translations for slider messages
- [ ] Add activity logging (Observer + Job) for slider CRUD operations
