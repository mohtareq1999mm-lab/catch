# Promotion Module

## Overview

The Promotion module manages promotional offers on the e-commerce platform. It provides three separate API surfaces:

- **Admin API** (`/api/v1/promotions`) — Full CRUD, protected by permissions (super admin + vendor + store owner scoped)
- **Public API** (`/api/v1/general/promotions`) — Read-only, no authentication required
- **Checkout API** (`/api/v1/checkout/promotions`) — Authenticated, eligible promotions for the current cart

Promotions are cart-level discounts (separate from product-level pricing). Only one promotion per order (no stacking). Promotions can be: percentage-based, fixed-rate, or gift-based. They support translatable names, media uploads (desktop + mobile images), usage tracking, date validity windows, and product scoping (all products vs specific products).

## Key Files

| Layer | File |
|-------|------|
| Admin Controller | `packages/marvel/src/Http/Controllers/PromotionController.php` |
| Public Controller | `app/Http/Controllers/Api/General/PromotionController.php` |
| Repository | `packages/marvel/src/Database/Repositories/PromotionRepository.php` |
| Model | `packages/marvel/src/Database/Models/Promotion.php` |
| Admin Resource | `packages/marvel/src/Http/Resources/PromotionResource.php` |
| Public Resource | `app/Http/Resources/Promotion/PromotionResource.php` |
| Create Request | `packages/marvel/src/Http/Requests/PromotionRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/UpdatePromotionRequest.php` |
| Promotion Service | `app/Services/General/PromotionService.php` |
| Data Service | `app/Services/General/PromotionDataService.php` |
| Eligibility Resolver | `app/Services/General/PromotionEngine/PromotionEligibilityResolver.php` |
| Promotion Applicator | `app/Services/General/PromotionEngine/PromotionApplicator.php` |
| Promotion Result (DTO) | `app/Services/General/PromotionEngine/PromotionResult.php` |
| Promotion Evaluation (DTO) | `app/Services/General/PromotionEngine/PromotionEvaluation.php` |
| Strategy Interface | `app/Services/General/PromotionEngine/Contracts/PromotionStrategy.php` |
| Abstract Strategy | `app/Services/General/PromotionEngine/Strategies/AbstractPromotionStrategy.php` |
| Percentage Strategy | `app/Services/General/PromotionEngine/Strategies/PercentagePromotionStrategy.php` |
| Fixed Strategy | `app/Services/General/PromotionEngine/Strategies/FixedPromotionStrategy.php` |
| Gift Strategy | `app/Services/General/PromotionEngine/Strategies/GiftPromotionStrategy.php` |
| PromotionOutcome (base) | `app/Services/General/PromotionEngine/Outcome/PromotionOutcome.php` |
| DiscountOutcome | `app/Services/General/PromotionEngine/Outcome/DiscountOutcome.php` |
| GiftOutcome | `app/Services/General/PromotionEngine/Outcome/GiftOutcome.php` |
| GiftItem (DTO) | `app/Services/General/PromotionEngine/DTOs/GiftItem.php` |
| Observer | `app/Observers/PromotionObserver.php` |
| Admin Routes | `packages/marvel/src/Rest/Routes.php` (lines 238, 628-630, 688-690) |
| Public Routes | `routes/api.php` (lines 54-55) |
| Checkout Route | `routes/api.php` (line 77) |
| Permissions | `packages/marvel/src/Enums/Permission.php` |
| Promotions Migration | `packages/marvel/database/migrations/2020_04_29_000001_create_promotions_table.php` |
| Pivot Migration | `packages/marvel/database/migrations/2026_05_03_111116_create_promotion_product_table.php` |
| Gift Products Migration | `packages/marvel/database/migrations/2026_05_17_000001_add_selected_promotion_checkout_fields.php` |
| Variant Nullable Migration | `packages/marvel/database/migrations/2026_07_18_000001_make_promotion_gift_product_variant_nullable.php` |
| Seeder | `database/seeders/PromotionSeeder.php` |
| Tests | `tests/Feature/PromotionFlowTest.php` |
| Tests | `tests/Feature/PromotionProductionHardenTest.php` |
| Tests | `tests/Unit/PromotionEligibilityResolverTest.php` |

## Dependencies

- **Spatie Translatable** (`HasTranslations`) — bilingual name (en/ar)
- **Spatie Media Library** (`InteractsWithMedia`) — promotion image management
- **Cviebrock Sluggable** — auto-slug generation from name
- **Prettus Repository** — repository pattern with search/filter criteria
- **Strategy Pattern** — PromotionEngine strategies for percentage, fixed, gift calculations
- **DTO Pattern** — PromotionResult, PromotionEvaluation, GiftItem (immutable)
- **Outcome Pattern** — DiscountOutcome, GiftOutcome for strategy results

## Permissions

| Permission | Required For |
|------------|-------------|
| `view-promotion` | GET /promotions, GET /promotions/{id} |
| `create-promotion` | POST /promotions |
| `update-promotion` | PUT /promotions/{id} |
| `delete-promotion` | DELETE /promotions/{id} |

## Routes

### Admin (Full CRUD — super admin)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/promotions` | List promotions (paginated, filterable, sortable) |
| POST | `/api/v1/promotions` | Create promotion (with images + product/gift associations) |
| GET | `/api/v1/promotions/{id}` | Show promotion by ID |
| PUT | `/api/v1/promotions/{id}` | Update promotion |
| DELETE | `/api/v1/promotions/{id}` | Delete promotion |

### Vendor (scoped update)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| PUT | `/api/v1/promotions/{id}` | Update promotion (vendor scope) |

### Store Owner (scoped create + delete)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/api/v1/promotions` | Create promotion (store owner scope) |
| DELETE | `/api/v1/promotions/{id}` | Delete promotion (store owner scope) |

### Public

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/general/promotions` | List valid promotions |
| GET | `/api/v1/general/promotions/{slug}` | Get promotion by slug with enriched products |

### Checkout (Authenticated)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/checkout/promotions` | Get eligible promotions for current cart |
