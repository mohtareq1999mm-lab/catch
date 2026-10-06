# Phase 12 — Customer Experience

## 1. Executive Verdict

**PASS WITH RESIDUAL RISKS.** The customer journey works end-to-end against the hardened mechanics audited in Phases 01–11: public catalog browsing, authenticated cart/coupon/promotion/checkout, three payment methods with mobile/web duality, owner-scoped orders/invoices/downloads, knowledge-gated public tracking, a complete notification center (channel + event preferences, quiet hours, device management, history), digital entitlements with signed delivery, and moderated reviews. The journey manual itself is mechanically stale (cart-owned reservations, EGP-only, MyFatoorah-only, cashier QR, public invoice verification), but its journey structure still matches the product. Residual risks: public tracking relies on guessable order numbers + often-known contact details (throttled/logged, acceptable with caveats), and journey-level runtime proof is absent.

## 2. Phase Objective

Per `PHASE-12-CUSTOMER-EXPERIENCE.md` (source: `HEAD:docs/production-manual/PHASE-12-CUSTOMER-EXPERIENCE.md`, 1144 lines): trace every customer touchpoint — browse, cart, coupon, promotions, checkout, three payments, callbacks, orders, invoices (view/download/verify), shipment tracking, refunds, notifications — with endpoints, flows, code references, visible behavior, tables, concurrency, recovery, config values, and file index.

## 3. Scope

**In scope:** customer-facing surface and posture across the journey: public catalog, cart UX mechanics (by reference), checkout/payment UX (by reference), order/invoice/tracking access control, notification center, digital delivery, reviews, refund requests. Mechanics already proven in Phases 01–11 are cited, not re-traced.

**Out of scope:** admin/support surfaces (Phases 13/14), deep mechanics of pricing/inventory/payment (Phases 01–07), fulfillment ops (Phase 09).

## 4. What Was Supposed to Be Implemented

The manual claims a 24-section journey: public browse endpoints; cart via `reserveItem` with 3-day reservation TTL; coupon apply; promotions; checkout with `ensureCartReservation`; MyFatoorah-only online payment (EGP), COD, cashier-with-QR; callbacks; orders; invoices (view/download/verify-public-60/min); shipment tracking; refunds; notifications; journey diagram; per-scenario recovery; config values; file index.

## 5. What Actually Exists

The journey structure holds; the mechanics underneath changed (all verified in prior phases):

- **Browse** (§12.1): public catalog endpoints unchanged in shape (products/categories/brands/banners/sliders/flash-sales/search/tags under `v1/general`, `routes/api.php:52-140`); cursor-pagination contract + filter aliases added post-manual (commits `180e2ce`, `26d3a27`).
- **Cart** (§§12.2–12.3): reservation-free container (Phase 02); prices snapshotted, refreshed at checkout; manual's `reserveItem`/TTL mechanics obsolete.
- **Coupon/promotions/checkout/payments/callbacks** (§§12.4–12.12): claim-gated coupons, descriptor gifts, flow-gated checkout, three gateways + per-order currency + zero-value path, idempotent completion, mobile/web duality (Phases 01–06).
- **Orders** (§§12.13–12.14): owner-scoped `OrderResource` detail + `invoiceByOrderId` (404-masked); customer cancel (pending/processing + unpaid).
- **Invoices** (§§12.15–12.17): owner-scoped `myInvoices`; signed view/download URLs; verify now **authenticated** (5/min) — manual's public-verify flow superseded (Phase 08 F-02).
- **Tracking** (§12.18): public `track-order` (order_number + email/phone match, throttled, access-logged) + authenticated tracking (owner-scoped, full timeline, cancel eligibility, progress).
- **Refunds** (§12.19): customer request flow (one-per-order, owner-or-super-admin) + approval fan-out (Phase 10).
- **Notifications** (§12.20): full center — per-channel + per-event preferences, quiet hours, language, device register/unregister, history endpoint; FCM/email/SMS/push fan-out via queued listeners + `SendFcmNotificationJob`.
- **Digital** (post-manual addition): entitlement index with signed download/preview/license-reveal/external-redirect URLs, owner-scoped, N+1-guarded; `ExternalUrlValidator`; license-key allocation tracking.
- **Reviews**: product reviews (authenticated create/update) + site reviews (public list, authenticated submit) with approve/reject moderation events.

## 6. Architecture

```
PUBLIC (throttled): catalog, search, site-review list, payment-gateways snapshot,
  callbacks/webhooks (gateway), track-order (knowledge-gated), invoice verify (auth — see drift)
AUTHENTICATED: cart, coupons, promotions, checkout, orders, invoices, tracking,
  downloads/licenses, reviews, notification center, devices, refunds, preferences/currency
POST-PURCHASE FAN-OUT (queued): order/payment/shipment/refund/coupon/promo notifications
  gated by UserNotificationPreference (channels × events × quiet hours × language)
```

## 7. Complete Execution Flow

Journey trace (mechanics by reference): browse (public, paginated) → add (reservation-free, price snapshot) → view (eager-loaded) → coupon (claim-gated, reason-coded) → promotions (read-only eligibility, explicit selection) → checkout (flow-gated, pending-reuse, order-owned reservation) → pay (gateway redirect / COD / cashier; zero-value local) → callback/webhook (idempotent, mismatch-closed, mobile/web) → orders (owner-scoped) → invoice (signed access, HMAC verify) → track (public-knowledge or owner-full) → deliver (notifications) → review/refund/return-request as applicable. Each step verified in its home phase; access boundaries verified here.

## 8. Business Rules

| # | Rule | Home phase | Customer-visible effect |
|---|---|---|---|
| R1 | One reusable cart; prices refresh at checkout | 02/01 | Cart never "expires"; totals may update at checkout |
| R2 | Coupons may require claiming first | 03 | Claim → apply → checkout; reason-coded rejections |
| R3 | Promotion selection explicit; gifts fail loudly | 04 | No silent gift drops |
| R4 | Pending orders resume on retry; method switches constrained | 01 | Seamless payment retry |
| R5 | COD unavailable for pickup; cashier forces pickup | 01 | Correct fulfillment options |
| R6 | Invoices owner-only; verification authenticated | 07/08 | No public invoice access |
| R7 | Tracking public with contact-proof, full with ownership | §11 here | Guests track with order+contact; owners see more |
| R8 | Refund requests one-per-order; delivered-digital not refundable | 10 | Clear refund eligibility |
| R9 | Notifications honor channel/event/quiet-hour preferences | §11 here | No unwanted 3am pushes |
| R10 | Digital goods delivered via entitlements, never auto-"delivered" | 09 | Digital orders rest at completed |

## 9. Source of Truth / Authorities

Customer state converges on the same authorities as Phases 01–11 (single lifecycle writer, order-owned inventory, flow-gated transitions, ledgered refunds, idempotent completion). Customer-specific authorities: `UserNotificationPreference` (notification gating), `DigitalEntitlement` + license allocations (digital ownership), `OrderTrackingEvent`/timeline (tracking projections), review moderation (publish state).

## 10. Database Impact

No customer-exclusive tables beyond: `user_notification_preferences`, `user_device_tokens` (+ `device_tokens` legacy), `order_notifications`, `order_tracking_events`, digital family (`digital_assets/entitlements/download_logs/license_keys`), `site_reviews` (+ review approval fields), `carts/cart_items` (Phase 02). All additive; preferences/devices are user-scoped with cascade posture per FK schema.

## 11. API Surface

| Area | Endpoints | Auth | Notes |
|---|---|---|---|
| Catalog | products/categories/brands/banners/sliders/flash-sales/search/tags | public | paginated, filtered, cached where marked |
| Cart | Marvel cart group | sanctum + throttle:cart | ownership enforced (Phase 02) |
| Coupons/promotions/checkout | apply/claim/mine/available; promotions; checkout; callbacks | sanctum (callbacks public) | reason-coded (Phase 03) |
| Orders | index/show/cancel/invoice | sanctum + owner | 404-masked (Phase 05) |
| Invoices | my-invoices; verify; signed view/download | sanctum / signed | verify auth-only (drift) |
| Tracking | track-order (public); my-orders; orders/{id}/track | knowledge / sanctum+owner | throttled + logged |
| Digital | downloads index; license reveal; external redirect | sanctum+owner / signed | validated externals |
| Reviews | product add/update; site list/store | sanctum (list public) | moderated |
| Notifications | preferences get/put; devices register/unregister; history | sanctum + owner | per-user scope |
| Refunds | refunds apiResource | sanctum + throttle:refunds | owner-scoped requests |

## 12. Authentication & Authorization

Public surface is intentionally narrow (catalog, gateway snapshot, gateway callbacks, knowledge-gated tracking); everything personal requires sanctum + ownership scoping with 404-masking (`show`, `invoiceByOrderId`, authenticated tracking, downloads, notification center). Refund requests owner-or-super-admin. Reviews authenticated (submission) with moderation gates. Verify-invoice requires any authenticated user (Phase 08 F-02 caveat). No customer endpoint exposes other users' data (verified per controller).

## 13. Validation

Journey inputs validated per endpoint (cart rules, coupon codes, promotion/gift ids, checkout request, refund requests, review bodies, device tokens, preference payloads). Rating/review content moderation via approval states. Preference updates constrained to known channels/events. Tracking requires order_number + contact pair (no open enumeration).

## 14. Transactions

Customer mutations ride the same transactional authorities as their home phases (cart savepoints, checkout single-transaction, completion locks, refund claim+ledger). Preference/device/review writes are single-row, idempotent, and retry-safe. Tracking and notification reads are non-transactional (correct).

## 15. Concurrency

Inherited from home phases (cart merge locks, checkout token+locks, coupon/assignment locks, refund claim+ledger). Journey-specific: device register/unregister idempotent; preference updates last-writer-wins (acceptable); verify-counter atomic increment; download/license reveal is read-mostly with allocation checks. Runtime proof absent (standard limitation).

## 16. Async / Queues / Events

The customer-visible async surface is notifications: ~30 user/admin notification classes across order/payment/shipment/refund/coupon/promotion/review/digital/abandoned-cart events, fanned out via queued listeners + FCM job, gated by preferences/quiet-hours. Abandoned-cart (hourly), ending-soon (daily), outbox publishing (minutely) run on schedule. No customer action blocks on queues.

## 17. Error Handling

Consistent envelope (`success/message/data/errors`) with reason codes on coupon/claim/flow failures; 401/403/404/409/422 semantics per endpoint (verified in home phases); throttles answer 429; signed-URL failures 404-mask; gateway failures surface as actionable messages (retry/different-method). The manual's §12.22 recovery scenarios hold structurally (cart-empty, stock-short, coupon-invalid, minimum-order, gateway-down, mismatch, invoice-retry, PDF-regen, duplicate-callback, error-callback-success, reaper races) with the mechanics updated per Phases 01–06.

## 18. Security

- No personal data on public endpoints (catalog only; tracking requires contact-proof; verify requires auth).
- Ownership scoping + 404 masking on all personal reads.
- Throttles on abuse-prone endpoints (cart, refunds, tracking, verify, callbacks, webhooks).
- Signed capability URLs for documents/downloads (no session, expiry + signature).
- Preference/device endpoints strictly owner-scoped (device unregister by id + owner check implied — verify on wiring; marked for Phase-13-adjacent confirmation as UNPROVEN detail).
- Review content moderated; refund requests throttled + owner-gated.

## 19. Performance

Catalog reads paginated with indexes + cache layers (`CacheApiResponse`, frontend-cache webhooks); cart/invoice/tracking reads eager-loaded; notification fan-out queued; digital index N+1-guarded; preference checks cached per request lifecycle (standard). No journey-level hot loop identified.

## 20. Tests & Verification

Journey-adjacent suites: `CustomerLifecycleContractTest` (OrderFlow), notification suites (`UserPromotionFlashSaleNotificationTest`, coupon notification queue, FCM paths), digital suites (`DigitalCartCheckoutTest` + delivery tests), review tests, tracking tests (`OrderBroadcastingTest` adjacent), `CartApiTest`, checkout suites. **None executed** (environment). Manual §12.24 file index is stale (line numbers/names predate refactorings).

## 21. Edge Cases

Covered across phases: empty/guest states (auth wall), stale prices/coupons/promotions (revalidated), pending-order resume, zero-value checkout, digital-only carts (no shipping), failed verifications (404/409), tampered invoices (withheld), unresolvable webhooks (ignored-200), refund-after-cancel (marker + CN, no double restore), delivered-digital non-refundable, expired claims (hourly sweep), revoked entitlements on refund.

## 22. Potential Bugs

### Finding F-01
- Severity: MEDIUM
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
Journey manual §§12.2–12.12, 12.15–12.17, 12.23–12.24 describe superseded mechanics: cart reservations/TTL/expiry, EGP-only amounts, MyFatoorah-only payments, cashier QR issuance, public invoice verification (60/min), and stale route line numbers/config values/file references.
#### Evidence
Phase 01–08 drift sections (each verified); `routes/api.php` current layout; Phase 08 F-02 (auth-only verify).
#### Why it matters
Support staff following the journey manual will misdiagnose customer issues (e.g., "cart expired" for what is now a reusable container; "verify without login" for an auth-walled endpoint).
#### Current behavior
Correct product; stale journey reference (especially §§12.2, 12.8, 12.10, 12.17).
#### Recommended future action
Refresh the journey manual's mechanics sections + config/file index (on explicit docs request).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-02
- Severity: LOW
- Type: SECURITY
- Status: STRONGLY REASONED
#### Finding
Public tracking trusts order_number + email/phone match. Order numbers are sequential (`ORD-00000001`…) and contact details are often known to third parties (family members, gift buyers — legitimate; attackers with breached emails — illegitimate). The endpoint is throttled (`throttle:public-tracking`) and access-logged, and returns a scoped projection (not full order/financial data), which bounds but does not eliminate the exposure.
#### Evidence
`OrderTrackingController.php:24-58` (validation + scoped `formatOrderForTracking`/`publicTimeline`); `routes/api.php:139` (throttle).
#### Why it matters
Sequential identifiers + knowledge factors that are widely shared make tracking data quasi-public; the projection scope is the main defense.
#### Current behavior
Scoped projection; throttled; logged.
#### Recommended future action
Confirm the public projection contains no PII beyond what the contact-proof justifies (address truncation, no phone/email echo); consider non-sequential public tracking tokens for new orders.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-03
- Severity: MEDIUM
- Type: TEST GAP
- Status: PROVEN (gap) / UNPROVEN (runtime)
#### Finding
No end-to-end journey test (browse → cart → checkout → pay → track → invoice → notify) was identified; coverage is per-domain. Cross-domain journey regressions (e.g., preference-gating breaking notifications, tracking projection drift) have no single pin. Nothing executed here regardless.
#### Evidence
Test inventory across phases (domain suites catalogued; no journey E2E file found by name search).
#### Why it matters
The customer experience is the composition of all phases; composition breaks where unit contracts meet.
#### Current behavior
Domain-covered; composition-unproven.
#### Recommended future action
Add one journey smoke suite (happy path + preference-gated notification assertion) and execute the domain suites in CI.
> NOTE: This audit is READ-ONLY. No fix was applied.

## 23. Potential Failure Scenarios

- **Preference-gating misfires**: notifications silently suppressed by quiet hours/event prefs — by design; history endpoint provides visibility.
- **Signed URL expiry mid-download**: customer re-issues via my-invoices (no session needed at use).
- **Public tracking abuse**: throttled + logged; projection scoped.
- **Digital asset revoked post-delivery**: entitlement revocation on refund (documented); download links fail closed.
- **Review removed on refund**: Track-A refunds wipe order reviews (Phase 10 F-03) — visible to customers as vanishing reviews.

## 24. Documentation Drift

Journey structure (§§12.1–12.24) matches the product's shape; mechanics inside §§12.2–12.12/12.15–12.17/12.23–12.24 are stale as itemized in F-01. Post-manual additions with no journey-manual home: claims/targeting, flow inputs, multi-gateway, webhooks, zero-value path, WMS tracking, notification center, digital delivery, signed document URLs, coupon discovery.

## 25. Dependencies

- **Depends on**: all commerce phases (01–11) + notification/digital/review/tracking subsystems.
- **Consumed by**: Phase 15 (timeline journey), support runbooks (Phase 14).
- **Shared tables**: per-domain tables (no journey-owned state).
- **Shared services**: per-domain authorities + `NotificationPreferencesController`, `DigitalDownloadController`, `OrderTrackingController`.

## 26. Out of Scope

Frontend implementation, mobile SDK behavior, marketing/CMS content, admin/support tooling, SLA/monitoring of customer-facing endpoints, localization completeness of customer strings.

## 27. Residual Risks

1. Journey manual mechanically stale (F-01).
2. Public tracking quasi-public by design (F-02).
3. No journey E2E pin; nothing executed (F-03).
4. Verify endpoint auth-wall vs auditor needs (Phase 08 F-02, carried).
5. Device-unregister ownership detail unverified (marked UNPROVEN).

## 28. Evidence / Source Files

Manual: `HEAD:docs/production-manual/PHASE-12-CUSTOMER-EXPERIENCE.md` (1144 lines, section-mapped + §§12.1–12.4 read in full, temp extract). Code: `routes/api.php:52-240` (public/authenticated journey surface verified); `OrderTrackingController.php` (public + authenticated + list paths verified); `DigitalDownloadController.php` (owner scope + signed URLs verified); `NotificationPreferencesController` + `UserNotificationPreference` (center verified); device-token routes; review routes + moderation events; `myInvoices`/signed/verify endpoints (Phases 07–08); journey mechanics (Phases 01–11 evidence, cited). Tests (journey-adjacent suites listed, not executed).

## 29. Final Assessment

**Verdict: PASS WITH RESIDUAL RISKS.**

The customer journey is complete, access-controlled, and composed of hardened domain mechanics, with a genuinely complete notification center and digital delivery story added since the manual. It cannot reach PASS because the journey manual misdescribes core mechanics, public tracking is quasi-public by design, and composition-level runtime proof is absent. No blocking defect found in the customer surface.
