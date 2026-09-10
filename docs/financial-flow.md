# Financial Flow — Complete Architecture Documentation

> Version: 1.0 | Classification: INTERNAL | Last Updated: 2026-07-27

---

## Executive Summary

The financial flow encompasses all money movement through the platform: payment capture via MyFatoorah gateway (online), cash on delivery (COD), and pay-at-cashier (QR); transaction lifecycle from pending to paid; amount verification between gateway and order; promotion and coupon usage accounting; refund processing (gateway refund + wallet credit + shop debit); and batch reconciliation. The system does **not** implement double-entry accounting — financial tracking is done through balance models (shop earnings, customer wallets, platform commissions). Tax collection is not yet implemented.

---

## 1. Payment Lifecycle Overview

```
ORDER CREATED (pending, unpaid)
    │
    ├── Online (MyFatoorah)
    │      └── Gateway invoice created → User redirected → Callback verifies → Order completed
    │
    ├── Cash on Delivery
    │      └── Order created → Admin marks as paid → Order completed
    │
    └── Pay at Cashier (QR)
           └── QR generated → Cashier scans → Admin marks paid → Order completed
```

---

## 2. Payment Methods

### 2.1 Online Payment (MyFatoorah)

**File**: `app/Services/Payment/PaymentCheckoutHandler.php`

**Flow**:
1. `handleOnlinePayment(Order $order)`:
   - Factory resolves `MyFatoorahGateway` from `PaymentGatewayFactory`
   - Calls `$gateway->createInvoice($order, $amount, $callbackUrl, $errorUrl)`
   - MyFatoorah API: `POST /v2/SendPayment` — creates invoice, returns redirect URL
   - Creates `Transaction` record with `status = 'pending'`, `invoice_id` from gateway
   - Returns redirect URL to frontend

2. Callback: `OrderController::checkoutCallback(Request $request)`:
   - Receives `paymentId` query parameter
   - Calls `$gateway->verifyPayment($paymentId)` → `POST /v2/GetPaymentStatus`
   - **Amount verification**: `abs(gatewayAmount - order.total_price) > 0.01` → blocks payment
   - **Currency verification**: gateway currency !== `config('payment.default_currency')` → blocks payment
   - On success DB transaction (with `lockForUpdate()`):
     - Transaction: `status = 'paid'`, `paid_at = now()`
     - Cart inventory finalized per shipping method
     - `finalizePromotionUsageAfterPayment()` — increments promotion usage
     - Order status → `'completed'`
     - `CouponService::recordCouponUsage()` — records coupon consumption
     - `PaymentSucceeded` event dispatched

3. Error callback: `OrderController::checkoutErrorCallback(Request $request)`:
   - Transaction → `status = 'failed'`, `error_message` set
   - `PaymentFailed` event dispatched
   - Cart inventory **released** (stock returned)

### 2.2 MyFatoorah Gateway

**File**: `app/Services/Gateway/MyFatoorahGateway.php`

**Interface**: `PaymentGatewayContract` — `createInvoice()`, `verifyPayment()`, `refund()`, `name()`

| Operation | API Endpoint | Purpose |
|---|---|---|
| `createInvoice()` | `POST /v2/SendPayment` | Creates payment invoice, returns redirect URL |
| `verifyPayment()` | `POST /v2/GetPaymentStatus` | Verifies payment status, returns amount + currency |
| `refund()` | `POST /v2/MakeRefund` | Initiates refund to customer |

**HTTP Client**: `app/Services/General/MyfatoraService.php`
- Bearer token: `config('services.myfatoorah.api_key')`
- Base URL: `config('services.myfatoorah.base_url')` (default: `https://apitest.myfatoorah.com/v2/`)
- Timeout: 30 seconds
- SSL verification disabled in `local` environment

**GatewayResult DTO**:
```php
class GatewayResult {
    public readonly bool $success;
    public readonly ?string $redirectUrl;
    public readonly ?string $gatewayTransactionId;
    public readonly ?float $amount;
    public readonly ?string $currency;
    public readonly ?string $status;
    public readonly ?string $errorMessage;
    public readonly ?array $rawResponse;
}
```

### 2.3 Cash on Delivery

**File**: `PaymentCheckoutHandler::handleCodPayment(Order $order)`

- Creates `Transaction` with `payment_method = 'cod'`, `status = 'pending'`
- Returns success immediately (no gateway call)
- Admin marks as paid via admin endpoint → `status = 'paid'`, `paid_at = now()`
- Same finalization as online callback: inventory, promotion usage, coupon usage, `PaymentSucceeded` event

**Restriction**: COD is **not available** for pickup fulfillment (`cod + pickup → 422`)

### 2.4 Pay at Cashier (QR)

**File**: `PaymentCheckoutHandler::handleCashierQrPayment(Order $order)`

- Generates QR code via `CashierQrService` with transaction UUID
- Creates `Transaction` with `payment_method = 'pay_at_cashier'`, `status = 'pending'`, `qr_code_url`
- Cashier scans QR to identify the transaction
- Admin marks as paid via admin endpoint → same finalization

---

## 3. Transaction State Machine

```
                    ┌─────────┐
                    │ PENDING │  ← Created during checkout
                    └────┬────┘
                         │
              ┌──────────┼──────────┐
              │          │          │
              ▼          ▼          ▼
           ┌──────┐   ┌──────┐   ┌──────┐
           │ PAID │   │FAILED│   │ PAID │  (retry)
           └──┬───┘   └──────┘   └──────┘
              │
         (terminal)
```

**Idempotency guard**: If transaction is already `paid` and order is `completed`, callback skips processing entirely.

### Transaction Table Schema

```sql
transactions:
  id                     BIGINT UNSIGNED
  uuid                   CHAR(36) UNIQUE          -- auto-generated on create
  invoice_id             INT NOT NULL              -- MyFatoorah invoice ID
  user_id                BIGINT NOT NULL
  payment_method         VARCHAR(255)              -- 'myfatoorah' | 'cod' | 'pay_at_cashier'
  status                 VARCHAR(30)               -- 'pending' | 'paid' | 'failed'
  amount                 DECIMAL(10,2) NOT NULL
  currency               VARCHAR(3) DEFAULT 'EGP'
  gateway_transaction_id VARCHAR(255)
  gateway_response       JSON
  error_message          TEXT
  qr_code_url            VARCHAR(500)
  paid_at                TIMESTAMP NULL
  order_id               BIGINT UNSIGNED           -- FK → orders.id
  created_at, updated_at TIMESTAMPS

  INDEX (status), INDEX (uuid)
```

---

## 4. Amount Verification

### 4.1 Callback Verification

In `checkoutCallback()`, two checks gate payment settlement:

```php
// Amount: tolerance of 0.01 (1 piaster)
if (abs($gatewayAmount - $order->total_price) > 0.01) {
    // → PaymentFailed event, order cancelled, inventory released
}

// Currency: config('payment.default_currency')
if ($gatewayCurrency !== config('payment.default_currency', 'EGP')) {
    // → PaymentFailed event, order cancelled
}
```

On mismatch: `PaymentFailed` event fires, cart inventory is released (stock returned), and the user is redirected to the failure page.

### 4.2 Batch Reconciliation

**Command**: `php artisan payments:reconcile`

**File**: `app/Jobs/PaymentReconciliationJob.php`

- Queries all non-failed transactions with `gateway_transaction_id`
- Calls `verifyPayment()` on each
- Compares: `amount` (tolerance 0.01), `currency`, `payment_status`, `order_status`
- Records mismatches in `payment_reconciliation_results` table

**`PaymentReconciliationResult` schema**:
```php
transaction_id, order_id, gateway, mismatch_type, expected_value, actual_value, notes, resolved_at
mismatch_type: 'amount' | 'currency' | 'payment_status' | 'order_status'
```

**Known gap**: `compareRefundStatus()` returns `false` (no-op) — refund reconciliation not yet implemented.

---

## 5. Coupon Usage Recording

**Trigger**: Called when order status transitions to `completed`.

### Assigned Coupons (with `coupon_assignments` table)

```
1. lockForUpdate() on assignment row
2. Check: used < max_uses
3. Check: no existing CouponAssignmentUsage for this order
4. coupon.increment('used')                    -- global counter
5. assignment.increment('used')                -- per-assignment counter
6. Create CouponAssignmentUsage record
7. Fire AssignedCouponConsumed event
```

### Public Coupons

```
1. CouponUsage::firstOrCreate(['coupon_id', 'user_id'], ['order_id', 'used_at'])
2. If wasRecentlyCreated: coupon.increment('used')
3. Unique constraint on (coupon_id, user_id) → one usage per user
```

**Policy**: Coupon quota is consumed **on payment success only**. Never returned on cancellation or refund (prevents abuse).

---

## 6. Promotion Usage Tracking

**Increment** (`PromotionService::incrementUsage()`):
- Called in `finalizePromotionUsageAfterPayment()` (payment callback) and `markCodAsPaid()`/`markCashierPaid()`
- `lockForUpdate()` on promotion row
- Checks `usage < limiter` (or `limiter` is null)
- `->increment('usage')`

**Decrement** (`PromotionService::decrementUsage()`):
- Called when order is **cancelled** via `changeOrderStatus()`
- `lockForUpdate()`, checks `usage > 0`, `->decrement('usage')`

---

## 7. Refund Flow

### States: `PENDING → PROCESSING → APPROVED` (terminal) or `REJECTED` (terminal)

### Approval Process (`RefundController::updateRefund()`)

1. **Authorization**: Requires `SUPER_ADMIN` permission
2. **Idempotency check**: If already `APPROVED`, return 400
3. **Gateway refund**:
   - If order has a gateway: `$gateway->refund($order, $amount)` → MyFatoorah `MakeRefund` API
   - If gateway fails: return 400 with gateway error
   - If `UnsupportedGatewayException` (COD/cashier): **skip gracefully**
4. **Financial DB transaction** (with row locking):
   - Update refund status to `APPROVED`
   - **Debit shop**: For each child order, `balance.decrement('total_earnings')` and `balance.decrement('current_balance')`
   - **Credit customer wallet**: `wallet.increment('total_points')` and `wallet.increment('available_points')` via `currencyToWalletPoints($amount)`
5. Fire `RefundApproved` event

### Key Financial Impacts of Refund Approval:

| Entity | Effect |
|---|---|
| Shop balance | Decreased (total_earnings, current_balance) |
| Customer wallet | Increased (total_points, available_points) |
| Transaction | **Unchanged** — remains `paid` |
| Coupon usage | **Not returned** — by design |
| Promotion usage | **Not decremented** |

Note: The wallet uses a **points-based** system. Real currency is converted via `currencyToWalletPoints()`.

---

## 8. Vendor/Seller Financials

### Balance Model (`Marvel\Database\Models\Balance`)

| Field | Type | Purpose |
|---|---|---|
| `total_earnings` | decimal | Lifetime earnings for the shop |
| `current_balance` | decimal | Available balance (earnings - withdrawals) |

Updated:
- **Credit**: When order is completed (vendor's share of sale)
- **Debit**: When refund is approved (returned to customer)

### Platform Commission (`PlatformCommission` Model)

| Field | Purpose |
|---|---|
| `order_total` | Total order amount |
| `commission_rate` | Commission percentage at time of order |
| `commission_amount` | Calculated commission |
| `shop_earnings` | Vendor payout (order_total - commission) |
| `commission_type` | Fixed or percentage |

Created when vendor balance is updated after order completion.

### Commission Model
Tiered commission rates based on vendor earnings thresholds.

---

## 9. Invoice Financial Entries

**Status**: Invoice generation is **wired but dormant**. The `GenerateInvoiceListener` is queued on `PaymentSucceeded` and calls `InvoiceService::generateFromOrder()`, but `Invoice::create()` is **never called** in the current codebase. No controller or routes exist for invoice CRUD.

### Invoice Schema (financial fields)

```sql
invoices:
  subtotal         DECIMAL(10,3)
  shipping_price   DECIMAL(10,3)
  fast_shipping_fee DECIMAL(10,3)
  promotion_discount DECIMAL(10,3)
  coupon_discount  DECIMAL(10,3)
  total            DECIMAL(10,3)
  amount_paid      DECIMAL(10,3)
  currency         VARCHAR(3)
```

### InvoiceSnapshotService
Builds a full immutable financial snapshot of the order at invoice time:
- `pricing_breakdown`: subtotal, shipping, fast_shipping, promotions, coupons, total
- `items_snapshot`: each item's pricing at the time of snapshot
- `validation`: runs `FinancialInvariantValidator` to check: `total === subtotal - promotion - coupon + shipping`

**Known bug**: `FinancialInvariantValidator` formula is `subtotal - promotion - coupon + shipping` but **missing `fast_shipping_fee`**. Will fail for any fast-shipping order. The snapshot's `pricing_breakdown` includes fast_shipping_fee but the invariant formula does not.

---

## 10. Money Precision & Rounding

### Storage Precision by Table

| Table | Precision | Examples |
|---|---|---|
| `orders.total_price` | DECIMAL(10,3) | `100.000` |
| `transactions.amount` | DECIMAL(10,2) | `100.00` |
| `cart_items.price` | DECIMAL(10,2) | `100.00` |
| `order_products.*` | DECIMAL(10,3) | pricing fields |
| `invoices.*` | DECIMAL(10,3) | financial fields |
| `coupons.discount` | DECIMAL(8,3) | `10.000` |
| `promotions.value` | DECIMAL(10,2) | `10.00` |

### Rounding at Each Stage

| Stage | Method | Location |
|---|---|---|
| Product price | `round(price, 2)` | `ProductPricingService` |
| Promotion discount | `round(discount * 100)` → int cents → `fromCents()` | PromotionApplicator |
| Per-item allocation | Integer cents, largest-remainder | PromotionApplicator |
| Coupon discount | `round(discount, 2)` → `round(price - discount, 2)` | CouponCalculator |
| Cart total | `round(sum(item.totals), 2)` | OrderService |
| Order total | `round(finalTotal + shipping + fastFee, 2)` | OrderCreationService |
| Tolerance | 0.01 (1 piaster/cent) | Callback verification, FinancialInvariantValidator |

### Integer Cent Conversion (Promotion Engine)

```php
toCents(float $value): int   → (int) round($value * 100)
fromCents(int $cents): float → $cents / 100
```

The promotion engine uses integer cents internally to avoid floating-point accumulation errors, then converts back to decimals for storage.

---

## 11. Known Issues & Technical Debt

### CRITICAL

| ID | Issue | Impact |
|---|---|---|
| FIN-INV-1 | `FinancialInvariantValidator` formula missing `fast_shipping_fee` | Will reject all fast-shipping order invoices |
| FIN-INV-2 | Invoice generation is dormant — `Invoice::create()` never called | No invoices in production; no routes, no controller |

### HIGH

| ID | Issue | Impact |
|---|---|---|
| FIN-1/CPN-1 | Stale in-memory coupon after `cart->update(['coupon' => null])` without `$cart->refresh()` | Expired coupon may be re-applied with invalid discount |
| FIN-2 | `PromotionService::applySelectedPromotion()` has DB side effects during preview | Preview mutates cart state irreversibly |

### MEDIUM

| ID | Issue | Impact |
|---|---|---|
| FIN-3 | `total_price = price * quantity` not rounded at cart-add | Floating-point artifacts stored (`10.99 * 3 = 32.97000000000001`) |
| FIN-4 | No tax implementation | Cannot collect or report tax |
| FIN-5 | `number_format()` returns string instead of `round()` returning float | Type inconsistency in PromotionApplicator |

### LOW

| ID | Issue | Impact |
|---|---|---|
| FIN-6 | `PaymentReconciliationJob::compareRefundStatus()` is a no-op | Refund reconciliation not implemented |
| FIN-7 | Mixed precision domains (D8,3 vs D10,2 vs D12,2) across monetary fields | Inconsistency but functionally compatible |
| FIN-8 | No double-entry accounting | Cannot produce balance sheet or journal entries |
| FIN-9 | Native PHP float arithmetic (no bcmath) | Potential accumulation errors at high volume/values |

---

## 12. Key Classes Reference

| Class | File | Responsibility |
|---|---|---|
| `PaymentCheckoutHandler` | `app/Services/Payment/PaymentCheckoutHandler.php` | Creates transactions for all payment methods |
| `MyFatoorahGateway` | `app/Services/Gateway/MyFatoorahGateway.php` | Gateway contract: createInvoice, verifyPayment, refund |
| `MyfatoraService` | `app/Services/General/MyfatoraService.php` | Low-level HTTP client for MyFatoorah API |
| `PaymentGatewayFactory` | `app/Services/Payment/PaymentGatewayFactory.php` | Resolves gateway string to gateway class |
| `GatewayResult` | `app/DTOs/GatewayResult.php` | Gateway response DTO |
| `Transaction` (model) | `packages/marvel/src/Database/Models/Transaction.php` | Transaction record with UUID, status, amounts |
| `CashierQrService` | `app/Services/General/CashierQrService.php` | QR code generation for pay-at-cashier |
| `CouponService` | `app/Services/General/CouponService.php` | Coupon validation, code lookup, usage recording |
| `CouponCalculator` | `app/Services/Coupon/CouponCalculator.php` | Coupon discount math |
| `PromotionService` | `app/Services/General/PromotionService.php` | Promotion orchestration, usage increment/decrement |
| `Refund` (model/controller) | Various | Refund lifecycle, gateway refund, balance adjustment |
| `Balance` (model) | `packages/marvel/src/Database/Models/Balance.php` | Shop-level earnings and current balance |
| `Wallet` (model) | `packages/marvel/src/Database/Models/Wallet.php` | Customer points (total + available) |
| `PlatformCommission` (model) | Various | Per-order commission tracking |
| `PaymentReconciliationJob` | `app/Jobs/PaymentReconciliationJob.php` | Batch reconciliation with gateway |
| `PaymentReconciliationResult` (model) | Various | Mismatch records from reconciliation |
| `GenerateInvoiceListener` | `app/Listeners/GenerateInvoiceListener.php` | Queued invoice generation on PaymentSucceeded |
| `InvoiceSnapshotService` | `app/Services/Invoice/InvoiceSnapshotService.php` | Immutable order snapshot for invoice |
| `FinancialInvariantValidator` | Various | Validates `total = subtotal - promo - coupon + shipping` |

---

## 13. Payment Method Comparison

| Aspect | Online (MyFatoorah) | COD | Pay at Cashier |
|---|---|---|---|
| Gateway call | Yes — SendPayment API | No | No |
| Amount verification | Cross-checked with gateway | N/A | N/A |
| Order completion | Automatic on callback | Manual (admin) | Manual (cashier) |
| Promotion usage | In callback finalization | In markCodAsPaid() | In markCashierPaid() |
| Coupon usage | On order → completed | In markCodAsPaid() | In markCashierPaid() |
| Cart finalized | In callback | In markCodAsPaid() | In markCashierPaid() |
| Gateway refund | Supported | Not supported | Not supported |
| Settlement risk | Gateway guarantees | Merchant assumes | Merchant assumes |
| Transaction fee | Gateway fee applies | No gateway fee | No gateway fee |
