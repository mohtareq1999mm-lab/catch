# Final System Overview

> Version: 1.0 | Classification: INTERNAL | Last Updated: 2026-07-27

---

## System Map

```
                          ┌─────────────────────────────────────────────────────┐
                          │                     FRONTEND                        │
                          │          (React/Next.js — external)                 │
                          └────────────────────┬────────────────────────────────┘
                                               │ REST API
                                               ▼
                    ┌────────────────────────────────────────────────────────┐
                    │                    API LAYER                           │
                    │   api/v1/general (storefront)  │  api/v1 (admin/cms)  │
                    │   Middleware: auth:sanctum, throttle, channel, lang    │
                    └────────────────────┬───────────────────────────────────┘
                                         │
                    ┌────────────────────┴───────────────────────────────────┐
                    │               CONTROLLER LAYER                         │
                    │   OrderController  │  CartController  │  Product...    │
                    │   CouponController │  PromotionCntrl │  Invoice...     │
                    │   + 30+ admin CRUD controllers                         │
                    │   "Controllers receive Request, call Service,          │
                    │    return Resource"                                    │
                    └────────────────────┬───────────────────────────────────┘
                                         │
                    ┌────────────────────┴───────────────────────────────────┐
                    │               SERVICE LAYER                            │
                    │                                                         │
                    │  ┌─────────────────┐  ┌──────────────────────────────┐  │
                    │  │ ORDER DOMAIN    │  │ PROMOTION ENGINE              │  │
                    │  │ OrderService    │  │ PromotionService              │  │
                    │  │ OrderCreation   │  │ PromotionEligibilityResolver  │  │
                    │  │ FastShipping    │  │ PromotionApplicator           │  │
                    │  │                 │  │ Strategy Pattern (3 types)    │  │
                    │  └─────────────────┘  └──────────────────────────────┘  │
                    │                                                         │
                    │  ┌─────────────────┐  ┌──────────────────────────────┐  │
                    │  │ PAYMENT DOMAIN  │  │ COUPON DOMAIN                 │  │
                    │  │ PaymentCheckout │  │ CouponService                 │  │
                    │  │ MyFatoorahGate  │  │ CouponCalculator              │  │
                    │  │ GatewayFactory  │  │ CouponValidator               │  │
                    │  │ CashierQr       │  │ CouponOrchestrator            │  │
                    │  └─────────────────┘  └──────────────────────────────┘  │
                    │                                                         │
                    │  ┌─────────────────┐  ┌──────────────────────────────┐  │
                    │  │ INVENTORY       │  │ PRICING                      │  │
                    │  │ CartInventory   │  │ ProductPricingService        │  │
                    │  │ Reserve/Release │  │ Flash→Discount→Base chain    │  │
                    │  │ Finalize/Restore│  │ Integer-cent calculation      │  │
                    │  └─────────────────┘  └──────────────────────────────┘  │
                    │                                                         │
                    │  ┌─────────────────┐  ┌──────────────────────────────┐  │
                    │  │ INVOICE DOMAIN  │  │ DASHBOARD                    │  │
                    │  │ InvoiceService  │  │ DashboardService             │  │
                    │  │ InvoiceSnapshot │  │ 17 cached analytics queries  │  │
                    │  │ PdfGeneration   │  │                              │  │
                    │  └─────────────────┘  └──────────────────────────────┘  │
                    └────────────────────┬───────────────────────────────────┘
                                         │
                    ┌────────────────────┴───────────────────────────────────┐
                    │           REPOSITORY / DATA ACCESS LAYER                │
                    │   Eloquent Models  │  Prettus Repositories (legacy)     │
                    │   LockForUpdate()  │  Eager Loading  │  ChunkById       │
                    └────────────────────┬───────────────────────────────────┘
                                         │
                    ┌────────────────────┴───────────────────────────────────┐
                    │              DATABASE (MySQL / InnoDB)                  │
                    │   100+ tables  │  Foreign Keys  │  Row-level locking    │
                    │   utf8mb4  │  Inventory: stock/reserved/sold           │
                    │   Financial: orders/transactions/invoices/refunds      │
                    └────────────────────────────────────────────────────────┘
                    ┌────────────────────────────────────────────────────────┐
                    │           BACKGROUND PROCESSING                        │
                    │   Queue: high → Invoice gen, Import                     │
                    │          medium → Notifications, Activity logs          │
                    │          low → PDF gen, Reconciliation                  │
                    │   Commands: CancelUnpaidOrders, ExpireCarts,            │
                    │             PaymentReconcile, ClearApiCache            │
                    │   Scheduler: NOT ACTIVE (needs cron)                   │
                    └────────────────────────────────────────────────────────┘
```

---

## Architecture Philosophy

The platform follows a **strict layered architecture**:

```
Controller → Request → Service → Repository → Model → Resource
```

- **Controllers** are thin — receive request, call service, return resource
- **Services** contain all business logic — order processing, pricing, promotion engine, coupon validation
- **Repositories** handle data access (legacy Marvel pattern uses Prettus; new code uses Eloquent directly with `lockForUpdate`)
- **Workflows** use **pessimistic locking** (52 `lockForUpdate()` calls) throughout the checkout pipeline — inventory, coupons, promotions, orders, transactions, invoices, refunds
- **Side effects** are handled via **Events + Listeners** — notifications, activity logging, inventory restoration, invoice generation

---

## Domain Summary

| Domain | Primary Service(s) | Key Model(s) | Documentation |
|---|---|---|---|
| **Cart** | `CartInventoryService` | Cart, CartItem, Product | [cart-lifecycle.md](cart-lifecycle.md) |
| **Checkout** | `OrderService`, `OrderCreationService` | Order | [checkout-system.md](checkout-system.md) |
| **Pricing** | `ProductPricingService` | Product, ProductVariant | [pricing-engine.md](pricing-engine.md) |
| **Coupons** | `CouponService`, `CouponCalculator`, `CouponValidator` | Coupon, CouponAssignment | [coupon-lifecycle.md](coupon-lifecycle.md) |
| **Promotions** | `PromotionService`, `PromotionApplicator` (Strategy Pattern) | Promotion | [promotion-lifecycle.md](promotion-lifecycle.md) |
| **Payment** | `PaymentCheckoutHandler`, `MyFatoorahGateway` | Transaction | [payment-flow.md](payment-flow.md) |
| **Orders** | `OrderService`, `FastShippingService` | Order, OrderProduct | [order-lifecycle.md](order-lifecycle.md) |
| **Invoices** | `InvoiceService`, `InvoiceSnapshotService` | Invoice | [invoice-system.md](invoice-system.md) |
| **Financial** | `PaymentReconciliationJob`, `RefundController` | Transaction, Balance, Wallet | [financial-flow.md](financial-flow.md) |
| **Events** | `EventServiceProvider`, `LogActivityJob` | ActivityLog (Spatie) | [events-and-listeners.md](events-and-listeners.md) |
| **State Machines** | `OrderService::changeOrderStatus()` | Order, Transaction, Invoice, Refund | [database-state-transitions.md](database-state-transitions.md) |
| **API** | 30+ Controllers | — | [api-contract.md](api-contract.md) |
| **Production** | Docker, Rate Limiters, Exception Handler | — | [production-hardening.md](production-hardening.md) |
| **Diagrams** | Mermaid sequence/state diagrams | — | [system-sequence-diagrams.md](system-sequence-diagrams.md) |

---

## Key Metrics

| Metric | Count |
|---|---|
| API Endpoints | ~250+ |
| Event Classes | 36 |
| Listener Classes | 43 (7 orphan/unregistered) |
| Job Classes | 6 |
| Model Observers | 9 (all logging via `LogActivityJob`) |
| `lockForUpdate()` calls | 52 |
| `DB::transaction()` calls | 21 |
| Rate Limiters | 11 |
| Cache `remember()` calls | 39 (22 home + 17 dashboard) |
| Enums | 30+ |
| Custom Commands | 4 (none scheduled) |
| Queue Levels | 4 (high, medium, low, default) |
| Payment Gateways | 11 (webhooks configured) |
| Docker build stages | 2 (composer + runtime) |

---

## Critical Business Flows

### 1. Checkout (Online Payment)
```
Cart → ProductPricingService → PromotionService → CouponCalculator → 
resolveShippingPrice → OrderCreationService → PaymentCheckoutHandler → 
MyFatoorahGateway → Transaction(pending) → Callback → verifyPayment() →
amount check → Transaction(paid) → Order(completed) → Inventory(finalized) →
Coupon(recorded) → Promotion(incremented) → Invoice(generated) → PDF(ready)
```

### 2. Checkout (COD/Cashier)
```
Cart → Order(completed) → Transaction(pending) → Admin marks paid →
Transaction(paid) → Inventory(finalized) → Coupon(recorded) → Promotion(incremented)
```

### 3. Cancellation
```
Order(cancelled) → Transaction(failed) → Inventory(restored) →
Promotion(decremented if not previously cancelled) →
Events: OrderCancelled, OrderStatusChanged
```

### 4. Refund
```
Refund(pending) → Admin approves → Gateway refund → Shop balance debited →
Customer wallet credited → Order(refunded) → Inventory(restored if not cancelled) →
Reviews removed → Events: RefundApproved
```

---

## Known Critical Issues

| ID | Issue | Doc Reference |
|---|---|---|
| P-1 | Dual checkout paths produce different totals | [pricing-engine.md](pricing-engine.md#63-the-dual-path-problem) |
| P-2 | FastShipping missing FREE_SHIPPING coupon check | [pricing-engine.md](pricing-engine.md#33-fast-shipping) |
| P-3 | PromotionService has DB side effects during preview | [pricing-engine.md](pricing-engine.md#45-side-effect-warning) |
| P-4 | Order item unit price rounding error | [pricing-engine.md](pricing-engine.md#10-known-issues--technical-debt) |
| P-5 | Cart total_price has 6 writers | [pricing-engine.md](pricing-engine.md#10-known-issues--technical-debt) |
| FIN-INV-1 | FinancialInvariantValidator missing fast_shipping_fee | [financial-flow.md](financial-flow.md#9-invoice-financial-entries) |
| FIN-INV-2 | Invoice generation is dormant (wired but never called) | [financial-flow.md](financial-flow.md#11-known-issues--technical-debt) |
| PRD-1 | No scheduled cron — 4 commands not registered | [production-hardening.md](production-hardening.md#72-scheduler-status-inactive) |
| PRD-2 | No backup solution | [production-hardening.md](production-hardening.md#12-gap-summary--recommendations) |
| PRD-3 | CORS wide open | [production-hardening.md](production-hardening.md#84-security-gaps) |

---

## Configuration Quick Reference

| Aspect | File | Production Recommendation |
|---|---|---|
| Cache driver | `config/cache.php` | Switch to `redis` |
| Session driver | `config/session.php` | Switch to `redis` |
| Queue connection | `config/queue.php` | Use `database` or `redis` |
| Log channel | `config/logging.php` | Use `daily` (14-day retention) |
| CORS origins | `config/cors.php` | Restrict to specific domains |
| Default currency | `config/payment.php` | Set via `DEFAULT_CURRENCY` env |
| Order timeout | `config/payment.php` | 72 hours (configurable) |
| OTP expiry | `config/one-time-passwords.php` | 2 minutes |
| Scope driver | `config/scout.php` | Switch to `algolia` or `meilisearch` |
| Media max size | `config/media-library.php` | 20MB |
| Activity log retention | `config/activitylog.php` | 60 days |

---

## Deployment Architecture

```
                          ┌─────────────────────────┐
                          │     Railway / Docker     │
                          │                         │
                          │  ┌───────────────────┐  │
                          │  │   PHP 8.2 CLI      │  │
                          │  │   artisan serve    │  │
                          │  │   port 8080        │  │
                          │  │   OPcache enabled  │  │
                          │  │   Non-root user    │  │
                          │  └────────┬──────────┘  │
                          │           │              │
                          │  ┌────────┴──────────┐  │
                          │  │   MySQL (Railway)  │  │
                          │  └───────────────────┘  │
                          │           │              │
                          │  ┌────────┴──────────┐  │
                          │  │   Meilisearch     │  │
                          │  │   (Docker Compose) │  │
                          │  └───────────────────┘  │
                          │           │              │
                          │  ┌────────┴──────────┐  │
                          │  │   MyFatoorah API  │  │
                          │  │   (external)      │  │
                          │  └───────────────────┘  │
                          └─────────────────────────┘
```

---

## Document Index

| # | Document | Description | Pages |
|---|---|---|---|
| 1 | [checkout-system.md](checkout-system.md) | Complete checkout pipeline from cart to payment | ~780 lines |
| 2 | [cart-lifecycle.md](cart-lifecycle.md) | Cart creation, inventory reservation, expiration | ~830 lines |
| 3 | [coupon-lifecycle.md](coupon-lifecycle.md) | Coupon validation, calculation, usage tracking | ~575 lines |
| 4 | [promotion-lifecycle.md](promotion-lifecycle.md) | Promotion engine (strategy pattern, proportional allocation) | ~430 lines |
| 5 | [payment-flow.md](payment-flow.md) | Payment methods, gateway integration, callback flow | ~610 lines |
| 6 | [order-lifecycle.md](order-lifecycle.md) | Order creation, status management, fulfillment | ~400 lines |
| 7 | [invoice-system.md](invoice-system.md) | Invoice generation, snapshot, PDF lifecycle | ~930 lines |
| 8 | [pricing-engine.md](pricing-engine.md) | Price resolution, discount stacking, rounding | ~780 lines |
| 9 | [financial-flow.md](financial-flow.md) | Money movement, reconciliation, refunds, accounting | ~400 lines |
| 10 | [events-and-listeners.md](events-and-listeners.md) | All 36 events, 43 listeners, 6 jobs with queue routing | ~400 lines |
| 11 | [database-state-transitions.md](database-state-transitions.md) | All 7 state machines with transition tables | ~350 lines |
| 12 | [api-contract.md](api-contract.md) | All 250+ endpoints organized by module | ~500 lines |
| 13 | [production-hardening.md](production-hardening.md) | Concurrency, caching, rate limiting, security, deployment | ~400 lines |
| 14 | [system-sequence-diagrams.md](system-sequence-diagrams.md) | 13 Mermaid sequence/state diagrams | ~350 lines |
| 15 | **final-system-overview.md** | This document — architecture map, metrics, critical issues | ~250 lines |
