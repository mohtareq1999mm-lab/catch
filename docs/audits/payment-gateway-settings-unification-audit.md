# Payment Gateway Settings Unification — Site Settings Authority (Stripe, MyFatoorah, Tap, Tabby, Tamara)

Date: 2026-10-31
Scope: existing Site Settings architecture extended to expose and enforce per-gateway enable/disable for the five online payment gateways. No new settings tables, no second settings system, no new registry.

Marker legend: **[VERIFIED]** read directly from source at the cited path/line · **[TESTED]** proved by a named, executed test in this report · **[INFERRED]** supported by multiple signals but not directly exercised · **[UNKNOWN]** insufficient evidence · **[RISK]** open exposure with documented mitigation or acceptor.

---

## 1. How Stripe settings worked before (and now)

- `config/payment.php:26-39` defines the `stripe` block: `enabled => env('STRIPE_ENABLED', false)`, `secret_key`, `webhook_secret`, `supported_currencies`, `methods: ['online']`. Secrets stay env-only. **[VERIFIED]**
- Admin toggles already persisted via `settings.options['payment_gateways']['stripe']['enabled']` through `PUT /api/v1/admin/payment-gateways/stripe` (`app/Http/Controllers/Api/Admin/PaymentGatewaySettingsController.php::update`) with `UpdateGatewaySettingsRequest` (strict `boolean` on `enabled`, `app/Http/Requests/Payment/UpdateGatewaySettingsRequest.php:26`). **[VERIFIED]**
- `GatewaySettingsService::merged()` merges config with the allowlisted override `{enabled, display_name, sort_order}` only (`app/Services/Payment/GatewaySettingsService.php:243-250`); read allowlist protects against hostile rows. **[VERIFIED]**
- `PaymentGatewayRegistry::canInitiate` gates order: **enabled first** (`PaymentGatewayRegistry.php:57-59`), then configured (`:86-88`), then method (`:90-93`), then currency (`:95-103`). Stripe rejects new initiation when disabled **[TESTED]** (`GatewaySettingsEnforcementTest::stripe_cannot_start_when_disabled_and_stays_rejected_when_unconfigured`), even with valid credentials — the §5 "site=false beats credentials" rule for the gateway that is disabled by default. **[VERIFIED]**

## 2. How MyFatoorah settings worked before (and now)

- Same storage shape: `config/payment.php:16-29` (`MYFATOORAH_ENABLED` default `true`), same `payment_gateways` overrides. **[VERIFIED]**
- Disable persists and re-blocks/re-allows initiation via admin API + merged service **[TESTED]** (`GatewaySettingsAdminTest::disable_myfatoorah_persists_and_blocks_initiate_then_reenable_restores`).
- Handler-level rejection with no transactions **[TESTED]** (`GatewayRegistryTest::disabled_myfatoorah_blocks_initiate_and_handler_without_transactions`, `GatewaySettingsEnforcementTest::disabling_each_mena_gateway_rejects_handler_initiation_with_no_transaction` — the loop includes myfatoorah).
- Mid-payment completion protected after disable **[TESTED]** (`GatewayDisableTest::disable_mid_payment_still_completes_then_new_initiation_422`).
- Enabled-but-unconfigured (`api_key=null`, currency pinned to include the USD catalog → the ONLY false operand) **[TESTED]** (`PublicPaymentGatewaysTest::enabled_gateway_without_credentials_stays_unavailable`); the mirrored currency-only case (credentials OK, currency outside the list → `available=false`) **[TESTED]** (`PublicPaymentGatewaysTest::enabled_configured_gateway_outside_catalog_currency_stays_unavailable`); `configured` stays internal and was proven through the admin view inside the latter test.

## 3. How Tap, Tabby, Tamara were integrated

All three were **already registry members before this task** (committed Tap; uncommitted Tabby/Tamara work in the same settings architecture): `config/payment.php:61-72` (tap), `:88-100` (tabby), `:130-159` (tamara); all `methods: ['online']`; all Secrets env-only (`tamara` api/notification tokens, `tap/tabby` secret_key). **[VERIFIED]**

This task adds, on the shared architecture (§1/§2, zero new code paths beyond the public view + cache flush):

1. `GatewaySettingsService::getPublicView` (`app/Services/Payment/GatewaySettingsService.php:119-157`) now returns `enabled` and `available` per gateway — this is how Tap/Tabby/Tamara (and the other two) reach the website. **[VERIFIED]** + **[TESTED]** (three new tests in `PublicPaymentGatewaysTest`, field-drift pin updated, assertion updated).
2. Admin enable toggles for the three **[TESTED]**: independent toggles (`GatewaySettingsAdminTest::enabled_toggles_are_independent_across_gateways`), enforcement (`GatewaySettingsEnforcementTest`: disabling → handler 422 with zero transactions; enabled-but-unconfigured → `misconfigured`; enabled+configured+supported-currency → `ok`; verify path keeps working while disabled), admin persistence round-trip through settings internals is pinned by the same file.
3. Checkout currency rules respected (tap: AED…USD broad list incl. KWD; tabby: **AED/SAR only**; tamara: SAR/AED/BHD/KWD/OMR) **[TESTED]** (`GatewaySettingsEnforcementTest::enabled_and_configured_gateways_pass_the_registry_gate_when_currency_supported` — tabby KWD → `currency_unsupported`, tabby SAR → `ok`).

No eligibility, pricing, invoice or lifecycle logic was duplicated or bypassed for any of the three: initiation/eligibility still runs inside `PaymentCheckoutHandler::handleOnlinePayment` (`app/Services/Payment/PaymentCheckoutHandler.php:37-53` gate; `:72-78` second, adapter-level currency check) and per-provider `isEligible` remains checkout-time (Tamara fail-open by design, `app/Services/Gateway/TamaraGateway.php:935-943`). **[VERIFIED]**

## 4. Final Site Settings structure

Single source of truth (no new storage):

```text
DB row `settings.options` (JSON; select on Settings::first())
  └─ payment_gateways[code] = { enabled: bool, display_name?: string, sort_order?: int }
       merged at READ time over config/payment.php gateways by GatewaySettingsService
       write path: PUT /api/v1/admin/payment-gateways/{code} → updateOverride() (lockForUpdate transaction)
  └─ also exposed to the website raw by SettingResource ('options' => ...,
     packages/marvel/src/Http/Resources/SettingResource.php:82) — includes payment_gateways settings keys, no secrets
```

- `enabled` semantics: `(bool)` merged value; missing key falls back to config default (`GatewaySettingsService::isEnabled`, `:38-47`; `getAdminView` `:91-99`). **[VERIFIED]**
- Updating one gateway cannot leak or reset others: write path edits only `[code]` (`:173-214`) **[TESTED]** (independence test above); configs themselves are untouched (allowlist).
- Backward compatibility: no migration, no default reset; existing env-backed behavior unchanged. **[VERIFIED]**

## 5. Dashboard changes

- The admin dashboard UI is NOT in this repository (storefront is external; `resources/js` has no gateway UI — repo scan empty). **[VERIFIED-NEGATIVE]**
- Task §4 is satisfied by the existing, tested admin API consumed by the Site Settings dashboard: `GET /api/v1/admin/payment-gateways` (rows incl. `enabled/configured` + per-gateway `supports_catalog_currency`, no secrets) and `PUT .../{code}` (`[TESTED]` full authorization matrix in `GatewaySettingsAdminTest`: 401 unauthenticated, 403 user without `view/update-settings` perms or a plain customer, 404 unknown code, 422 invalid Boolean / oversized display_name / non-int sort_order).
- No secrets in forms or responses **[TESTED]** (`responses_never_contain_secrets_and_secrets_are_never_stored`; canary injection attempt).

## 6. General API changes

- Endpoint unchanged: `GET /api/v1/general/payment-gateways` (`routes/api.php:118-119`; controller `app/Http/Controllers/Api/General/PaymentGatewayController.php`). **Extended response**: each gateway row is now `{code, display_name, enabled, available, supported_currencies, supports_catalog_currency}`. There is still no second endpoint; the response contract change is additive (two new boolean fields, order-stable). **[VERIFIED]** + **[TESTED]** (`PublicPaymentGatewaysTest::gateways_expose_only_public_fields_and_no_secrets` pins exact key set and rejects secret canaries).
- Website settings payload (`GET /api/v1/general/settings`) continues to deliver raw `options` incl. `payment_gateways` via `SettingResource` **[VERIFIED]** + **[TESTED]** (`GatewaySettingsAdminTest::site_settings_cache_is_flushed_so_website_sees_the_toggle`).
- Disabled/unconfigured gateways are still LISTED (with `available=false`) so the frontend can grey them out — retention pinned by the updated `gateway_order_matches_admin_view_and_lists_disabled_gateways` test. **[TESTED]**
- Arabic/English labels: gateway `display_name` values come from the same i18n conventions as before; `payment_methods` localized block unchanged **[TESTED]** (`payment_methods_are_fixed_and_localized` incl. Arabic assertions).

## 7. Frontend checkout changes

- No frontend code exists in this repo to modify (§5). The backend response (§6) is the integration surface: the storefront reads `data.gateways[]` — display `available=true` only, hide/grey `false`. **[INFERRED — frontend work happens out-of-repo; contract delivered and tested here.**
- The HTML/JS escaping caveat for admin-editable `display_name` is documented in the controller docblock. **[VERIFIED]**

## 8. Effective availability calculation

```text
available (display layer, getPublicView) = enabled && configured && supports_catalog_currency
initiation (authority, canInitiate)      = enabled && configured && method ∈ ['online'] && supportsCurrency(currency)
                                            [+ per-provider eligibility at order time, e.g. Tamara isEligible]
```

- `enabled`/`configured` derive from the same merge; `configured` via `isAdapterConfigured($base)` fail-closed (`GatewaySettingsService.php:286-309`). **[VERIFIED]**
- Divergence between the display snapshot and the order moment fails closed at checkout (422, `PAYMENT_GATEWAY_UNAVAILABLE` or `PAYMENT_CURRENCY_UNSUPPORTED`). **[TESTED]**
- Missing credentials ⇒ `available=false` shown even when enabled **[TESTED]** (myfatoorah case above; stripe `misconfigured` reason tested).
- Unsupported currency ⇒ `available=false` where catalog currency is outside the gateway list **[TESTED]** (tamara/tabby/myfatoorah USD-catalog rows in `GatewaySettingsAdminTest::list_shape_sorted_and_catalog_support_flags`; tabby KWD rejection at registry level).

## 9. Backend initiation enforcement

- Every online initiation funnels through `PaymentCheckoutHandler::handleOnlinePayment` (called from `OrderController:199` and `FastShippingController:67`); the registry `canInitiate` gate is the single authority and manual gateway codes cannot bypass it — the handler reads the merged settings row fresh on every call. **[VERIFIED]**
- Handlers reject with 422 and no transaction rows for disabled gateways **[TESTED]** for myfatoorah/tap/tabby/tamara (three suites cited above; transactions count assertion included) and for the never-enabled stripe (registry level; stripe is default-disabled).
- Refund initiation is separately fail-closed: `PaymentRefundService` re-implements an equivalent gate inline (definition exists → `isEnabled` → factory make → `isConfigured`, app/Services/Payment/PaymentRefundService.php ~L200-219) before its provider call — semantically aligned with `canInitiate` today, but a mechanism-drift risk if `canInitiate` gains new checks later (see §16). The webhook-side external-refund record path is deliberately ungated. **[VERIFIED]** by source read; full refund-suite runs are blocked by the pre-existing route gap below.
- **Zero-value exemption (pre-existing, D-05)**: zero-amount online orders bypass `canInitiate` (no provider call, no money movement; `app/Http/Controllers/Api/General/OrderController.php:193-197, 213-240`). A disabled gateway can therefore still name a completed FREE order. This task neither widens nor relies on that exemption; any frontend copy saying "disabled ⇒ no order can complete" is wrong for the zero-amount case. **[VERIFIED]**

## 10. In-flight transaction behavior (§9 requirement)

- The initiator/verifier split is structural: `canInitiate` consults `enabled`; `canVerify`/`resolve`/`PaymentGatewayFactory::make` deliberately ignore it (`PaymentGatewayRegistry.php:108-119`, factory docblock `app/Services/Payment/PaymentGatewayFactory.php:10-20`). **[VERIFIED]**
- End-to-end mid-payment disable: myfatoorah completes, new initiation 422 **[TESTED]** (`GatewayDisableTest::disable_mid_payment_still_completes_then_new_initiation_422`).
- Verify-path immunity while disabled **[TESTED]** for tap/tabby/tamara: `canVerify=true`, `resolve()` returns the adapter, `factory->make()` succeeds with the settings row carrying `enabled=false` (`GatewaySettingsEnforcementTest::verify_path_keeps_working_while_disabled`). **[TESTED]**
- Webhook handlers, capture, refund and reconciliation never consult the enabled flag by construction (factory/registry paths only). **[VERIFIED]** + **[TESTED]** indirectly: `TamaraWebhookTest` 16 tests, `TapWebhookTest` 14 tests, `PaymentWebhookTest` 10 tests, `Gateways` flow tests — all green (§14). A dedicated literal "disable gateway then run webhook" test for tap/tabby/tamara was NOT added; see UNKNOWN.
- **[RISK]** Refund regression status is currently blocked by a PRE-EXISTING tree condition, not this task:
  - The admin refund endpoint `POST /api/v1/admin/payments/{order}/refund` **was once shipped** (added in commit `d3f6e0a` as the F-1 block `Route::prefix('v1/admin/payments')` + `Route::post('{order}/refund', PaymentRefundController::class)` and **removed** in commit `92a8eb8` "refactor: remove unused admin analytics routes and clean up fulfillment-related services" — a cleanup that deleted a live payment route block and orphaned the committed refund tests in `GatewayDisableTest`). `app/Http/Controllers/Api/Admin/PaymentRefundController.php` exists nowhere today (HEAD nor working tree).
  - `app/Services/Payment/PaymentRefundService.php` EXISTS at HEAD (blob `67c44bc`); the working tree carries uncommitted modifications to it from the unfinished refund session.
  - Refund-family tests in `GatewayDisableTest` (7), `TamaraWebhookTest`, `TabbyWebhookTest`, `TabbyGatewayFlowTest` fail identically with this task's three production files reverted to HEAD (A/B verified twice, restored byte-identical). Not touched per the "no unrelated payment lifecycle changes" rule; the owners of `92a8eb8`/the refund session must restore the route + reconcile the tests.
  - The full-file sequential `GatewayDisableTest` run additionally shows 1205 lock-timeout cascades on currency seeding; in isolation a single refund test shows only the plain 404 — the cascade is a sequential-run artifact of this tree state, reproducible without this task's changes.

## 11. Cache invalidation

- `GatewaySettingsService` reads `Settings::first()` uncached on every merge — checkout and the public gateway endpoint get the freshest effective settings without any cache layer. **[VERIFIED]**
- The only tagged-cached consumer is `GET /api/v1/general/settings` (`SettingController` → `HasCache::remember(FrontendResource::SETTINGS, md5(url))`). This task adds `flushTag(FrontendResource::SETTINGS)` to admin gateway updates (`app/Http/Controllers/Api/Admin/PaymentGatewaySettingsController.php:70-75`), mirroring the marvel settings update convention (`packages/marvel/src/Http/Controllers/SettingsController.php:99`). **[VERIFIED]** + **[TESTED]** (`GatewaySettingsAdminTest::site_settings_cache_is_flushed_so_website_sees_the_toggle` — primed cache reflects both directions of the toggle).
- Legacy `cached_settings_{locale}` model-cache (24h, documented defect, pinned by `SettingsRegressionTest` 10/20 PASS with this task) is intentionally NOT re-touched; new gateway controls do not rely on it. **[VERIFIED-NEGATIVE]**
- Note on stores: redis (prod) and array (phpunit) support native tags; file/database go through the pre-existing `HasCache` registry fallback (`app/Traits/HasCache.php:94-113`), proven live by `FrontendCachePayloadTest` and the settings self-test. **[VERIFIED]**
- In-flight transactions are unaffected by the flush (they never read the tagged settings cache). **[INFERRED]**

## 12. Files created / modified (this task only)

Production (3):
- `app/Services/Payment/GatewaySettingsService.php` — `getPublicView` gains `enabled`/`available` (+ docblock).
- `app/Http/Controllers/Api/Admin/PaymentGatewaySettingsController.php` — flush tag after update (+ docblock).
- `app/Http/Controllers/Api/General/PaymentGatewayController.php` — docblock only (verdict documented).

Tests (3):
- `tests/Feature/Payment/PublicPaymentGatewaysTest.php` — 2 contract pins updated; +4 new tests (net; includes the isolated credentials/currency operand tests added after review).
- `tests/Feature/Payment/GatewaySettingsAdminTest.php` — +3 new tests (independence, cache flush, admin-toggle→public-endpoint loop; the loop + refactor added after review); file also carries earlier uncommitted additions from the Tabby/Tamara sessions.
- `tests/Feature/Payment/GatewaySettingsEnforcementTest.php` — NEW (5 tests, 54 assertions); hermetic currency pins added after review.

No database changes; no routes changed; **[VERIFIED]** via `git status` (60 dirty paths at close = 55 baseline + exactly these 5 statuses + 1 untracked test; unrelated product/WMS/barcode work untouched).

## 13. Database changes

- None. No migrations, no schema or seed changes. Settings live in the existing JSON `options` column. **[VERIFIED]**

## 14. Tests executed (env: PHP 8.2.30, MySQL `catch_verify`, real DB, per-file runs)

PASS with this task's changes live:

| Suite | Result |
|---|---|
| PublicPaymentGatewaysTest | OK 13 tests / 80 assertions (final re-run after review fixes) |
| GatewaySettingsEnforcementTest (NEW) | OK 5 / 54 |
| GatewaySettingsAdminTest | OK 14 / 202 (final re-run after review fixes) |
| GatewayRegistryTest | OK 10 / 35 |
| SettingsRegressionTest | OK 10 / 20 |
| FrontendCachePayloadTest | OK 8 / 20 |
| TamaraGatewayTest (unit) | OK 36 / 215 |
| TabbyGatewayTest (unit) | OK 23 / 113 |
| TapGatewayTest (unit) | OK 16 / 82 |
| PaymentWebhookTest | OK 10 / 79 |
| StripeGatewayTest | OK 16 / 83 |
| PayPalGatewayTest | OK 17 / 80 |
| TamaraGatewayFlowTest | OK 10 / 80 |
| TapGatewayFlowTest | OK 11 / 69 |
| TapWebhookTest | OK 14 / 121 |
| PaymentCompletionTest | OK 14 / 117 |
| PaymentSecurityTest | OK 11 / 172 |
| PaymentRedirectContractTest | OK 9 / 74 |
| PaymentConcurrencyTest | OK 9 / 123 |

PARTIAL:

- `GatewayDisableTest` — 3 relevant tests PASS (mid-payment in-flight completion + 2 mark-paid authorization tests, 29 assertions); **7 refund tests FAIL pre-existing** (see §10 [RISK]); the suite's full-file sequential run additionally shows 1205 lock-timeout cascade artifacts that do not reproduce in isolation (plain 404, proven pre-existing via HEAD-revert A/B).

PRE-EXISTING FAILURES (proven unrelated: identical failures with this task's production files reverted to HEAD, then restored byte-identical):

- `TamaraWebhookTest::tamara_refund_replays_never_double_apply_the_refund` (Mockery exact-count on refund replay).
- `TabbyWebhookTest::tabby_refund_webhook_reconciles_the_ledger_of_a_paid_row` (`completed` vs `ignored`).
- `TabbyGatewayFlowTest::adapter_refund_targets_the_latest_transaction_and_sends_the_idempotent_reference` (`success=false`).
- All `GatewayDisableTest::refund_*` (route never registered anywhere in history).

NOT RUN (unrelated or out of coupled scope): `BypassGateTest`, `CatalogCurrencyAuthorityTest`, `CurrencyPrecisionTest`, `ExternalRefundTest`, `RefundCurrencyAndLedgerTest` (same pre-existing refund family), `MarvelStatus*`, `StripeFailureCorrelationTest`, `PayPalCaptureIdempotencyTest`, `OtpLocalGatewayTest`, webhook-Verifier unit tests (`TamaraWebhookVerifierTest`, `TabbyWebhookVerifierTest`, `TapWebhookVerifierTest`).

No existing test was weakened; contract pins were updated only for the requirement-mandated response extension (§6).

## 15. Backward compatibility

- Public API: strictly additive two boolean fields; exact-key pin updated deliberately per task §6; no field removed or reordered behavior. **[VERIFIED]** + **[TESTED]**
- Admin API: unchanged request/response shape; new observed effect (cache flush) only. **[VERIFIED]** + **[TESTED]**
- Settings storage: same allowlist, same defaults, same merge semantics; env-only secrets preserved. **[VERIFIED]**
- Checkout/payment lifecycle: no behavioral change except the previously-existing control path now also reflected to the website. **[VERIFIED]** + **[TESTED]** where feasible.

## 16. Remaining risks

1. **[RISK]** Refund subsystem in this working tree is mid-flight from another session; see §10 for the full, corrected history (route shipped in `d3f6e0a`, deleted by the `92a8eb8` "fulfillment cleanup" commit, refund controller absent, service modified uncommitted; tests fail with and without this task's changes). Until the route + tests are reconciled, refund E2E regression cannot be green in this tree. This is a repo-level maintenance hazard independent of gateway settings.
2. **[RISK]** `available` lets any unauthenticated client infer internal state: `enabled=true && available=false` means "credentials missing or currency unsupported". Accepted pattern for a catalog snapshot; documented here and in the controller docblock; no mitigation planned beyond the existing `configured`-key omission.
3. **[RISK]** Storefront must not treat `available` as security — documented in controller + service docblocks; checkout remains the 422 authority. A client that ignores `available` still cannot start a disabled gateway, but it may show dead options.
4. **[UNKNOWN]** A literal "disable then process tap/tabby/tamara webhook while disabled" E2E test was not added; only registry/factory-level verify immunity is proven for those three (myfatoorah has the full E2E). Independent grep confirms `PaymentWebhookController` contains zero enabled/`canInitiate` references — structural coverage stands; a webhook-per-disable test remains future hardening.
5. **[UNKNOWN]** Marvel's `SettingsController::update` also writes `options` and already flushes the same tag; a site-settings update that EDITS `payment_gateways` sub-keys through that generic path is therefore correctly invalidated too — behavior inferred from code (`SettingsController.php:99`), not directly tested in scope.
6. **[RISK]** If a settings row predates the write-allowlist era, extra keys under `payment_gateways[code]` are read-filtered for the engine but still exposed raw by `SettingResource`'s `options` passthrough (pre-existing surface, unchanged by this task; no secrets are storable, so exposure is limited to metadata).
7. **[INFERRED]** The 1205 lock-timeout cascade inside a full `GatewayDisableTest` sequential run appears related to the incomplete refund/hardening work (not reproduced in isolation, not in my scope). If it reappears on clean trees, it needs its own investigation with lock instrumentation.
8. **[VERIFIED]** Zero-value (D-05) exemption: disabled gateways can still name zero-amount orders; no money movement; frontend copy must not promise otherwise (§9).
9. **[SHOULD-FIX-later]** `PaymentRefundService` re-implements the initiation gate inline instead of calling `canInitiate()` (equivalent today) — worth unifying when the refund subsystem lands, to avoid mechanism drift.

---

## 17. Independent review (READ-ONLY subagent reviewer, final gate)

Verdict: **PASS-WITH-SHOULD-FIX** (no BLOCKER, no MUST-FIX). The four SHOULD-FIX findings were implemented 1:1 and re-run green:

1. Credentials test isolated from the currency operand (currency pinned; both operand directions now have their own test).
2. Display-formula necessity of `configured` + `supports_catalog_currency` operands pinned — `enabled_configured_gateway_outside_catalog_currency_stays_unavailable`.
3. Admin-toggle→public-endpoint E2E loop through the REAL admin API — added (`admin_toggle_reflects_immediately_on_public_gateway_endpoint`), DB-leftover tolerant (flips from observed initial state).
4. Env-dependent hermeticity — `tap/tabby/tamara/myfatoorah` currency lists pinned in the affected test setUps.

Two factually wrong history statements (§10) and one §9 imprecision were corrected against direct evidence (`git ls-tree HEAD -- app/Services/Payment/PaymentRefundService.php` → blob `67c44bc`; `git log -S 'PaymentRefundController' -- 'routes/api.php'` → added `d3f6e0a`, removed `92a8eb8`; `git show 92a8eb8 -- routes/api.php` shows the F-1 route block deletion).

NICE-TO-HAVEs recorded in §16: availability-based state inference (no mitigation needed), dead `setGatewayEnabled(null)` branch removed, `canVerify`-naming seam (pre-existing), per-public-request double `Settings::first()` (pre-existing micro-opt, out of diff).

Reviewer independently re-ran ten suites on the real DB (`catch_verify`) with matching results.

---

## 18. Second pass — settings-response integration + settings-endpoint gateway control

Date: 2026-10-31 (same session, follow-up task). Marker legend as §0.

### 18.1 What existed before this pass

- §1–§17 infrastructure: per-gateway `{enabled, available}` rows on `GET /api/v1/general/payment-gateways`, admin toggle `PUT /api/v1/admin/payment-gateways/{code}`, registry enforcement (§9), in-flight preservation (§10), cache flush (§11).
- The two Site Settings GET endpoints — public `GET /api/v1/general/settings` (`App\Http\Controllers\Api\General\SettingController::index`, lazy cache closure, tag `FrontendResource::SETTINGS`) and admin `GET /api/v1/settings` (marvel `SettingsController::index`, non-closure model cache quirk, same tag) — did NOT carry any per-gateway block. Both render the SAME `Marvel\Http\Resources\SettingResource` (null-invariant branch + model branch), so one resource edit covers both contracts. **[VERIFIED]**
- `PUT /api/v1/settings` could not change gateway toggles: `SettingsController::update` passes `$request->only([...])` (gateway keys not accepted) and replaces `options` verbatim — a settings save that omitted `payment_gateways` would silently WIPE stored toggles. **[VERIFIED]** (kernel behavior, pre-existing hazard, narrowed this pass).
- Effective admin-read permission rule discovered: route middleware `permission:view-settings|update-settings` (Spatie OR) INTERSECTS with the controller constructor's `permission:view-settings` for `index` — so `view-settings` is mandatory on GET; `update-settings` alone reads nothing but writes fine on PUT (constructor pins only `update-settings` there). Discovered by test failure, then confirmed by source. **[VERIFIED]**

### 18.2 Additional user directive received mid-task

"Control it in true or false from the settings endpoint" — gateway enable state must be controllable through the Site Settings endpoint itself, stored in the settings table, reflected to the frontend GET. Implemented as described below (no new table; both write surfaces converge on the same storage).

### 18.3 Changes (this pass only)

Production (4 files):

1. `app/Services/Payment/GatewaySettingsService.php` — NEW `clientAvailabilityMap()`: compact `{code: {enabled, available}}` map on the same `getPublicView()` merge; currency-subsystem failure degrades to `config('payment.default_currency', 'KWD')`; never throws. `(+ CurrencyService import)` **[VERIFIED]**
2. `packages/marvel/src/Http/Resources/SettingResource.php` — NEW private `gatewayStates()` (try/catch → `[]` fault tolerance) + `payment_gateways` key added to BOTH `toArray` branches (null-invariant AND model). Additive; pre-existing keys untouched. **[VERIFIED]** + **[TESTED]** (null-safety pins `ProductionRootCauseRegressionTest` 8/22)
3. `packages/marvel/src/Http/Controllers/SettingsController.php` — `GatewaySettingsService` injected; `update()` now: (a) pre-validates a `payment_gateways` patch map BEFORE any persistence (unknown code → 422 all-or-nothing), (b) preserves stored `options.payment_gateways` when an options payload omits them (anti-wipe round-trip guard), (c) applies patches after the row save through `updateOverride()` (write allowlist, lockForUpdate), (d) keeps the existing unconditional flush + single `settings_updated` audit. **[VERIFIED]** + **[TESTED]**
4. `packages/marvel/src/Http/Requests/SettingsRequest.php` — `payment_gateways` `sometimes|array`, `payment_gateways.*` `array`, `payment_gateways.*.enabled` `required|boolean`. Only `enabled` is honored per code; `available` and unknown keys are ignored (never writable). **[VERIFIED]** + **[TESTED]**

Tests (1 new file, 6 tests / 93 assertions):

5. `tests/Feature/Payment/SettingsResponsePaymentGatewaysTest.php` — (a) general GET map: 5 codes × exact `{enabled, available}` boolean keys, verdict examples, canary secret scan, backward-compat keys; (b) admin GET 401/403/view-only-200/update-only-403 + map + secret scan; (c) dashboard toggle via DEDICATED admin API → both GETs both directions; (d) `PUT /api/v1/settings` gateway control: guest 401, no-perm 403, update-only writes, all-or-nothing unknown code (nothing persisted), malformed boolean 422, `available` not writable, flip ON/OFF reflected on GET; (e) settings save without gateway block preserves toggles; (f) missing settings row survives (config-driven block intact).

Docs (2 new, template-style, all claims verified against source this session):

6. `docs/api/get-general-settings-payment-gateways-availability.md`
7. `docs/api/admin-settings-payment-gateways-control.md`

### 18.4 Final response shape (both settings GETs)

```json
"payment_gateways": {
  "stripe":     { "enabled": false, "available": false },
  "myfatoorah": { "enabled": true,  "available": false },
  "tap":        { "enabled": true,  "available": true },
  "tabby":      { "enabled": true,  "available": false },
  "tamara":     { "enabled": false, "available": false }
}
```

Fixed 5 codes, exactly `{enabled, available}` per code, no secrets/no metadata; `available = enabled && configured && supports_catalog_currency` (display layer; checkout 422 is the authority — §8/§9 unchanged).

Dashboard → API → frontend flow (both surfaces converge): `PUT /api/v1/settings {payment_gateways: {code: {enabled}}}` **or** dedicated `PUT /api/v1/admin/payment-gateways/{code}` → row write via `updateOverride` → tagged cache flush (both update paths) → `GET /api/v1/general/settings` + `GET /api/v1/settings` reflect immediately → storefront consumes the block; checkout independently enforces the same flags.

### 18.5 Tests executed this pass (per-file, `catch_verify`)

Command pattern: `$env:DB_DATABASE='catch_verify'; php vendor\phpunit\phpunit\phpunit <path>`

| Suite | Result |
|---|---|
| SettingsResponsePaymentGatewaysTest (NEW) | OK 6 / 93 |
| ProductionRootCauseRegressionTest | OK 8 / 22 |
| SettingsRegressionTest | OK 10 / 20 |
| FrontendCachePayloadTest | OK 8 / 20 |
| PublicPaymentGatewaysTest | OK 13 / 80 |
| GatewaySettingsAdminTest | OK 14 / 202 |
| GatewaySettingsEnforcementTest | OK 5 / 54 |

Triage history (both fixed): duplicate `seedCurrencyData()` call → 1062 (removed); wrong test expectation about update-only GET (actual rule: constructor middleware intersection → 403 by design; write permission proven on PUT instead).

### 18.6 Not verified this pass

- `MeemForensicFullTest::test_admin_settings_requires_auth` only pins 401 shape — unaffected by resource/controller edits; full 101-test forensic file not re-run.
- Frontend work: storefront out of repo; contracts documented, not implemented.
- Pass-1 unknowns unchanged (§16: webhook-while-disabled literal E2E, refund family blocked by the `92a8eb8` route deletion).

### 18.7 Remaining risks this pass

1. **[RISK]** `PUT /api/v1/settings` gateway patches are audited only via the single `settings_updated` record (no per-gateway `ActivitySnapshot` like the dedicated endpoint emits). Trade-off accepted: one dashboard save can patch N gateways without N audit rows.
2. **[RISK]** Toggles cannot be removed by an options-omitting save (preservation guard) — deliberate; per-[code] row remains removable only via the engine's per-code semantics.
3. **[VERIFIED-NEGATIVE]** No secrets write/read path added; `payment_gateways` keys strictly filtered (write allowlist + read allowlist + boolean validation).
4. **[INFERRED]** Generic `options` replace semantics for OTHER unspecified keys remain the pre-existing kernel behavior (unchanged, out of scope per no-refactor rule).

### 18.8 Independent review (READ-ONLY subagent reviewer, pass-2)

Verdict: **PASS-WITH-SHOULD-FIX** (no BLOCKER, no MUST-FIX). Review notes:

- Reviewer re-execution of the test suite failed on an environment condition (`SQLSTATE[HY000] [2002]` — MySQL was down at its session start; the implementer's runs had been green on the same protocol pre-review; the same outage persisted into the post-fix re-run window. Runtime greenness for THIS pass is therefore implementer-executed, not reviewer-reproduced. Static review of all diffs, docs, and consumers was complete.)
- Code-level answers: (A) partial-save window is unreachable without a stale/hostile DB row + contradictory payload (contrived); (B) options-preservation guard verified null-safe (`Settings` model casts `options` => array) and no legitimate wipe path broken; (C) cost of `clientAvailabilityMap()` on the hot public endpoint flagged; (D) currency fallback OK (fallback key mismatch with `shop.default_currency` noted); (E) request rules fail-closed, no bypass (numeric-string keys handled, `only-available` entries → 422); (F) authorization matrix matches tests/docs; write callers' flush coverage complete except the kernel exception window; `SettingsRepository::updateSetting()` is dead code (zero callers); (G) no in-repo caller regression risk; webhook/webhook-cache consumers unaffected; (H) core doc claims verified (PUT response reflects fresh row — the update() re-fetch; admin-GET update-only → 403).

SHOULD-FIX findings and their implementation (same session):

1. **Flush position on the exception path** — `flushTag` moved from after the media blocks (`SettingsController.php:127`) to directly after the gateway-patch commit (`:110`), before any late-failure branch; a 422 from an image-upload failure can no longer leave the tagged cache stale while state persisted. The remaining "4xx may describe persisted state" nuance is pre-existing kernel behavior for general settings fields and is documented, not fixed in this scope.
2. **Hot-path cost of the availability map** — `clientAvailabilityMap()` now caches its result under the SAME `FrontendResource::SETTINGS` tag every write path flushes (15-min TTL for out-of-band self-healing; map invalidated instantly by settings saves, admin gateway toggles, and currency-catalog writes). Implemented via the existing `HasCache` trait (closure computed only on miss, non-tagging drivers fall back to the registry path).
3. (Cheap hardening from a NICE-TO-HAVE) `SettingResource::gatewayStates()` now logs the caught throwable before degrading to `[]` instead of swallowing silently.

Recorded NICE-TO-HAVEs (no code change this scope): ghost codes admitted by `merged()` from stale DB rows (pre-existing pass-1 semantics, read-allowlisted), dead writer `SettingsRepository::updateSetting()` (would bypass flush + allowlist — delete/guard if ever re-wired), currency fallback key mismatch (`payment.default_currency` KWD vs `shop.default_currency` USD — fail-safe, documented).

Post-fix syntax verification (`php -l`) clean on all three touched files.

**Runtime re-verification after MySQL restore (2026-11-01, same `catch_verify` protocol, post-review-fix build): ALL GREEN**

| Suite | Result |
| --- | --- |
| `tests/Feature/Payment/SettingsResponsePaymentGatewaysTest.php` | OK 6 tests / 93 assertions |
| `tests/Unit/FrontendCachePayloadTest.php` | OK 8 / 20 |
| `tests/Feature/ProductionRootCauseRegressionTest.php` | OK 8 / 22 |
| `tests/Feature/Settings/SettingsRegressionTest.php` | OK 10 / 20 |
| `tests/Feature/Payment/PublicPaymentGatewaysTest.php` | OK 13 / 80 |
| `tests/Feature/Payment/GatewaySettingsAdminTest.php` | OK 14 / 202 |
| `tests/Feature/Payment/GatewaySettingsEnforcementTest.php` | OK 5 / 54 |

Total: **64 tests / 491 assertions green** on the post-fix build. The green runs were executed on the CURRENT working tree, which also contains the parallel in-flight Tap/Tabby/Tamara session's 6-gateway registry (their additive edits to `GatewaySettingsAdminTest`/`PublicPaymentGatewaysTest` assertions ran inside these suites) — i.e. this pass's storage + response contract is verified compatible with the expanded registry. Review-fix effects covered: the cached `clientAvailabilityMap` is exercised through the PUT-toggles→both-GETs refresh tests and the tagged-flush tests (both directions, green); the `:110` flush position is exercised by the same visibility chain tests; the logged-swallow path is static-verified (`php -l` + logic review) since green runs do not trigger failures.

