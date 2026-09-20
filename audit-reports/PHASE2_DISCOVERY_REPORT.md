# PHASE 2 REMEDIATION: DISCOVERY REPORT

**Date:** 2026-09-25  
**Engineer:** Principal/Senior E-commerce Architect  
**Status:** Discovery Phase Complete  

---

## EXECUTIVE SUMMARY

This report documents the actual payment system architecture discovered in the repository before implementing Phase 2 remediation.

**Key Finding:** The existing Phase 2 implementation has fundamental flaws that make the claim "duplicate payment processing is impossible" **FALSE**.

---

## 1. FRAMEWORK ENVIRONMENT

### Framework Versions
- **Laravel:** 10.30.1
- **PHP:** 8.5.8 (cli) (ZTS Visual C++ 2022 x64)
- **Database Engine:** MySQL (via PDO, InnoDB assumed)
- **Default Connection:** `mysql` (config/database.php line 18)
- **Transaction Isolation:** Not explicitly configured (MySQL default: REPEATABLE-READ)

### Key Dependencies
- `laravel/framework`: 10.30.1
- `doctrine/dbal`: 3.7.1 (schema inspection)
- `guzzlehttp/guzzle`: 7.8.0 (HTTP client for gateway APIs)
- `predis/predis`: ^3.6 (Redis client)

### Infrastructure
- **Queue Driver:** Not discovered yet (likely database or Redis)
- **Cache Driver:** Not discovered yet (likely Redis based on predis dependency)
- **Broadcasting:** Pusher (packages/marvel/config/broadcasting.php exists)

---

## 2. PAYMENT GATEWAY ARCHITECTURE

### Gateway Contract

**File:** `app/Services/Payment/Contracts/PaymentGatewayContract.php`

```php
interface PaymentGatewayContract
{
    public function createInvoice(Order $order, float $amount, string $callbackUrl, 
                                  string $errorUrl, array $metadata = []): GatewayResult;
    
    public function verifyPayment(string $gatewayTransactionId): GatewayResult;
    
    public function refund(Order $order, float $amount, ?string $reason = null): GatewayResult;
    
    public function name(): string;
    
    public function supportsCurrency(string $currencyCode): bool;
}
```

### Gateway Result DTO

**File:** `app/DTOs/GatewayResult.php`

```php
class GatewayResult
{
    public readonly bool $success;
    public readonly ?string $redirectUrl;
    public readonly ?string $gatewayTransactionId;  // ← CRITICAL: This is the external payment identity
    public readonly ?float $amount;
    public readonly ?string $currency;
    public readonly ?string $status;
    public readonly ?string $errorMessage;
    public readonly ?array $rawResponse;
}
```

### MyFatoorah Gateway Implementation

**File:** `app/Services/Gateway/MyFatoorahGateway.php`

**Payment Identity Semantics:**
- `createInvoice()` returns `InvoiceId` as `gatewayTransactionId`
- `verifyPayment()` accepts `PaymentId` as input
- Verification API uses: `Key` = paymentId, `KeyType` = 'PaymentId'
- Verification returns: `InvoiceId`, `InvoiceStatus`, `InvoiceValue`, `DisplayCurrencyIso`

**CRITICAL FINDING:** The gateway uses **TWO identifiers**:
1. **InvoiceId** - The gateway's invoice identity (returned by createInvoice)
2. **PaymentId** - The callback parameter (may differ from InvoiceId)

**Payment Flow:**
```
createInvoice() → InvoiceId (stored as gateway_transaction_id)
↓
Customer redirected to gateway
↓
Gateway callback with PaymentId parameter
↓
verifyPayment(PaymentId) → returns InvoiceId
```

**Current Issue:** The callback lookup tries BOTH:
- `gateway_transaction_id = PaymentId` (may not match)
- `invoice_id = PaymentId` (fallback)

Then falls back to verified InvoiceId if first lookup fails.

### Webhook Security Infrastructure

**File:** `app/ValueObjects/WebhookSignature.php`

```php
class WebhookSignature
{
    public function generate(string $payload): string {
        return hash_hmac('sha256', $payload, $this->secret);
    }
    
    public function verify(string $payload, string $signature): bool {
        return hash_equals($this->generate($payload), $signature);
    }
}
```

**CRITICAL FINDING:** HMAC signature infrastructure EXISTS but is used for **FRONTEND webhooks ONLY**, not for payment gateway callbacks.

**File:** `app/Services/FrontendWebhookService.php` - Uses HMAC for outbound webhooks to frontend
**File:** `tests/Unit/WebhookSignatureTest.php` - Comprehensive HMAC tests exist

**Payment Gateway Callbacks:** NO signature verification found in `checkoutCallback()`.

---

## 3. TRANSACTION MODEL

### Database Schema

**File:** `packages/marvel/src/Database/Models/Transaction.php`

**Fillable Columns:**
```php
$fillable = [
    'order_id', 'invoice_id', 'payment_method', 'user_id', 'uuid', 
    'status', 'amount', 'currency', 'gateway_transaction_id', 
    'gateway_response', 'error_message', 'qr_code_url', 'paid_at', 
    'idempotency_key'  // Added in Phase 2
];
```

**Casts:**
```php
'gateway_response' => 'array',
'paid_at' => 'datetime',
'amount' => 'float',
```

**Boot Hook:**
```php
static::creating(function (Transaction $transaction) {
    if (!$transaction->uuid) {
        $transaction->uuid = (string) Str::uuid();
    }
});
```

**Relationships:**
- `belongsTo(Order::class)`

**Scopes:**
- `scopePending()` - where status = 'pending'
- `scopePaid()` - where status = 'paid'
- `scopeFailed()` - where status = 'failed'

### Migration History

**File:** `database/migrations/2026_09_25_000001_add_idempotency_key_to_transactions.php` (APPLIED)

```php
$table->string('idempotency_key', 64)
    ->nullable()
    ->unique()
    ->after('gateway_transaction_id');
```

**Constraint:** UNIQUE INDEX on `idempotency_key` column

**Other Migrations:**
- `2026_07_08_000002_add_payment_tracking_to_transactions.php` - Added payment tracking
- `2026_07_08_141643_add_not_null_constraints_to_orders_and_transactions.php` - Added NOT NULL constraints
- `2026_09_02_000001_make_transactions_invoice_id_nullable.php` - Made invoice_id nullable for COD

### Schema Audit - Constraints

**CRITICAL FINDING:** `gateway_transaction_id` has NO UNIQUE constraint.

**Implications:**
- Multiple `transactions` rows CAN have the same `gateway_transaction_id`
- Same external payment could map to multiple orders
- Lock targeting is ambiguous if duplicates exist

**Required Investigation:** Check production data for duplicate `gateway_transaction_id` values.

---

## 4. CALLBACK/WEBHOOK ROUTING

**File:** `routes/api.php` (lines 115-116)

```php
Route::match(['get', 'post'], 'checkout/callback', [OrderController::class, 'checkoutCallback'])
    ->middleware('throttle:payment-callback')
    ->name('api.checkout.callback');

Route::match(['get', 'post'], 'checkout/error-callback', [OrderController::class, 'checkoutErrorCallback'])
    ->middleware('throttle:payment-callback')
    ->name('api.checkout.errorCallback');
```

**Observations:**
- Accepts BOTH GET and POST (browser redirect + webhook)
- Uses custom throttle middleware: `throttle:payment-callback`
- NO authentication middleware
- NO HMAC signature verification middleware
- PUBLIC endpoints - anyone can POST to them

**Trust Model:** Gateway API verification (`verifyPayment()`) is the ONLY authentication.

---

## 5. PAYMENT CALLBACK IMPLEMENTATION

**File:** `app/Http/Controllers/Api/General/OrderController.php::checkoutCallback()` (lines 169-442)

### Current Flow

```
1. Validate paymentId parameter (lines 178-188)
2. Lookup transaction by gateway_transaction_id OR invoice_id (lines 200-202)
3. Determine gateway name from transaction or default 'myfatoorah' (line 204)
4. Call gateway->verifyPayment(paymentId) (line 213)
5. Get verified InvoiceId from result (line 215)
6. If transaction not found, retry lookup with verified InvoiceId (lines 217-221)
7. If verification fails, mark transaction failed (lines 245-262)
8. DB::transaction() starts at LINE 289 ← TRANSACTION BOUNDARY
   a. Acquire lock: lockForUpdate() (lines 295-302)
   b. Check idempotency_key (lines 308-316) ← PRIMARY DEFENSE
   c. Set idempotency_key = UUID (lines 318-320) ← NOT COMMITTED YET
   d. Check order status (lines 322-332) ← SECONDARY DEFENSE
   e. Validate amount/currency (lines 334-389)
   f. If validation fails, mark failed and return (lines 378-385)
   g. Update transaction status = 'paid' (lines 391-400)
   h. Update order payment fields (lines 400-407)
   i. Commit inventory (line 397)
   j. Set $processed = true flag (line 401)
9. DB::transaction() commits at LINE 408 ← IDEMPOTENCY TOKEN COMMITS HERE
10. If $processed, dispatch PaymentSucceeded event (lines 405-407)
11. Return success response (lines 410-442)
```

### CRITICAL FLAW CONFIRMED

**Lines 289-408:** The ENTIRE payment processing logic is inside `DB::transaction(function() {...})`.

**Line 320:** `$lockedTransaction->update(['idempotency_key' => $idempotencyToken]);`

**Line 408:** Transaction closure ends - this is when the UPDATE commits.

**Proof of Flaw:**
```php
DB::transaction(function () use (...) {  // LINE 289 - BEGIN
    $lockedTransaction = Transaction::where(...)->lockForUpdate()->first();
    
    if ($lockedTransaction->idempotency_key !== null) { return; }  // Check token
    
    $idempotencyToken = \Illuminate\Support\Str::uuid()->toString();
    $lockedTransaction->update(['idempotency_key' => $idempotencyToken]);  // LINE 320 - UPDATE
    
    // ... 80+ lines of business logic ...
    
}); // LINE 408 - COMMIT (token becomes visible to other connections NOW)
```

**Concurrency Window:** From line 320 (UPDATE) to line 408 (COMMIT), the token is NOT visible to other database connections.

**However:** Pessimistic locking (`lockForUpdate()`) DOES serialize access. Second request blocks until first commits.

**Verdict:** The token is NOT independently committed, but locking provides protection. The claim "token commits immediately" in Phase 2 report was **FALSE**.

---

## 6. INVENTORY SIDE EFFECTS

**File:** `app/Services/Inventory/OrderReservationService.php`

### Commit Method (lines 78-103)

```php
public function commit(Order $order): bool
{
    return $this->run(function () use ($order) {
        $claimed = Order::whereKey($order->id)
            ->where('inventory_state', Order::INVENTORY_STATE_ACTIVE)
            ->lockForUpdate()
            ->first();
        
        if (!$claimed) {
            return false; // not active — never double-commit
        }
        
        $claimed->forceFill(['inventory_state' => Order::INVENTORY_STATE_COMMITTED])->save();
        
        foreach ($this->aggregatePhysicalLines($claimed) as $line) {
            $stock = $this->lockStockRow($line['product_id'], $line['product_variant_id']);
            $stock->stock_quantity = max(0, $physicalQuantity - $line['quantity']);
            $stock->reserved_quantity = max(0, (int) ($stock->reserved_quantity ?? 0) - $line['quantity']);
            $stock->sold_quantity = (int) ($stock->sold_quantity ?? 0) + $line['quantity'];
            $stock->in_stock = ((int) $stock->stock_quantity - (int) $stock->reserved_quantity) > 0;
            $stock->save();
        }
        
        return true;
    });
}
```

### Idempotency Analysis

**State Machine Guard:** `where('inventory_state', Order::INVENTORY_STATE_ACTIVE)`

**Behavior:**
- First call: `ACTIVE` → `COMMITTED` (state transition occurs, inventory deducted)
- Second call: Order not `ACTIVE` → returns false, no inventory change

**Verdict:** ✅ **PASS** - Inventory commit is idempotent due to state machine guard.

**Composition with Payment Transaction:**
- `run()` method (line 141-147) detects existing transaction level
- If called inside `DB::transaction()`, does NOT create nested transaction
- Composes with caller's transaction boundary

**Risk:** If inventory commit THROWS exception after idempotency token is set, the entire transaction rolls back. Token rollback behavior depends on database engine.

---

## 7. COUPON SIDE EFFECTS

**Search Result:** `MarkCouponClaimRedeemed` class not found in initial search.

**Status:** Requires further investigation to verify coupon redemption idempotency.

---

## 8. EVENT DISPATCH

### PaymentSucceeded Event

**File:** `app/Events/PaymentSucceeded.php`

```php
class PaymentSucceeded implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    
    public function __construct(public $order) {}
}
```

**Dispatch Pattern:** `ShouldDispatchAfterCommit` - Laravel 10 defers event until transaction commits.

**Dispatch Location:** `OrderController.php:405-407` (AFTER transaction closure)

```php
}, function () use ($order, &$processed) {
    if ($processed) {
        event(new PaymentSucceeded($order->fresh()));
    }
});
```

**Analysis:**
- Second closure argument to `DB::transaction()` is the "onCommit" callback
- Runs AFTER transaction commits successfully
- Uses `$processed` flag to conditionally dispatch
- Calls `$order->fresh()` to reload from database

**Idempotency:** Event dispatches on EVERY successful callback processing. If idempotency check fails but order already processed, event does NOT fire (because `$processed` remains false).

**Risk:** If multiple legitimate processing attempts occur (retries after transient failures), the event fires multiple times.

---

## 9. PAYMENT IDENTITY MODEL

### Current Implementation

**External Gateway Identity:**
- MyFatoorah uses `InvoiceId` (from createInvoice response)
- Callback receives `PaymentId` parameter (may differ from InvoiceId)
- Verification API returns `InvoiceId` (authoritative)

**Internal Transaction Identity:**
- `transactions.id` (auto-increment primary key)
- `transactions.uuid` (auto-generated UUID, UNIQUE)
- `transactions.gateway_transaction_id` (stores InvoiceId, NOT UNIQUE)
- `transactions.invoice_id` (nullable, used for fallback lookup)
- `transactions.idempotency_key` (Phase 2 addition, locally generated UUID, UNIQUE)

### Identity Mapping Issues

**Issue 1:** `gateway_transaction_id` is NOT UNIQUE

**Consequence:** Same external payment (InvoiceId) could appear in multiple transaction rows.

**Issue 2:** Callback lookup ambiguity

```php
// First attempt: lookup by callback paymentId
$transaction = Transaction::where('gateway_transaction_id', $paymentId)
    ->orWhere('invoice_id', $paymentId)
    ->first();

// If not found, verify with gateway and retry with verified InvoiceId
if (!$transaction) {
    $transaction = Transaction::where('gateway_transaction_id', $verifiedInvoiceId)
        ->orWhere('invoice_id', $verifiedInvoiceId)
        ->first();
}
```

**Risk:** If multiple transactions have same `gateway_transaction_id`, `first()` returns arbitrary row.

**Issue 3:** Idempotency token is LOCAL, not gateway-bound

```php
$idempotencyToken = \Illuminate\Support\Str::uuid()->toString();  // Locally generated
```

**Semantics:** Token represents "I started processing this transaction row", NOT "Gateway payment X was processed".

**Consequence:** Different transaction rows with same `gateway_transaction_id` have different tokens, allowing duplicate processing of same external payment.

---

## 10. FAILURE SCENARIOS

### Scenario 1: Amount Validation Fails After Token Set

```
1. Token set (line 320)
2. Amount validation fails (line 353)
3. Transaction marked 'failed' (line 378-382)
4. Transaction commits with: status=failed, idempotency_key=UUID
5. Second callback with corrected amount
6. Token check (line 308) → BLOCKS retry
7. Payment LOST
```

**Recovery:** NO automated recovery mechanism exists.

### Scenario 2: Business Logic Exception After Token Set

```
1. Token set (line 320)
2. Inventory commit throws exception (line 397)
3. DB::transaction() rolls back
4. Token rollback: DEPENDS ON DATABASE ENGINE
   - InnoDB: Token rolled back ✓
   - MyISAM: Token COMMITTED ✗ (if any table uses MyISAM)
```

**Recovery:** Depends on database engine behavior. No explicit handling.

### Scenario 3: Event Dispatch Failure

```
1. Token set (line 320)
2. All business logic succeeds
3. Transaction commits (line 408)
4. Event dispatch (line 405-407) throws exception
5. HTTP response error to gateway
6. Gateway retries
7. Token check → BLOCKS retry
8. Order state: Inconsistent (locally paid, no notifications sent)
```

**Recovery:** NO automated recovery mechanism.

---

## 11. CONCURRENCY TEST ANALYSIS

**File:** `tests/Feature/PaymentIdempotencyTest.php`

### Test: "concurrent_callbacks_are_serialized_by_pessimistic_locking" (lines 192-245)

```php
for ($i = 0; $i < 5; $i++) {
    $result = DB::transaction(function () use ($transaction) {
        $lockedTransaction = Transaction::where('id', $transaction->id)
            ->lockForUpdate()
            ->first();
        
        if ($lockedTransaction->idempotency_key !== null) {
            return 'idempotent';
        }
        
        $idempotencyToken = \Illuminate\Support\Str::uuid()->toString();
        $lockedTransaction->update(['idempotency_key' => $idempotencyToken]);
        $lockedTransaction->update(['status' => 'paid', 'paid_at' => now()]);
        
        return 'processed';
    });
    
    $results[] = $result;
}
```

**Reality:** This is a SEQUENTIAL for-loop, NOT concurrent execution.

**What It Tests:**
- ✅ Token check works across sequential attempts
- ✅ Once token is set, subsequent attempts are blocked

**What It Does NOT Test:**
- ❌ True concurrent threads/processes
- ❌ Lock contention with simultaneous requests
- ❌ Race conditions during transaction execution window
- ❌ Database connection pool behavior

**Verdict:** Test provides FALSE confidence. Actual concurrency is **UNTESTED**.

---

## 12. SECURITY ANALYSIS

### Webhook Authentication

**Finding:** Payment callback endpoint has NO signature verification.

**Route:** `Route::match(['get', 'post'], 'checkout/callback', ...)`
- NO middleware authentication
- NO HMAC signature check
- PUBLIC endpoint

**Trust Model:** Relies entirely on `gateway->verifyPayment()` API call.

**Risk Assessment:**
- ✅ Gateway API verification provides indirect authentication
- ❌ No protection against replay attacks
- ❌ No protection if gateway API key is compromised
- ❌ Attacker can trigger verification for any paymentId

### Amount/Currency Validation

**Implementation:** Lines 334-389 of `OrderController.php`

**Strengths:**
- ✅ Uses integer cents (1000 factor for 3-decimal currencies)
- ✅ Currency normalized (uppercase, trimmed)
- ✅ Fail-closed on NULL amounts/currency

**Weakness:** Test-gateway bypass (lines 343-344)

```php
$isTestGateway = str_contains(config('services.myfatoorah.base_url', ''), 'apitest');
$isProduction = app()->environment('production');

if ($isTestGateway && !$isProduction) {
    // Skip amount validation
}
```

**Risk:** If production config accidentally points to test gateway URL, amount validation is silently bypassed.

**Better Pattern:** Check `$isProduction` FIRST - never bypass in production regardless of URL.

---

## 13. REMEDIATION REQUIREMENTS

Based on discovery, Phase 2 remediation must address:

### CRITICAL Priority

1. **Fix transaction boundary semantics**
   - Document that token commits when transaction commits, not independently
   - OR implement separate token commit before business logic

2. **Add recovery mechanism**
   - Admin command/endpoint to retry stuck payments
   - Verify gateway status before retry
   - Clear token conditionally

3. **Write true concurrent tests**
   - Use separate processes or database connections
   - Force simultaneous execution
   - Verify lock serialization under real concurrency

4. **Fix token semantics or gateway identity binding**
   - Either: Set token AFTER validation succeeds
   - Or: Bind token to gateway payment identity, not local processing attempt

### HIGH Priority

5. **Add gateway_transaction_id uniqueness constraint**
   - Investigate existing duplicate data
   - Add UNIQUE constraint if business rules allow

6. **Verify coupon redemption idempotency**
   - Locate MarkCouponClaimRedeemed implementation
   - Test duplicate redemption scenarios

7. **Consider webhook signature verification**
   - Determine if MyFatoorah supports HMAC
   - Implement if available

8. **Add gateway identity verification**
   - Assert verifiedInvoiceId matches stored gateway_transaction_id

### MEDIUM Priority

9. **Fix test-gateway bypass logic**
   - Never bypass amount validation in production
   - Check environment first, not URL

10. **Document recovery procedures**
    - Runbook for stuck payments
    - Admin commands for manual intervention

---

## 14. ARCHITECTURAL DECISION REQUIRED

### Option A: Token-Based Idempotency (Current)

**Keep current approach but fix semantics:**
- Set token AFTER amount validation succeeds
- Add recovery mechanism for legitimate retries
- Document that protection relies on pessimistic locking

**Pros:** Minimal changes, leverages existing locking
**Cons:** Token blocks retries after validation failures

### Option B: Gateway Identity Binding

**Bind idempotency to gateway payment identity:**
- Use `gateway_transaction_id` as idempotency key
- Add UNIQUE constraint on `gateway_transaction_id`
- Eliminates locally-generated token

**Pros:** True payment-level idempotency
**Cons:** Requires data migration, assumes no legitimate duplicates

### Option C: Separate Processing Lock Table

**Create dedicated payment_processing_locks table:**
- Composite key: (gateway_transaction_id, status)
- INSERT with ON DUPLICATE KEY IGNORE
- Atomic at database level

**Pros:** Clean separation, explicit lock records
**Cons:** Additional table, more complex

### Recommendation

**Proceed with Option A (Enhanced)** with following changes:

1. Keep pessimistic locking as primary defense
2. Set token AFTER amount validation succeeds (not before)
3. Add explicit recovery mechanism
4. Add gateway identity verification
5. Write true concurrent tests
6. Document actual protection guarantees

**Rationale:** Minimizes changes, preserves existing architecture, fixes semantic issues without requiring data migration.

---

## 15. NEXT STEPS

1. ✅ Complete discovery (this document)
2. ⏭️ Investigate duplicate gateway_transaction_id in production
3. ⏭️ Locate and analyze coupon redemption logic
4. ⏭️ Design recovery mechanism
5. ⏭️ Implement remediation (Option A Enhanced)
6. ⏭️ Write true concurrent tests
7. ⏭️ Perform final verification passes
8. ⏭️ Generate final Phase 2 report

---

## CONCLUSION

The existing Phase 2 implementation provides protection through pessimistic locking, but contains semantic errors and missing recovery mechanisms. The claim "duplicate payment processing is impossible" was overstated.

**Current State:** Pessimistic locking serializes access correctly, but:
- Token does not commit independently (contrary to Phase 2 report)
- No recovery mechanism for legitimate retries
- Concurrency tests are sequential, not concurrent
- Gateway identity not enforced with constraints
- Webhook authentication relies solely on gateway API verification

**Remediation Goal:** Fix semantic issues, add recovery mechanism, verify concurrency with real tests, and document actual protection guarantees.

---

**Status:** Discovery Complete - Proceeding to Phase 2B (Payment Identity Model)
