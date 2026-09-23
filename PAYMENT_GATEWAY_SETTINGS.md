# PAYMENT_GATEWAY_SETTINGS.md

> Admin gateway control. Home: `settings.options['payment_gateways']` (D-04). Secrets: env-only.

## Effective availability

```
canInitiate = known && enabled && class-exists && isConfigured()
              && method-compatible && currency-supported
canVerify   = known (enabled ignored — §13: disabling never strands in-flight payments)
```

## Admin API

- `GET /api/v1/admin/payment-gateways` (`view-settings`): rows `{code, display_name, enabled,
  configured, supported_currencies, methods, sort_order}` sorted by `sort_order`, plus
  `catalog_currency` + `supports_catalog_currency` preview flags.
- `PUT /api/v1/admin/payment-gateways/{code}` (`update-settings`): allowlist
  `{enabled:boolean, display_name:string≤60, sort_order:int}`; unknown code → 404; secrets /
  class / currencies / methods in payload are ignored. Audited (activity log).
- Responses never contain secret material (tested assertion).

## Environment

```env
# MyFatoorah (live)
MYFATOORAH_ENABLED=true
MYFATOORAH_API_KEY=...
MYFATOORAH_BASE_URL=https://api.myfatoorah.com/v2/
MYFATOORAH_SUPPORTED_CURRENCIES=KWD,SAR,AED,BHD,QAR,OMR,EGP
PAYMENT_TEST_GATEWAY_BYPASS=false   # apitest mismatch bypass: needs flag + local/testing env
# Stripe (adapter ready; sandbox BLOCKED until keys provisioned)
STRIPE_ENABLED=false
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=
STRIPE_SUPPORTED_CURRENCIES=USD,EUR,KWD,SAR,AED
# PayPal (adapter ready; sandbox BLOCKED until keys provisioned)
PAYPAL_ENABLED=false
PAYPAL_MODE=sandbox
PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
PAYPAL_WEBHOOK_ID=
PAYPAL_SUPPORTED_CURRENCIES=USD,EUR
```

Legacy `ACTIVE_PAYMENT_GATEWAY`, `STRIPE_API_KEY`, `PAYPAL_SANDBOX/LIVE_*` remain read by the
vendored Marvel kernel only (annotated in `.env.example`); the application path uses the keys above.

## Enable/disable semantics (§13, tested)

- Disabling blocks NEW initiations (422) immediately, including between availability check and
  invoice creation (re-checked in handler; residual distributed window is fail-safe: any created
  invoice always yields a verifiable pending txn row).
- Verification/completion/reconciliation of existing payments is unaffected (proven by §46 test:
  create-while-enabled → disable → callback completes → new initiation 422).

## Deploy checklist

1. `composer install` (adds `stripe/stripe-php`, `srmklive/paypal`).
2. Set env keys above (production MyFatoorah base URL, never apitest + bypass on).
3. `php artisan migrate` (transactions idempotency/columns, reconciliation, history assumed).
4. `php artisan db:seed --class=PermissionSeeder` (**required**: activates `payments.mark_paid`,
   `payments.refund`, `payments.verify`, `payments.reconcile`; until run, mark-paid 403s by design).
5. Confirm `currency_selection_enabled` posture (payment always uses catalog regardless).
