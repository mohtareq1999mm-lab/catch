# PAYMENT_SYSTEM_MASTER_TODO.md

> Source of truth for the Master Payment System program (§55). Phase-gate rule (§56):
> no phase VERIFIED without real evidence (§57). Statuses: PENDING | IN_PROGRESS | PASS |
> FAIL | BLOCKED | NOT RUN. (Re-created 2026-09-26 after unrelated workspace cleanup removed
> the original; content reflects final verified state.)

## PHASE 0 — Discovery — PASS

Mapped live path (checkout→handler→factory→gateway→callback), currency authority gap
(effective vs catalog), Marvel legacy dead-ends (no SDKs installed), settings/DB/routes/tests.
Outputs: `PAYMENT_DISCOVERY_REPORT.md`; routes verified live (`route:list --path=checkout`).

## PHASE 1 — Architecture — PASS

- P1-01 contract +`code()`/`isConfigured()`, additive; MyFatoorahGateway implements.
- P1-02 `PaymentCompletionService::completeLocked` extracted; callbacks delegate; factory seam kept.
- P1-03 duplicate-hold policy (D-06): `duplicate_hold` + `payment_reconciliation_results`.
- Proof: `PaymentCompletionTest` 13/13.

## PHASE 2 — Currency — PASS (D-01 catalog-always)

- `PaymentCurrencyResolver` (sole payment reader), snapshot `currency_code` = catalog,
  `CurrencyPrecision` (3dp BHD/JOD/KWD/OMR/TND else 2dp), base nowhere in payment path.
- Proof: `CatalogCurrencyAuthorityTest`, `CurrencyPrecisionTest`, currency suites.

## PHASE 3 — Gateway Settings — PASS (D-04 settings.options)

- `GatewaySettingsService` (config + options merge, read/write allowlist), admin GET/PUT
  (`view/update-settings`), secrets env-only, disable-blocks-initiate-not-verify.
- Proof: `GatewaySettingsAdminTest` 10/10 + §46 `GatewayDisableTest`.

## PHASE 4 — Gateway Registry — PASS

- `PaymentGatewayRegistry` (resolve/canInitiate/canVerify) + factory delegation + §14 chain.
- Proof: `GatewayRegistryTest` 9/9.

## PHASE 5 — Canonical Completion — PASS (D-05 zero-value w/o gateway)

- `completeLocked` + outcomes + mismatch/coupon exceptions; zero-value branch.
- Proof: `PaymentCompletionTest`.

## PHASE 6 — MyFatoorah Repair — PASS (live rows BLOCKED, no creds)

- Refund positive-status validation; allowlisted `gateway_response`; preserved core.
- Proof: refund matrix + PII tests.

## PHASE 7 — Stripe — PASS mocked (sandbox BLOCKED, no keys)

- `StripeGateway` (Checkout Sessions, ISO-4217 minor units, KWD×1000, zero-decimal list,
  PI cross-check, succeeded-only refunds). SDK `stripe/stripe-php v13.1.0`.
- Proof: `StripeGatewayTest` 16/16.

## PHASE 8 — PayPal — PASS mocked (sandbox BLOCKED, no creds)

- `PayPalGateway` (CAPTURE intent, capture-on-verify + idempotency + already-captured recovery,
  COMPLETED-only refunds). SDK `srmklive/paypal 3.0.19`. Legacy Marvel class untouched (dead).
- Proof: `PayPalGatewayTest` 17/17 + `PayPalCaptureIdempotencyTest`.

## PHASE 9 — Webhooks/Callbacks — PASS

- `PaymentWebhookController` (Stripe HMAC + PI→session resolution; PayPal SDK verify +
  external-refund ledger path), `throttle:payment-webhook`, event-id dedupe cap 20,
  MyFatoorah callback-only by design.
- Proof: `PaymentWebhookTest` 10/10 + `StripeFailureCorrelationTest` + `ExternalRefundTest`.

## PHASE 10 — Refunds — PASS

- `PaymentRefundService` + `POST /api/v1/admin/payments/{order}/refund` (`payments.refund`):
  paid-minus-ledger cap 100, txn-currency auth, idempotency ledger, full-refund side effects.
- Proof: `GatewayDisableTest` + `RefundCurrencyAndLedgerTest`.

## PHASE 11 — Manual Payments — PASS

- `payments.mark_paid` on mark-paid routes; F-1 authority gate in `changeOrderStatus`
  (unpaid + authed + completing − perm → 422), incl. legacy Marvel PATCH path;
  `payments.verify`/`reconcile` seeded; actor/reason audit. Deploy: seed `PermissionSeeder`.
- Proof: `MarvelStatusAuthorityTest`, flipped `MarvelStatusReachabilityTest`.

## PHASE 12 — Security — PASS

- `PaymentSecurityTest` 11/11 (forgery, replay, invalid gateway, IDOR, mass assignment,
  leakage scan, tamper fail-closed). Mark-paid-any-order allegation proven not-a-defect
  (no shop ownership column; dedicated permission IS the boundary + audit).

## PHASE 13 — Concurrency/Idempotency — PASS

- `PaymentConcurrencyTest` 9/9 (dual callbacks, callback+webhook race, disable window,
  coupon-leak defect found+fixed). True parallel load NOT run (documented residual).

## PHASE 14 — Full Test Matrix — PASS (mocked; live rows BLOCKED)

- See `PAYMENT_TEST_MATRIX.md`. Payment dir **149 passed**.

## PHASE 15 — Integration/Sandbox — BLOCKED

- BLOCKED — LIVE PROVIDER VERIFICATION UNAVAILABLE (no creds for any provider).

## PHASE 16 — Regression — PASS WITH DOCUMENTED PRE-EXISTING FAILURES

- Green: Payment 145, Currency 190, AdminOrder 58, CartLifecycle 38, CheckoutApi 13,
  PendingRedesign 16, AssignedCoupon 49, CouponSystem 23, GiftRecon 8.
- Pre-existing (verified cause, untouched files): legacy hand-schema suites missing newer
  tables; `LogActivityJob` signature drift (RateMode/MandatoryGate 5); view drift
  (WebhookPaymentCompletionTest). Legacy F-1-contract tests updated to hardened contract.

## PHASE 17 — Production Readiness — PASS WITH DEPLOY NOTES

- Logging w/o secrets, throttles, reconciliation job + rows, rollback = revert (no destructive
  migrations). Notes in `PAYMENT_GATEWAY_SETTINGS.md`.

## PHASE 18 — Final Audit — COMPLETE + FORENSIC RE-AUDIT

- `PAYMENT_ARCHITECTURE.md`, `PAYMENT_FLOW.md`, `PAYMENT_GATEWAY_SETTINGS.md`,
  `PAYMENT_TEST_MATRIX.md`, `PAYMENT_SYSTEM_FINAL_AUDIT.md` (rewritten to §56 structure).
- Forensic fixes with measured proof: F-AUDIT-01 (1213 deadlock → unified Txn→Order locks,
  both-commit re-proven); F-AUDIT-02 (Marvel approval claim + cap + shared ledger,
  `MarvelRefundInterplayTest` 4/4; marketplace-schema gap escalated, fails safely).
- MyFatoorah sandbox connectivity + key acceptance LIVE-VERIFIED (read-only probe).
- Payment dir: **149 passed**. Git: no commits/resets; 71 doc deletions BLOCKED — INTENT
  UNKNOWN (all in HEAD, restorable); stash untouched; no payment migrations added.
- **Final: PASS WITH DOCUMENTED NON-BLOCKING LIMITATIONS** (live Stripe/PayPal + full
  MyFatoorah matrix credential-blocked).

## Decisions (user-approved 2026-09-26)

D-01 catalog-always | D-02/D-03 install SDKs + sandbox-when-keys | D-04 settings.options |
D-05 zero-value completes w/o gateway | D-06 duplicate reconcile-hold.
