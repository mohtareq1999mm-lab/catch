# Translation Audit — en/ar (UPDATED read-only completion)

## Verified locale contract (HIGH confidence)
- Selector is **`lang: ar|en` request header**, NOT `Accept-Language`. Evidence: `App\Http\Middleware\CheckLangMiddleware.php:27-44` (reads `$request->header('lang','en')`, allowlist en/ar, missing→en, unsupported→`config('app.locale')`).
- `config/app.php`: `'locale'=>'ar'`, `'fallback_locale'=>'en'` — VERIFIED.
- Kernel `api` group already includes `CheckLangMiddleware` → explicit `lang` middleware redundant but harmless. WMS groups inherit via `api` — no leak.

## Key parity (VERIFIED via PHP script reading lang files, no DB)
- Command: PHP script iterating `resources/lang/en/*.php` vs `resources/lang/ar/*.php`, comparing top-level keys + one-level nested groups, output JSON.
- Result: **PERFECT parity — 0 missing keys in either direction for all 15 files**:
  - activity 99/99, auth 3/3, checkout 69/69, common 3/3, coupon 12/12, message 592/592, notifications 12/12, pagination 2/2, passwords 5/5, permissions 285/285, sms 1/1, tax 0/0, tracking 14/14, validation 61/61, wms 12/12. `missingInAr=[]`, `missingInEn=[]`, `nestedMissingInAr={}` for all.
- Line-count drift (message.php en 668 vs ar 613 lines; validation en 370 vs ar 390) is formatting/value-length, NOT missing keys. Earlier I18N drift concern (findings.md) is CORRECTED to PASS for key presence; value quality (grammar/placeholders) NOT audited.
- `resources/lang/ar.json` NOT checked (flat JSON, needs separate parse — marked UNVERIFIED).

## Constant-resolution audit (v4 script, faithful Laravel group/item + Arr::get semantics, no DB)
- All 328 `define()` constants in `packages/marvel/config/constants.php` resolved through the `ApiResponse::translateNotice` fallback chain (`message.{val}` → `{val}`) in BOTH locales: **327/327 resolve**.
- Single miss both locales: `DEFAULT_CURRENCY_FORMATION => en-US` — an Excel number-format locale used only by `OrderExport` (verified), never passed to `__()` for API responses. NOT A DEFECT, no fix.
- Earlier v1-v3 script iterations mismodeled Laravel lookup (nested-walk without exact-first / without group split) and falsely reported 326/1 misses — methodology corrected and documented here; never presented as findings.
- Envelope safety net: `ApiResponse::translateNotice` falls back to the raw key when missing, so this audit (not runtime complaints) is the completeness proof. Verdict: **no raw-key leaks in the constants contract**.
- Deep (2+ level) recursive diff NOT run (one-level only) — marked PARTIAL. Placeholder (`:attribute`) consistency NOT checked.

## Message usage (sampled, VERIFIED)
- `App\Exceptions\Handler.php` uses `__('message.ERROR.*')` — no raw-key leak in sample.
- `product-type` closure returns `__("message.PRODUCT_TYPE.*")` (localized); `enum-types` returns raw enum values (by design, N/A).
- Flow/input JSON labels + nested `flow_inputs` error association: NOT TESTED (BLOCKED — no DB).

## Acceptance checklist status
- [x] Key presence parity (top-level + 1 nested): PASS (evidence above).
- [ ] Value quality/placeholders, ar.json, deep recursion, per-endpoint en/ar response tests: NOT STARTED/BLOCKED.
- [ ] Frontend: BLOCKED / FRONTEND SOURCE NOT AVAILABLE.

## Status
- I18N-001 PASS. I18N-002 PARTIAL (parity PASS, per-rule execution BLOCKED). I18N-003 NOT STARTED.
- No translation fixes applied (none needed for key presence).
