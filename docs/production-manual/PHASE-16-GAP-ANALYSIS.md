# Phase 16: Gap Analysis & Final Production Readiness Report

> **Purpose:** Compare current implementation vs expected production design for every subsystem. Classify every gap, provide prioritized roadmap, test matrix, and final production readiness score.

---

## Part A: Complete Gap Analysis

### GAP-1: Invoice Debit Note Permission Check (BUG-1)

| Attribute | Value |
|-----------|-------|
| **Severity** | **HIGH** |
| **Area** | InvoiceController::issueDebitNote |
| **Current State** | No permission middleware registered. `issueDebitNote` has no middleware in constructor (only `correct` and `cancel` have `CORRECT_INVOICE`/`CANCEL_INVOICE` permissions). |
| **Expected State** | Must require `permission:ISSUE_DEBIT_NOTE` middleware |
| **Business Impact** | Any authenticated user can issue debit notes against invoices |
| **Technical Impact** | Missing authorization gate |
| **Financial Impact** | Unauthorized financial adjustments possible |
| **Security Impact** | **CRITICAL** — arbitrary debit notes with financial liability |
| **Customer Impact** | None directly, but company liability |
| **Fix** | Add `$this->middleware('permission:' . Permission::ISSUE_DEBIT_NOTE, ['only' => ['issueDebitNote']]);` to InvoiceController constructor; add `ISSUE_DEBIT_NOTE` constant to Permission enum |

### GAP-2: ShipmentController Missing Permissions (BUG-2)

| Attribute | Value |
|-----------|-------|
| **Severity** | **HIGH** |
| **Area** | ShipmentController (all methods) |
| **Current State** | No permission middleware on any shipment endpoint. Routes in `api.php:113-120` have only `auth:sanctum`. |
| **Expected State** | Admin-only endpoints for shipment management |
| **Business Impact** | Any authenticated user can create/view/update shipments |
| **Technical Impact** | No access control |
| **Security Impact** | **HIGH** — customers could see others' shipments |
| **Fix** | Add `permission:VIEW_ORDERS` to index/show, `permission:UPDATE_ORDER_STATUS` to store/updateStatus |

### GAP-3: ErrorCallback Always Marks Failed (BUG-4)

| Attribute | Value |
|-----------|-------|
| **Severity** | **MEDIUM** |
| **Area** | OrderController::checkoutErrorCallback |
| **Current State** | Calls `gateway->verifyPayment()` but **ignores the result**. Always marks transaction as `failed` regardless of what gateway reports. |
| **Expected State** | Must check `$result->success` before marking as failed. If gateway says success, should process normally. |
| **Business Impact** | If customer is redirected to error page but payment actually succeeded, the order is incorrectly marked as failed |
| **Technical Impact** | Lost orders — payment at gateway but not in system |
| **Financial Impact** | **HIGH** — lost revenue, angry customers |
| **Customer Impact** | Customer charged but order not fulfilled |

### GAP-4: Dual Event System (BUG-10)

| Attribute | Value |
|-----------|-------|
| **Severity** | **MEDIUM** |
| **Area** | Events in `App\Events` vs `Marvel\Events` |
| **Current State** | Two separate event namespaces with overlapping concerns: `App\Events\PaymentSucceeded` vs `Marvel\Events\PaymentSuccess`, `App\Events\OrderCancelled` vs `Marvel\Events\OrderCancelled`, etc. Listeners registered for one class may never fire for the other. |
| **Expected State** | Single event chain. App events should wrap or extend Marvel events. |
| **Business Impact** | Notifications may not be sent for payment success/failure depending on which event fires |
| **Technical Impact** | Event-driven workflows unpredictable |
| **Fix** | Audit all event dispatches and listener registrations. Ensure `PaymentSucceeded` (App) is what fires and its listeners fire. Remove unused Marvel event registrations or map them. |

### GAP-5: PDF Generation Is a Placeholder (BUG-8)

| Attribute | Value |
|-----------|-------|
| **Severity** | **MEDIUM** |
| **Area** | GenerateInvoicePdfJob |
| **Current State** | `handle()` only logs "PDF generation placeholder" and sets status to `ready`. No actual PDF file is created. |
| **Expected State** | Must generate a real PDF using barryvdh/laravel-dompdf (already in composer.json), save to storage/invoices/, set pdf_path, compute pdf_checksum |
| **Business Impact** | Customers cannot download invoice PDFs |
| **Customer Impact** | **HIGH** — invoice download returns 404 "PDF not yet generated" |
| **Fix** | Implement actual PDF generation using DomPDF template |

### GAP-6: No CancelUnpaidOrders Command (BUG-9)

| Attribute | Value |
|-----------|-------|
| **Severity** | **MEDIUM** |
| **Area** | Missing scheduled command |
| **Current State** | No Artisan command exists to cancel unpaid pending orders after timeout. |
| **Expected State** | Scheduled task (every 5-15 minutes) finds pending orders older than threshold, cancels them with `lockForUpdate()`, releases inventory |
| **Business Impact** | Orders can remain pending indefinitely, holding inventory hostage |
| **Customer Impact** | Inventory blocked for other customers |
| **Fix** | Create `CancelUnpaidOrders` command, register in `Kernel::schedule()` |

### GAP-7: Cart Expiration Not Scheduled

| Attribute | Value |
|-----------|-------|
| **Severity** | **MEDIUM** |
| **Area** | CartInventoryService::expireCarts |
| **Current State** | `expireCarts()` method exists with chunked processing, but is NOT registered in any scheduler. |
| **Expected State** | Must be called every 5 minutes via Laravel scheduler |
| **Business Impact** | Carts never expire, inventory stays reserved until manual cleanup or TTL hit |
| **Fix** | Add `$schedule->call(fn() => app(CartInventoryService::class)->expireCarts())->everyFiveMinutes()` to Kernel |

### GAP-8: Missing Return System

| Attribute | Value |
|-----------|-------|
| **Severity** | **MEDIUM** |
| **Area** | Full subsystem |
| **Current State** | No dedicated return system. Returns are only handled through the refund system. No ReturnRequest/ReturnItem/ReturnStatus models. |
| **Expected State** | Complete return lifecycle with RMA numbers, inspection workflow, restocking fees, replacement option |
| **Business Impact** | Cannot handle physical returns properly |
| **Customer Impact** | Poor return experience |
| **Fix** | Build complete Return subsystem (see Phase 11 for full design) |

### GAP-9: Missing Shipment Events & Listeners

| Attribute | Value |
|-----------|-------|
| **Severity** | **MEDIUM** |
| **Area** | Shipment lifecycle |
| **Current State** | ShipmentService updates status but fires NO events, NO notifications. No shipment-related events defined. |
| **Expected State** | ShipmentStatusChanged event, listeners for notifications, customer SMS/email updates |
| **Business Impact** | Customers never notified of shipment status changes |
| **Customer Impact** | No tracking updates |
| **Fix** | Add `ShipmentStatusChanged` event, listeners for customer notifications |

### GAP-10: correctInvoice Accepts Arbitrary Overrides (BUG-13)

| Attribute | Value |
|-----------|-------|
| **Severity** | **MEDIUM** |
| **Area** | InvoiceService::correctInvoice |
| **Current State** | `foreach ($overrides as $key => $value) { data_set($snapshot, $key, $value); }` — accepts ANY key path without validation. An admin could override financial invariants. |
| **Expected State** | Whitelist of allowed override keys (e.g., `customer.name`, `customer.email`, `billing_address.*`, `shipping_address.*`, `metadata.*`). Financial fields (total, subtotal, etc.) should only change through formal correction rules. |
| **Business Impact** | Incorrect corrections could break financial audit trail |
| **Fix** | Add whitelist validation in CorrectInvoiceRequest |

### GAP-11: No InvoiceCreated Event on Correction (BUG-6)

| Attribute | Value |
|-----------|-------|
| **Severity** | **LOW** |
| **Area** | InvoiceService::correctInvoice |
| **Current State** | `correctInvoice()` creates a new invoice but does NOT dispatch `InvoiceCreated` event nor queue `GenerateInvoicePdfJob`. |
| **Expected State** | Correction invoices should follow the same lifecycle as originals |
| **Fix** | Add `InvoiceCreated::dispatch()` and `GenerateInvoicePdfJob::dispatch()` in correction flow |

### GAP-12: Inconsistent Allowed States (BUG-5)

| Attribute | Value |
|-----------|-------|
| **Severity** | **LOW** |
| **Area** | Invoice cancel vs debit note states |
| **Current State** | `cancelInvoice()` allows cancellation from `['generated', 'ready', 'failed', 'corrected']` while `issueDebitNote()` allows debit from `['generated', 'ready', 'verified', 'downloaded', 'printed']`. The cancel list is missing `verified`, `downloaded`, `printed`. |
| **Expected State** | Both should reference the same allowed state list from InvoiceStatus enum |
| **Fix** | Unify allowed states using `InvoiceStatus::GENERATED->allowedTransitions()` |

### GAP-13: checkoutErrorCallback Logic Flaw (BUG-4 Detail)

| Attribute | Value |
|-----------|-------|
| **Severity** | **MEDIUM** |
| **Area** | OrderController::checkoutErrorCallback |
| **Current State** | Calls `$gateway->verifyPayment($paymentId)` but **never uses `$result->success`**. Regardless of gateway response, always marks transaction as `failed`. |
| **Expected State** | Should check if gateway actually reports success. If so, process normally (same as callback). Only mark failed if gateway confirms failure. |
| **Customer Impact** | If callback URL was not hit but error URL was (e.g., browser closed, network issue), customer loses their order despite successful payment |
| **Fix** | Add `if ($result->success) { /* process as success */ }` before failing |

### GAP-14: Customer Notifications Missing

| Attribute | Value |
|-----------|-------|
| **Severity** | **MEDIUM** |
| **Area** | Notification system |
| **Current State** | No customer-facing notifications for: invoice ready, shipment status changes, order processing updates. Only admin notifications exist for new orders and some payment events. |
| **Expected State** | Customers should receive: order confirmation (email/SMS), payment confirmation, invoice ready notification, shipment tracking updates, delivery confirmation |
| **Customer Impact** | Poor post-purchase experience |

---

## Part B: Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            FRONTEND (SPA)                                   │
│                    React/Vue/Next.js (external)                             │
└──────────────────────────┬──────────────────────────────────────────────────┘
                           │ HTTP/HTTPS
                           ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                          API GATEWAY                                        │
│                    Laravel (routes/api.php)                                 │
│               Middleware: api, auth:sanctum, permission, throttle           │
└──────────────────────────┬──────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                          CONTROLLERS                                       │
│  OrderController  │  InvoiceController  │  ShipmentController  │  Coupon   │
│  ProductController│  CategoryController │  PromotionController  │  Cart     │
│  (thin — delegates to Services)                                            │
└──────────────────────────┬──────────────────────────────────────────────────┘
                           │ Dependency Injection
                           ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                          SERVICES / ACTIONS                                │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │
│  │  OrderService │  │InvoiceService│  │CartInventory │  │  Coupon/     │  │
│  │              │  │              │  │  Service     │  │  Promotion   │  │
│  │  +checkout   │  │  +generate   │  │  +reserve    │  │  Services    │  │
│  │  +callback   │  │  +correct    │  │  +finalize   │  │              │  │
│  │  +markPaid   │  │  +cancel     │  │  +expire     │  │              │  │
│  └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘  │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │
│  │  Payment     │  │  Shipment    │  │  Checkout    │  │  Credit/Debit│  │
│  │  Handler     │  │  Service     │  │  Creation    │  │  Note Svc    │  │
│  └──────────────┘  └──────────────┘  └──────────────┘  └──────────────┘  │
└──────────────────────────┬──────────────────────────────────────────────────┘
                           │
              ┌────────────┼────────────┐
              ▼            ▼            ▼
┌──────────────────┐ ┌──────────┐ ┌──────────┐
│    EVENTS        │ │  JOBS    │ │  MODELS  │
│  PaymentSucceeded│ │PDF Gen   │ │ Order    │
│  PaymentFailed   │ │Reconcil. │ │ Invoice  │
│  OrderCreated    │ │Activity  │ │ Cart     │
│  OrderCancelled  │ │Password  │ │ Product  │
│  InvoiceCreated  │ │Reset     │ │ Coupon   │
│  RefundApproved  │ │          │ │ Shipment │
└──────────────────┘ └──────────┘ └────┬─────┘
                                       │ Eloquent
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                          DATABASE (MySQL/PostgreSQL)                       │
│  Tables: carts, cart_items, orders, order_products, transactions,         │
│  invoices, invoice_timeline, invoice_sequences, credit_notes,             │
│  debit_notes, shipments, products, product_variants, coupons,             │
│  coupon_usages, coupon_assignments, coupon_assignment_usages,             │
│  promotions, users, categories, brands, banners, sliders, settings,       │
│  governorates, shipping_prices, pickup_locations, activity_log, etc.      │
└─────────────────────────────────────────────────────────────────────────────┘
                                       │
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                          EXTERNAL SERVICES                                 │
│  ┌──────────────┐   ┌──────────────┐   ┌──────────────────┐              │
│  │  MyFatoorah  │   │   Pusher     │   │    Email/SMS     │              │
│  │  (Payments)  │   │  (Realtime)  │   │  (Resend/SMTP)   │              │
│  └──────────────┘   └──────────────┘   └──────────────────┘              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Part C: Production Readiness Score

| Subsystem | Score | Justification |
|-----------|-------|---------------|
| **Checkout Flow** | **7/10** | Payment callback has BUG-4; error callback has logic flaw; otherwise solid with lockForUpdate, idempotency, mismatch checks |
| **Cart Lifecycle** | **6/10** | Core works but no expiration scheduler; stale prices possible; no cart cleanup |
| **Coupon System** | **8/10** | Well-designed with lockForUpdate, idempotent consumption, dual path (assigned/public), proper guards |
| **Promotion System** | **8/10** | Three strategies, gift handling, proper eligibility resolution, consumption guards, rollback on cancel |
| **Order Lifecycle** | **7/10** | Complete state machines; dual event system concern; missing order timeout/cancel command |
| **Payment Lifecycle** | **6/10** | All 3 methods work; callback has mismatch protection; ERROR CALLBACK IS BROKEN (BUG-4); dual event issue |
| **Invoice Lifecycle** | **8/10** | Excellent design: snapshots, hashes, gapless sequences, 6 validators, timeline, correction, cancel, credit/debit notes |
| **Invoice QR** | **3/10** | QR only for cashier payments; verification URL exists but no QR on invoice PDF itself |
| **Shipment Lifecycle** | **4/10** | Complete state machine exists but NO events, NO notifications, NO permission checks (BUG-2) |
| **Refund Lifecycle** | **5/10** | Events and listeners exist; credit note generation works; payment gateway refund NOT integrated |
| **Return Lifecycle** | **0/10** | **SYSTEM DOES NOT EXIST** — no models, no endpoints, no workflow |
| **Customer Experience** | **5/10** | Basic order viewing works; NO shipment tracking, NO invoice PDF, NO post-purchase notifications |
| **Admin Experience** | **7/10** | Dashboard with 15+ analytics; full CRUD for most entities; Telescope monitoring; activity logging; Missing: reconciliation UI, shipment management UI |
| **Support Tooling** | **3/10** | No dedicated support interface; manual DB checks needed for most scenarios |
| **Testing Coverage** | **7/10** | ~150 tests exist; coverage across user, product, category, brand, checkout, payment, invoice; MISSING: concurrency, load, security, shipment, refund, return |
| **Concurrency Protection** | **7/10** | lockForUpdate at all critical points; guards (coupon_consumed, promotion_consumed, inventory_restored_at); MISSING: cancel-unpaid command concurrency |
| **Security** | **6/10** | Sanctum auth, permission middleware on most admin endpoints; HIGH gaps: BUG-1, BUG-2; missing policies for Order, Invoice |
| **Overall** | **6/10** | **NOT PRODUCTION READY** — 2 HIGH severity bugs, 6 MEDIUM bugs, 1 missing subsystem |

---

## Part D: Prioritized Implementation Roadmap

### P0 — BLOCKER (Must Fix Before Launch)

| # | Gap | Effort | Risk | Priority |
|---|-----|--------|------|----------|
| 1 | **BUG-2**: Add permission middleware to ShipmentController | 1h | HIGH | **#1** |
| 2 | **BUG-1**: Add permission check to issueDebitNote | 30min | HIGH | **#2** |
| 3 | **BUG-4**: Fix checkoutErrorCallback to check gateway result | 2h | MEDIUM | **#3** |
| 4 | **GAP-6**: Create CancelUnpaidOrders command with lockForUpdate | 4h | MEDIUM | **#4** |
| 5 | **GAP-7**: Register cart expiration scheduler | 30min | MEDIUM | **#5** |
| 6 | **BUG-10**: Resolve dual event system (ensure events fire correct listeners) | 3h | MEDIUM | **#6** |
| 7 | **GAP-14**: Add customer notifications for key events | 8h | MEDIUM | **#7** |

### P1 — Week 1 After Launch

| # | Gap | Effort |
|---|-----|--------|
| 8 | **BUG-8**: Implement actual PDF generation using DomPDF | 8h |
| 9 | **BUG-13**: Add override key whitelist in CorrectInvoiceRequest | 2h |
| 10 | **BUG-6**: Add InvoiceCreated dispatch + PDF job for corrections | 1h |
| 11 | **GAP-9**: Add shipment lifecycle events + customer notifications | 6h |
| 12 | **GAP-12**: Unify allowed states for cancel/debit-note | 1h |

### P2 — Week 2-3

| # | Gap | Effort |
|---|-----|--------|
| 13 | **GAP-8**: Build complete Return subsystem | 40h |
| 14 | Add OrderPolicy + InvoicePolicy (authorization) | 4h |
| 15 | Fix payment status accessor inconsistency (BUG-11) | 2h |
| 16 | Add invoice QR code generation on PDF | 4h |

### P3 — Future (Month 2+)

| # | Gap | Effort |
|---|-----|--------|
| 17 | Multi-language invoice PDF templates | 8h |
| 18 | Advanced reconciliation dashboard | 16h |
| 19 | Load testing + performance optimization | 20h |
| 20 | API contract tests (Pact/PHPUnit) | 16h |
| 21 | Concurrency stress tests | 12h |
| 22 | Security audit + penetration testing | 20h |

---

## Part E: Comprehensive Test Matrix

### Current Test Coverage

```
tests/
├── Concerns/
│   ├── CreatesTestTables.php      — 726 lines: ALL table schemas
│   └── WithInvoiceTables.php      — Invoice-specific tables
├── Unit/
│   ├── ExampleTest.php
│   ├── ChannelEnumTest.php
│   ├── ChannelContextTest.php
│   ├── CouponCalculatorTest.php
│   ├── CouponValidatorTest.php
│   ├── ProductPricingServiceTest.php
│   ├── PromotionStrategyTest.php
│   ├── PromotionEligibilityResolverTest.php
│   ├── FastShippingRepositoryTest.php
│   ├── FastShippingScopeTest.php
│   ├── SnapshotIntegrityServiceTest.php    (10 tests)
│   └── InvoiceLifecycleTest.php            (24 tests)
└── Feature/
    ├── AuthenticationTest.php
    ├── UserAuthAdminTest.php
    ├── UserControllerTest.php
    ├── RoleAndPermissionTest.php
    ├── PaymentSystemTest.php
    ├── PaymentCheckoutTest.php
    ├── PaymentCallbackStressTest.php
    ├── PaymentReconciliationTest.php
    ├── CheckoutApiTest.php
    ├── CheckoutConcurrencyStressTest.php
    ├── CartExpirationTest.php
    ├── OrderCreationFlowTest.php
    ├── ProductCrudTest.php
    ├── Category* (10+ files)
    ├── Brand* (5+ files)
    ├── Coupon* (3+ files)
    ├── Promotion* (5+ files)
    ├── FlashSale* (5+ files)
    └── 50+ more feature test files
```

### Required New Tests

#### P0 Tests (Must Have Before Launch)

| Test | Type | What to Test |
|------|------|-------------|
| `CheckoutCallbackIdempotencyTest` | Feature | Callback called twice — second should be no-op |
| `CheckoutErrorCallbackGatewaySuccessTest` | Feature | Error callback when gateway reports success |
| `CheckoutAmountMismatchTest` | Feature | Amount difference > 0.01 blocks order |
| `CheckoutCurrencyMismatchTest` | Feature | Different currency blocks order |
| `CancelUnpaidOrdersCommandTest` | Feature | Command cancels expired pending orders |
| `CartExpirationSchedulerTest` | Feature | Expired carts release inventory |
| `InvoicePermissionTest` | Feature | Verify permission middleware on all invoice endpoints |
| `ShipmentPermissionTest` | Feature | Verify permission middleware on shipment endpoints |

#### P1 Tests

| Test | Type | What to Test |
|------|------|-------------|
| `InvoicePdfGenerationTest` | Feature | PDF is generated, stored, has correct checksum |
| `InvoiceCorrectionCreatesInvoiceTest` | Feature | Correction creates new invoice with InvoiceCreated event |
| `CorrectInvoiceOverrideValidationTest` | Feature | Only whitelisted overrides accepted |
| `ShipmentStatusEventsTest` | Feature | Status changes fire events, trigger notifications |
| `ConcurrentCheckoutSameCartTest` | Concurrency | Two simultaneous checkouts — only one succeeds |
| `ConcurrentCouponConsumptionTest` | Concurrency | Two simultaneous orders using same assigned coupon |

#### P2 Tests

| Test | Type | What to Test |
|------|------|-------------|
| `ReturnLifecycleTest` | Feature | Complete return flow (after system built) |
| `OrderAuthorizationTest` | Feature | OrderPolicy gates work correctly |
| `InvoiceAuthorizationTest` | Feature | InvoicePolicy gates work correctly |
| `PaymentStatusAccessorTest` | Unit | getPaymentStatusAttribute returns correct values |
| `TransactionConcurrencyTest` | Concurrency | Multiple simultaneous callbacks for same transaction |

#### P3 Tests

| Test | Type | What to Test |
|------|------|-------------|
| `ApiContractTest` | Contract | Response structure matches API spec |
| `LoadTestCheckout` | Load | 100 concurrent checkouts |
| `LoadTestInvoiceGeneration` | Load | 100 concurrent invoice generations |
| `SecurityPenetrationTest` | Security | SQL injection, XSS, CSRF, auth bypass |
| `FullOrderLifecycleFuzzTest` | Integration | Random state transitions, all permutations |

### Test Matrix Summary

| Category | Existing | Required | Total |
|----------|----------|----------|-------|
| Unit Tests | ~50 | 20 | 70 |
| Feature Tests | ~100 | 40 | 140 |
| Concurrency Tests | 0 | 6 | 6 |
| Load Tests | 0 | 4 | 4 |
| Security Tests | 0 | 4 | 4 |
| API Contract Tests | 0 | 4 | 4 |
| **Total** | **~150** | **78** | **~228** |

---

## Part F: Final Production Launch Checklist

### Pre-Launch (Week Before)

```
[ ] All P0 gaps fixed (GAP-1 through GAP-7)
[ ] Permission middleware on ALL admin endpoints audited
[ ] BUG-4 (error callback) fixed — gateway result checked
[ ] CancelUnpaidOrders command created + registered in Kernel::schedule()
[ ] Cart expiration cron registered (every 5 minutes)
[ ] Dual event system resolved — listeners confirmed correct
[ ] Customer notifications working: order confirmation, payment confirmation
[ ] All ~150 existing tests passing
[ ] CreateTestTables schema matches production migrations
[ ] Test suite runs in < 30 seconds
[ ] CI pipeline passes (if configured)
[ ] PDF generation placeholder marked — if not fixed, feature flagged
```

### Launch Day

```
[ ] Database migrations run
[ ] Invoice sequence initialized (series=INV, year=current, last_sequence=0)
[ ] Queue workers running for high, medium, low queues
[ ] Scheduler running (php artisan schedule:run)
[ ] MyFatoorah gateway configured with live credentials
[ ] Pusher configured for admin notifications
[ ] Email driver configured (Resend/SMTP)
[ ] Storage link created (php artisan storage:link)
[ ] App debug mode OFF
[ ] Telescope restricted to admin only
[ ] CORS configured for frontend domain
[ ] Rate limiting configured (60/min for verify, 30/min for download)
[ ] Monitoring/alerts configured (Telescope, logs, queue failed jobs)
```

### Post-Launch (Week 1)

```
[ ] Monitor queue failed jobs (PaymentReconciliationJob, GenerateInvoiceListener)
[ ] Verify invoice generation works end-to-end
[ ] Verify PDF generation (if implemented)
[ ] Monitor PaymentReconciliationJob mismatches
[ ] Check cart expiration is running (released inventory)
[ ] Verify customer notifications being sent
[ ] Review error logs for callback failures
[ ] Load test production environment
```

### Post-Launch (Week 2-3)

```
[ ] All P1 gaps fixed
[ ] Implement P2 gaps (Return system, authorization policies)
[ ] Begin P3 items (contract tests, security audit)
[ ] Full regression test run
```

---

## Part G: Rollback Strategy

| Scenario | Rollback Action | Data Loss | Impact |
|----------|----------------|-----------|--------|
| Payment callback bug found | Feature flag to disable auto-finalization, switch to manual | None | Operations team processes manually |
| Invoice generation broken | Disable GenerateInvoiceListener, manual invoice dispatch | None | Support team handles invoices |
| PDF generation broken | Feature flag to show "PDF coming soon" message | None | Customers wait |
| CancelUnpaidOrders buggy | Comment out scheduler line, re-run cancelled orders | Orders may have been cancelled | Support reinstates orders |
| Cart expiration aggressive | Reduce TTL back to 3 days, re-run with longer TTL | Expired carts lost | Customers re-add items |
| MyFatoorah gateway down | Switch default gateway to fallback (if configured) | None | Customers use alt gateway |
| Database migration issue | `php artisan migrate:rollback` | 1 step of data | Temporary downtime |
| Queue worker failure | Restart horizon/supervisor | Failed jobs in retry | Auto-retry handling |
