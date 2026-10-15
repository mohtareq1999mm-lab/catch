# API Contracts — index (skeleton, NOT STARTED)

> Per-domain request/response contracts/examples from actual code + runtime evidence (redacted). Status: NOT STARTED (discovery census done, contract extraction pending; halted after data-loss incident — read-only work only).

- `error/route-inventory.md` — runtime denominator (533 routes, method+URI+name+action+middleware).
- `error/api-domain-coverage.md` — per-domain counts + DB footprint summary (Order Flow + WMS VERIFIED, others PARTIAL).
- `error/flow-contract.md` — checkout-to-fulfillment lifecycle contract (VERIFIED).
- Per-route JSON examples: NOT YET GENERATED. Template per endpoint: method, final URI, auth/permission, path/query/body fields, content-type, locale (`lang` header), success schema + status, error schema + status, validation entry point (FormRequest), service/DB tables, side effects (events/jobs/queue/external).
- No secrets/PII in examples (redacted IDs).
