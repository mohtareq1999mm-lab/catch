# Fast Shipping — Request Flow Diagrams

---

## 1. Get Settings (Admin)

```
┌────────┐    ┌──────────────────┐    ┌─────────────────────┐    ┌──────────┐
│ Client │    │ FastShipping    │    │ FastShipping        │    │  Cache   │
│        │    │ Controller      │    │ Repository          │    │          │
└───┬────┘    └──────┬──────────┘    └─────────┬───────────┘    └────┬─────┘
    │                │                         │                     │
    │  GET /settings  │                         │                     │
    │────────────────→│                         │                     │
    │                │                         │                     │
    │                │  getSettings()           │                     │
    │                │────────────────────────→│                     │
    │                │                         │                     │
    │                │                         │  Cache::remember()  │
    │                │                         │────────────────────→│
    │                │                         │                     │
    │                │                         │  Cache MISS         │
    │                │                         │←────────────────────│
    │                │                         │                     │
    │                │                         │  Settings::first()  │
    │                │                         │  ──── DB ────→      │
    │                │                         │                     │
    │                │                         │  Cache SET          │
    │                │                         │  ──── Cache ───→    │
    │                │                         │                     │
    │                │  { enabled, available,  │                     │
    │                │    fee, hours, ... }     │                     │
    │                │←────────────────────────│                     │
    │                │                         │                     │
    │  200 OK        │                         │                     │
    │←───────────────│                         │                     │
```

---

## 2. Update Settings (Admin)

```
┌────────┐    ┌──────────────────┐    ┌─────────────────────┐    ┌──────────┐
│ Client │    │ FastShipping    │    │ FastShipping        │    │ Settings │
│        │    │ Controller      │    │ Repository          │    │  Table   │
└───┬────┘    └──────┬──────────┘    └─────────┬───────────┘    └────┬─────┘
    │                │                         │                     │
    │  PUT /settings  │                         │                     │
    │  { enabled,     │                         │                     │
    │    fee, ... }   │                         │                     │
    │────────────────→│                         │                     │
    │                │                         │                     │
    │                │  updateSettings($data)   │                     │
    │                │────────────────────────→│                     │
    │                │                         │                     │
    │                │                         │  DB::transaction    │
    │                │                         │────────────────┐    │
    │                │                         │                │    │
    │                │                         │  lockForUpdate │    │
    │                │                         │────────────────→│    │
    │                │                         │                │    │
    │                │                         │  UPDATE        │    │
    │                │                         │  options JSON  │    │
    │                │                         │────────────────→│    │
    │                │                         │                │    │
    │                │                         │  Cache::forget │    │
    │                │                         │  (cache inval) │    │
    │                │                         │                │    │
    │                │                         │←───────────────│    │
    │                │  success                │                     │
    │                │←────────────────────────│                     │
    │                │                         │                     │
    │  200 OK        │                         │                     │
    │←───────────────│                         │                     │
```

---

## 3. Get Status (Public)

```
┌────────┐    ┌──────────────────┐    ┌─────────────────────┐
│ Client │    │ FastShipping    │    │ FastShipping        │
│        │    │ Controller      │    │ Repository          │
└───┬────┘    └──────┬──────────┘    └─────────┬───────────┘
    │                │                         │
    │  GET /status    │                         │
    │────────────────→│                         │
    │                │                         │
    │                │  getStatus()             │
    │                │────────────────────────→│
    │                │                         │
    │                │                         │  isGloballyEnabled()
    │                │                         │  isWithinWorkingHours()
    │                │                         │  getFee()
    │                │                         │  getDurationMinutes()
    │                │                         │  calculateEta()
    │                │                         │
    │                │  { enabled, available,  │
    │                │    fee, hours, eta }     │
    │                │←────────────────────────│
    │                │                         │
    │  200 OK        │                         │
    │←───────────────│                         │
```

---

## 4. List Products (Public)

```
┌────────┐    ┌──────────────────┐    ┌─────────────────────┐    ┌──────────┐
│ Client │    │ FastShipping    │    │ FastShipping        │    │ Product  │
│        │    │ Controller      │    │ Service             │    │  Table   │
└───┬────┘    └──────┬──────────┘    └─────────┬───────────┘    └────┬─────┘
    │                │                         │                     │
    │  GET /products  │                         │                     │
    │  ?search=&     │                         │                     │
    │  &limit=15     │                         │                     │
    │────────────────→│                         │                     │
    │                │                         │                     │
    │                │  getFastShippingProducts │                     │
    │                │────────────────────────→│                     │
    │                │                         │                     │
    │                │                         │  Product::active()  │
    │                │                         │    ->fastShippingAvailable()
    │                │                         │    ->with(categories)
    │                │                         │    ->withAvg(reviews)
    │                │                         │    ->withCount(reviews)
    │                │                         │────────────────→    │
    │                │                         │                     │
    │                │                         │  Paginated results  │
    │                │                         │←────────────────────│
    │                │                         │                     │
    │                │  Paginated product list  │                     │
    │                │←────────────────────────│                     │
    │                │                         │                     │
    │  200 OK        │                         │                     │
    │←───────────────│                         │                     │
```

---

## 5. Checkout (Public, Auth Required)

```
┌────────┐    ┌──────────────┐    ┌──────────────────┐    ┌─────────────┐    ┌──────────────┐
│ Client │    │ FastShipping │    │ FastShipping     │    │ Cart        │    │ Order        │
│        │    │ Controller   │    │ Service          │    │ Inventory   │    │ Creation     │
└───┬────┘    └──────┬───────┘    └───────┬──────────┘    └──────┬──────┘    └──────┬───────┘
    │                │                    │                      │                  │
    │ POST /checkout  │                    │                      │                  │
    │ { auth token,   │                    │                      │                  │
    │   governorate,  │                    │                      │                  │
    │   address, ... }│                    │                      │                  │
    │────────────────→│                    │                      │                  │
    │                │                    │                      │                  │
    │                │ checkout(request)  │                      │                  │
    │                │───────────────────→│                      │                  │
    │                │                    │                      │                  │
    │                │                    │ getActiveCartForUser │                  │
    │                │                    │─────────────────────→│                  │
    │                │                    │                      │                  │
    │                │                    │ Cart found           │                  │
    │                │                    │←─────────────────────│                  │
    │                │                    │                      │                  │
    │                │                    │ ensureCartReservation│                  │
    │                │                    │─────────────────────→│                  │
    │                │                    │                      │                  │
    │                │                    │ OK / error           │                  │
    │                │                    │←─────────────────────│                  │
    │                │                    │                      │                  │
    │                │                    │ createFastOrder()    │                  │
    │                │                    │── DB Transaction ──→ │                  │
    │                │                    │                      │                  │
    │                │                    │ Validate:            │                  │
    │                │                    │  • Governorate       │                  │
    │                │                    │  • Working hours     │                  │
    │                │                    │  • Products eligible │                  │
    │                │                    │  • Cart not empty    │                  │
    │                │                    │                      │                  │
    │                │                    │ lockForUpdate        │                  │
    │                │                    │─────────────────────→│                  │
    │                │                    │                      │                  │
    │                │                    │ Coupon re-validate   │                  │
    │                │                    │ Calculate totals     │                  │
    │                │                    │ Calculate ETA        │                  │
    │                │                    │                      │                  │
    │                │                    │ createOrder()        │                  │
    │                │                    │───────────────────────────────────────→│
    │                │                    │                      │                  │
    │                │                    │ createOrderItems()   │                  │
    │                │                    │───────────────────────────────────────→│
    │                │                    │                      │                  │
    │                │                    │ finalizeOrder()      │                  │
    │                │                    │───────────────────────────────────────→│
    │                │                    │                      │                  │
    │                │                    │ finalizeItems()      │                  │
    │                │                    │─────────────────────→│                  │
    │                │                    │                      │                  │
    │                │                    │ DB Commit            │                  │
    │                │                    │                      │                  │
    │                │                    │ Handle Payment       │                  │
    │                │                    │  (online/cod/        │                  │
    │                │                    │   cashier)           │                  │
    │                │                    │                      │                  │
    │                │  Order + Payment   │                      │                  │
    │                │←───────────────────│                      │                  │
    │                │                    │                      │                  │
    │  200 OK        │                    │                      │                  │
    │←───────────────│                    │                      │                  │
```

---

## 6. List Orders (Public, Auth Required)

```
┌────────┐    ┌──────────────────┐    ┌─────────────────────┐    ┌──────────┐
│ Client │    │ FastShipping    │    │ FastShipping        │    │  Order   │
│        │    │ Controller      │    │ Service             │    │  Table   │
└───┬────┘    └──────┬──────────┘    └─────────┬───────────┘    └────┬─────┘
    │                │                         │                     │
    │  GET /orders    │                         │                     │
    │  ?limit=15     │                         │                     │
    │────────────────→│                         │                     │
    │                │                         │                     │
    │                │  paginateFastOrders()    │                     │
    │                │────────────────────────→│                     │
    │                │                         │                     │
    │                │                         │  Order::fast()      │
    │                │                         │    ->forUser($user) │
    │                │                         │    ->with(items)    │
    │                │                         │    ->paginate()     │
    │                │                         │────────────────→    │
    │                │                         │                     │
    │                │                         │  Paginated orders   │
    │                │                         │←────────────────────│
    │                │                         │                     │
    │                │  Paginated order list   │                     │
    │                │←────────────────────────│                     │
    │                │                         │                     │
    │  200 OK        │                         │                     │
    │←───────────────│                         │                     │
```

---

## 7. Toggle Fast Shipping on Product (Admin)

```
┌────────┐    ┌──────────────────┐    ┌──────────┐
│ Client │    │ ProductController │    │ Product  │
│        │    │                  │    │  Table   │
└───┬────┘    └──────┬───────────┘    └────┬─────┘
    │                │                     │
    │ PUT /products/ │                     │
    │   {id}/fast-   │                     │
    │   shipping     │                     │
    │ { available:   │                     │
    │   true }       │                     │
    │────────────────→│                     │
    │                │                     │
    │                │  findOrFail($id)    │
    │                │────────────────────→│
    │                │                     │
    │                │  Product found      │
    │                │←────────────────────│
    │                │                     │
    │                │  UPDATE             │
    │                │  is_fast_shipping_  │
    │                │  available = true   │
    │                │────────────────────→│
    │                │                     │
    │                │  200 + Product      │
    │←───────────────│                     │
```

---

## 8. Toggle Fast Shipping on Governorate (Admin)

```
┌────────┐    ┌──────────────────────┐    ┌──────────────┐
│ Client │    │ GovernorateController│    │ Governorate  │
│        │    │                      │    │    Table     │
└───┬────┘    └──────┬───────────────┘    └──────┬───────┘
    │                │                           │
    │ PUT /govern-   │                           │
    │ orates/{id}/   │                           │
    │ fast-shipping  │                           │
    │ { enabled:     │                           │
    │   true }       │                           │
    │────────────────→│                           │
    │                │                           │
    │                │  findById($id)             │
    │                │──────────────────────────→│
    │                │                           │
    │                │  Governorate found        │
    │                │←──────────────────────────│
    │                │                           │
    │                │  UPDATE                   │
    │                │  is_fast_shipping_enabled │
    │                │  = true                   │
    │                │──────────────────────────→│
    │                │                           │
    │                │  200 + Governorate        │
    │←───────────────│                           │
```
