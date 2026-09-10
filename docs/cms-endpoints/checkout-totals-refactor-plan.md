# Checkout Totals Refactor Plan

## Problem Analysis

The checkout system calculates order totals in **two independent paths** during the same request cycle:

### Path A: `OrderService::calcInvoicePrice()`
- Calls `calculateCheckoutTotals()` → returns `['final_total', 'subtotal', 'promotion_discount', 'coupon_discount', 'promotion', 'gift_items']`
- Calls `resolveShippingPrice()` independently
- Computes `finalTotal = final_total + shippingPrice`
- Stores result in `cart.total_price`

### Path B: `OrderService::addItemsInOrder()`
- Calls `getCheckoutTotalsFromCart()` → returns the **same structure** but **recalculated from scratch** using cart items
- Calls `resolveShippingPrice()` independently (same governorate lookup)
- Passes totals to `OrderCreationService::createOrder()`

### Risk

If the cart's items or totals change between `calcInvoicePrice()` (called during checkout preview) and `addItemsInOrder()` (called during order finalization), the two paths could produce **different totals**. This is a potential inconsistency.

### Root Cause

There is no **shared immutable CheckoutTotals DTO** passed from the controller through to order creation. Each service method independently recalculates the same values, violating DRY and introducing a race condition window.

---

## Proposed Solution

### Step 1: Create `CheckoutTotals` DTO

```php
namespace App\DTOs;

class CheckoutTotals
{
    public function __construct(
        public readonly float $subtotal,
        public readonly float $promotionDiscount,
        public readonly float $couponDiscount,
        public readonly float $finalTotal,
        public readonly ?array $promotion,
        public readonly array $giftItems,
        public readonly float $shippingPrice = 0,
        public readonly ?float $freeShippingOver = null,
        public readonly ?float $fastShippingFee = null,
        public readonly ?int $governorateId = null,
    ) {}
}
```

### Step 2: Single Calculation Method

```php
// In OrderService
public function calculateCheckoutTotalsDTO(
    Cart $cart,
    ?int $governorateId,
    ?int $selectedPromotionId,
    ?float $fastShippingFee = null,
): CheckoutTotals;
```

This single method would:
1. Calculate promotion/coupon totals
2. Calculate shipping price (with free shipping threshold)
3. Combine into immutable DTO
4. **Be used by both** `calcInvoicePrice()` and `addItemsInOrder()`

### Step 3: Update OrderCreationService

```php
public function createOrder(
    array $orderData,
    Cart $cart,
    CheckoutTotals $checkoutTotals, // typed DTO instead of array
    // ...
): ?Order;
```

### Step 4: Wire Through Controller

```php
// OrderController::checkout()
$checkoutTotals = $this->orderService->calculateCheckoutTotalsDTO(
    $cart, $governorateId, $promotionId, $fastFee
);

// Both paths use the SAME DTO
$invoicePrice = $this->orderService->calcInvoicePrice($checkoutTotals);
$order = $this->orderService->addItemsInOrder($request, $checkoutTotals);
```

---

## Trade-Offs

| Aspect | Impact |
|--------|--------|
| **Correctness** | Eliminates drift between preview and final totals |
| **Complexity** | Adds one new class (DTO) — low complexity |
| **Performance** | Single calculation instead of two — minor improvement |
| **Testability** | DTO is pure data, trivially testable |
| **Backward Compatibility** | Method signatures change (internal only, no API change) |
| **Effort** | ~2 hours: DTO + refactor services + update tests |

---

## Scalability Impact

- Removes duplicate DB queries (fewer reads under load)
- Immutable DTO prevents accidental mutation
- Single calculation point makes future extensions (e.g., tax, fees) easier

---

## Implementation Order

1. Create `CheckoutTotals` DTO
2. Add `calculateCheckoutTotalsDTO()` to `OrderService`
3. Update `calcInvoicePrice()` to use DTO
4. Update `addItemsInOrder()` to use DTO
5. Update `OrderCreationService::createOrder()` signature
6. Update all callers (OrderController, FastShippingService)
7. Update tests
8. Run full test suite
