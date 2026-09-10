# Changelog - Promotion Feature

All notable changes to the Promotion feature should be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added
- Full promotion management system with discount and gift engine
- `Promotion` model with translatable names (Spatie Translatable)
- Media support via Spatie Media Library (desktop + mobile images)
- Auto-generated unique promotion codes
- Sluggable URLs via cviebrock/eloquent-sluggable

### Promotion Types
- **Percentage Promotion** (`type_amount: percentage`) — configurable discount percentage with optional `max_discount_amount` cap
- **Fixed Rate Promotion** (`type_amount: fixed_rate`) — fixed amount discount
- **Gift Promotion** (`type_amount: gift`) — free gift items with variant selection and quantity

### Admin API (Marvel Package)
- `GET /api/v1/promotions` — Paginated list with search, type, status filters
- `POST /api/v1/promotions` — Create promotion (multi-language, images, products, gift products, schedule)
- `GET /api/v1/promotions/{promotion}` — Single promotion with products and gift products
- `PUT /api/v1/promotions/{promotion}` — Update promotion with partial data
- `DELETE /api/v1/promotions/{promotion}` — Delete promotion

### Public API (App Layer)
- `GET /api/v1/general/promotions` — Public promotion listing
- `GET /api/v1/general/promotions/{slug}` — Public promotion detail with products
- `GET /api/v1/general/checkout/promotions` — Checkout-eligible promotions (authenticated)

### Promotion Engine (Strategy Pattern)
- `PromotionEligibilityResolver` — Evaluates cart against all promotions
- `PromotionApplicator` — Applies discount/gift outcomes to cart
- `PercentagePromotionStrategy` — Percentage discount calculation with max cap
- `FixedPromotionStrategy` — Fixed amount discount (floor at 0)
- `GiftPromotionStrategy` — Gift item resolution with stock/variant validation
- `AbstractPromotionStrategy` — Base strategy class
- DTOs: `PromotionResult`, `PromotionEvaluation`, `GiftItem`, `CheckoutTotals`

### Checkout Integration
- Cart eligibility checks at checkout
- Promotion discount + coupon discount stacking (promotion first)
- Gift item reservation in cart via `CartInventoryService`
- Order snapshot with promotion data (id, code, type, discount)
- Invoice audit trail with line-item promotion tracking
- Usage increment on order creation, decrement on cancellation
- Cart modification clears applied promotion

### Infrastructure
- `PromotionRepository` with transactional create/update, product sync, gift product sync
- Permission enums: `view-promotion`, `create-promotion`, `update-promotion`, `delete-promotion`
- `PromotionObserver` for activity logging with field-level change tracking
- Promotion seeders with 20 promotional campaigns
- Section type registration for homepage content blocks
- Translation constants for success messages (EN + AR)
- Activity log translations (EN + AR) for promotions
- Permission translations (EN + AR)

### Tests
- `PromotionCrudTest` — CRUD, validation, auth
- `PromotionCheckoutTest` — Checkout integration, resources
- `PromotionFlowTest` — Gift flow, usage, cart modification
- `PromotionEligibilityResolverTest` — Engine unit tests
- `PromotionProductionHardenTest` — 1,347 lines of production hardening tests

## [Unreleased - Technical Debt]

- [x] No Policy class (uses middleware-only authorization)
- [x] No GraphQL support for promotions
- [x] No frontend components in repository
- [x] Promotion engine complexity — deep service chain for checkout integration
