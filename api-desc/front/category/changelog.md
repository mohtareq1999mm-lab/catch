# Changelog - Category Feature

All notable changes to the Category feature should be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added
- Hierarchical product category management with parent-child self-referencing
- `Category` model with translatable `name` and `details` (Spatie Translatable)
- Media support via Spatie Media Library (desktop + mobile image collections)
- Soft deletes for safe category removal
- Automatic level calculation based on parent depth
- Cycle detection and circular reference prevention in hierarchy

### Admin API (Marvel Package)
- `GET /api/v1/categories` — Paginated list with filtering (parent, search, featured, status)
- `POST /api/v1/categories` — Create category (multi-language name, images, parent, products)
- `GET /api/v1/categories/{id}` — Single category with parent, children, products
- `PUT /api/v1/categories/{id}` — Update category with partial data
- `DELETE /api/v1/categories/{id}` — Soft delete category
- `PUT /api/v1/categories/feature` — Toggle featured status
- `GET /api/v1/featured-categories` — Public endpoint for featured categories
- `GET /api/v1/dashboard/category-stats` — Category statistics (cached 5 min)
- `GET /api/v1/dashboard/categories` — Category analytics with revenue data (cached 5 min)
- `GET /api/v1/category-wise-product` — Category-wise product counts
- `GET /api/v1/category-wise-product-sale` — Category-wise sales data

### Public API (App Layer)
- `GET /api/v1/general/categories` — Public category listing with search, parent-only filter
- `GET /api/v1/general/categories/{slug}` — Public category detail by slug with children + products

### GraphQL
- `categories` query with pagination, filtering (name, parent, language)
- `category` query by ID or slug
- `createCategory` mutation (delegates to controller)
- `updateCategory` mutation (delegates to controller)
- `deleteCategory` mutation (Lighthouse @delete)

### Infrastructure
- `CategoryRepository` with transactional save/update and image handling
- `CategoryHierarchyService` for level calculation, cycle detection, tree loading
- `CategoryService` for public API with channel filtering
- `DashboardService` for analytics queries
- `CategoryObserver` for activity logging on created/updated/deleted
- `LogActivityJob` dispatched on queue for activity log entries
- Permission enums: `view-categories`, `create-category`, `update-category`, `delete-category`
- Category seeder with hierarchical categories (Face, Eyes, Lips, Skincare, etc.)
- Translation constants for success/error messages (en + ar)
- Activity log translations (en + ar)

### Resources
- `CategoryResource` — Admin resource with conditional `details` exclusion on index
- `CategoryCollection` — Paginated collection
- `CategoryHomeResource` — Lightweight public listing resource
- `CategoryWithChildResource` — Public detail resource with children + products
- `CategoryNavbarResource` — Recursive navbar structure
- `CategoryWithChildNameResource` — Recursive with level limitation

### Tests
- 12 comprehensive test files covering CRUD, validation, auth, permissions, translations, soft deletes, relationships, resources, media, pivots, featured toggling, and regression

## Identified Technical Debt

- [ ] Remove legacy JSON slug handling in `retrieved` event once data is migrated
- [ ] Add rate limiting to public category endpoints
- [ ] Consider caching settings/configuration for dashboard analytics queries
