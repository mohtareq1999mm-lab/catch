# Coupon Notification Flow (email excluded by business decision)

## Decision
Email is OUT OF SCOPE for coupon notifications. Required: Database (in-app) + Pusher realtime + FCM push. Assignment must succeed with no SMTP, no email, no mail config. `UserCouponAssignedNotification::via()` = `['database','fcm','broadcast']` only; `toMail()` dormant.

## Flow
`Admin POST assignments → CouponAssignmentRepository::assignCoupon → commit → event(new CouponAssigned($assignment)) → SendUserCouponAssignedNotification (queue high, ShouldQueue) → $user->notify(new UserCouponAssignedNotification($assignment)) → database + FcmChannel + broadcast`.

## Payload (single source: `toDatabase`)
`{title{en,ar}, message{en,ar}, icon:tag, resource_type:coupon, resource_id, action_url:/coupons/{id}, coupon_assignment_id, coupon_id, coupon_code, max_uses, expires_at}`. `broadcastType=databaseType=coupon.assigned`.
- Guards: listener drops non-`USER` types; FCM skips when title/body unresolvable (logs `FCM skipped`).
- Pusher: `toBroadcast` = same payload on queue high; channel `users.{userId}` (owner-only, `routes/channels.php:22-24`); admin channel `admin.notifications` unchanged.
- FCM: `FcmChannel` reuses DB payload, resolves localized title/body by app locale, dispatches `SendFcmNotificationJob(title, body, data-minus-title/message, ownerId)` — owner tokens only, invalid tokens removed, tries=3 backoff [30,120].
- Frontend: prefix `action_url` with `APP_URL_FRONTEND` (backend-relative; P1 email-host defect N/A now that mail is out of scope). Subscribe `users.{id}` event `coupon.assigned` → refresh `GET mine`.

## Isolation
User A never receives User B (channel auth + owner-scoped FCM + per-user notify). Verified: `channels.php` owner check, FCM `notifiable->getKey()` scoping, no global broadcast.
