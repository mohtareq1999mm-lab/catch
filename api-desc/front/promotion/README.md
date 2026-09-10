# Promotion Feature - API Investigation

## Feature Name

Promotion Management (Discounts, Gift Offers, Campaigns)

## Description

The Promotion feature provides a full discount and gift promotion engine with CRUD management, checkout integration, and product-level eligibility. Supports three promotion types: **percentage discount**, **fixed rate discount**, and **gift items**. Features a Strategy Pattern-based eligibility resolver, usage limits, date validity windows, and per-product or all-products targeting.

## Architecture Overview

```
[Client]
    |
    |--- GET /api/v1/general/promotions              (Public API)
    |--- GET /api/v1/general/promotions/{slug}       (Public API)
    |--- GET /api/v1/general/checkout/promotions     (Checkout API - auth)
    |
    |--- GET    /api/v1/promotions                   (Admin API)
    |--- POST   /api/v1/promotions                   (Admin API)
    |--- GET    /api/v1/promotions/{id}              (Admin API)
    |--- PUT    /api/v1/promotions/{id}              (Admin API)
    |--- DELETE /api/v1/promotions/{id}              (Admin API)
    |
    v
[PromotionController (Marvel)]  or  [PromotionController (General)]
    |
    v
[PromotionRepository / PromotionService / PromotionEngine]
    |
    v
[Promotion Model]
    |--- products (BelongsToMany Product)
    |--- giftProducts (BelongsToMany Product w/ pivot: quantity, product_variant_id)
    |
    v
[Promotion Engine]
    |--- PromotionEligibilityResolver
    |--- PromotionApplicator
    |--- Strategies: PercentagePromotionStrategy, FixedPromotionStrategy, GiftPromotionStrategy
    |--- Outcomes: DiscountOutcome, GiftOutcome
```

## Key Endpoints

### Public API (routes/api.php - prefix: `v1/general`)

| Method | URI | Controller | Auth |
|--------|-----|-----------|------|
| GET | `/v1/general/promotions` | `General\PromotionController@index` | No |
| GET | `/v1/general/promotions/{slug}` | `General\PromotionController@getPromotionBySlug` | No |
| GET | `/v1/general/checkout/promotions` | `OrderController@eligiblePromotions` | Yes (sanctum) |

### Admin API (packages/marvel/src/Rest/Routes.php - prefix: `v1`)

| Method | URI | Controller | Permission |
|--------|-----|-----------|-----------|
| GET | `/v1/promotions` | `PromotionController@index` | `view-promotion` |
| POST | `/v1/promotions` | `PromotionController@store` | `create-promotion` |
| GET | `/v1/promotions/{promotion}` | `PromotionController@show` | `view-promotion` |
| PUT | `/v1/promotions/{promotion}` | `PromotionController@update` | `update-promotion` |
| DELETE | `/v1/promotions/{promotion}` | `PromotionController@destroy` | `delete-promotion` |

## Key Files

| Layer | Path |
|-------|------|
| Model | `packages/marvel/src/Database/Models/Promotion.php` |
| Repository | `packages/marvel/src/Database/Repositories/PromotionRepository.php` |
| Controller (Admin) | `packages/marvel/src/Http/Controllers/PromotionController.php` |
| Controller (Public) | `app/Http/Controllers/Api/General/PromotionController.php` |
| Service (Checkout) | `app/Services/General/PromotionService.php` |
| Service (Data) | `app/Services/General/PromotionDataService.php` |
| Eligibility Resolver | `app/Services/General/PromotionEngine/PromotionEligibilityResolver.php` |
| Promotion Applicator | `app/Services/General/PromotionEngine/PromotionApplicator.php` |
| Strategy Interface | `app/Services/General/PromotionEngine/Contracts/PromotionStrategy.php` |
| Percentage Strategy | `app/Services/General/PromotionEngine/Strategies/PercentagePromotionStrategy.php` |
| Fixed Strategy | `app/Services/General/PromotionEngine/Strategies/FixedPromotionStrategy.php` |
| Gift Strategy | `app/Services/General/PromotionEngine/Strategies/GiftPromotionStrategy.php` |
| Create Request | `packages/marvel/src/Http/Requests/PromotionRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/UpdatePromotionRequest.php` |
| Resource (Admin) | `packages/marvel/src/Http/Resources/PromotionResource.php` |
| Resource (Public) | `app/Http/Resources/Promotion/PromotionResource.php` |
| Observer | `app/Observers/PromotionObserver.php` |
| Enum (Type) | `packages/marvel/src/Enums/PromotionType.php` |
| Enum (MountType) | `packages/marvel/src/Enums/PromotionMountType.php` |
| Enum (Permission) | `packages/marvel/src/Enums/Permission.php` |
| DTOs | `app/Services/General/PromotionEngine/PromotionResult.php` |
| DTOs | `app/Services/General/PromotionEngine/PromotionEvaluation.php` |
| DTOs | `app/Services/General/PromotionEngine/DTOs/GiftItem.php` |
| DTOs | `app/DTOs/CheckoutTotals.php` |
| Routes (Marvel) | `packages/marvel/src/Rest/Routes.php` |
| Routes (General) | `routes/api.php` |

## Tech Stack

- **Laravel** with Eloquent ORM
- **Strategy Pattern** for discount/gift type resolution
- **Spatie Translatable** for localized names
- **Spatie Media Library** for image attachments (desktop + mobile)
- **Spatie Permission** for authorization
- **cviebrock/eloquent-sluggable** for slug generation
- **PromotionObserver** for activity logging via `LogActivityJob`
