# Coupon Complete End-to-End Flow (one story: VIP50, ASSIGNED_AND_TARGETED, and_dynamic, min 5 orders, users 100/200)

What is a coupon? A discount grant with base rules + optional per-user assignments + optional dynamic targeting. Admin creates it (POST /coupons → 201, valid immediately). Admin configures targeting (PUT /targeting → 200, delayed run scheduled) and assigns users 100/200 (POST /assignments → 201 each, immediate `coupon.assigned` to each). Distribution: outbox (available_at +240s / 4min) → sweep → broker → handler (reload + drift check) → candidates (rule users ∪ assigned) → chunks → evaluate → user 100 (5 orders + assigned) eligible → `coupon.eligible`; user 200 (0 orders) not eligible (still grant-notified). User 100: catalog/available → claim (201 ACTIVE, require_claim) → apply (200) → checkout (200 order + 30-min reservation) → COD mark-paid (200) → consumption (used++, assignment.used++, receipt, reservation consumed, claim REDEEMED) → `coupon.used`. Duplicate callback → no-op. Every arrow above is a real endpoint or INTERNAL BACKEND OPERATION labeled in the master doc; failure branches (400/404/409/422/500) documented per endpoint.

ببساطة: الأدمن ينشئ ويظبط، النظام يوزع بعد ١٠ دقايق على المستحقين، العميل يطالب ويطبق ويدفع، والخصم يتسجل مرة واحدة بس.

---

## Admin end-to-end: SAVE20 (§16)

1. `POST /api/v1/coupons` `{name{en,ar}, images, discount:20, discount_type:percentage, max_discount_amount, dates, limiter, status:1}` → **201**, `audience_type:PUBLIC`, `targeting_mode:null`. Changed: `coupons` row + media. Admin knows: code (server-generated) + PUBLIC. Customer knows: nothing yet. Notification: none yet (grace window).
2. `PUT /api/v1/coupons/{id}/targeting` `{mode:assignment_or_dynamic, require_claim:true, rule_tree:{min_orders:5}}` → **200**. Changed: `coupon_targetings` row. Admin knows: `TARGETED`, mode. Customer: nothing. Internal: `CouponTargetingChanged` → delayed run scheduled (240s / 4min).
3. `POST /api/v1/coupons/{id}/assignments` ×2 (Ahmed, Mona; max_uses 2) → **201** each. Changed: 2 assignment rows. Admin knows: `ASSIGNED_AND_TARGETED`. Customers: each gets `coupon.assigned` (immediate, with code + quotas). DB: assignments; event AFTER commit.
4. `POST /api/v1/coupons/{id}/distribute` → **202** run (or 409 if one running). Internal: outbox→broker→evaluate→ Ahmed (5 orders + grant) → `coupon.eligible`; Mona (0 orders, no grant path… has grant → eligible via `or` leg) → `coupon.eligible`. (If mode were `assignment_and_dynamic`, Mona would stay ineligible: grant ✓ but rule ✗.)
5. `GET /api/v1/coupons/{id}` → `audience_type:ASSIGNED_AND_TARGETED`, `targeting_mode:assignment_or_dynamic`. `GET distributions/{run}` → recipient breakdown. `GET usage-info` → capacity explanation.

## Customer end-to-end: Ahmed (§17)

`GET /general/coupons` (REST: sees SAVE20 shell, code hidden) → `GET /available` (REST: eligible ✓) → `GET /mine` (REST: grant visible with code) → `POST /coupons/{id}/claim` (REST → 201 ACTIVE; INTERNAL: claim row) → `POST /coupons/apply` (REST → 200 totals; INTERNAL: orchestrator revalidation) → `POST /checkout` (REST → 200 order; INTERNAL: locked revalidation + snapshot + 30-min reservation) → gateway `callback` (REST → completion; INTERNAL: `PaymentSucceeded` → consumption `used++/assignment.used++`, reservation consumed, claim REDEEMED, `AssignedCouponConsumed` → `coupon.used` push) → duplicate callback (REST → silent no-op). Every REST↔INTERNAL boundary above is labeled in the contract doc.
