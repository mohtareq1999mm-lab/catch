# Coupon Module — Frontend (Public API)

## Overview

The Coupon module manages discount coupons for the storefront. Public endpoints allow browsing available coupons and applying a coupon code to the user's cart. Coupons support fixed-rate and percentage discounts with optional usage limits, date ranges, and product/user assignments.

## Key Files

| Layer | File |
|-------|------|
| Controller | `app/Http/Controllers/Api/General/CouponController.php` |
| Service | `app/Services/General/CouponService.php` |
| Orchestrator | `app/Services/Coupon/CouponOrchestrator.php` |
| Validator | `app/Services/Coupon/CouponValidator.php` |
| Calculator | `app/Services/Coupon/CouponCalculator.php` |
| Resource | `app/Http/Resources/Coupons/CouponResource.php` |
| Model | `packages/marvel/src/Database/Models/Coupon.php` |
| Routes | `routes/api.php` (lines 61-62) |
| Translation (EN) | `resources/lang/en/message.php` |
| Translation (AR) | `resources/lang/ar/message.php` |

## Routes

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/v1/general/coupons` | Public | List valid coupons |
| POST | `/api/v1/general/coupons/apply` | auth:sanctum | Apply coupon to cart |

## Dependencies

- **Spatie Translatable** (`HasTranslations`) — bilingual coupon names
- **Spatie Media Library** (`InteractsWithMedia`) — coupon images
- **CouponOrchestrator** — validation pipeline
- **CouponValidator** — date/usage/assignment/product eligibility
- **CouponCalculator** — price calculation (fixed/percentage)
- **CouponAssignmentValidator** — user-specific assignment checks
- **Laravel DB Transactions** — atomic coupon application
