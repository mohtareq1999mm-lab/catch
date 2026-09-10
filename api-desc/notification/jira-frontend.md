# Jira - Notification Feature (Frontend)

## Epic: End-User Notification Center UI

### Story Points Estimate: 8

---

## User Stories

### FE-US-001: Notification Bell with Dropdown
**As** a user
**I want** a bell icon showing unread count with a dropdown of recent unread notifications
**So that** I can quickly see new activity

**Acceptance Criteria:**
- Bell icon with unread badge count from `GET /api/v1/notifications/unread`
- Dropdown shows last 5 unread with title, message, time
- Click navigates to `action_url`
- Mark as read on click
- Realtime updates via Pusher `private-users.{id}`

### FE-US-002: Notification Center Page
**As** a user
**I want** a full notification page with pagination
**So that** I can review all my notification history

**Acceptance Criteria:**
- Paginated list from `GET /api/v1/notifications`
- Each item shows icon, title, message, time, read/unread state
- Mark as read button per item
- Mark all as read button
- Delete individual

### FE-US-003: Realtime Toast
**As** a user
**I want** a toast popup when a new notification arrives
**So that** I get immediate feedback

**Acceptance Criteria:**
- Subscribe to `private-users.{id}`
- Listen for `Illuminate\Notifications\Events\BroadcastNotificationCreated`
- Render toast by `type` → icon/action
- Respect `{en,ar}` localization

### FE-US-004: Type-Based Rendering
**As** a frontend dev
**I want** a stable `type` field to switch UI
**So that** I don't depend on class names

**Acceptance Criteria:**
- Map of 21 `type` values → icon + deep link (see `frontend.md`)
- Handle unknown `type` gracefully (default bell icon)

---

## Frontend Tasks

| ID | Description | h | Component |
|----|-------------|---|-----------|
| FE-T1 | Notification API client | 3 | `notificationApi` |
| FE-T2 | Bell + dropdown | 5 | `NotificationBell.vue` |
| FE-T3 | Center page + pagination | 8 | `NotificationCenter.vue` |
| FE-T4 | Realtime Echo wiring | 5 | `Echo` service |
| FE-T5 | Toast | 3 | `NotificationToast.vue` |
| FE-T6 | Type→UI map | 3 | `notificationTypes.ts` |
| FE-T7 | Localization (en/ar) | 3 | i18n |

---

## Notification Response Examples — all 21 `type` values

These mirror the **realtime broadcast payload** (Pusher `BroadcastNotificationCreated`):
`title`/`message` are `{en,ar}` objects; `type` is the stable business id. The REST
list endpoint returns the same object but with `title`/`message` already resolved
to a single string by the `lang` header.

### Phase 1 — Order / Payment / Coupon

**`order.created`**
```json
{
  "id": "uuid", "type": "order.created", "icon": "shopping-cart",
  "resource_type": "order", "resource_id": 123, "action_url": "/orders/123",
  "title": { "en": "Order placed", "ar": "تم تقديم الطلب" },
  "message": { "en": "Your order #12345 has been placed.", "ar": "..." },
  "order_id": 123, "order_number": "12345",
  "total_amount": "1500.00", "payment_status": "pending", "order_status": "pending"
}
```

**`payment.succeeded`**
```json
{
  "id": "uuid", "type": "payment.succeeded", "icon": "credit-card",
  "resource_type": "order", "resource_id": 123, "action_url": "/orders/123",
  "title": { "en": "Payment successful", "ar": "تم الدفع بنجاح" },
  "message": { "en": "Payment for order #12345 succeeded.", "ar": "..." },
  "order_id": 123, "order_number": "12345",
  "total_amount": "1500.00", "payment_status": "succeeded"
}
```

**`payment.failed`**
```json
{
  "id": "uuid", "type": "payment.failed", "icon": "credit-card",
  "resource_type": "order", "resource_id": 123, "action_url": "/orders/123",
  "title": { "en": "Payment failed", "ar": "فشل الدفع" },
  "message": { "en": "Payment for order #12345 failed.", "ar": "..." },
  "order_id": 123, "order_number": "12345", "payment_status": "failed"
}
```

**`order.delivered`**
```json
{
  "id": "uuid", "type": "order.delivered", "icon": "truck",
  "resource_type": "order", "resource_id": 123, "action_url": "/orders/123",
  "title": { "en": "Order delivered", "ar": "تم تسليم الطلب" },
  "message": { "en": "Order #12345 has been delivered.", "ar": "..." },
  "order_id": 123, "order_number": "12345", "order_status": "delivered"
}
```

**`order.cancelled`**
```json
{
  "id": "uuid", "type": "order.cancelled", "icon": "x-circle",
  "resource_type": "order", "resource_id": 123, "action_url": "/orders/123",
  "title": { "en": "Order cancelled", "ar": "تم إلغاء الطلب" },
  "message": { "en": "Order #12345 has been cancelled.", "ar": "..." },
  "order_id": 123, "order_number": "12345", "order_status": "cancelled"
}
```

**`order.refunded`**
```json
{
  "id": "uuid", "type": "order.refunded", "icon": "refresh",
  "resource_type": "refund", "resource_id": 55, "action_url": "/refunds/55",
  "title": { "en": "Order refunded", "ar": "تم استرداد الطلب" },
  "message": { "en": "Refund for order #12345 processed.", "ar": "..." },
  "refund_id": 55, "order_id": 123, "amount": "1500.00", "status": "completed"
}
```

**`coupon.assigned`**
```json
{
  "id": "uuid", "type": "coupon.assigned", "icon": "tag",
  "resource_type": "coupon", "resource_id": 7, "action_url": "/coupons/7",
  "title": { "en": "Coupon assigned", "ar": "تم تعيين قسيمة" },
  "message": { "en": "You received coupon SAVE10.", "ar": "..." },
  "coupon_assignment_id": 90, "coupon_id": 7, "coupon_code": "SAVE10",
  "max_uses": 1, "expires_at": "2026-09-01T00:00:00+00:00"
}
```

**`coupon.available`**
```json
{
  "id": "uuid", "type": "coupon.available", "icon": "tag",
  "resource_type": "coupon", "resource_id": 7, "action_url": "/coupons/7",
  "title": { "en": "New coupon available", "ar": "قسيمة جديدة متاحة" },
  "message": { "en": "Coupon SAVE10 is now available.", "ar": "..." },
  "coupon_id": 7, "coupon_code": "SAVE10", "coupon_type": "fixed"
}
```

**`coupon.used`**
```json
{
  "id": "uuid", "type": "coupon.used", "icon": "tag",
  "resource_type": "coupon", "resource_id": 7, "action_url": "/coupons/7",
  "title": { "en": "Coupon used", "ar": "تم استخدام القسيمة" },
  "message": { "en": "Coupon SAVE10 was used.", "ar": "..." },
  "coupon_id": 7, "coupon_code": "SAVE10", "order_id": 123,
  "remaining_uses": 0, "consumed_at": "2026-08-13T10:00:00+00:00"
}
```

### Phase 2 — Promotion / Flash Sale available

**`promotion.available`**
```json
{
  "id": "uuid", "type": "promotion.available", "icon": "tag",
  "resource_type": "promotion", "resource_id": 3, "action_url": "/promotions/3",
  "title": { "en": "New promotion", "ar": "عرض جديد" },
  "message": { "en": "Promotion Summer Sale is live.", "ar": "..." },
  "promotion_id": 3, "promotion_code": "SUMMER",
  "discount_type": "percentage", "discount_value": "20",
  "start_at": "2026-08-01T00:00:00+00:00", "end_at": "2026-08-31T00:00:00+00:00"
}
```

**`flash_sale.available`**
```json
{
  "id": "uuid", "type": "flash_sale.available", "icon": "bolt",
  "resource_type": "flash_sale", "resource_id": 4, "action_url": "/flash-sales/4",
  "title": { "en": "Flash sale live", "ar": "بيع سريع مباشر" },
  "message": { "en": "Flash sale Mega Deal is live.", "ar": "..." },
  "flash_sale_id": 4, "discount_type": "percentage", "discount_value": "30",
  "start_date": "2026-08-13", "end_date": "2026-08-14"
}
```

### Phase 3 — Wishlist / Review / Price / Stock / Ending soon / Cart

**`review.approved`**
```json
{
  "id": "uuid", "type": "review.approved", "icon": "star",
  "resource_type": "review", "resource_id": 8, "action_url": "/reviews/8",
  "title": { "en": "Review approved", "ar": "تمت الموافقة على المراجعة" },
  "message": { "en": "Your review for iPhone 15 was approved.", "ar": "..." },
  "review_id": 8, "product_id": 123
}
```

**`review.rejected`**
```json
{
  "id": "uuid", "type": "review.rejected", "icon": "star",
  "resource_type": "review", "resource_id": 8, "action_url": "/reviews/8",
  "title": { "en": "Review rejected", "ar": "تم رفض المراجعة" },
  "message": { "en": "Your review for iPhone 15 was rejected.", "ar": "..." },
  "review_id": 8, "product_id": 123
}
```

**`discount.changed`**
```json
{
  "id": "uuid", "type": "discount.changed", "icon": "tag",
  "resource_type": "product", "resource_id": 123, "action_url": "/products/iphone-15",
  "title": { "en": "Discount updated", "ar": "تم تحديث الخصم" },
  "message": { "en": "Discount changed on iPhone 15.", "ar": "..." },
  "product_id": 123
}
```

**`price.drop`**
```json
{
  "id": "uuid", "type": "price.drop", "icon": "price-drop",
  "resource_type": "product", "resource_id": 123, "action_url": "/products/iphone-15",
  "title": { "en": "Price dropped!", "ar": "انخفض السعر!" },
  "message": { "en": "iPhone 15 is now cheaper.", "ar": "..." },
  "product_id": 123, "old_price": "1200.00", "new_price": "999.00"
}
```

**`back.in.stock`**
```json
{
  "id": "uuid", "type": "back.in.stock", "icon": "box",
  "resource_type": "product", "resource_id": 123, "action_url": "/products/iphone-15",
  "title": { "en": "Back in stock", "ar": "متوفر مجدداً" },
  "message": { "en": "iPhone 15 is back in stock.", "ar": "..." },
  "product_id": 123
}
```

**`promotion.price.drop`**
```json
{
  "id": "uuid", "type": "promotion.price.drop", "icon": "tag",
  "resource_type": "promotion", "resource_id": 3, "action_url": "/promotions/3",
  "title": { "en": "Promotion price drop", "ar": "انخفاض سعر العرض" },
  "message": { "en": "Summer Sale just dropped in price.", "ar": "..." },
  "promotion_id": 3
}
```

**`flash_sale.price.drop`**
```json
{
  "id": "uuid", "type": "flash_sale.price.drop", "icon": "bolt",
  "resource_type": "flash_sale", "resource_id": 4, "action_url": "/flash-sales/4",
  "title": { "en": "Flash sale price drop", "ar": "انخفاض سعر البيع السريع" },
  "message": { "en": "Mega Deal just dropped in price.", "ar": "..." },
  "flash_sale_id": 4
}
```

**`cart.abandoned`**
```json
{
  "id": "uuid", "type": "cart.abandoned", "icon": "cart",
  "resource_type": "cart", "resource_id": 42, "action_url": "/cart",
  "title": { "en": "You left items in your cart", "ar": "تركت عناصر في سلة التسوق" },
  "message": { "en": "Complete your purchase before they sell out.", "ar": "..." },
  "cart_id": 42
}
```

**`promotion.ending_soon`**
```json
{
  "id": "uuid", "type": "promotion.ending_soon", "icon": "hourglass",
  "resource_type": "promotion", "resource_id": 3, "action_url": "/promotions/3",
  "title": { "en": "Promotion ending soon", "ar": "العرض ينتهي قريباً" },
  "message": { "en": "Summer Sale ends soon.", "ar": "..." },
  "promotion_id": 3, "end_at": "2026-08-31T00:00:00+00:00"
}
```

**`flash_sale.ending_soon`**
```json
{
  "id": "uuid", "type": "flash_sale.ending_soon", "icon": "hourglass",
  "resource_type": "flash_sale", "resource_id": 4, "action_url": "/flash-sales/4",
  "title": { "en": "Flash sale ending soon", "ar": "البيع السريع ينتهي قريباً" },
  "message": { "en": "Mega Deal ends soon.", "ar": "..." },
  "flash_sale_id": 4, "end_date": "2026-08-14"
}
```

> All examples share the envelope: `id`, `type`, `title{en,ar}`, `message{en,ar}`,
> `icon`, `resource_type`, `resource_id`, `action_url`. Switch the UI on `type`
> (and `resource_type`); use `action_url` for navigation and `icon` for the glyph.

---

## How to use this response in the frontend

### 1. Acquire the data (two sources)

**A. REST hydrate (history + badge count)** — call on app load / pull-to-refresh:
```js
const list = await notificationApi.list({ page: 1 });
const unread = await notificationApi.unread();   // unread.data + meta.total (badge)
store.notifications = list.data;
store.unreadCount = unread.meta.total;
```

**B. Realtime push (instant)** — subscribe to the private channel and normalize
the incoming event into the same shape as the REST items:
```js
echo.private(`users.${userId}`)
  .listen('.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated', (e) => {
    store.notifications.unshift(normalize(e));   // newest first
    store.unreadCount++;
    toast(normalize(e));                          // NotificationToast
  });
```

### 2. Normalize into a view model

The realtime payload keeps `title`/`message` as `{en,ar}`; the REST payload already
resolves them to a single string. Normalize once so the rest of the UI is agnostic:
```js
function normalize(n, locale = 'en') {
  const t = typeof n.title === 'object' ? n.title[locale] ?? n.title.en : n.title;
  const m = typeof n.message === 'object' ? n.message[locale] ?? n.message.en : n.message;
  return {
    id: n.id,
    type: n.type,                 // business id: 'price.drop'
    title: t,
    message: m,
    icon: n.icon,                // 'price-drop', 'tag', 'truck' ...
    resourceType: n.resource_type,
    resourceId: n.resource_id,
    actionUrl: n.action_url,     // e.g. '/products/iphone-15'
    createdAt: n.created_at,
    readAt: n.read_at ?? null,
  };
}
```

### 3. Render by `type` (component / icon / route map)

Never branch on class names — use the stable `type`. A single map drives icon,
label and click target:
```js
const TYPE_MAP = {
  'order.created':        { icon: 'shopping-cart', to: (n) => `/orders/${n.resourceId}` },
  'payment.succeeded':    { icon: 'credit-card',   to: (n) => `/orders/${n.resourceId}` },
  'payment.failed':       { icon: 'credit-card',   to: (n) => `/orders/${n.resourceId}` },
  'order.delivered':      { icon: 'truck',         to: (n) => `/orders/${n.resourceId}` },
  'order.cancelled':      { icon: 'x-circle',      to: (n) => `/orders/${n.resourceId}` },
  'order.refunded':       { icon: 'refresh',       to: (n) => `/refunds/${n.resourceId}` },
  'coupon.assigned':      { icon: 'tag',           to: (n) => `/coupons/${n.resourceId}` },
  'coupon.available':     { icon: 'tag',           to: (n) => `/coupons/${n.resourceId}` },
  'coupon.used':          { icon: 'tag',           to: (n) => `/coupons/${n.resourceId}` },
  'promotion.available':  { icon: 'tag',           to: (n) => `/promotions/${n.resourceId}` },
  'flash_sale.available': { icon: 'bolt',          to: (n) => `/flash-sales/${n.resourceId}` },
  'review.approved':      { icon: 'star',          to: (n) => `/reviews/${n.resourceId}` },
  'review.rejected':      { icon: 'star',          to: (n) => `/reviews/${n.resourceId}` },
  'discount.changed':     { icon: 'tag',           to: (n) => n.actionUrl },
  'price.drop':           { icon: 'price-drop',    to: (n) => n.actionUrl },
  'back.in.stock':        { icon: 'box',           to: (n) => n.actionUrl },
  'promotion.price.drop': { icon: 'tag',           to: (n) => `/promotions/${n.resourceId}` },
  'flash_sale.price.drop':{ icon: 'bolt',          to: (n) => `/flash-sales/${n.resourceId}` },
  'cart.abandoned':       { icon: 'cart',          to: () => '/cart' },
  'promotion.ending_soon':{ icon: 'hourglass',     to: (n) => `/promotions/${n.resourceId}` },
  'flash_sale.ending_soon':{icon: 'hourglass',    to: (n) => `/flash-sales/${n.resourceId}` },
};

function render(n) {
  const cfg = TYPE_MAP[n.type] ?? { icon: 'bell', to: () => n.actionUrl };
  return { icon: cfg.icon, href: cfg.to(n) };
}
```
> Unknown `type` falls back to a default bell icon — do not crash on new types.

### 4. Localization

- REST: send `lang: ar` header → `title`/`message` already Arabic.
- Realtime: pick `title[locale]` / `message[locale]` client-side (see `normalize`).
- Keep a single `currentLocale` reactive value; re-normalize the list when it changes.

### 5. Click → navigate + mark read

```js
async function onNotificationClick(n) {
  router.push(render(n).href);          // use action_url / type map
  if (!n.readAt) {
    await notificationApi.markAsRead(n.id);
    n.readAt = new Date().toISOString();
    store.unreadCount = Math.max(0, store.unreadCount - 1);
  }
}
// "Mark all read" button:
await notificationApi.markAllAsRead();
store.notifications.forEach(n => (n.readAt = n.readAt ?? new Date().toISOString()));
store.unreadCount = 0;
```

### 6. Recommended components (Vue example)

```
NotificationBell.vue       → badge = store.unreadCount; dropdown lists last 5 normalized items
NotificationCenter.vue     → v-for over store.notifications; each row uses render() for icon + href
NotificationToast.vue      → listens to store push; auto-dismiss after 5s
NotificationItem.vue       → props: notification; shows icon + title + message + time + read state
notificationTypes.ts       → the TYPE_MAP above (single source of truth)
echo.ts                    → Laravel Echo init + private-users.{id} subscription
```

**End-to-end flow:** subscribe on login → push realtime into store → hydrate from
REST on open → render by `type` map → on click navigate via `action_url` and
`PATCH .../read` → keep `unreadCount` in sync. No code depends on PHP class names.

