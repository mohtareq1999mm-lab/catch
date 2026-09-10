# Changelog - Product Feature

All notable changes to the Product feature should be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added
- Product management with translatable name and description (Spatie Translatable)
- Simple and variable product types with variant support
- `Product` model with 20+ relationships (categories, brands, tags, reviews, orders, flash sales, promotions, etc.)
- Multi-image support via Spatie Media Library
- Soft deletes for safe product removal
- Full-text search via Laravel Scout (Meilisearch)
- Product pricing engine with hierarchy: Flash Sale > Discount > Base Price
- Strategy-based product listing via ProductEngine (10 strategies)
- Dynamic filtering by category, brand, price range, attributes, dimensions, rating

### Public API (App Layer)
- `GET /api/v1/general/products` — Strategy-based product listing with filters, search, sort
- `GET /api/v1/general/products/{slug}` — Single product with reviews, related products, filters
- `POST /api/v1/general/products/{id}/reviews` — Add product review with images
- `PUT /api/v1/general/products/reviews/{id}` — Update own review

### Admin API (Marvel Package)
- `GET /api/v1/products` — Paginated list with search, sort, advanced filtering
- `POST /api/v1/products` — Create product (simple or variable) with variants, images, relations
- `GET /api/v1/products/{id}` — Single product with full relations
- `PUT /api/v1/products/{id}` — Update product with full relation re-sync
- `DELETE /api/v1/products/{id}` — Soft delete single product
- `POST /api/v1/products/bulk-delete` — Bulk soft delete
- `DELETE /api/v1/products/all` — Delete all products (chunked)
- `PUT /api/v1/products/{id}/fast-shipping` — Toggle fast shipping flag
- `GET /api/v1/draft-products` — Vendor draft products
- `GET /api/v1/products-stock` — Low stock products (<10)

### Special Endpoints
- `GET /api/v1/popular-products` — Popular products by order count
- `GET /api/v1/best-selling-products` — Top-selling products by volume
- `GET /api/v1/products/calculate-rental-price` — Rental price calculation
- `GET /api/v1/products/export` — CSV export (background job)
- `POST /api/v1/products/import` — CSV import (background job with progress)
- `POST /api/v1/import-products` — Bulk import via CSV
- `GET /api/v1/export-products/{shop_id}` — Shop-specific export
- `POST /api/v1/import-variation-options` — Variant CSV import
- `GET /api/v1/export-variation-options/{shop_id}` — Variant CSV export

### GraphQL
- `products` query with pagination, search, orderBy, filters (50+ type fields)
- `product` query by ID or slug
- `createProduct` mutation (resolver: ProductMutator@storeProduct)
- `updateProduct` mutation (resolver: ProductMutator@updateProduct)
- `deleteProduct` mutation (resolver: ProductMutator@deleteProduct)
- `calculateRentalPrice` mutation
- `importProducts` mutation
- `importVariationOptions` mutation

### Infrastructure
- `ProductRepository` with transaction-based create/update, variant management, media handling
- `ProductService` for public API with filter, search, pricing enrichment
- `ProductFilter` service with 10+ filter types
- `ProductPricingService` with flash sale, discount, and coupon pricing
- `ProductEngine` with Strategy pattern (10 strategies)
- `ProductImportService` with progress tracking and cancellation
- Permission enums: `view-products`, `create-product`, `update-product`, `delete-product`, `view-low-stock-products`, `view-draft-products`
- Product type enums: `simple`, `variable`
- Product status enums: `under_review`, `approved`, `rejected`, `publish`, `unpublish`, `draft`
- Product visibility enums: `visibility_private`, `visibility_public`, `visibility_protected`
- Events: `DigitalProductUpdateEvent`
- Listeners: `DigitalProductNotifyLogs`, `ProductReviewApproved`, `ProductReviewRejected`
- Jobs: `ImportProductsJob` (high queue, 3 retries, 1500s timeout), `ExportProductsJob`
- Notifications: `DigitalProductUpdateNotification`, `ProductApprovedNotification`, `ProductRejectedNotification`
- Global scope: `FastShippingScope` for channel-based filtering
- Traits: `HasChannelFilter`, `HasProductFilters`
- Translation keys (EN + AR) for all product messages
- OpenAPI annotations in ProductController
- Product factory for variant testing
- Auto SKU generation: `PRD-{id}` (zero-padded to 3 digits)

### Tests
- 8 Feature test files + 1 Unit test covering CRUD, validation, auth, permissions, filtering, pricing, import, export, tags

## [Unreleased - Technical Debt]

- [ ] Create Product model factory (only ProductVariantFactory exists)
- [ ] Create missing ProductReview event classes (ProductReviewApproved, ProductReviewRejected)
- [ ] Consolidate translatable search patterns between Repository and Service
- [ ] Remove duplicate route definitions in Routes.php
- [ ] Implement empty migration files or remove them
- [ ] Standardize digital file resolver responses to use API resources
