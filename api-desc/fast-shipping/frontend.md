# Fast Shipping — Frontend Integration

---

## Public Endpoints (No Auth Required)

### Check Fast Shipping Availability

```js
// Check if fast shipping is available
const response = await fetch('/api/v1/fast-shipping/status');
const data = await response.json();

// data = {
//   enabled: true,
//   available: true,
//   duration_minutes: 120,
//   fee: 30,
//   opens_at: "08:00",
//   closes_at: "22:00",
//   available_again_at: null
// }
```

**Purpose:** Determine whether to show/hide fast shipping option on checkout page.

### List Fast Shipping Products

```js
const response = await fetch('/api/v1/fast-shipping/products?search=&limit=15');
const data = await response.json();
// data.data = [...products]
// data.meta = { current_page, last_page, per_page, total }
```

**Purpose:** Display products that can be fast-shipped in a dedicated product listing.

---

## Public Endpoints (Auth Required)

### Submit Fast Checkout

```js
const token = '...'; // Sanctum token

const response = await fetch('/api/v1/fast-shipping/checkout', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
    },
    body: JSON.stringify({
        name: 'John Doe',
        user_phone: '+201234567890',
        user_email: 'john@example.com',
        address: { street: '123 Main St', city: 'Cairo' },
        governorate_id: 1,
        selected_promotion_id: null,
        selected_gift_product_id: null,
        fulfillment_type: 'delivery',
        payment_method: 'online',
        gateway: 'myfatoorah',
        notes: 'Leave at door',
    }),
});

const data = await response.json();
```

### List My Fast Orders

```js
const response = await fetch('/api/v1/fast-shipping/orders?limit=15', {
    headers: { 'Authorization': `Bearer ${token}` },
});
const data = await response.json();
// data.data = [...orders with orderItems.product, orderItems.productVariant]
```

---

## Admin Endpoints

| Method | URI | Auth |
|--------|-----|------|
| GET | `/api/v1/fast-shipping/settings` | Sanctum + `view-fast-shipping` |
| PUT | `/api/v1/fast-shipping/settings` | Sanctum + `update-fast-shipping` |
| PUT | `/api/v1/governorates/{id}/fast-shipping` | Sanctum |
| PUT | `/api/v1/products/{id}/fast-shipping` | Sanctum |

### Get/Update Settings

```js
const token = '...'; // Admin Sanctum token

// GET settings
const settingsResponse = await fetch('/api/v1/fast-shipping/settings', {
    headers: { 'Authorization': `Bearer ${token}` },
});
const settings = await settingsResponse.json();

// PUT settings
const updateResponse = await fetch('/api/v1/fast-shipping/settings', {
    method: 'PUT',
    headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
    },
    body: JSON.stringify({
        enabled: true,
        duration_minutes: 90,
        fee: 25,
        start_hour: '09:00',
        end_hour: '21:00',
    }),
});
```

---

## UI Components

### Fast Shipping Status Badge

Show a badge indicating whether fast shipping is available:

- **Available:** Green badge — "Fast Shipping Available"
- **Unavailable (disabled):** Gray badge — "Fast Shipping Disabled"
- **Unavailable (after hours):** Orange badge — "Available again at {time}"

### Fast Shipping Toggle (Admin)

Settings page with form fields:

| Field | Type | Description |
|-------|------|-------------|
| Enabled | Switch | Global enable/disable |
| Duration (minutes) | Number input | 1–1440 |
| Fee | Currency input | Fast shipping fee |
| Start Hour | Time picker | Format: H:i |
| End Hour | Time picker | Format: H:i |

### Product Fast Shipping Toggle

In product edit form, add a "Fast Shipping Eligible" checkbox.

### Governorate Fast Shipping Toggle

In governorate edit form, add a "Fast Shipping Enabled" checkbox.

### Checkout Flow

1. User adds items to cart
2. Cart displays estimated delivery options
3. If fast shipping is available (enabled + within hours + eligible items + eligible governorate), show "Fast Shipping" as an option
4. User enters address, selects governorate
5. User completes payment
6. Order is created with `shipping_method = FAST`
7. ETA is displayed to user

---

## State Patterns

### Loading State

```jsx
{
    loading && <Spinner />;
}
```

### Empty State (Products)

```jsx
{
    !loading && products.length === 0 && (
        <EmptyState message="No fast shipping products available" />
    );
}
```

### Error State

```jsx
{
    error && <Alert type="error" message={error.message} />;
}
```

### Availability State

```jsx
{
    status.available
        ? <Badge type="success">Fast Shipping Available</Badge>
        : status.enabled
            ? <Badge type="warning">Available again at {status.available_again_at}</Badge>
            : <Badge type="disabled">Fast Shipping Disabled</Badge>;
}
```
