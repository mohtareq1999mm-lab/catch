# Refund Strategy Plan

## Current State

The codebase has a **partial refund capability** in the Marvel CMS package. Key observations:

### What Exists
| Component | Status | Location |
|-----------|--------|----------|
| `Refund` model | ✅ Complete | `packages/marvel/src/Database/Models/Refund.php` |
| `RefundController` | ✅ Complete | `packages/marvel/src/Http/Controllers/RefundController.php` |
| `RefundStatus` enum | ✅ Complete | `PENDING, APPROVED, REJECTED, PROCESSING, REFUNDED` |
| `RefundRequested` event | ✅ Complete | `packages/marvel/src/Events/RefundRequested.php` |
| `RefundUpdate` event | ✅ Complete | `packages/marvel/src/Events/RefundUpdate.php` |
| `RefundApproved` event | ✅ Exists but NO listener | `packages/marvel/src/Events/RefundApproved.php` |
| `RefundReason` model | ✅ Complete | With CRUD + seeder |
| `RefundPolicy` model | ✅ Complete | With CRUD + seeder |
| GraphQL queries/mutations | ✅ Complete | Refund, RefundPolicy, RefundReason |
| `OrderStatus::REFUNDED` | ✅ Exists | `'order-refunded'` |
| Refund permissions | ✅ Exists | `VIEW_REFUNDS`, `CREATE_REFUND` |
| Notifications | ✅ Exists | `RefundRequested`, `RefundUpdate` |
| Dashboard refund rate | ✅ Calculated | From `refunds` table |

### What's Missing
| Component | Status | Detail |
|-----------|--------|--------|
| **MyFatoorah refund API** | ❌ | `MyfatoraService` has no `refund()` or `makeRefund()` method |
| **Inventory restoration on refund** | ❌ | `RefundApproved` has no listener |
| **Financial reconciliation** | ❌ | Dashboard tracks refund amounts but not by gateway |
| **App-level refund endpoint** | ❌ | Refund endpoints are CMS/GraphQL only |
| **Refund validation** | ⚠️ | No guard against refunding COD orders |
| **Test coverage** | ❌ | No refund integration tests |

---

## Proposed Architecture

### Step 1: Add Refund Enums

```php
// app/Enums/RefundStatus.php
enum RefundStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case PROCESSED = 'processed';
    case FAILED = 'failed';
    case REJECTED = 'rejected';
}

// Add to Marvel OrderStatus enum:
// 'refunded', 'partially_refunded'
```

### Step 2: Create `refunds` Table

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigIncrements | |
| `order_id` | FK → orders | |
| `user_id` | FK → users | Who requested |
| `amount` | decimal(12,2) | Partial or full |
| `reason` | text | |
| `status` | string | RefundStatus enum |
| `gateway_refund_id` | string|null | MyFatoorah refund ID |
| `processed_at` | timestamp|null | |
| `notes` | text|null | Internal notes |
| timestamps + softDeletes | | |

### Step 3: Create Refund Service

```php
class RefundService
{
    public function requestRefund(Order $order, float $amount, string $reason): Refund;
    public function approveRefund(Refund $refund): void;
    public function processRefund(Refund $refund): void; // calls MyFatoorah
    public function rejectRefund(Refund $refund, string $reason): void;

    // MyFatoorah integration
    private function refundViaGateway(Refund $refund): string; // returns gateway_refund_id

    // Inventory restoration
    private function restoreInventory(Refund $refund): void;
}
```

### Step 4: MyFatoorah Refund Integration

```php
// packages/marvel/src/MyFatoorah/MyFatoorahService.php
public function refund(string $transactionId, float $amount, string $reason): array
{
    $response = $this->client->post('v2/MakeRefund', [
        'Key' => $transactionId,
        'KeyType' => 'invoiceId', // or 'paymentId'
        'RefundAmount' => $amount,
        'RefundReason' => $reason,
        'CurrencyIso' => $this->currency,
    ]);

    // {
    //   "IsSuccess": true,
    //   "Data": {
    //     "RefundId": "R12345",
    //     "RefundStatus": "SUCCESS"
    //   }
    // }
}
```

### Step 5: Events & Listeners

| Event | Listener |
|-------|----------|
| `RefundRequested` | `SendRefundNotification` |
| `RefundApproved` | `ProcessRefund` (queued) |
| `RefundProcessed` | `LogRefundActivity`, `UpdateDashboardAnalytics` |
| `RefundFailed` | `NotifyAdmin` |

### Step 6: Endpoints

| Method | URL | Purpose |
|--------|-----|---------|
| POST | `/refunds` | Request refund |
| GET | `/refunds` | List refunds (admin) |
| GET | `/refunds/{id}` | Show refund details |
| PUT | `/refunds/{id}/approve` | Approve (admin) |
| PUT | `/refunds/{id}/reject` | Reject (admin) |
| GET | `/orders/{id}/refunds` | Refunds for specific order |

### Step 7: Dashboard Integration

- Update refund rate calculation to use actual `refunds` table
- Add `refunded_amount` to finance analytics
- Add `refund_count` to order analytics

---

## Security Considerations

- Only customers who **own** the order can request refunds
- Only **admin** with `refund-order` permission can approve/reject
- Refund amount must not exceed order `total_price`
- Cannot refund an order more than once beyond the remaining balance
- Gateway refund must be idempotent (handle MyFatoorah failures gracefully)

---

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Order paid via COD | Refund not applicable (no gateway) |
| Partial refund | Track remaining refundable amount |
| MyFatoorah timeout | Mark refund as `pending`, retry via job |
| Order has multiple transactions | Refund against latest successful transaction |
| Refund after inventory restored | Don't restore inventory twice |
| Currency mismatch | Validate against order amount |

---

## Implementation Order

1. Add refund enums + statuses
2. Create `refunds` migration
3. Create `Refund` model
4. Create `MyFatoorah::refund()` method
5. Create `RefundService` with full logic
6. Create `RefundController` + Form Requests + Resources
7. Add routes
8. Create events + listeners
9. Create tests (success, validation, auth, edge cases)
10. Add dashboard analytics updates
11. Run full test suite

---

## Effort Estimate

- Core logic: ~4 hours
- MyFatoorah integration: ~2 hours
- Tests: ~3 hours
- Dashboard updates: ~1 hour
- **Total: ~10 hours**
