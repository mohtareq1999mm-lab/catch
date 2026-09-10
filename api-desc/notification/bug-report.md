# Bug Report - Notification Feature (Phase 1 / 2 / 3)

## Issue 1 (HIGH): `notifications.type` stored PHP FQCN instead of stable business id

- **Status:** FIXED
- **Description:** Before the refactor, Laravel's `DatabaseChannel::buildPayload()`
  fell back to `get_class($notification)` because no `databaseType()` method
  existed. The `notifications.type` column therefore stored
  `App\Notifications\UserProductPriceDropNotification`, coupling the API/frontend
  to PHP class names and breaking if classes are renamed/moved.
- **Fix:** Added `databaseType($notifiable)` to all 21 `User*Notification` classes,
  returning `broadcastType()`. Now `type` = `price.drop` etc. (runtime-verified
  by executing `DatabaseChannel::send()` against all 21 notifications).
- **Impact:** This is a **breaking change for any client** that parsed the FQCN
  from the REST `type` field. The separate SPA must switch to the business `type`.
  The broadcast `type` was already the business id, so the realtime path is
  unaffected.

## Issue 2 (HIGH): No real-time delivery for end users

- **Status:** FIXED
- **Description:** Notifications were persisted but never pushed. End users had to
  poll the REST endpoint.
- **Fix:** All 21 notifications use `via(['database','broadcast'])`; the
  `BroadcastChannel` dispatches `BroadcastNotificationCreated` on
  `private-users.{id}` (resolved from `User::receivesBroadcastNotificationsOn()`).

## Issue 3 (HIGH): Wishlist fan-out could target admins / wrong recipients

- **Status:** FIXED
- **Description:** The wishlist fan-out action could notify any user including
  admins, and lacked scoping to the product's wishlist owners.
- **Fix:** `NotifyWishlistUsersOfProduct` queries `wishlists` for the product and
  notifies only those users, explicitly excluding `type === 'admin'`.

## Known Issues / Open

- Realtime payload repeats `{en,ar}` (REST resolves to a single locale). Optional
  future improvement: resolve realtime `title`/`message` server-side.
- The legacy `api-desc/notifaction` (typo) folder documents the *admin*
  notification system (different feature, FQCN types). Keep the two separate.
