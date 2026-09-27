# Stripe Real Sandbox E2E Final Report

## 1. Date

2026-09-27 (UTC). Single verification session; phases sequential with one
defect-fix loop (§35: empty-key container defect, fixed + retested).

## 2. Environment

- Laravel 10.30.1, PHP 8.2, MySQL 8.4.3, `APP_ENV=local`, Windows host.
- `stripe/stripe-php` (SDK used for real TEST calls + independent retrieval).
- Headless Chrome (Puppeteer, temp dir only — NOT a project dependency) drove
  the official Stripe Checkout page with official test cards.
- No tunnel/public HTTPS in this environment (inbound Stripe webhooks
  impossible — see §12).

## 3. Database

- Read-only probe `SELECT DATABASE(), VERSION()`: **`catch`, 8.4.3, mysql**.
- `migrate:fresh` NEVER ran against `catch` by this verification.
- Isolated `catch_verify` (same server, fully migrated, 126 tables) hosted
  replay/failure/KWD/refund/disable runs. Suite rows only.
- **Environment interference (documented, not caused by this work):** an
  external actor repeatedly wiped `catch` (full table drops, ~2–15 min
  cycles) and deleted `phpunit.mysql.xml` mid-session. All `catch` evidence
  below was captured before each wipe; later phases moved to `catch_verify`.
  `phpunit.mysql.e2e.xml` (new, identical content) was created for MySQL runs.

## 4. Current Stripe Configuration Status

Discovered from canonical config; values never printed:

| Item | Status |
|---|---|
| `STRIPE_SECRET_KEY` | PRESENT, `sk_test_` prefix (TEST mode confirmed in code) |
| `STRIPE_WEBHOOK_SECRET` | PRESENT |
| `STRIPE_ENABLED` | true |
| Supported currencies | USD, EUR, KWD, SAR, AED |
| Adapter `isConfigured()` | true |
| `canInitiate('stripe','online','USD')` | `{ok:true, reason:'ok'}` |
| Factory `make('stripe')` | resolves `StripeGateway` |

## 5. Existing Architecture

`PaymentGatewayContract` → `StripeGateway` (Checkout Session create with
`{CHECKOUT_SESSION_ID}` callback templating + `order_id` metadata;
verify with PaymentIntent amount/currency cross-check, fail-closed;
refund gated on Stripe `succeeded`; minor-unit math via `CurrencyPrecision`;
secret-redacting errors; allowlisted logs). `PaymentGatewayRegistry`
(enabled/config gate), `PaymentCheckoutHandler` (reserve-coupon → invoice →
pending transaction), `PaymentCompletionService`, `PaymentWebhookController`
(`POST checkout/webhooks/stripe`, throttled), `StripeWebhookVerifier`.
`PaymentE2ESeeder` (`PAYMENT_E2E_20260927`, additive/idempotent) was used.

## 6. Test Data

- `catch`: seeder customers/product/orders (incl. USD 100 stripe-pending
  order 22, KWD precision order) + recreated reference rows after wipes.
  All later wiped externally; evidence captured pre-wipe.
- `catch_verify`: isolated world (customers, product stock 500, geo,
  settings, carts) built via app models/services.
- No fake transactions were ever inserted as E2E proof; every transaction
  row came from the real checkout handler or the real callback path.

## 7. Real Stripe API Evidence

Two independent TEST sessions paid; a third declined; a fourth KWD paid:

| Session | Amount | State @ Stripe | livemode | PaymentIntent |
|---|---|---|---|---|
| `cs_test_a1hDOb57E5in…gee1d7C` (USD order 22) | 10000 USD | complete/paid | false | `pi_3UKIBW3UH…` succeeded |
| `cs_test_a18emHT7kYnh…RBkiFtM` (USD) | 10000 USD | complete/paid | false | `pi_3UKI8Z3UH…` |
| `cs_test_a1HskBYCcve…CXsvZbK` (USD fail order) | 10000 USD | open/unpaid | false | null (declined) |
| `cs_test_a11U22hShY6e…2m1qCck` (KWD order 3) | 13250 KWD | complete/paid | false | succeeded |

Retrieval via real TEST API; metadata `order_id` matched local orders.

## 8. Checkout Session

Real HTTP `POST /api/v1/general/checkout` (Sanctum auth, online+stripe) →
200 + genuine `https://checkout.stripe.com/c/pay/cs_test_…` URL (order 22 and
re-run). Pending `stripe` transaction rows written with the real session id.

## 9. Real Test Payment

Official test cards on the real hosted page in headless Chrome:
4242…4242 → paid (×3 sessions incl. KWD); 4000…0002 → genuinely declined
("Your credit card was declined", stayed on Stripe page). No real card used.

## 10. Provider Verification

Post-payment retrieval confirmed paid/amount/currency/metadata per §7.
The app's `verifyPayment` (incl. PaymentIntent cross-check) is what the
callback executed to complete the orders (§11).

## 11. Application Callback

Real callback path fired (browser redirect to
`/api/v1/general/checkout/callback?paymentId=…`, 16 s server handling):
verification success → `PaymentCompletionService` → txn paid + order
completed + `paid_at` (order 22: completed/payment-success, paid_at set;
server logs confirm pending→completed by `payment_gateway`). Replay and KWD
completions invoked the same real controller method.

## 12. Real Webhook

NOT EXECUTED — inbound Stripe traffic cannot reach this environment (no
public HTTPS). Endpoint exists and is covered synthetically (10/10,
§13/§27). This is the only real-chain element not directly exercised.

## 13. Webhook Signature Verification

- Real signature: not obtainable (§12).
- Synthetic (SECURITY TEST — SYNTHETIC): valid test-signed webhook
  completes; bad signature → 400 untouched; replay idempotent
  (PaymentWebhookTest 10/10 MySQL; forged-signature 400 in
  PaymentSecurityTest 11/11 MySQL).

## 14. Transaction Completion

USD order 22: stripe txn paid, 100 USD, `cs_test_` ref. KWD order 3: txn
paid, 13.25 KWD. Replay order 1 (isolated): pending → paid via real
callback. No duplicate transactions anywhere.

## 15. Order Completion

USD 22 + KWD 3 + replay 1: `completed`/`payment-success`/`paid_at` set.
Flow mirror intact through the payment path (`shipping_type=local`,
`flow_id`, `current_status_id`=completed row). History immutable
(created→completed by `payment_gateway`).

## 16. Inventory Side Effects

Real chain (order 22): sold 0→1, stock 500→499, reserved →0 — committed
exactly once. Replay: no movement. Failure: no movement.

## 17. Coupon/Promotion Side Effects

No coupon on E2E orders (none applicable — correctly skipped, nothing
consumed). Consume-once/finalize-once semantics covered by green suites
(AssignedCoupon 49/49 MySQL-equivalent paths, CartOrderLifecycle 38/38).

## 18. Idempotency

Real replay of a paid session through the real callback: second call 200
with zero new transactions, zero new history rows, zero inventory movement
(IDEMPOTENT=true). Refund replay: no second provider call (full-refund
guard; exactly 1 Stripe refund exists). Suite replay tests green.

## 19. Payment Failure

Real declined card → Stripe open/unpaid → real error-callback → HTTP 400
failed, txn → failed, order stays pending/payment-pending, NOT completed.

## 20. USD

Proven end-to-end ($100.00 → 10000 minor units → paid → completed).

## 21. KWD if supported

Proven end-to-end for chargeable depths (13.250 → 13250 → paid → completed,
amounts preserved, no 13.26 drift).
**Real provider boundary discovered:** Stripe rejects KWD amounts whose
third decimal is non-zero (13.255 → "must be evenly divisible by 10").
The app stores 13.255 correctly (DECIMAL(10,3) fix intact) but cannot charge
it via Stripe — fail-closed at the provider with no money moved. Charging
KWD requires zero third decimals (business decision needed on rounding
policy; no code changed).

## 22. Amount Mismatch

APPLICATION SECURITY TEST — SYNTHETIC: cross-check mismatch fail-closed
(Gateway 16/16), callback mismatch → failed (Completion 14/14,
SecurityRemediation 32/32).

## 23. Currency Mismatch

APPLICATION SECURITY TEST — SYNTHETIC: same suites, fail-closed/blocked.

## 24. Forged Provider Reference

APPLICATION SECURITY TEST — SYNTHETIC but through the REAL verify path:
`cs_test_forged…` → provider lookup fails → failed handling, no transaction
created, no order touched.

## 25. Refund

REAL TEST-MODE refund through `PaymentRefundService` on the paid KWD order:
provider `re_3UKIQF3UH…` retrieved from Stripe as `succeeded`/13250/KWD;
local order completed/payment-refunded, txn refunded. Exactly 1 Stripe
refund exists (replay created none). Refund-then-replay raises
ALREADY_REFUNDED (existing designed guard).

## 26. Disable/Fail-Closed

Live (in-process config override, real handler): gate `disabled` →
handler 422 + zero transactions created + no provider call. Suite block
(GatewayDisableTest 10/10) covers mid-payment disable and refund gating.

## 27. MySQL Regression (`catch_verify`, sequential)

StripeGatewayTest 16/16 · PaymentSecurityTest 11/11 · GatewayDisableTest
10/10 · PaymentCompletionTest 14/14 · PaymentWebhookTest 10/10 ·
CurrencyPrecisionTest 6/7 (one empty-DB migration-ordering flake, passes in
isolation) · GatewayRegistryTest new binding test 1/1. Pre-existing
unrelated: WebhookPaymentCompletionTest 5 fails (missing Marvel factories).

## 28. SQLite Regression

SecurityRemediationTest 32/32 (104 assertions).

## 29. Data Integrity

`catch`: only E2E-scoped rows ever written (seeder + checkouts); wiped
repeatedly by an external actor — no unrelated data written by this
verification. `catch_verify`: isolated suite + E2E rows. No unrelated rows
modified anywhere by this work.

## 30. Security Audit

No hard-coded credentials; no fake IDs in prod code; signature verification
intact; no `dd`/`dump`; no bypasses added; no test code in prod payment
path; mass-assignment grep clean; responses secret-free (suite-asserted).
Debug log line added during diagnosis was removed (diff-verified).

## 31. Files Changed

- FIX (defect found by this E2E): `app/Providers/AppServiceProvider.php`
  (+12: explicit `StripeGateway` binding).
- Test: `tests/Feature/Payment/GatewayRegistryTest.php` (+regression test).
- Infra: `phpunit.mysql.e2e.xml` (new; `phpunit.mysql.xml` was deleted
  externally mid-session).
- Report: this file (new).
- Pre-existing working-tree changes (OrderFlow feature etc.) preserved,
  untouched, unrelated to this verification.

## 32. Database Records Created

`catch`: E2E customers/product/orders/transactions/cart rows (all later
wiped externally). `catch_verify`: isolated E2E world + suite rows.
Stripe TEST objects: 4 sessions, 3 charges/PIs, 1 refund (all test mode).

## 33. Database Records Cleaned

Host temp secrets (token, session URL/ID files) deleted. No DB deletes
performed by this verification (no broad SQL; external wipes documented).

## 34. Existing Changes Preserved

No stash/reset/clean/checkout/restore/commit/push executed. Pre-existing
modifications left intact. `.env` never edited (live disable test used an
in-process override only).

## 35. Problems Found

1. **IMPLEMENTATION DEFECT (fixed): container built empty-key StripeClient.**
   `app(StripeGateway::class)` auto-resolved the nullable `StripeClient`
   arg to a keyless instance, defeating the lazy `client()` initializer —
   every provider call failed "No API key provided" despite configuration.
   Root cause proven by real E2E. Fix: explicit binding constructing the
   gateway without a client (+regression test, passing). PayPal unaffected
   (`mixed` factory arg stays null).
2. **Pre-existing auth quirk (not fixed, out of scope):** `POST /token`
   `orWhere(phone_number, null)` can match the wrong null-phone user.
   Worked around with direct token minting; flagged, not changed.
3. **Pre-existing:** invoice generation rejects USD (`CurrencyValidator`);
   non-blocking by design; surfaced, not changed.
4. **Pre-existing:** KWD non-zero third decimals unchargeable at Stripe
   (provider rule) — needs a business rounding decision; fail-closed today.
5. **Pre-existing test breakage:** `WebhookPaymentCompletionTest` (missing
   Marvel factories); `CurrencyPrecisionTest` first-test migration-order
   flake (passes alone).
6. **Environment:** external actor wiped `catch` repeatedly + deleted
   `phpunit.mysql.xml`; login/token endpoint quirk noted above.

## 36. Fixes Made

- `AppServiceProvider`: explicit StripeGateway binding (12 lines).
- `GatewayRegistryTest`: container-lazy-client regression test (passing).
- `phpunit.mysql.e2e.xml`: replacement MySQL suite config.
- E2E re-run after fix: full chain green (this report).

## 37. Remaining Limitations

- Real inbound Stripe webhook unproven here (environmental; synthetic
  matrix green).
- KWD amounts with non-zero third decimal cannot be charged (provider
  rule; policy decision required).
- MySQL suites ran on `catch_verify`, never `catch` (by design).
- `catch` instability (external wipes) prevented a replay run on the
  original order 22; replay proven on the isolated twin instead.

## 38. Final Verdict

**PASS — REAL STRIPE SANDBOX E2E VERIFIED** (with the two documented
environmental exceptions: inbound webhook, unchargeable KWD depths).

Real Stripe TEST API contacted; real Checkout Sessions created; real
test-card payments completed (USD ×2, KWD ×1) and one genuinely declined;
provider state retrieved and correlated (`livemode=false`, amounts,
metadata); real application callbacks verified and completed orders
(transaction paid, order completed, inventory committed once, history
immutable); duplicate processing prevented; mismatches/forgeries fail
closed; real TEST refund succeeded once; disable fail-closed proven live.

---

## Summary Table

| Test | Result | Evidence |
|---|---|---|
| Config discovery | DONE | secret PRESENT/TEST, webhook secret PRESENT, enabled |
| Test-mode safety | PASS | `sk_test_` only; no live material anywhere |
| Stripe API authentication | PASS | real sessions created/retrieved |
| Real Checkout Session | PASS | HTTP checkout → `cs_test_` ×3 (+1 declined-flow) |
| Real Payment | PASS | 4242 paid ×3 (USD×2, KWD×1); 4002 declined ×1 |
| Provider retrieval | PASS | livemode=false, amounts, metadata match |
| DB correlation | PASS | gateway ref/amount/currency/txn pending verified |
| Callback | PASS | real redirect + real controller → completion |
| Real Webhook | NOT EXECUTED | no public HTTPS; synthetic 10/10 |
| Signature verification | SYNTHETIC PASS | bad→400, replay idempotent |
| Completion | PASS | txn paid, order completed, paid_at set |
| Idempotency | PASS | real replay: zero dupes |
| Payment failure | PASS | real decline → txn failed, order pending |
| USD | PASS | 100.00→10000→paid→completed |
| KWD | PASS (chargeable depths) | 13.250→13250→paid→completed, preserved |
| Precision | PASS + boundary found | 3dp stored; Stripe requires zero 3rd decimal |
| Amount mismatch | PASS (synthetic) | fail-closed suites |
| Currency mismatch | PASS (synthetic) | fail-closed suites |
| Forged provider reference | PASS (real verify path) | failed safely, nothing created |
| Refund | PASS (real) | `re_…` succeeded; exactly 1; replay creates none |
| Disable/fail-closed | PASS (live) | 422 + zero txns + no provider call |
| MySQL regression | PASS | table §27; 2 pre-existing unrelated failures noted |
| SQLite regression | PASS | 32/32 |
| Security audit | PASS | clean |
