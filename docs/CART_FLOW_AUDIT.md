# Cart & Checkout Flow Audit

**Date**: 2026-08-31  
**Branch**: main  
**Commit**: e1d355f

---

## Executive Summary

This document traces the complete cart and checkout lifecycle from source code, not documentation. It covers:

- Cart creation and item management
- Inventory reservation (cart-level and order-level)
- Checkout flow
- Payment processing (online, COD, pay-at-cashier)
- Inventory finalization
- Cart cleanup and expiration

**Key Architecture Decision** (2026-08-26, commit 37ce4a8):
- **Inventory ownership transferred from Cart to Order**
- Cart-level reservations are ephemeral (3-day TTL)
- Order-level reservations are authoritative (24-hour TTL)
- Cart expiration no longer manages inventory (Order owns it)

---

## 1. Architecture Overview

### 1.1 Data Models

#### Cart (packages/marvel/src/Database/Models/Cart.php)

```php
carts table:
- id: bigint (PK)
- user_id: bigint (FK→users, UNIQUE)  // One cart per user
- coupon: string (nullable)            // Coupon CODE, not FK
- total_price: decimal(10,2)
- status: enum('active','expired','checked_out')
- reserved_at: timestamp (nullable)
- expires_at: timestamp (nullable)     // Reservation TTL (3 days)
- created_at, updated_at
```

**Key constraints**:
- `UNIQUE(user_id)` - one cart per user
- `INDEX(user_id, status)` - find active carts
- `INDEX(status, expires_at)` - find expired carts

#### CartItem (packages/marvel/src/Database/Models/CartItem.php)

```php
cart_items table:
- id: bigint (PK)
- cart_id: bigint (FK→carts, CASCADE ON DELETE)
- product_id: bigint (FK→products, NULL ON DELETE)
- quantity: int
- product_variant_id: bigint (nullable, FK→product_variants)
- price: decimal(10,2)
- total_price: decimal(10,2)           // price * quantity
- attributes: json (nullable)
- reserved_quantity: int               // Currently reserved
- discount_amount: decimal(10,2)
- shipping_method: string(20)          // 'SCHEDULED' or 'FAST'
- is_gift: boolean
- promotion_id: bigint (nullable, FK→promotions)
```

**Key constraints**:
- `cart_items.cart_id` CASCADE ON DELETE - deleting cart deletes items
- `INDEX(cart_id, product_id, product_variant_id)` - find matching items
- `INDEX(cart_id, is_gift)` - separate gift items

#### Order (packages/marvel/src/Database/Models/Order.php)

```php
orders table (inventory-relevant fields):
- id: bigint (PK)
- user_id: bigint (FK→users)
- status: enum('pending','processing','completed','cancelled',...)
- inventory_state: enum('none','active','released','committed')  // NEW (2026-08-26)
- inventory_reserved_at: timestamp (nullable)                    // NEW
- reservation_expires_at: timestamp (nullable)                   // NEW (24h TTL)
- payment_status: enum('pending','paid','failed',...)
- fulfillment_status: enum(...)
- ... (other order fields)
```

**Inventory state machine** (Order-owned, 2026-08-26):
```
none    → active      (checkout, reserves inventory)
active  → committed   (payment success, finalizes inventory)
active  → released    (payment failure/timeout, releases inventory)
```

#### OrderProduct (order line items)

```php
order_products table:
- id: bigint (PK)
- order_id: bigint (FK→orders)
- product_id: bigint
- product_variant_id: bigint (nullable)
- quantity: int                        // Reservation source
- price: decimal
- item_type: enum('physical','digital') // NEW (2026-08-23)
- ... (other fields)
```

**These rows ARE the reservation** - they track exactly what's reserved for the order.

### 1.2 Inventory Tracking

#### Product / ProductVariant

```php
products / product_variants tables:
- stock_quantity: int          // Physical inventory
- reserved_quantity: int       // Total reserved (cart + order)
- sold_quantity: int          // Historical sales
- in_stock: boolean           // Computed: (stock_quantity - reserved_quantity) > 0
```

**Available stock calculation**:
```php
available = max(0, stock_quantity - reserved_quantity)
```

**Inventory operations**:
- **Reserve**: `reserved_quantity += qty`
- **Release**: `reserved_quantity -= qty`
- **Commit**: `stock_quantity -= qty`, `reserved_quantity -= qty`, `sold_quantity += qty`

---

## 2. Cart Lifecycle

### 2.1 Cart States

```
┌─────────┐
│ No Cart │
└────┬────┘
     │ add item
     v
┌─────────────┐
│   active    │ ←──┐
│  (reserved) │    │ update/add item
└──┬──┬───┬──┘    │
   │  │   │       │
   │  │   └───────┘
   │  │
   │  │ expire (3-day TTL)
   │  v
   │ ┌──────────┐
   │ │ expired  │
   │ └──────────┘
   │
   │ checkout → payment success
   v
┌──────────────┐
│ checked_out  │ (tombstone)
└──────────────┘
```

**Important**: Cart records are NEVER deleted, only transitioned between states.

### 2.2 Cart Creation

**Trigger**: `POST /api/v1/cart`

**Flow**:
```
CartCreateRequest
  → CartController::store()
    → CartRepository::storeCart()
      → persistCart($request, 'add')
        → Lock existing cart OR create new
        → syncItems()
          → CartInventoryService::reserveItem()
        → Update cart.total_price
```

**Code** (app/Repositories/CartRepository.php:93):
```php
$cart = Cart::query()
    ->where('user_id', $userId)
    ->lockForUpdate()
    ->first();

if (!$cart) {
    $cart = Cart::create([
        'user_id' => $userId,
        'status' => 'active',
    ]);
}
```

**Inventory Behavior** (app/Services/General/CartInventoryService.php):
```php
// reserveItem() with mode='add'
$desiredQuantity = ($existingItem->quantity ?? 0) + $quantity;
$stock = $this->lockStockRow($productId, $variantId);
$available = max(0, $stock->stock_quantity - $stock->reserved_quantity);

if ($available < $delta) {
    throw InsufficientStockException;
}

$stock->reserved_quantity += $delta;
$cart->expires_at = now()->addDays(3); // CART_TTL_DAYS
```

**Source Files**:
- Controller: `app/Http/Controllers/Api/General/CartController.php:44`
- Repository: `app/Repositories/CartRepository.php:85-150`
- Service: `app/Services/General/CartInventoryService.php:35-110`

---

## 3. Cart Item Management

### 3.1 Add Item

**Endpoint**: `POST /api/v1/cart`

**Request**:
```json
{
  "item": {
    "product_id": 1,
    "quantity": 2,
    "product_variant_id": 5,
    "shipping_method": "SCHEDULED",
    "attributes": {...}
  }
}
```

**Validation** (app/Http/Requests/CartCreateRequest.php):
- `product_id`: required, exists in products
- `quantity`: required, integer, min:1
- `product_variant_id`: nullable, exists in product_variants
- `shipping_method`: required, in:SCHEDULED,FAST

**Behavior**:
- If item exists: `quantity += new_quantity` (additive)
- If product has variants but no variant_id provided: ERROR
- If FAST shipping but product not eligible: ERROR
- Locks product/variant row during reservation
- Updates `cart_items.reserved_quantity`
- Sets `cart.expires_at = now() + 3 days`

**Source**: `app/Services/General/CartInventoryService.php:35-110`

### 3.2 Update Item (Set Quantity)

**Endpoint**: `PUT /api/v1/cart`

**Behavior**:
- Sets quantity to EXACT value (not additive)
- If `shipping_method` not provided: preserves existing
- If quantity < 1: throws QUANTITY_MINIMUM error
- Delta reservation: `reserve += (new - old)`

**Mode difference**:
```php
// mode='add': desiredQuantity = existing + new  (additive)
// mode='set': desiredQuantity = new            (exact)
```

**Source**: `app/Repositories/CartRepository.php:152-200`

### 3.3 Remove Item

**Endpoint**: `DELETE /api/v1/cart/item/{itemId}`

**Flow**:
```
CartController::deleteItemFromCart()
  → Find user's cart (NO lockForUpdate!)
  → Find item in cart
  → CartInventoryService::releaseItem($item, deleteItem=true)
    → Lock item + stock
    → stock.reserved_quantity -= item.reserved_quantity
    → Delete CartItem
    → If no items remain: cart.coupon = null
```

**Bug** (CART-2): No `lockForUpdate` on cart - potential race condition.

**Source**: `app/Http/Controllers/Api/General/CartController.php:96-115`

### 3.4 Clear Cart

**Endpoint**: `DELETE /api/v1/cart`

**Flow**:
```
CartController::destroy()
  → Find cart (NO lockForUpdate!)
  → If coupon exists + no confirm: return warning
  → CartInventoryService::releaseCart($cart, deleteItems=true)
    → Lock cart + items
    → For each item: releaseItem()
    → cart.status = 'active'
    → cart.total_price = 0
    → cart.expires_at = null
```

**Important**: Cart record PERSISTS (not deleted), just emptied.

**Source**: `app/Http/Controllers/Api/General/CartController.php:119-135`

---

## 4. Inventory Reservation

### 4.1 Cart-Level Reservation (Legacy, Still Active)

**Purpose**: Temporary holds during shopping (3-day TTL)

**Mechanism** (app/Services/General/CartInventoryService.php):
```php
// Reserve
$stock->reserved_quantity += $quantity;
$cart->reserved_at = now();
$cart->expires_at = now()->addDays(3);

// Release
$stock->reserved_quantity -= $quantity;
$cart->expires_at = null;
$cart->reserved_at = null;
```

**TTL**: 3 days (CART_TTL_DAYS constant)

**Expiration Command**: `cart:expire` (but see note below)

**Source**: `app/Services/General/CartInventoryService.php:35-310`

### 4.2 Order-Level Reservation (NEW, Authoritative)

**Added**: 2026-08-26 (commit 37ce4a8)

**Purpose**: Authoritative reservation during payment (24-hour TTL)

**Mechanism** (app/Services/Inventory/OrderReservationService.php):
```php
// State machine (idempotent, row-locked)
none -> active:      reserveForOrder()   // Checkout
active -> committed: commit()            // Payment success
active -> released:  release()           // Payment failure/timeout
```

**Implementation**:
```php
public function reserveForOrder(Order $order): void
{
    $order = Order::whereKey($order->id)
        ->where('inventory_state', Order::INVENTORY_STATE_NONE)
        ->lockForUpdate()
        ->first();
    
    if (!$order) return; // Already reserved
    
    foreach ($physicalLines as $line) {
        $stock = $this->lockStockRow($line['product_id'], $line['variant_id']);
        $available = $stock->stock_quantity - $stock->reserved_quantity;
        
        if ($available < $line['quantity']) {
            throw InsufficientStockException;
        }
        
        $stock->reserved_quantity += $line['quantity'];
        $stock->save();
    }
    
    $order->inventory_state = Order::INVENTORY_STATE_ACTIVE;
    $order->inventory_reserved_at = now();
    $order->reservation_expires_at = now()->addHours(24);
    $order->save();
}
```

**Key features**:
- **Idempotent**: Re-reserving an `active` order is a no-op
- **Locked**: Row-level locks prevent race conditions
- **Digital exclusion**: Digital products (rule D1) never touch inventory
- **Deterministic order**: Locks acquired in sorted order to prevent deadlocks

**TTL**: 24 hours (ORDER_TIMEOUT_HOURS)

**Reaper**: `orders:cancel-unpaid` command releases expired reservations

**Source**: `app/Services/Inventory/OrderReservationService.php:37-72`

### 4.3 Reservation Ownership (Critical Change)

**Before 2026-08-26**:
- Cart owned inventory reservation
- Checkout transferred cart items to order
- Cart expiration released inventory

**After 2026-08-26** (commit 37ce4a8):
- **Order owns inventory reservation**
- Cart reservation is temporary shopping hold (3 days)
- Order reservation is payment-window hold (24 hours)
- Cart expiration NO LONGER releases order inventory
- Order expiration (`orders:cancel-unpaid`) releases order inventory

**Architecture rationale** (from commit message):
> "order-owned reservation lifecycle with 24h unpaid-order reaper"
> "CancelUnpaidOrders rewritten: race-safe reaper with defensive gateway pre-check; releases only its own reservation"
> "CartInventoryService refactored to slice-clearing (clearCheckedOutSlice); cart-expiry commands retired"

**Impact**:
- Cart can expire (status='expired') but order inventory stays reserved
- Order reservation expires independently (24h from checkout)
- Unpaid order cancellation is the ONLY way to release order inventory

**Source**: 
- Migration: `database/migrations/2026_08_26_100001_add_inventory_reservation_state_to_orders.php`
- Service: `app/Services/Inventory/OrderReservationService.php`
- Command: `app/Console/Commands/CancelUnpaidOrders.php`

---

## 5. Checkout Flow

### 5.1 Endpoint

**Request**: `POST /api/v1/checkout`

**Controller**: `app/Http/Controllers/Api/General/OrderController.php:71`

### 5.2 Complete Flow

```
1. Validation (OrderCreateRequest)
   ↓
2. Get active cart + ensure reservation
   ↓
3. Payment method validation
   ↓
4. DB Transaction BEGIN
   ↓
5. OrderService::addItemsInOrder()
   - Lock cart (status='active')
   - Load SCHEDULED items only
   - Refresh prices
   - Validate coupon
   - Apply promotion
   - Create/update Order
   - Create OrderProducts
   - OrderReservationService::reserveForOrder()  // NEW
   - Dispatch OrderCreated event
   ↓
6. DB Transaction COMMIT
   ↓
7. Payment handling:
   - online: redirect to gateway
   - cod: create pending transaction
   - pay_at_cashier: generate QR code
   ↓
8. Return order + payment URL/QR
```

### 5.3 OrderService::addItemsInOrder() Details

**Source**: `app/Services/General/OrderService.php:148-280`

```php
DB::transaction(function () {
    // 1. Lock cart
    $cart = Cart::where('user_id', auth()->id())
        ->where('status', 'active')
        ->lockForUpdate()
        ->with([
            'items' => fn($q) => $q->where('shipping_method', ShippingMethod::SCHEDULED),
            'items.product.flash_sales' => fn($q) => $q->valid(),
        ])
        ->first();
    
    if (!$cart || $cart->items->isEmpty()) {
        throw new CartEmptyException;
    }
    
    // 2. Refresh cart item prices (flash sales, promotions)
    $this->refreshCartItemPrices($cart);
    
    // 3. Validate coupon
    if ($cart->coupon) {
        $coupon = Coupon::where('code', $cart->coupon)->first();
        if (!$coupon || !CouponService::isValid($coupon)) {
            $cart->update(['coupon' => null]);
        }
    }
    
    // 4. Calculate totals + apply promotion
    $totals = $this->calculateCheckoutTotals($cart, $request);
    
    // 5. Find/create order
    $order = Order::where('user_id', $user->id)
        ->where('status', 'pending')
        ->lockForUpdate()
        ->first();
    
    if ($order) {
        $this->updateOrder($order, $request, $totals);
    } else {
        $order = $this->createOrder($request, $totals);
    }
    
    // 6. Sync order products
    $this->syncOrderProducts($order, $cart->items, $totals);
    
    // 7. Reserve inventory FOR THE ORDER (NEW)
    $this->orderReservationService->reserveForOrder($order);
    
    // 8. Dispatch event
    event(new OrderCreated($order));
    
    return $order;
});
```

**Key points**:
- Cart status remains 'active' (NOT modified during checkout)
- Cart items remain (NOT deleted during checkout)
- Only SCHEDULED items are processed (FAST items ignored)
- Order reservation is ATOMIC with order creation
- If reservation fails, entire transaction rolls back

### 5.4 What Happens to Cart During Checkout

**Cart state AFTER checkout**:
```php
cart.status = 'active'              // Unchanged
cart.items = [...]                  // Still present
cart.reserved_at = <timestamp>      // Cart reservation still active
cart.expires_at = <3 days>         // Cart TTL still counting
```

**Cart is NOT modified**. It stays active with items intact until payment callback.

### 5.5 Pending Order Handling

**If user already has a pending order**:
- Existing order is UPDATED (not new order created)
- Order products are SYNCED (deleted/recreated)
- Inventory reservation is RE-RESERVED (idempotent)

**Race protection**: `lockForUpdate()` prevents duplicate orders.

**Source**: `app/Services/General/OrderService.php:219-245`

---

## 6. Payment Processing

### 6.1 Payment Methods

| Method | Initial State | Cart Behavior | Inventory Finalization |
|--------|--------------|---------------|----------------------|
| online | pending | Stays active | On payment callback |
| cod | pending | Stays active | On admin mark-as-paid |
| pay_at_cashier | pending | Stays active | On admin confirm payment |

### 6.2 Online Payment Flow

**Step 1: Redirect to Gateway**

```
OrderController::checkout()
  → PaymentCheckoutHandler::handleOnlinePayment()
    → Gateway::initiatePayment()
    → Return payment URL
```

**Step 2: User Pays at Gateway**

(External - MyFatoorah/Stripe/etc.)

**Step 3: Gateway Callback**

```
POST /api/v1/callback/{gateway}

OrderController::checkoutCallback()
  → Lock transaction + order
  → Gateway::verifyPayment()
  → If success:
      → Update transaction.status = 'paid'
      → OrderReservationService::commit(order)
          → order.inventory_state = 'committed'
          → stock.stock_quantity -= qty
          → stock.reserved_quantity -= qty
          → stock.sold_quantity += qty
      → CartInventoryService::clearCheckedOutSlice()
          → Delete cart items for this shipping method
          → If no items remain: cart.status = 'checked_out'
      → OrderService::changeOrderStatus('completed')
      → Record coupon usage
      → Increment promotion usage
      → Dispatch PaymentSucceeded event
  → If failure:
      → Update transaction.status = 'failed'
      → Dispatch PaymentFailed event
      → Cart UNTOUCHED
```

**Source**: `app/Http/Controllers/Api/General/OrderController.php:189-350`

**Important**: Inventory commit happens ATOMICALLY with payment success.

### 6.3 COD Payment Flow

**Step 1: Create Pending Order**

```
OrderController::checkout()
  → PaymentCheckoutHandler::handleCodPayment()
    → Create transaction (status='pending', method='cod')
    → Return success
```

**Cart state**: Active, items reserved, awaiting fulfillment

**Step 2: Admin Marks as Paid**

```
POST /api/v1/orders/{id}/cod-paid

OrderController::markCodAsPaid()
  → Lock transaction + order
  → transaction.status = 'paid'
  → order.status = 'completed'
  → Record coupon usage
  → OrderReservationService::commit(order)        // Finalize inventory
  → CartInventoryService::clearCheckedOutSlice()  // Delete cart items
  → Dispatch PaymentSucceeded event
```

**Source**: `app/Http/Controllers/Api/General/OrderController.php:442-480`

### 6.4 Pay at Cashier Flow

**Step 1: Generate QR Code**

```
OrderController::checkout()
  → PaymentCheckoutHandler::handleCashierQrPayment()
    → Generate QR with order_id
    → Create transaction (status='pending', method='pay_at_cashier')
    → Return QR image
```

**Step 2: Cashier Scans + Confirms**

```
POST /api/v1/orders/{id}/cashier-paid

OrderController::markCashierPaid()
  → Lock transaction + order
  → transaction.status = 'paid'
  → order.status = 'completed'
  → Record coupon usage
  → OrderReservationService::commit(order)        // Finalize inventory
  → CartInventoryService::clearCheckedOutSlice()  // Delete cart items
  → Dispatch PaymentSucceeded event
```

**Source**: `app/Http/Controllers/Api/General/OrderController.php:520-558`

---

## 7. Inventory Finalization

### 7.1 Commit Process (Payment Success)

**Triggered by**:
- Online payment callback (success)
- COD marked as paid
- Cashier confirms payment

**Implementation** (app/Services/Inventory/OrderReservationService.php:78-103):

```php
public function commit(Order $order): bool
{
    return $this->run(function () use ($order) {
        // Claim: only 'active' orders can commit
        $claimed = Order::whereKey($order->id)
            ->where('inventory_state', Order::INVENTORY_STATE_ACTIVE)
            ->lockForUpdate()
            ->first();
        
        if (!$claimed) {
            return false; // Not active (already committed/released)
        }
        
        // Mark committed
        $claimed->inventory_state = Order::INVENTORY_STATE_COMMITTED;
        $claimed->save();
        
        // Finalize inventory for each physical line
        foreach ($physicalLines as $line) {
            $stock = $this->lockStockRow($line['product_id'], $line['variant_id']);
            
            $stock->stock_quantity = max(0, $stock->stock_quantity - $line['quantity']);
            $stock->reserved_quantity = max(0, $stock->reserved_quantity - $line['quantity']);
            $stock->sold_quantity += $line['quantity'];
            $stock->in_stock = ($stock->stock_quantity - $stock->reserved_quantity) > 0;
            $stock->save();
        }
        
        return true;
    });
}
```

**Idempotency**: Committing an already-committed order is a no-op.

**Digital exclusion**: Lines with `item_type='digital'` are skipped (rule D1).

### 7.2 Cart Cleanup (clearCheckedOutSlice)

**After inventory commit**, cart items for that shipping method are deleted:

```php
CartInventoryService::clearCheckedOutSlice($cart, $shippingMethod)
{
    DB::transaction(function () use ($cart, $shippingMethod) {
        $cart = Cart::whereKey($cart->id)->lockForUpdate()->first();
        
        // Delete items for this shipping method
        $cart->items()->where('shipping_method', $shippingMethod)->delete();
        
        $remaining = $cart->items()->count();
        
        if ($remaining === 0) {
            // All items processed
            $cart->update([
                'status' => 'checked_out',
                'total_price' => 0,
                'expires_at' => null,
                'reserved_at' => null,
            ]);
        } else {
            // Some items remain (e.g., FAST shipping not yet processed)
            $cart->update([
                'total_price' => $cart->items()->sum('total_price'),
            ]);
        }
    });
}
```

**Split-shipping**: If cart has SCHEDULED + FAST items, only SCHEDULED items are deleted after SCHEDULED payment. FAST items remain for their own payment flow.

**Source**: `app/Services/General/CartInventoryService.php:311-350`

---

## 8. Payment Failure Handling

### 8.1 Immediate Failure

**Callback**: `OrderController::checkoutCallback()` with verification failure

**Actions**:
```php
DB::transaction(function () {
    $transaction->update(['status' => 'failed']);
    event(new PaymentFailed($order));
});
```

**Cart state**: **UNTOUCHED**
- status = 'active'
- items remain
- reservation remains

**Order state**: **UNTOUCHED**
- status = 'pending'
- inventory_state = 'active' (reservation still held)

**User can**: Retry checkout immediately

**Source**: `app/Http/Controllers/Api/General/OrderController.php:315-335`

### 8.2 Payment Cancellation

**Callback**: `OrderController::checkoutErrorCallback()`

**Same behavior as immediate failure** - cart and order untouched.

**Source**: `app/Http/Controllers/Api/General/OrderController.php:390-420`

### 8.3 Payment Timeout (24-Hour Expiry)

**Command**: `orders:cancel-unpaid` (scheduled hourly)

**Process**:
```php
CancelUnpaidOrders::handle()
{
    $orders = Order::query()
        ->where('status', 'pending')
        ->where('inventory_state', Order::INVENTORY_STATE_ACTIVE)
        ->whereNotNull('reservation_expires_at')
        ->where('reservation_expires_at', '<=', now())
        ->cursor();
    
    foreach ($orders as $order) {
        DB::transaction(function () use ($order) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->first();
            
            // Re-check conditions (race protection)
            if ($lockedOrder->status !== 'pending' 
                || $lockedOrder->inventory_state !== Order::INVENTORY_STATE_ACTIVE
                || $lockedOrder->reservation_expires_at->isFuture()) {
                return;
            }
            
            // Defensive gateway check
            if ($this->gatewayReportsPaid($lockedOrder)) {
                return; // Customer paid but callback delayed
            }
            
            // Release inventory
            $this->orderReservationService->release($lockedOrder);
            
            // Cancel order
            $lockedOrder->update([
                'status' => 'cancelled',
                'payment_status' => Order::PAYMENT_STATUS_FAILED,
                'fulfillment_status' => Order::FULFILLMENT_STATUS_CANCELLED,
            ]);
            
            // Fail pending transactions
            $lockedOrder->transactions()
                ->where('status', 'pending')
                ->update(['status' => 'failed']);
            
            // Events
            event(new OrderStatusChanged($lockedOrder));
            event(new OrderCancelled($lockedOrder));
            event(new PaymentFailed($lockedOrder));
        });
    }
}
```

**Key features**:
1. **Defensive gateway check**: Queries gateway to ensure payment truly failed
2. **Race protection**: Re-checks conditions after acquiring lock
3. **Inventory release**: `OrderReservationService::release()` handles it
4. **Cart NOT touched**: Cart expiration is independent

**Source**: `app/Console/Commands/CancelUnpaidOrders.php:39-127`

**Scheduled**: Hourly (see `app/Console/Kernel.php`)

---

## 9. Coupons and Promotions

### 9.1 Coupon Storage

**Cart model**:
```php
$cart->coupon = 'SUMMER2024';  // Plain string, NOT FK
```

**No referential integrity** - if coupon deleted from DB, cart still holds the code.

### 9.2 Coupon Validation

**Happens at**:
1. Checkout time (OrderService::addItemsInOrder)
2. Cart resource serialization (CartResource::toArray)

**Validation** (app/Services/General/OrderService.php:168-179):
```php
if ($cart->coupon) {
    $coupon = Coupon::where('code', $cart->coupon)->first();
    
    if (!$coupon) {
        $cart->update(['coupon' => null]);
        // ... log error
    }
    
    if (!CouponService::isValid($coupon, $user, $cart)) {
        $cart->update(['coupon' => null]);
        // ... log error
    }
}
```

**Bug** (CART-4): Stale in-memory `$cart->coupon` after clearing - already documented.

### 9.3 Coupon Lifecycle

| Event | Coupon Behavior |
|-------|----------------|
| Cart created | Not set |
| Coupon applied | `cart.coupon = 'CODE'` |
| Cart item added/updated/removed | **Cleared** if last item removed |
| Checkout | Validated, cleared if invalid |
| Payment success | Recorded in order, usage incremented |
| Payment failure | Remains on cart |
| Cart expired | Remains on cart (unless all items deleted) |

### 9.4 Promotion Application

**Stored on**: cart_items (not cart)
```php
cart_items.promotion_id = 1
cart_items.discount_amount = 10.50
```

**Applied at**: Checkout (OrderService::calculateCheckoutTotals)

**Cleared on**: Any cart mutation (add/update/remove triggers revalidatePromotion)

**Promotion flow**:
```
User selects promotion
  ↓
POST /api/v1/checkout with selected_promotion_id
  ↓
PromotionService::applySelectedPromotion()
  ↓
PromotionApplicator::applyOutcome()
  ↓
Updates cart_items with promotion_id + discount_amount
```

**Ephemeral**: Promotions are recalculated on every cart mutation. They only persist between promotion selection and cart change.

**Source**: 
- Service: `app/Services/General/PromotionService.php:90-180`
- Applicator: `app/Services/PromotionEngine/PromotionApplicator.php:20-100`

---

## 10. Cart Expiration

### 10.1 Expiration Command (Legacy)

**Command**: `cart:expire`

**Implementation** (app/Services/General/CartInventoryService.php:235-272):
```php
public function expireCarts(): void
{
    $carts = Cart::query()
        ->where('status', 'active')
        ->whereNotNull('expires_at')
        ->where('expires_at', '<=', now())
        ->chunk(100, function ($carts) {
            foreach ($carts as $cart) {
                $this->expireCart($cart);
            }
        });
}

private function expireCart(Cart $cart): void
{
    DB::transaction(function () use ($cart) {
        $cart = Cart::whereKey($cart->id)->lockForUpdate()->first();
        
        if (!$cart || $cart->expires_at > now()) {
            return; // Already processed or not yet expired
        }
        
        // Release cart-level reservation
        foreach ($cart->items as $item) {
            $this->releaseStock($item);
        }
        
        // Delete all cart items
        $cart->items()->delete();
        
        // Mark expired
        $cart->update([
            'status' => 'expired',
            'total_price' => 0,
            'expires_at' => null,
            'reserved_at' => null,
        ]);
    });
}
```

**TTL**: 3 days from last reservation

**CRITICAL NOTE** (post 2026-08-26):
- This command ONLY releases cart-level reservations
- It does NOT touch order-level reservations
- If cart has expired but order is pending, order inventory stays reserved
- Order inventory is released by `orders:cancel-unpaid` (independent 24h TTL)

### 10.2 Scheduled Execution

**Schedule** (app/Console/Kernel.php):
```php
$schedule->command('cart:expire')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();
```

**Status**: ACTIVE (still scheduled)

### 10.3 Order Expiration (Authoritative)

**Command**: `orders:cancel-unpaid`

**See section 8.3** for full details.

**Key difference**:
- Cart expiration: releases CART reservation
- Order expiration: releases ORDER reservation (authoritative)

---

## 11. Transaction Boundaries

### 11.1 Critical Transactions

#### Checkout (OrderService::addItemsInOrder)

```php
DB::beginTransaction();
try {
    $cart = Cart::lockForUpdate()->first();
    $order = $this->createOrder(...);
    $this->syncOrderProducts(...);
    $this->orderReservationService->reserveForOrder($order);
    event(new OrderCreated($order));
    DB::commit();
} catch (\Throwable $e) {
    DB::rollBack();
    throw $e;
}
```

**Scope**: Cart read, order creation, order products, inventory reservation, event dispatch

**Rollback triggers**: 
- Insufficient stock
- Invalid cart state
- Database constraint violation

#### Payment Callback (OrderController::checkoutCallback)

```php
DB::transaction(function () {
    $transaction = Transaction::lockForUpdate()->first();
    $order = Order::lockForUpdate()->first();
    
    if ($paymentVerified) {
        $transaction->update(['status' => 'paid']);
        $this->orderReservationService->commit($order);
        $this->cartInventoryService->clearCheckedOutSlice($cart, $shippingMethod);
        $this->orderService->changeOrderStatus($order, 'completed');
        $this->recordCouponUsage($order);
        $this->incrementPromotionUsage($order);
    } else {
        $transaction->update(['status' => 'failed']);
    }
});
```

**Scope**: Transaction update, inventory commit, cart cleanup, order status, coupon/promotion tracking

**Atomic**: Payment success with inventory finalization - either both succeed or both fail

#### Cart Operations

**Add/Update Item**:
```php
DB::transaction(function () {
    $cart = Cart::lockForUpdate()->first();
    $item = CartItem::lockForUpdate()->first();
    $stock = Product/Variant::lockForUpdate()->first();
    $stock->reserved_quantity += $delta;
    $cart->total_price = ...;
});
```

**Remove Item**:
```php
DB::transaction(function () {
    $item = CartItem::lockForUpdate()->first();
    $stock = Product/Variant::lockForUpdate()->first();
    $stock->reserved_quantity -= $item->reserved_quantity;
    $item->delete();
});
```

### 11.2 Event Dispatching

**Post-commit dispatch** (Laravel 10.30 behavior):

```php
class OrderCreated implements ShouldQueue, ShouldDispatchAfterCommit {}
class PaymentSucceeded implements ShouldQueue, ShouldDispatchAfterCommit {}
```

**Fix** (commit c49ea84): Events implement `ShouldDispatchAfterCommit` at the EVENT level, not just listener level.

**Why important**: If transaction rolls back, event never fires. If event fires before commit, listeners see uncommitted data.

---

## 12. Database Relationships

### 12.1 Entity Relationship Diagram

```
users (1) ──────┬──────> carts (1)
                │
                │
                └──────> orders (*)

carts (1) ──────────────> cart_items (*)
                               │
                               ├──────> products (*)
                               └──────> product_variants (*)

orders (1) ─────────────> order_products (*)
                               │
                               ├──────> products (*)
                               └──────> product_variants (*)

products (1) ────────────> product_variants (*)
              └──────────> stock counters (reserved_quantity, stock_quantity)

carts (*) ───────────────> coupons (*) [via code string, NO FK]
cart_items (*) ──────────> promotions (*) [via promotion_id FK]
```

### 12.2 Cascade Behavior

**Deleting cart**:
- `cart_items` CASCADE DELETE (all items deleted)

**Deleting cart item**:
- No cascade (standalone delete)

**Deleting product**:
- `cart_items.product_id` SET NULL (item stays, product nullified)
- `order_products.product_id` (no constraint - historical record)

**Deleting user**:
- `carts.user_id` SET NULL (cart stays, user nullified)
- `orders.user_id` (no cascade - historical record)

---

## 13. Concurrency and Race Conditions

### 13.1 Lock Ordering

**OrderReservationService** uses deterministic lock ordering:
```php
$lines = $this->aggregatePhysicalLines($order)
    ->sortBy(function ($line) {
        return [$line['product_id'], $line['product_variant_id']];
    });
```

**Prevents deadlocks** when multiple orders reserve same products concurrently.

### 13.2 Idempotent Operations

**Order reservation**:
```php
if ($order->inventory_state !== Order::INVENTORY_STATE_NONE) {
    return; // Already reserved
}
```

**Order commit**:
```php
$claimed = Order::where('inventory_state', Order::INVENTORY_STATE_ACTIVE)
    ->lockForUpdate()
    ->first();

if (!$claimed) {
    return false; // Not active (already committed/released)
}
```

**Safe to retry**: Payment callbacks can be called multiple times without double-committing inventory.

### 13.3 Known Race Conditions

**CART-2**: `deleteItemFromCart()` uses `$user->cart` without `lockForUpdate()`
**CART-3**: `destroy()` uses `auth()->user()->cart` without `lockForUpdate()`

**Impact**: Concurrent deletes could race, leading to:
- Double-release of inventory
- Inconsistent total_price
- Orphaned items

**Mitigation**: Low likelihood in practice (single user rarely deletes items concurrently from multiple devices)

---

## 14. Testing

### 14.1 Existing Test Suites

**Cart tests**:
- `tests/Feature/CartApiTest.php` - CRUD operations
- `tests/Feature/CartExpirationTest.php` - TTL behavior

**Checkout tests**:
- `tests/Feature/CheckoutRegressionTest.php` - Happy path
- `tests/Feature/CheckoutConcurrencyStressTest.php` - Race conditions
- `tests/Feature/CheckoutPendingOrderRedesignTest.php` - Pending order reuse

**Inventory tests**:
- `tests/Feature/Inventory/OrderReservationLifecycleTest.php` - State machine
- `tests/Feature/Inventory/GiftAndReconciliationTest.php` - Promotion gifts

**Payment tests**:
- `tests/Feature/PaymentCallbackStressTest.php` - Duplicate callbacks
- `tests/Feature/PaymentCheckoutTest.php` - Payment methods
- `tests/Feature/PaymentSystemTest.php` - Gateway integration

### 14.2 Coverage Gaps

**Missing tests**:
1. Cart expiration when order is pending (ownership split)
2. Race condition: cart delete during checkout
3. Race condition: payment callback during cart expiration
4. Split-shipping: SCHEDULED paid, FAST pending
5. Coupon deleted after applied to cart
6. Promotion deleted after applied to cart items

---

## 15. Known Issues and Bugs

| ID | Severity | File | Description |
|----|----------|------|-------------|
| CART-1 | LOW | CartRepository:87 | Cart lookup finds ANY status, reuses checked_out/expired cart |
| CART-2 | LOW | CartController:96 | deleteItemFromCart uses $user->cart without lockForUpdate |
| CART-3 | LOW | CartController:120 | destroy uses auth()->user()->cart without lockForUpdate |
| CART-4 | LOW | OrderService:168 | Stale $cart->coupon in-memory after clearing |
| CART-5 | LOW | CartInventoryService:211 | finalizeCart does not clear coupon |
| CART-6 | LOW | CartInventoryService:273 | expireCart does not clear coupon |
| CART-7 | INFO | Various | No cart state transition logging/history |

---

## 16. Critical Questions Answered

### When does cart remain?

| Scenario | Cart Remains? | Status | Reason |
|----------|--------------|--------|--------|
| Items in cart, browsing | YES | active | Normal shopping |
| Checkout initiated | YES | active | Payment pending |
| Payment pending (online) | YES | active | Awaiting callback |
| COD order created | YES | active | Awaiting fulfillment |
| Cashier QR generated | YES | active | Awaiting payment |
| Payment success | NO (converted) | checked_out | Items finalized |
| Payment failure | YES | active | Retry allowed |
| Payment timeout (24h) | YES | active | Order cancelled, cart unaffected |
| Cart expiration (3 days) | YES (empty) | expired | Items deleted, record stays |
| User clears cart | YES (empty) | active | Record stays, items deleted |

### When does inventory finalize?

| Event | Inventory Action |
|-------|-----------------|
| Add to cart | Reserve (cart-level, 3-day TTL) |
| Checkout | Reserve (order-level, 24-hour TTL) |
| Payment success | **Commit** (reserve → sold) |
| COD marked paid | **Commit** |
| Cashier confirms | **Commit** |
| Payment failure | No change (stays reserved) |
| Payment timeout | **Release** (order-level only) |
| Cart expiration | **Release** (cart-level only) |

### When does cart disappear?

**Never truly deleted** - cart records persist in all states (active, expired, checked_out).

**Items are deleted** when:
- Payment succeeds (clearCheckedOutSlice)
- Cart expires (expireCart)
- User clears cart (releaseCart)

---

## 17. Source File Reference

### Controllers
- `app/Http/Controllers/Api/General/CartController.php` - Cart CRUD
- `app/Http/Controllers/Api/General/OrderController.php` - Checkout + payments

### Services
- `app/Services/General/CartInventoryService.php` - Cart reservation (legacy)
- `app/Services/Inventory/OrderReservationService.php` - Order reservation (NEW)
- `app/Services/General/OrderService.php` - Order creation + checkout
- `app/Services/Checkout/OrderCreationService.php` - Order factory
- `app/Services/Payment/PaymentCheckoutHandler.php` - Payment dispatch

### Repositories
- `app/Repositories/CartRepository.php` - Cart persistence

### Commands
- `app/Console/Commands/CancelUnpaidOrders.php` - Order expiration reaper
- `app/Console/Commands/ExpireCarts.php` - Cart expiration (LEGACY)

### Models
- `packages/marvel/src/Database/Models/Cart.php`
- `packages/marvel/src/Database/Models/CartItem.php`
- `packages/marvel/src/Database/Models/Order.php`
- `packages/marvel/src/Database/Models/OrderProduct.php`

### Migrations
- `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php` - Base schema
- `database/migrations/2026_08_26_100001_add_inventory_reservation_state_to_orders.php` - Order reservation

### Tests
- `tests/Feature/CartApiTest.php`
- `tests/Feature/CartExpirationTest.php`
- `tests/Feature/CheckoutRegressionTest.php`
- `tests/Feature/CheckoutConcurrencyStressTest.php`
- `tests/Feature/Inventory/OrderReservationLifecycleTest.php`

---

## 18. Architectural Evolution

### Timeline

| Date | Commit | Change | Impact |
|------|--------|--------|--------|
| Initial | 0202cbd | Cart-owned inventory | Cart expiration released inventory |
| 2026-08-19 | fa5bdfc | Queue: redis → database | Infrastructure change, no cart impact |
| 2026-08-26 | 37ce4a8 | **Order-owned inventory** | Cart expiration no longer releases order inventory |
| 2026-08-26 | c49ea84 | Event transaction boundaries | Ensures events fire after commit |

### Current Architecture (2026-08-26+)

**Two-level reservation**:
1. **Cart-level** (shopping hold, 3-day TTL)
   - Temporary reservation during browsing
   - Released on cart expiration
   - Does NOT affect orders

2. **Order-level** (payment hold, 24-hour TTL)
   - Authoritative reservation during payment
   - Released on payment timeout
   - Independent of cart state

**Ownership**: Order owns inventory from checkout onward.

**Expiration**: Cart and order expire independently.

---

**End of Cart & Checkout Flow Audit**
